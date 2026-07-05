<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Google_Credentials
{
	private const OPTION = 'leadforms_go_google_credentials';
	private const MAX_FILE_SIZE = 131072;

	public static function credentials(): array|\WP_Error
	{
		if (defined('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH')) {
			return self::from_constant();
		}

		$encrypted = get_option(self::OPTION, '');
		if (! is_string($encrypted) || $encrypted === '') {
			return new \WP_Error('missing_credentials', __('Завантажте JSON-ключ Google Service Account.', 'leadforms-go'));
		}

		$json = Settings::decrypt_secret($encrypted);
		if ($json === '') {
			return new \WP_Error('invalid_credentials', __('Збережений JSON-ключ не вдалося розшифрувати. Завантажте його повторно.', 'leadforms-go'));
		}

		return self::decode($json);
	}

	public static function store_upload(array $file): true|\WP_Error
	{
		$error = isset($file['error']) && is_scalar($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ($error !== UPLOAD_ERR_OK) {
			return new \WP_Error('upload_failed', self::upload_error($error));
		}

		$size     = isset($file['size']) && is_scalar($file['size']) ? (int) $file['size'] : 0;
		$tmp_name = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
		$name     = isset($file['name']) && is_string($file['name']) ? sanitize_file_name($file['name']) : '';

		if ($size < 1 || $size > self::MAX_FILE_SIZE || $tmp_name === '' || ! is_uploaded_file($tmp_name)) {
			return new \WP_Error('invalid_upload', __('Некоректний файл або його розмір перевищує 128 КБ.', 'leadforms-go'));
		}

		if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
			return new \WP_Error('invalid_extension', __('Виберіть JSON-файл.', 'leadforms-go'));
		}

		$json = @file_get_contents($tmp_name);
		return is_string($json)
			? self::store_json($json)
			: new \WP_Error('read_failed', __('Не вдалося прочитати завантажений файл.', 'leadforms-go'));
	}

	public static function store_json(string $json): true|\WP_Error
	{
		if ($json === '' || strlen($json) > self::MAX_FILE_SIZE) {
			return new \WP_Error('invalid_size', __('Некоректний розмір JSON-файлу.', 'leadforms-go'));
		}

		$credentials = self::decode($json);
		if (is_wp_error($credentials)) {
			return $credentials;
		}

		$normalized = wp_json_encode($credentials, JSON_UNESCAPED_SLASHES);
		if (! is_string($normalized)) {
			return new \WP_Error('encode_failed', __('Не вдалося обробити JSON-ключ.', 'leadforms-go'));
		}

		$encrypted = Settings::encrypt_secret($normalized);
		if ($encrypted === '') {
			return new \WP_Error('encryption_failed', __('Сервер не зміг безпечно зашифрувати JSON-ключ.', 'leadforms-go'));
		}

		$saved = update_option(self::OPTION, $encrypted, false);
		if (! $saved && get_option(self::OPTION, '') !== $encrypted) {
			return new \WP_Error('storage_failed', __('Не вдалося зберегти зашифрований JSON-ключ.', 'leadforms-go'));
		}

		return true;
	}

	public static function delete(): bool
	{
		return delete_option(self::OPTION);
	}

	public static function status(): array
	{
		$credentials = self::credentials();

		return [
			'configured' => ! is_wp_error($credentials),
			'source'     => defined('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH') ? 'constant' : (get_option(self::OPTION, '') !== '' ? 'admin' : 'missing'),
			'email'      => is_wp_error($credentials) ? '' : (string) $credentials['client_email'],
			'message'    => is_wp_error($credentials) ? $credentials->get_error_message() : __('JSON-ключ підключено та зашифровано.', 'leadforms-go'),
		];
	}

	private static function from_constant(): array|\WP_Error
	{
		$path    = realpath((string) LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH);
		$webroot = realpath(ABSPATH);
		$size    = $path !== false ? @filesize($path) : false;

		$inside_webroot = $path !== false && $webroot !== false
			&& str_starts_with(strtolower(wp_normalize_path($path)), strtolower(trailingslashit(wp_normalize_path($webroot))));

		if ($path === false || ! is_readable($path) || $inside_webroot || ! is_int($size) || $size < 1 || $size > self::MAX_FILE_SIZE) {
			return new \WP_Error('unsafe_credentials', __('JSON-ключ із wp-config.php недоступний або розміщений небезпечно.', 'leadforms-go'));
		}

		$json = @file_get_contents($path);
		return is_string($json)
			? self::decode($json)
			: new \WP_Error('read_failed', __('Не вдалося прочитати JSON-ключ.', 'leadforms-go'));
	}

	private static function decode(string $json): array|\WP_Error
	{
		$data = json_decode($json, true);
		if (! is_array($data) || ($data['type'] ?? '') !== 'service_account') {
			return new \WP_Error('invalid_credentials', __('Це не JSON-ключ Google Service Account.', 'leadforms-go'));
		}

		$email       = sanitize_email(is_scalar($data['client_email'] ?? null) ? (string) $data['client_email'] : '');
		$private_key = is_string($data['private_key'] ?? null) ? trim((string) $data['private_key']) : '';
		$token_uri   = esc_url_raw(is_scalar($data['token_uri'] ?? null) ? (string) $data['token_uri'] : '');
		$token_host  = strtolower((string) wp_parse_url($token_uri, PHP_URL_HOST));

		if ($email === '' || ! str_ends_with(strtolower($email), '.iam.gserviceaccount.com')) {
			return new \WP_Error('invalid_email', __('JSON не містить коректний email Service Account.', 'leadforms-go'));
		}

		if (! str_starts_with($private_key, '-----BEGIN PRIVATE KEY-----') || ! str_ends_with($private_key, '-----END PRIVATE KEY-----') || ! function_exists('openssl_pkey_get_private') || @openssl_pkey_get_private($private_key) === false) {
			return new \WP_Error('invalid_private_key', __('JSON містить некоректний приватний ключ.', 'leadforms-go'));
		}

		if ($token_uri === '' || wp_parse_url($token_uri, PHP_URL_SCHEME) !== 'https' || ! in_array($token_host, ['oauth2.googleapis.com', 'accounts.google.com'], true)) {
			return new \WP_Error('invalid_token_uri', __('JSON містить некоректну адресу авторизації Google.', 'leadforms-go'));
		}

		return [
			'type'           => 'service_account',
			'project_id'     => sanitize_text_field(is_scalar($data['project_id'] ?? null) ? (string) $data['project_id'] : ''),
			'private_key_id' => sanitize_text_field(is_scalar($data['private_key_id'] ?? null) ? (string) $data['private_key_id'] : ''),
			'private_key'    => $private_key . "\n",
			'client_email'   => $email,
			'token_uri'      => $token_uri,
		];
	}

	private static function upload_error(int $error): string
	{
		return match ($error) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('JSON-файл завеликий.', 'leadforms-go'),
			UPLOAD_ERR_NO_FILE => __('Спочатку виберіть JSON-файл.', 'leadforms-go'),
			default => __('Не вдалося завантажити JSON-файл.', 'leadforms-go'),
		};
	}
}
