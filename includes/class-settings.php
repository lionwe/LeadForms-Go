<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Settings
{
	private const OPTION = 'leadforms_go_settings';
	private static ?array $cache = null;

	public static function all(): array
	{
		if (self::$cache !== null) return self::$cache;
		$value = get_option(self::OPTION, []);
		self::$cache = is_array($value) ? array_replace_recursive(self::defaults(), $value) : self::defaults();
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

	public static function sanitize(mixed $input): array
	{
		$input = is_array($input) ? $input : [];
		$current = self::all();
		$raw = static function (string $section, string $key) use ($input): string {
			$value = $input[$section][$key] ?? '';
			return is_scalar($value) ? trim(wp_unslash((string) $value)) : '';
		};
		$text = static fn (string $section, string $key): string => sanitize_text_field($raw($section, $key));
		$secret = static function (string $section, string $key) use ($input, $current): string {
			$submitted = $input[$section][$key] ?? '';
			$value = is_scalar($submitted) ? trim(wp_unslash((string) $submitted)) : '';
			return $value === '' ? (string) ($current[$section][$key] ?? '') : sanitize_text_field($value);
		};
		return [
			'general' => ['retain_data' => ! empty($input['general']['retain_data'])],
			'telegram' => [
				'enabled' => ! empty($input['telegram']['enabled']),
				'token' => $secret('telegram', 'token'),
				'chat_id' => $text('telegram', 'chat_id'),
			],
			'sheets' => [
				'enabled' => ! empty($input['sheets']['enabled']),
				'spreadsheet_id' => (string) preg_replace('/[^A-Za-z0-9_-]/', '', $text('sheets', 'spreadsheet_id')),
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
		self::$cache = $sanitized;
	}

	private static function defaults(): array
	{
		return [
			'general' => ['retain_data' => true],
			'telegram' => ['enabled' => false, 'token' => '', 'chat_id' => ''],
			'sheets' => ['enabled' => false, 'spreadsheet_id' => '', 'sheet_name' => 'Sheet1', 'fields_order' => ''],
			'crm' => ['enabled' => false, 'partner_id' => '', 'token' => '', 'adv_id' => ''],
		];
	}
}
