<?php

declare(strict_types=1);

namespace {
	define('ARRAY_A', 'ARRAY_A');
	function __(string $value): string { return $value; }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
	function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	function esc_html__(string $value): string { return esc_html($value); }
	function esc_html_e(string $value): void { echo esc_html($value); }
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone('UTC'); }
}

namespace LeadFormsGo {
	final class Database
	{
		public static function tables(): array { return ['deliveries' => 'wp_deliveries', 'submissions' => 'wp_submissions']; }
	}

	final class Settings
	{
		public static function section(string $connector): array { return ['enabled' => true]; }
	}

	final class Route_Config
	{
		public static function is_enabled(string $connector, array $route): bool { return ($route['state'] ?? 'inherit') !== 'disabled'; }
	}
}

namespace {
	final class Route_Health_Test_WPDB
	{
		public array $prepared = [];
		public array $rows = [
			['connector' => 'telegram', 'successful' => '12', 'failed' => '2', 'queued' => '1', 'processing' => '3', 'cancelled' => '4', 'last_success' => '2026-09-25 11:45:00'],
		];
		public function prepare(string $query, mixed ...$args): string { $this->prepared = [$query, $args]; return $query; }
		public function get_results(string $query, mixed $format): array { return $this->rows; }
	}

	require dirname(__DIR__) . '/includes/class-admin-integrations.php';
	$wpdb = new Route_Health_Test_WPDB();
	$renderer = new \LeadFormsGo\Admin_Integrations();
	$method = new \ReflectionMethod($renderer, 'render_delivery_health');
	ob_start();
	$method->invoke($renderer, 15, [
		'telegram' => ['state' => 'inherit'],
		'sheets' => ['state' => 'disabled'],
		'crm' => ['state' => 'enabled'],
	]);
	$html = (string) ob_get_clean();
	$query = $wpdb->prepared[0] ?? '';
	$args = $wpdb->prepared[1] ?? [];
	$checks = [
		$args === [15],
		str_contains($query, 's.is_test = 0') && str_contains($query, 's.is_suspected_bot = 0'),
		str_contains($query, "d.status = 'processing'") && str_contains($query, "d.status = 'cancelled'"),
		str_contains($html, '12') && str_contains($html, '2026-09-25 11:45'),
		str_contains($html, 'Telegram') && str_contains($html, 'Google Sheets') && str_contains($html, 'CRM G-PLUS'),
		str_contains($html, 'Вимкнено') && str_contains($html, 'Увімкнено для форми'),
	];
	if (in_array(false, $checks, true)) {
		fwrite(STDERR, "Route health must show per-form delivery states and exclude test/bot submissions.\n");
		exit(1);
	}
	fwrite(STDOUT, "Route health tests passed.\n");
}
