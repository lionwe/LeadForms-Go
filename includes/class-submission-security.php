<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Submission_Security
{
	private const MIN_FILL_SECONDS = 1;
	private const MAX_CONTEXT_AGE = 2 * DAY_IN_SECONDS;
	private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/** @return array{nonce:string, token:string} */
	public static function context(int $form_id): array
	{
		$timestamp = time();
		return [
			'nonce' => wp_create_nonce(self::nonce_action($form_id)),
			'token' => $timestamp . '.' . self::signature($form_id, $timestamp),
		];
	}

	public static function verify_context(int $form_id, string $nonce, string $token): bool
	{
		if (! wp_verify_nonce($nonce, self::nonce_action($form_id))) return false;
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2 || ! ctype_digit($parts[0])) return false;
		$timestamp = (int) $parts[0];
		$age = time() - $timestamp;
		if ($age < self::MIN_FILL_SECONDS || $age > self::MAX_CONTEXT_AGE) return false;
		return hash_equals(self::signature($form_id, $timestamp), $parts[1]);
	}

	public static function valid_request_id(string $request_id): bool
	{
		return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $request_id) === 1;
	}

	public static function valid_origin(): bool
	{
		$origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_ORIGIN'])) : '';
		if ($origin === '') return true;
		$origin_authority = self::origin_authority($origin);
		$site_authority = self::origin_authority(home_url('/'));
		return $origin_authority !== '' && $site_authority !== '' && hash_equals($site_authority, $origin_authority);
	}

	public static function is_honeypot_filled(array $submitted): bool
	{
		$value = $submitted['_lfg_website'] ?? '';
		return is_scalar($value) && trim((string) $value) !== '';
	}

	public static function consume_rate_limit(int $form_id): bool
	{
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
		$ip_limit = min(100, max(1, (int) apply_filters('leadforms_go_rate_limit_per_ip', 10, $form_id)));
		$global_limit = min(5000, max($ip_limit, (int) apply_filters('leadforms_go_rate_limit_global', 200, $form_id)));
		$ip_key = hash_hmac('sha256', 'form:' . $form_id . ':ip:' . $ip, wp_salt('nonce'));
		$global_key = hash_hmac('sha256', 'form:' . $form_id . ':global', wp_salt('nonce'));
		return Repositories::consume_rate_limit($ip_key, $ip_limit, self::RATE_WINDOW)
			&& Repositories::consume_rate_limit($global_key, $global_limit, self::RATE_WINDOW);
	}

	public static function referer(): string
	{
		$referer = wp_get_referer();
		if (! is_string($referer) || $referer === '') return '';
		$referer_host = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
		$site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		return $referer_host !== '' && hash_equals($site_host, $referer_host) ? esc_url_raw($referer) : '';
	}

	private static function nonce_action(int $form_id): string
	{
		return 'leadforms_go_submit_' . $form_id;
	}

	private static function signature(int $form_id, int $timestamp): string
	{
		return hash_hmac('sha256', $form_id . '|' . $timestamp, wp_salt('auth'));
	}

	private static function origin_authority(string $url): string
	{
		$parts = wp_parse_url($url);
		if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
		$scheme = strtolower((string) $parts['scheme']);
		$host = strtolower((string) $parts['host']);
		if (! in_array($scheme, ['http', 'https'], true)) return '';
		$port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
		return $scheme . '://' . $host . ':' . $port;
	}
}
