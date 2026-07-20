<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Google_Api_Client
{
	private const REQUEST_TIMEOUT = 12;
	private const RESPONSE_SIZE_LIMIT = 262144;
	private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

	public function request(string $method, string $url, ?array $body = null): array|\WP_Error
	{
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || $host !== 'sheets.googleapis.com') return new \WP_Error('unsafe_google_url', __('Некоректна адреса Google API.', 'leadforms-go'));
		$token = $this->token();
		if (is_wp_error($token)) return $token;
		$args = [
			'method' => strtoupper($method),
			'timeout' => self::REQUEST_TIMEOUT,
			'redirection' => 0,
			'limit_response_size' => self::RESPONSE_SIZE_LIMIT,
			'sslverify' => true,
			'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
		];
		if ($body !== null) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($body);
		}
		$response = wp_remote_request($url, $args);
		if (is_wp_error($response)) return new \WP_Error('google_transport', __('Не вдалося з’єднатися з Google.', 'leadforms-go'));
		$code = wp_remote_retrieve_response_code($response);
		$decoded = json_decode(wp_remote_retrieve_body($response), true);
		if ($code < 200 || $code >= 300) return new \WP_Error('google_http_' . $code, __('Google API відхилив запит.', 'leadforms-go'), ['http_code' => $code]);
		return ['http_code' => $code, 'body' => is_array($decoded) ? $decoded : []];
	}

	private function token(): string|\WP_Error
	{
		$credentials = Google_Credentials::credentials();
		if (is_wp_error($credentials)) return $credentials;
		if (! function_exists('openssl_sign')) return new \WP_Error('missing_openssl', __('OpenSSL недоступний на сервері.', 'leadforms-go'));
		$cache_key = 'leadforms_go_google_' . substr(hash('sha256', (string) $credentials['client_email'] . ':' . (string) $credentials['private_key_id'] . ':' . self::SCOPE), 0, 20);
		$cached = get_transient($cache_key);
		if (is_string($cached) && $cached !== '') {
			$decrypted = Settings::decrypt_secret($cached);
			if ($decrypted !== '') return $decrypted;
		}
		$token_uri = esc_url_raw((string) $credentials['token_uri']);
		$token_host = strtolower((string) wp_parse_url($token_uri, PHP_URL_HOST));
		if (wp_parse_url($token_uri, PHP_URL_SCHEME) !== 'https' || ! in_array($token_host, ['oauth2.googleapis.com', 'accounts.google.com'], true)) return new \WP_Error('invalid_token_uri', __('Некоректна адреса авторизації Google.', 'leadforms-go'));
		$now = time();
		$encode = static fn (array $value): string => rtrim(strtr(base64_encode((string) wp_json_encode($value)), '+/', '-_'), '=');
		$unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode(['iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => $token_uri, 'iat' => $now, 'exp' => $now + HOUR_IN_SECONDS]);
		if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) return new \WP_Error('signing_failed', __('Не вдалося підписати запит Google.', 'leadforms-go'));
		$jwt = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
		$response = wp_remote_post($token_uri, [
			'timeout' => self::REQUEST_TIMEOUT,
			'redirection' => 0,
			'limit_response_size' => self::RESPONSE_SIZE_LIMIT,
			'sslverify' => true,
			'body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
		]);
		if (is_wp_error($response)) return new \WP_Error('token_transport_failed', __('Не вдалося з’єднатися з Google.', 'leadforms-go'));
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (wp_remote_retrieve_response_code($response) !== 200 || ! is_array($body) || empty($body['access_token'])) return new \WP_Error('token_failed', __('Не вдалося авторизуватися в Google.', 'leadforms-go'));
		$encrypted = Settings::encrypt_secret((string) $body['access_token']);
		if ($encrypted !== '') set_transient($cache_key, $encrypted, max(60, ((int) ($body['expires_in'] ?? 3600)) - 120));
		return (string) $body['access_token'];
	}
}
