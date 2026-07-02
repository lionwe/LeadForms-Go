<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Connectors
{
	/** @return array<string, Connector_Interface> */
	public static function all(): array
	{
		$connectors = [new Telegram_Connector(), new Sheets_Connector(), new Crm_Connector()];
		$indexed = [];
		foreach ($connectors as $connector) {
			$indexed[$connector->key()] = $connector;
		}
		$filtered = apply_filters('leadforms_go_connectors', $indexed);
		if (! is_array($filtered)) return $indexed;
		$valid = [];
		foreach ($filtered as $connector) {
			if (! $connector instanceof Connector_Interface) continue;
			$key = sanitize_key($connector->key());
			if ($key !== '') $valid[$key] = $connector;
		}
		return $valid;
	}
}

abstract class Abstract_Connector implements Connector_Interface
{
	protected const REQUEST_TIMEOUT = 12;

	protected function settings(): array { return Settings::section($this->key()); }
	public function is_enabled(): bool { return ! empty($this->settings()['enabled']); }
	protected function result(mixed $response, string $external_reference = ''): Result
	{
		if (is_wp_error($response)) {
			return new Result(false, 0, __('Не вдалося з’єднатися з віддаленим сервісом.', 'leadforms-go'), true);
		}
		$code = wp_remote_retrieve_response_code($response);
		$success = $code >= 200 && $code < 300;
		$retryable = ! $success && ($code === 408 || $code === 425 || $code === 429 || $code >= 500);
		return new Result($success, $code, $success ? '' : __('Віддалений сервіс відхилив запит.', 'leadforms-go'), $retryable, $success ? $external_reference : '');
	}
}

final class Telegram_Connector extends Abstract_Connector
{
	public function key(): string { return 'telegram'; }
	public function validate_settings(): true|\WP_Error
	{
		$s = $this->settings();
		return ! empty($s['token']) && ! empty($s['chat_id']) ? true : new \WP_Error('missing_settings', __('Потрібні токен Telegram-бота та ID чату.', 'leadforms-go'));
	}
	public function test_connection(): Result
	{
		$valid = $this->validate_settings();
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$s = $this->settings();
		return $this->result(wp_remote_get('https://api.telegram.org/bot' . rawurlencode($s['token']) . '/getChat?chat_id=' . rawurlencode($s['chat_id']), ['timeout' => self::REQUEST_TIMEOUT]));
	}
	public function send(array $data, string $referer): Result
	{
		$valid = $this->validate_settings();
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$s = $this->settings();
		$lines = [__('Нова заявка з форми:', 'leadforms-go')];
		foreach ($data as $key => $value) {
			$lines[] = sanitize_text_field((string) $key) . ': ' . sanitize_textarea_field((string) $value);
		}
		if ($referer !== '') $lines[] = __('Джерело:', 'leadforms-go') . ' ' . esc_url_raw($referer);
		$text = implode("\n", $lines);
		if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > 4000) {
			$text = (function_exists('mb_substr') ? mb_substr($text, 0, 3997, 'UTF-8') : substr($text, 0, 3997)) . '…';
		}
		$response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($s['token']) . '/sendMessage', ['timeout' => self::REQUEST_TIMEOUT, 'body' => ['chat_id' => $s['chat_id'], 'text' => $text]]);
		$body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
		$message_id = is_array($body) ? absint($body['result']['message_id'] ?? 0) : 0;
		$chat_id = is_array($body) ? (string) ($body['result']['chat']['id'] ?? '') : '';
		$username = is_array($body) ? sanitize_key((string) ($body['result']['chat']['username'] ?? '')) : '';
		$reference = $message_id > 0 ? 'message:' . $message_id : '';
		if ($message_id > 0 && $username !== '') $reference = 'https://t.me/' . rawurlencode($username) . '/' . $message_id;
		elseif ($message_id > 0 && str_starts_with($chat_id, '-100')) $reference = 'https://t.me/c/' . rawurlencode(substr($chat_id, 4)) . '/' . $message_id;
		return $this->result($response, $reference);
	}
}

final class Sheets_Connector extends Abstract_Connector
{
	public function key(): string { return 'sheets'; }
	public function validate_settings(): true|\WP_Error
	{
		$s = $this->settings();
		if (empty($s['spreadsheet_id']) || empty($s['sheet_name'])) return new \WP_Error('missing_settings', __('Потрібні ID таблиці та назва аркуша.', 'leadforms-go'));
		if (! defined('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH')) return new \WP_Error('missing_credentials', __('Шлях до облікових даних Google не налаштований.', 'leadforms-go'));
		if (! function_exists('openssl_sign')) return new \WP_Error('missing_openssl', __('Розширення OpenSSL недоступне на сервері.', 'leadforms-go'));
		$path = realpath((string) LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH);
		$webroot = realpath(ABSPATH);
		if ($path === false || ! is_readable($path) || ($webroot !== false && str_starts_with(wp_normalize_path($path), trailingslashit(wp_normalize_path($webroot))))) return new \WP_Error('unsafe_credentials', __('Файл облікових даних має бути доступним для читання та розміщеним поза публічним каталогом WordPress.', 'leadforms-go'));
		return true;
	}
	public function test_connection(): Result
	{
		$token = $this->token();
		if (is_wp_error($token)) return new Result(false, 0, $token->get_error_message(), $this->token_error_is_retryable($token));
		$s = $this->settings();
		$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($s['spreadsheet_id']) . '?fields=spreadsheetId';
		return $this->result(wp_remote_get($url, ['timeout' => self::REQUEST_TIMEOUT, 'headers' => ['Authorization' => 'Bearer ' . $token]]));
	}
	public function send(array $data, string $referer): Result
	{
		$token = $this->token();
		if (is_wp_error($token)) return new Result(false, 0, $token->get_error_message(), $this->token_error_is_retryable($token));
		$s = $this->settings();
		$order = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) $s['fields_order']) ?: [])));
		$values = [];
		foreach ($order as $field) $values[] = $data[$field] ?? '';
		foreach ($data as $key => $value) if (! in_array($key, $order, true)) $values[] = $value;
		$values[] = $referer;
		$range = rawurlencode($s['sheet_name'] . '!A1');
		$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($s['spreadsheet_id']) . '/values/' . $range . ':append?valueInputOption=USER_ENTERED';
		$response = wp_remote_post($url, ['timeout' => self::REQUEST_TIMEOUT, 'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], 'body' => wp_json_encode(['values' => [$values]])]);
		$reference = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet_id']) . '/edit';
		return $this->result($response, $reference);
	}
	private function token(): string|\WP_Error
	{
		$valid = $this->validate_settings();
		if (is_wp_error($valid)) return $valid;
		$path = realpath((string) LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH);
		$size = $path !== false ? @filesize($path) : false;
		if ($path === false || ! is_readable($path) || ! is_int($size) || $size > 1024 * 1024) {
			return new \WP_Error('invalid_credentials', __('Некоректний файл облікових даних Google.', 'leadforms-go'));
		}
		$cache_key = 'leadforms_go_google_' . substr(hash('sha256', $path . ':' . (string) @filemtime($path)), 0, 20);
		$cached = get_transient($cache_key);
		if (is_string($cached) && $cached !== '') return $cached;
		$contents = @file_get_contents($path);
		$credentials = is_string($contents) ? json_decode($contents, true) : null;
		if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key']) || empty($credentials['token_uri'])) return new \WP_Error('invalid_credentials', __('Некоректний файл облікових даних Google.', 'leadforms-go'));
		$token_uri = esc_url_raw((string) $credentials['token_uri']);
		$token_host = strtolower((string) wp_parse_url($token_uri, PHP_URL_HOST));
		if ($token_uri === '' || ! str_starts_with($token_uri, 'https://') || ! in_array($token_host, ['oauth2.googleapis.com', 'accounts.google.com'], true)) {
			return new \WP_Error('invalid_credentials', __('Некоректний файл облікових даних Google.', 'leadforms-go'));
		}
		$now = time();
		$encode = static fn (array $value): string => rtrim(strtr(base64_encode((string) wp_json_encode($value)), '+/', '-_'), '=');
		$unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets', 'aud' => $token_uri, 'iat' => $now, 'exp' => $now + 3600]);
		if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) return new \WP_Error('signing_failed', __('Не вдалося підписати запит авторизації Google.', 'leadforms-go'));
		$jwt = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
		$response = wp_remote_post($token_uri, ['timeout' => self::REQUEST_TIMEOUT, 'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]]);
		if (is_wp_error($response)) return new \WP_Error('token_transport_failed', __('Не вдалося з’єднатися з Google.', 'leadforms-go'));
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (wp_remote_retrieve_response_code($response) !== 200 || empty($body['access_token'])) return new \WP_Error('token_failed', __('Не вдалося авторизуватися в Google.', 'leadforms-go'));
		set_transient($cache_key, $body['access_token'], max(60, ((int) ($body['expires_in'] ?? 3600)) - 120));
		return (string) $body['access_token'];
	}
	private function token_error_is_retryable(\WP_Error $error): bool
	{
		return ! in_array($error->get_error_code(), ['missing_credentials', 'missing_openssl', 'unsafe_credentials', 'invalid_credentials', 'signing_failed'], true);
	}
}

final class Crm_Connector extends Abstract_Connector
{
	public function key(): string { return 'crm'; }
	public function validate_settings(): true|\WP_Error
	{
		$s = $this->settings();
		return ! empty($s['partner_id']) && ! empty($s['token']) ? true : new \WP_Error('missing_settings', __('Потрібні ID партнера та токен CRM.', 'leadforms-go'));
	}
	public function test_connection(): Result
	{
		$valid = $this->validate_settings();
		return is_wp_error($valid)
			? new Result(false, 0, $valid->get_error_message(), false)
			: new Result(true, 0, __('Налаштування заповнені. G-PLUS не надає безпечного тестового запиту без створення заявки.', 'leadforms-go'));
	}
	public function send(array $data, string $referer): Result
	{
		$valid = $this->validate_settings();
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$s = $this->settings(); $name_parts = []; $phone = ''; $notes = [];
		foreach ($data as $key => $value) {
			if (preg_match('/телефон|номер|phone|tel/iu', (string) $key)) $phone = (string) $value;
			elseif (preg_match('/ім.?я|прізвище|(^|[_\s-])(first|last)?name($|[_\s-])/iu', (string) $key)) $name_parts[] = sanitize_text_field((string) $value);
			else $notes[] = sanitize_text_field((string) $key) . ': ' . sanitize_textarea_field((string) $value);
		}
		if ($referer !== '') $notes[] = __('Джерело:', 'leadforms-go') . ' ' . sanitize_url($referer);
		$body = ['action' => 'partner-custom-form', 'partner_id' => $s['partner_id'], 'token' => $s['token'], 'adv_id' => $s['adv_id'], 'name' => implode(' ', array_filter($name_parts)), 'phone' => $phone, 'note' => implode("\n", $notes)];
		$response = wp_remote_post('https://crm.g-plus.app/api/actions', ['timeout' => self::REQUEST_TIMEOUT, 'sslverify' => true, 'body' => $body]);
		$response_body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
		$external_id = is_array($response_body) ? sanitize_text_field((string) ($response_body['lead_id'] ?? $response_body['id'] ?? $response_body['data']['id'] ?? '')) : '';
		return $this->result($response, $external_id !== '' ? 'lead:' . $external_id : '');
	}
}
