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
		if (is_array($value) && isset($value['general']['phone_countries']) && is_array($value['general']['phone_countries'])) {
			$all['general']['phone_countries'] = self::sanitize_phone_countries($value['general']['phone_countries']);
		}
		if (empty($all['general']['phone_country_selector_configured'])) $all['general']['phone_country_selector'] = false;
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

	/** @return array<string, array{name:string, dial:string, mask:string, min:int, max:int}> */
	public static function phone_countries(): array
	{
		return [
			'UA' => ['name' => __('Україна', 'leadforms-go'), 'dial' => '380', 'mask' => '+380 (00) 000-00-00', 'min' => 9, 'max' => 9],
			'PL' => ['name' => __('Польща', 'leadforms-go'), 'dial' => '48', 'mask' => '+48 000 000 000', 'min' => 9, 'max' => 9],
			'DE' => ['name' => __('Німеччина', 'leadforms-go'), 'dial' => '49', 'mask' => '+49 000 000000000', 'min' => 7, 'max' => 11],
			'CZ' => ['name' => __('Чехія', 'leadforms-go'), 'dial' => '420', 'mask' => '+420 000 000 000', 'min' => 9, 'max' => 9],
			'SK' => ['name' => __('Словаччина', 'leadforms-go'), 'dial' => '421', 'mask' => '+421 000 000 000', 'min' => 9, 'max' => 9],
			'RO' => ['name' => __('Румунія', 'leadforms-go'), 'dial' => '40', 'mask' => '+40 000 000 000', 'min' => 9, 'max' => 9],
			'MD' => ['name' => __('Молдова', 'leadforms-go'), 'dial' => '373', 'mask' => '+373 00 000 000', 'min' => 8, 'max' => 8],
			'HU' => ['name' => __('Угорщина', 'leadforms-go'), 'dial' => '36', 'mask' => '+36 00 000 0000', 'min' => 8, 'max' => 9],
			'LT' => ['name' => __('Литва', 'leadforms-go'), 'dial' => '370', 'mask' => '+370 000 00000', 'min' => 8, 'max' => 8],
			'LV' => ['name' => __('Латвія', 'leadforms-go'), 'dial' => '371', 'mask' => '+371 000 00000', 'min' => 8, 'max' => 8],
			'EE' => ['name' => __('Естонія', 'leadforms-go'), 'dial' => '372', 'mask' => '+372 0000 0000', 'min' => 7, 'max' => 8],
			'FR' => ['name' => __('Франція', 'leadforms-go'), 'dial' => '33', 'mask' => '+33 0 00 00 00 00', 'min' => 9, 'max' => 9],
			'IT' => ['name' => __('Італія', 'leadforms-go'), 'dial' => '39', 'mask' => '+39 000 000 0000', 'min' => 9, 'max' => 10],
			'ES' => ['name' => __('Іспанія', 'leadforms-go'), 'dial' => '34', 'mask' => '+34 000 000 000', 'min' => 9, 'max' => 9],
			'PT' => ['name' => __('Португалія', 'leadforms-go'), 'dial' => '351', 'mask' => '+351 000 000 000', 'min' => 9, 'max' => 9],
			'NL' => ['name' => __('Нідерланди', 'leadforms-go'), 'dial' => '31', 'mask' => '+31 00 00000000', 'min' => 9, 'max' => 9],
			'BE' => ['name' => __('Бельгія', 'leadforms-go'), 'dial' => '32', 'mask' => '+32 000 00 00 00', 'min' => 8, 'max' => 9],
			'AT' => ['name' => __('Австрія', 'leadforms-go'), 'dial' => '43', 'mask' => '+43 000 0000000', 'min' => 7, 'max' => 10],
			'CH' => ['name' => __('Швейцарія', 'leadforms-go'), 'dial' => '41', 'mask' => '+41 00 000 00 00', 'min' => 9, 'max' => 9],
			'GB' => ['name' => __('Велика Британія', 'leadforms-go'), 'dial' => '44', 'mask' => '+44 0000 000000', 'min' => 9, 'max' => 10],
			'IE' => ['name' => __('Ірландія', 'leadforms-go'), 'dial' => '353', 'mask' => '+353 00 000 0000', 'min' => 9, 'max' => 9],
			'US' => ['name' => __('США', 'leadforms-go'), 'dial' => '1', 'mask' => '+1 (000) 000-0000', 'min' => 10, 'max' => 10],
			'CA' => ['name' => __('Канада', 'leadforms-go'), 'dial' => '1', 'mask' => '+1 (000) 000-0000', 'min' => 10, 'max' => 10],
			'IL' => ['name' => __('Ізраїль', 'leadforms-go'), 'dial' => '972', 'mask' => '+972 00 000 0000', 'min' => 8, 'max' => 9],
			'TR' => ['name' => __('Туреччина', 'leadforms-go'), 'dial' => '90', 'mask' => '+90 000 000 0000', 'min' => 10, 'max' => 10],
			'GE' => ['name' => __('Грузія', 'leadforms-go'), 'dial' => '995', 'mask' => '+995 000 000 000', 'min' => 9, 'max' => 9],
		];
	}

	/** @return array{enabled:bool, default:string, display:string, allowed:array<int, string>, countries:array<string, array{name:string, dial:string, mask:string, min:int, max:int}>} */
	public static function phone_configuration(): array
	{
		$general = self::section('general');
		$countries = self::phone_countries();
		$allowed = array_values(array_intersect(array_keys($countries), (array) ($general['phone_countries'] ?? [])));
		if ($allowed === []) $allowed = ['UA'];
		$default = strtoupper((string) ($general['phone_default_country'] ?? 'UA'));
		if (! in_array($default, $allowed, true)) $default = $allowed[0];
		$display = (string) ($general['phone_country_display'] ?? 'code');
		if (! in_array($display, ['name_code', 'code', 'flag_code', 'flag'], true)) $display = 'code';
		return ['enabled' => ! empty($general['phone_country_selector_configured']) && ! empty($general['phone_country_selector']), 'default' => $default, 'display' => $display, 'allowed' => $allowed, 'countries' => array_intersect_key($countries, array_flip($allowed))];
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
				'phone_country_selector' => ! empty($input['general']['phone_country_selector']),
				'phone_country_selector_configured' => ! empty($input['general']['phone_country_selector_configured']),
				'phone_default_country' => self::sanitize_phone_default($input['general'] ?? []),
				'phone_country_display' => in_array(($input['general']['phone_country_display'] ?? ''), ['name_code', 'code', 'flag_code', 'flag'], true) ? (string) $input['general']['phone_country_display'] : 'code',
				'phone_countries' => self::sanitize_phone_countries($input['general']['phone_countries'] ?? []),
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
			'general' => ['retain_data' => true, 'retention_days' => 180, 'attribution_days' => 30, 'phone_country_selector' => false, 'phone_country_selector_configured' => false, 'phone_default_country' => 'UA', 'phone_country_display' => 'code', 'phone_countries' => ['UA', 'PL', 'DE', 'CZ', 'SK', 'GB', 'US', 'CA']],
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

	private static function sanitize_phone_countries(mixed $value): array
	{
		$value = is_array($value) ? array_map(static fn (mixed $country): string => strtoupper(sanitize_key((string) $country)), $value) : [];
		$value = array_values(array_unique(array_intersect(array_keys(self::phone_countries()), $value)));
		return $value !== [] ? $value : ['UA'];
	}

	private static function sanitize_phone_default(mixed $general): string
	{
		$general = is_array($general) ? $general : [];
		$allowed = self::sanitize_phone_countries($general['phone_countries'] ?? []);
		$default = strtoupper(sanitize_key((string) ($general['phone_default_country'] ?? 'UA')));
		return in_array($default, $allowed, true) ? $default : $allowed[0];
	}
}
