<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Settings
{
	private const OPTION = 'leadforms_go_settings';
	private const ENCRYPTED_PREFIX = 'lfg:v1:';
	private static ?array $cache = null;

	public static function all(): array
	{
		if (self::$cache !== null) return self::$cache;
		$value = get_option(self::OPTION, []);
		$all = is_array($value) ? array_replace_recursive(self::defaults(), $value) : self::defaults();
		$all['telegram']['token'] = self::constant_or_secret('LEADFORMS_GO_TELEGRAM_TOKEN', (string) $all['telegram']['token']);
		$all['telegram']['chat_id'] = self::constant_or_value('LEADFORMS_GO_TELEGRAM_CHAT_ID', (string) $all['telegram']['chat_id']);
		$all['crm']['token'] = self::constant_or_secret('LEADFORMS_GO_CRM_TOKEN', (string) $all['crm']['token']);
		$all['crm']['partner_id'] = self::constant_or_value('LEADFORMS_GO_CRM_PARTNER_ID', (string) $all['crm']['partner_id']);
		$all['crm']['adv_id'] = self::constant_or_value('LEADFORMS_GO_CRM_ADV_ID', (string) $all['crm']['adv_id']);
		$all['antispam']['turnstile_site_key'] = self::constant_or_value('LEADFORMS_GO_TURNSTILE_SITE_KEY', (string) $all['antispam']['turnstile_site_key']);
		$all['antispam']['turnstile_secret_key'] = self::constant_or_secret('LEADFORMS_GO_TURNSTILE_SECRET_KEY', (string) $all['antispam']['turnstile_secret_key']);
		self::$cache = $all;
		return self::$cache;
	}

	public static function section(string $section): array
	{
		$all = self::all();
		return is_array($all[$section] ?? null) ? $all[$section] : [];
	}

	public static function register(): void
	{
		register_setting('leadforms_go', self::OPTION, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize'],
			'default' => self::defaults(),
		]);
	}

	public static function update_section(string $section, array $values): void
	{
		if (! in_array($section, ['general', 'telegram', 'sheets', 'crm', 'antispam'], true)) return;
		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? array_replace_recursive(self::defaults(), $stored) : self::defaults();
		$stored[$section] = $values;
		update_option(self::OPTION, self::sanitize($stored), false);
		self::$cache = null;
	}

	public static function disable_integrations(): void
	{
		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? array_replace_recursive(self::defaults(), $stored) : self::defaults();
		foreach (['telegram', 'sheets', 'crm'] as $section) {
			$stored[$section]['enabled'] = false;
		}
		update_option(self::OPTION, self::sanitize($stored), false);
		self::$cache = null;
	}

	public static function sanitize(mixed $input): array
	{
		$input = is_array($input) ? $input : [];
		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? array_replace_recursive(self::defaults(), $stored) : self::defaults();
		$raw = static function (string $section, string $key) use ($input): string {
			$value = $input[$section][$key] ?? '';
			return is_scalar($value) ? trim(wp_unslash((string) $value)) : '';
		};
		$text = static fn (string $section, string $key): string => sanitize_text_field($raw($section, $key));
		$secret = static function (string $section, string $key) use ($input, $stored): string {
			$submitted = $input[$section][$key] ?? '';
			$value = is_scalar($submitted) ? trim(wp_unslash((string) $submitted)) : '';
			if ($value === '') return (string) ($stored[$section][$key] ?? '');
			$encrypted = self::encrypt_secret(sanitize_text_field($value));
			if ($encrypted !== '') return $encrypted;
			add_settings_error(self::OPTION, 'secret_encryption_failed', __('Секрет не оновлено: сервер не зміг безпечно зашифрувати значення.', 'leadforms-go'));
			return (string) ($stored[$section][$key] ?? '');
		};
		return [
			'general' => [
				'retain_data' => ! empty($input['general']['retain_data']),
				'retention_days' => min(3650, max(0, absint($input['general']['retention_days'] ?? 180))),
				'attribution_days' => min(365, max(0, absint($input['general']['attribution_days'] ?? 30))),
			],
			'antispam' => [
				'provider' => ($input['antispam']['provider'] ?? '') === 'turnstile' ? 'turnstile' : 'none',
				'turnstile_site_key' => $text('antispam', 'turnstile_site_key'),
				'turnstile_secret_key' => $secret('antispam', 'turnstile_secret_key'),
			],
			'telegram' => [
				'enabled' => ! empty($input['telegram']['enabled']),
				'token' => $secret('telegram', 'token'),
				'chat_id' => $text('telegram', 'chat_id'),
			],
			'sheets' => [
				'enabled' => ! empty($input['sheets']['enabled']),
				'spreadsheet_id' => self::spreadsheet_id($raw('sheets', 'spreadsheet_id')),
				'sheet_name' => $text('sheets', 'sheet_name'),
				'fields_order' => $text('sheets', 'fields_order'),
			],
			'crm' => [
				'enabled' => ! empty($input['crm']['enabled']),
				'partner_id' => $text('crm', 'partner_id'),
				'token' => $secret('crm', 'token'),
				'adv_id' => $text('crm', 'adv_id'),
			],
		];
	}

	public static function maybe_encrypt_secrets(): void
	{
		$stored = get_option(self::OPTION, []);
		if (! is_array($stored)) return;
		$changed = false;
		foreach ([['telegram', 'token', 'LEADFORMS_GO_TELEGRAM_TOKEN'], ['crm', 'token', 'LEADFORMS_GO_CRM_TOKEN'], ['antispam', 'turnstile_secret_key', 'LEADFORMS_GO_TURNSTILE_SECRET_KEY']] as [$section, $key, $constant]) {
			$value = (string) ($stored[$section][$key] ?? '');
			if (defined($constant) && $value !== '') {
				$stored[$section][$key] = '';
				$changed = true;
			} elseif ($value !== '' && ! str_starts_with($value, self::ENCRYPTED_PREFIX)) {
				$encrypted = self::encrypt_secret($value);
				if ($encrypted !== '') {
					$stored[$section][$key] = $encrypted;
					$changed = true;
				}
			}
		}
		if ($changed) update_option(self::OPTION, $stored, false);
		self::$cache = null;
	}

	public static function encrypt_secret(string $value): string
	{
		if ($value === '' || str_starts_with($value, self::ENCRYPTED_PREFIX)) return $value;
		if (! function_exists('openssl_encrypt')) return '';
		try {
			$iv = random_bytes(12);
		} catch (\Throwable) {
			return '';
		}
		$tag = '';
		$encrypted = openssl_encrypt($value, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
		if (! is_string($encrypted) || $tag === '') return '';
		return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $encrypted);
	}

	public static function decrypt_secret(string $value): string
	{
		if ($value === '' || ! str_starts_with($value, self::ENCRYPTED_PREFIX)) return $value;
		if (! function_exists('openssl_decrypt')) return '';
		$decoded = base64_decode(substr($value, strlen(self::ENCRYPTED_PREFIX)), true);
		if (! is_string($decoded) || strlen($decoded) < 29) return '';
		$iv = substr($decoded, 0, 12);
		$tag = substr($decoded, 12, 16);
		$plaintext = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
		return is_string($plaintext) ? $plaintext : '';
	}

	public static function import_legacy(string $section, array $legacy): void
	{
		$all = self::all();
		if ($section === 'telegram') {
			$all['telegram'] = ['enabled' => true, 'token' => (string) ($legacy['telegram_token'] ?? ''), 'chat_id' => (string) ($legacy['telegram_chat_id'] ?? '')];
		} elseif ($section === 'sheets') {
			$all['sheets'] = ['enabled' => false, 'spreadsheet_id' => (string) ($legacy['page_id'] ?? ''), 'sheet_name' => (string) ($legacy['sheet_name'] ?? ''), 'fields_order' => (string) ($legacy['fields_order'] ?? '')];
		} elseif ($section === 'crm') {
			$all['crm'] = ['enabled' => true, 'partner_id' => (string) ($legacy['crm_partner_id'] ?? ''), 'token' => (string) ($legacy['crm_token'] ?? ''), 'adv_id' => (string) ($legacy['crm_adv_id'] ?? '')];
		}
		$sanitized = self::sanitize($all);
		update_option(self::OPTION, $sanitized, false);
		self::$cache = null;
	}

	private static function defaults(): array
	{
		return [
			'general' => ['retain_data' => true, 'retention_days' => 180, 'attribution_days' => 30],
			'antispam' => ['provider' => 'none', 'turnstile_site_key' => '', 'turnstile_secret_key' => ''],
			'telegram' => ['enabled' => false, 'token' => '', 'chat_id' => ''],
			'sheets' => ['enabled' => false, 'spreadsheet_id' => '', 'sheet_name' => 'Sheet1', 'fields_order' => ''],
			'crm' => ['enabled' => false, 'partner_id' => '', 'token' => '', 'adv_id' => ''],
		];
	}

	private static function constant_or_secret(string $name, string $stored): string
	{
		return defined($name) ? sanitize_text_field((string) constant($name)) : self::decrypt_secret($stored);
	}

	private static function constant_or_value(string $name, string $stored): string
	{
		return defined($name) ? sanitize_text_field((string) constant($name)) : $stored;
	}

	private static function encryption_key(): string
	{
		return hash_hmac('sha256', 'leadforms-go-settings', wp_salt('auth'), true);
	}

	private static function spreadsheet_id(string $value): string
	{
		$value = trim($value);
		if (preg_match('~/(?:spreadsheets/)?(?:u/\d+/)?d/([A-Za-z0-9_-]+)~', $value, $match)) $value = $match[1];
		return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
	}
}
