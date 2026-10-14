<?php

declare(strict_types=1);

namespace {
	define('MINUTE_IN_SECONDS', 60);
	define('HOUR_IN_SECONDS', 3600);
	class WP_Error
	{
		public function __construct(public string $message = 'request failed') {}
	}
	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function absint(mixed $value): int { return abs((int) $value); }
	function __(string $value): string { return $value; }
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	function admin_url(string $path): string { return 'https://example.com/wp-admin/' . $path; }
	function wp_json_encode(mixed $value): string|false { return json_encode($value); }
	function update_option(string $key, mixed $value, mixed $autoload = null): bool { $GLOBALS['alert_option'] = $value; return true; }
	function wp_next_scheduled(string $hook): int|false { return $GLOBALS['alert_scheduled'] ?? false; }
	function wp_schedule_single_event(int $timestamp, string $hook): bool { $GLOBALS['alert_scheduled'] = $timestamp; return true; }
	function wp_remote_post(string $url, array $args): mixed
	{
		$GLOBALS['alert_requests'][] = ['url' => $url, 'args' => $args];
		return array_shift($GLOBALS['alert_responses']);
	}
	function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
	function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['code'] ?? 0); }
}

namespace LeadFormsGo {
	final class Database
	{
		public static function site_transfer_notice(): array { return $GLOBALS['alert_option']; }
	}

	final class Settings
	{
		public static function section(string $section): array { return ['enabled' => false, 'token' => 'configured-token', 'chat_id' => '-100123456']; }
	}

	final class Connection_Profiles
	{
		public static function all(string $connector): array { return []; }
	}
}

namespace {
	require dirname(__DIR__) . '/includes/class-telegram-interactions.php';
	use LeadFormsGo\Telegram_Interactions;

	$success = ['code' => 200, 'body' => '{"ok":true}'];
	$GLOBALS['alert_option'] = [
		'previous' => 'https://old.example', 'current' => 'https://new.example',
		'cancelled' => 3, 'telegram_alert_status' => 'pending', 'telegram_alert_attempts' => 0,
	];
	$GLOBALS['alert_responses'] = [$success];
	$GLOBALS['alert_requests'] = [];
	$GLOBALS['alert_scheduled'] = false;
	Telegram_Interactions::send_site_transfer_alert();
	$sent = $GLOBALS['alert_option']['telegram_alert_status'] === 'sent'
		&& $GLOBALS['alert_option']['telegram_alert_attempts'] === 1
		&& ($GLOBALS['alert_requests'][0]['args']['body']['chat_id'] ?? '') === '-100123456'
		&& str_contains((string) ($GLOBALS['alert_requests'][0]['args']['body']['text'] ?? ''), 'інтеграції вимкнено')
		&& str_contains((string) ($GLOBALS['alert_requests'][0]['args']['body']['text'] ?? ''), 'https://example.com/wp-admin/admin.php?page=leadforms-go-settings')
		&& str_contains((string) ($GLOBALS['alert_requests'][0]['args']['body']['text'] ?? ''), 'ввімкніть їх знову');
	if (! $sent) {
		fwrite(STDERR, "A disabled integration must still use stored Telegram credentials to send the site-transfer alert.\n");
		exit(1);
	}

	$GLOBALS['alert_option']['telegram_alert_status'] = 'pending';
	$GLOBALS['alert_option']['telegram_alert_attempts'] = 0;
	$GLOBALS['alert_scheduled'] = false;
	$GLOBALS['alert_responses'] = [new WP_Error()];
	Telegram_Interactions::send_site_transfer_alert();
	if ($GLOBALS['alert_option']['telegram_alert_status'] !== 'pending' || $GLOBALS['alert_option']['telegram_alert_attempts'] !== 1 || $GLOBALS['alert_scheduled'] === false) {
		fwrite(STDERR, "A failed alert must be recorded and scheduled for retry.\n");
		exit(1);
	}

	$GLOBALS['alert_option']['telegram_alert_status'] = 'pending';
	$GLOBALS['alert_option']['telegram_alert_attempts'] = 2;
	$GLOBALS['alert_scheduled'] = false;
	$GLOBALS['alert_responses'] = [new WP_Error()];
	Telegram_Interactions::send_site_transfer_alert();
	if ($GLOBALS['alert_option']['telegram_alert_status'] !== 'failed' || $GLOBALS['alert_option']['telegram_alert_attempts'] !== 3) {
		fwrite(STDERR, "The alert retry policy must stop and report failure after three attempts.\n");
		exit(1);
	}

	fwrite(STDOUT, "Telegram alert tests passed.\n");
}
