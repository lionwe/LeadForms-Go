<?php

declare(strict_types=1);

namespace {
	$GLOBALS['test_options'] = [];
	$GLOBALS['test_scheduled'] = [];
	$GLOBALS['test_home_url'] = 'https://new.example/site/';
	function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['test_options'][$key] ?? $default; }
	function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['test_options'][$key] = $value; return true; }
	function delete_option(string $key): bool { unset($GLOBALS['test_options'][$key]); return true; }
	function home_url(string $path = '/'): string { return rtrim($GLOBALS['test_home_url'], '/') . $path; }
	function esc_url_raw(string $url, array $protocols = []): string { return filter_var($url, FILTER_SANITIZE_URL); }
	function untrailingslashit(string $value): string { return rtrim($value, '/'); }
	function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
	function wp_next_scheduled(string $hook): int|false { return $GLOBALS['test_scheduled'][$hook] ?? false; }
	function wp_schedule_single_event(int $timestamp, string $hook): bool { $GLOBALS['test_scheduled'][$hook] = $timestamp; return true; }
	function __(string $value): string { return $value; }
}

namespace LeadFormsGo {
	final class Settings
	{
		public static bool $disabled = false;
		public static function disable_integrations(): void { self::$disabled = true; }
	}

	final class Repositories
	{
		public static int $cancelled = 0;
		public static function cancel_active_deliveries(string $message): int { self::$cancelled = 4; return self::$cancelled; }
	}

	final class Telegram_Interactions
	{
		public const SITE_TRANSFER_ALERT_HOOK = 'leadforms_go_site_transfer_alert';
		public static int $send_attempts = 0;
		public static function send_site_transfer_alert(): void { ++self::$send_attempts; }
	}
}

namespace {
	require dirname(__DIR__) . '/includes/class-database.php';
	$GLOBALS['test_options']['leadforms_go_site_origin'] = 'https://old.example/site/';
	$protect_transfer = new \ReflectionMethod(\LeadFormsGo\Database::class, 'protect_site_transfer');
	$protect_transfer->invoke(null);
	$notice = \LeadFormsGo\Database::site_transfer_notice();
	$assertions = [
		\LeadFormsGo\Settings::$disabled,
		$notice['previous'] === 'https://old.example/site/',
		$notice['current'] === 'https://new.example/site',
		$notice['cancelled'] === 4,
		$notice['telegram_alert_status'] === 'pending',
		isset($GLOBALS['test_scheduled'][\LeadFormsGo\Telegram_Interactions::SITE_TRANSFER_ALERT_HOOK]),
		\LeadFormsGo\Telegram_Interactions::$send_attempts === 1,
	];
	if (in_array(false, $assertions, true)) {
		fwrite(STDERR, "Site transfer must disable integrations, cancel active deliveries, store a notice, and schedule the Telegram alert.\n");
		exit(1);
	}
	$GLOBALS['test_options'] = ['leadforms_go_site_origin' => 'https://new.example/site'];
	$GLOBALS['test_scheduled'] = [];
	$GLOBALS['test_home_url'] = 'http://new.example/site/';
	\LeadFormsGo\Settings::$disabled = false;
	\LeadFormsGo\Repositories::$cancelled = 0;
	$protect_transfer->invoke(null);
	if (\LeadFormsGo\Settings::$disabled || \LeadFormsGo\Repositories::$cancelled !== 0 || $GLOBALS['test_options']['leadforms_go_site_origin'] !== 'http://new.example/site') {
		fwrite(STDERR, "A protocol-only change on the same site must not disable integrations.\n");
		exit(1);
	}
	fwrite(STDOUT, "Site transfer tests passed.\n");
}
