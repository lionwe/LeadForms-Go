<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Connection_Profiles
{
	private const OPTION = 'leadforms_go_connection_profiles';

	public static function register(): void
	{
		register_setting('leadforms_go', self::OPTION, ['type' => 'array', 'sanitize_callback' => [self::class, 'sanitize'], 'default' => []]);
	}

	public static function all(string $connector = ''): array
	{
		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? $stored : [];
		foreach ($stored as &$profile) {
			if (is_array($profile) && isset($profile['token'])) $profile['token'] = Settings::decrypt_secret((string) $profile['token']);
		}
		unset($profile);
		if ($connector === '') return $stored;
		return array_values(array_filter($stored, static fn (array $profile): bool => ($profile['connector'] ?? '') === $connector));
	}

	public static function find(string $id, string $connector = ''): ?array
	{
		$id = sanitize_key($id);
		foreach (self::all($connector) as $profile) if (($profile['id'] ?? '') === $id) return $profile;
		return null;
	}

	public static function sanitize(mixed $input): array
	{
		$input = is_array($input) ? $input : [];
		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? $stored : [];
		$by_id = [];
		foreach ($stored as $profile) if (is_array($profile) && ! empty($profile['id'])) $by_id[(string) $profile['id']] = $profile;
		$clean = [];
		foreach (array_slice($input, 0, 100) as $row) {
			if (! is_array($row)) continue;
			$id = sanitize_key((string) ($row['id'] ?? '')) ?: 'profile_' . strtolower(wp_generate_password(12, false, false));
			if (! empty($row['delete'])) {
				if (self::is_used($id) && isset($by_id[$id])) {
					$clean[] = $by_id[$id];
					add_settings_error(self::OPTION, 'profile_in_use_' . $id, __('Профіль використовується формою і не був видалений.', 'leadforms-go'));
				}
				continue;
			}
			$connector = sanitize_key((string) ($row['connector'] ?? ''));
			$name = sanitize_text_field((string) ($row['name'] ?? ''));
			if (! in_array($connector, ['telegram', 'sheets', 'crm'], true) || $name === '') continue;
			$previous = is_array($by_id[$id] ?? null) ? $by_id[$id] : [];
			$token = trim(wp_unslash((string) ($row['token'] ?? '')));
			if ($token === '') $encrypted_token = (string) ($previous['token'] ?? '');
			else $encrypted_token = Settings::encrypt_secret(sanitize_text_field($token));
			$clean[] = [
				'id' => $id,
				'connector' => $connector,
				'name' => $name,
				'token' => $encrypted_token,
				'chat_id' => sanitize_text_field((string) ($row['chat_id'] ?? '')),
				'topic_id' => absint($row['topic_id'] ?? 0),
				'spreadsheet_id' => self::spreadsheet_id((string) ($row['spreadsheet_id'] ?? '')),
				'sheet_name' => sanitize_text_field((string) ($row['sheet_name'] ?? '')),
				'partner_id' => sanitize_text_field((string) ($row['partner_id'] ?? '')),
				'adv_id' => sanitize_text_field((string) ($row['adv_id'] ?? '')),
			];
		}
		return $clean;
	}

	private static function is_used(string $id): bool
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE routing_config LIKE %s", '%' . $wpdb->esc_like('"' . $id . '"') . '%')) > 0;
	}

	private static function spreadsheet_id(string $value): string
	{
		if (preg_match('~/(?:spreadsheets/)?(?:u/\d+/)?d/([A-Za-z0-9_-]+)~', $value, $match)) $value = $match[1];
		return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
	}
}
