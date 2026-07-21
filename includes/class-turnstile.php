<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Turnstile
{
	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	public const ACTION = 'leadforms_go_submit';

	public static function enabled(): bool
	{
		$settings = Settings::section('antispam');
		return ($settings['provider'] ?? 'none') === 'turnstile'
			&& ! empty($settings['turnstile_site_key'])
			&& ! empty($settings['turnstile_secret_key']);
	}

	public static function markup(): string
	{
		if (! self::enabled()) return '';
		return '<div class="leadforms-go-turnstile cf-turnstile"></div>';
	}

	public static function site_key(): string
	{
		return sanitize_text_field((string) (Settings::section('antispam')['turnstile_site_key'] ?? ''));
	}

	public static function verify(string $token, string $request_id): true|\WP_Error
	{
		if (! self::enabled()) return true;
		if ($token === '' || strlen($token) > 2048) return new \WP_Error('turnstile_missing', __('Підтвердьте, що ви не робот.', 'leadforms-go'));
		$settings = Settings::section('antispam');
		$body = [
			'secret' => (string) ($settings['turnstile_secret_key'] ?? ''),
			'response' => $token,
			'idempotency_key' => wp_is_uuid($request_id) ? $request_id : wp_generate_uuid4(),
		];
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';
		if (filter_var($ip, FILTER_VALIDATE_IP)) $body['remoteip'] = $ip;
		$response = wp_remote_post(self::VERIFY_URL, [
			'timeout' => 10,
			'redirection' => 0,
			'limit_response_size' => 32768,
			'sslverify' => true,
			'body' => $body,
		]);
		if (is_wp_error($response)) return new \WP_Error('turnstile_unavailable', __('Не вдалося перевірити CAPTCHA. Спробуйте ще раз.', 'leadforms-go'));
		$decoded = json_decode(wp_remote_retrieve_body($response), true);
		if (! is_array($decoded) || empty($decoded['success'])) return new \WP_Error('turnstile_failed', __('Перевірка CAPTCHA не пройдена. Оновіть сторінку та спробуйте ще раз.', 'leadforms-go'));
		$hostname = strtolower(sanitize_text_field((string) ($decoded['hostname'] ?? '')));
		$expected_hostname = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		if ($hostname === '' || $expected_hostname === '' || ! hash_equals($expected_hostname, $hostname)) return new \WP_Error('turnstile_hostname', __('CAPTCHA створена для іншого сайту.', 'leadforms-go'));
		if (! hash_equals(self::ACTION, sanitize_key((string) ($decoded['action'] ?? '')))) return new \WP_Error('turnstile_action', __('CAPTCHA не відповідає цій формі.', 'leadforms-go'));
		return true;
	}
}
