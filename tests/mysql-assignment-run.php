<?php

declare(strict_types=1);

/**
 * Optional database-backed transfer test. It only connects to loopback MySQL
 * and creates a session-scoped temporary table; it never boots WordPress.
 */

namespace {
	$database_host = (string) getenv('LFG_TEST_DB_HOST');
	$database_name = (string) getenv('LFG_TEST_DB_NAME');
	$database_user = (string) getenv('LFG_TEST_DB_USER');
	$database_password = (string) getenv('LFG_TEST_DB_PASSWORD');
	$wordpress_root = rtrim((string) getenv('LFG_TEST_WP_ROOT'), '/\\');
	if ($database_host === '' || $database_name === '' || $database_user === '' || $wordpress_root === '') {
		fwrite(STDOUT, "MySQL assignment integration skipped; set LFG_TEST_DB_* and LFG_TEST_WP_ROOT.\n");
		exit(0);
	}
	if (! str_starts_with($database_host, '127.0.0.1:') || ! is_readable($wordpress_root . '/wp-includes/class-wpdb.php')) {
		fwrite(STDERR, "Refusing MySQL test unless the database host is loopback and the WordPress root is valid.\n");
		exit(1);
	}
	define('WP_DEBUG', false);
	define('WP_DEBUG_DISPLAY', false);
	$GLOBALS['lfg_mysql_test_filters'] = [];
	function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
	{
		foreach ($GLOBALS['lfg_mysql_test_filters'][$hook] ?? [] as $callbacks) {
			foreach ($callbacks as $callback) $value = $callback($value, ...$args);
		}
		return $value;
	}
	function has_filter(string $hook, callable $callback): int|false
	{
		foreach ($GLOBALS['lfg_mysql_test_filters'][$hook] ?? [] as $priority => $callbacks) {
			if (in_array($callback, $callbacks, true)) return (int) $priority;
		}
		return false;
	}
	function add_filter(string $hook, callable $callback, int $priority = 10): bool
	{
		$GLOBALS['lfg_mysql_test_filters'][$hook][$priority][] = $callback;
		return true;
	}
	function do_action(string $hook, mixed ...$args): void {}
	function is_multisite(): bool { return false; }
	function absint(mixed $value): int { return abs((int) $value); }
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function current_time(string $type): string { return $type === 'mysql' ? gmdate('Y-m-d H:i:s') : ''; }
	require $wordpress_root . '/wp-includes/class-wpdb.php';
}

namespace LeadFormsGo {
	final class Form_Translations
	{
		public const DEFAULT_LOCALE = 'uk_UA';
	}
}

namespace {
	require dirname(__DIR__) . '/includes/class-database.php';
	require dirname(__DIR__) . '/includes/class-repositories.php';
	$db = new \wpdb($database_user, $database_password, $database_name, $database_host);
	if (! $db->ready) {
		fwrite(STDERR, "Could not connect to the loopback MySQL test database.\n");
		exit(1);
	}
	$db->set_prefix('lfgtest_' . bin2hex(random_bytes(6)) . '_');
	$GLOBALS['wpdb'] = $db;
	$table = \LeadFormsGo\Database::tables()['submissions'];
	$created = $db->query("CREATE TEMPORARY TABLE `{$table}` (
		id bigint(20) unsigned NOT NULL,
		lead_state varchar(20) NOT NULL DEFAULT 'new',
		status_changed_by varchar(255) NOT NULL DEFAULT '',
		status_changed_by_telegram_id varchar(32) NOT NULL DEFAULT '',
		status_changed_at datetime DEFAULT NULL,
		assigned_to varchar(255) NOT NULL DEFAULT '',
		assigned_to_telegram_id varchar(32) NOT NULL DEFAULT '',
		assigned_by varchar(255) NOT NULL DEFAULT '',
		assigned_by_telegram_id varchar(32) NOT NULL DEFAULT '',
		transfer_requested_by varchar(255) NOT NULL DEFAULT '',
		transfer_requested_by_telegram_id varchar(32) NOT NULL DEFAULT '',
		transfer_requested_at datetime DEFAULT NULL,
		acknowledged_at datetime DEFAULT NULL,
		PRIMARY KEY (id)
	)");
	if ($created === false) {
		fwrite(STDERR, "Could not create a session-only temporary submissions table.\n");
		exit(1);
	}
	$db->query("INSERT INTO `{$table}` (id, lead_state) VALUES (1, 'new'), (2, 'acknowledged'), (3, 'acknowledged')");
	$db->query("UPDATE `{$table}` SET assigned_to = 'Marta', assigned_to_telegram_id = '101', assigned_by = 'Marta', assigned_by_telegram_id = '101' WHERE id IN (2, 3)");
	$db->query("UPDATE `{$table}` SET transfer_requested_by = 'Oleh', transfer_requested_by_telegram_id = '202' WHERE id = 3");
	$check = static function (bool $condition, string $message): void {
		if (! $condition) {
			fwrite(STDERR, $message . PHP_EOL);
			exit(1);
		}
	};

	$claimed = \LeadFormsGo\Repositories::claim_lead(1, 'Marta', '101');
	$check($claimed['status'] === 'claimed', 'A new lead must be claimable in MySQL.');
	$requested = \LeadFormsGo\Repositories::claim_lead(1, 'Oleh', '202');
	$check($requested['status'] === 'transfer_requested', 'Another manager must create a transfer request in MySQL.');
	$pending = \LeadFormsGo\Repositories::claim_lead(1, 'Iryna', '303');
	$check($pending['status'] === 'transfer_pending', 'A third manager must not overwrite a pending transfer in MySQL.');
	$unauthorized = \LeadFormsGo\Repositories::approve_lead_transfer(1, 'Oleh', '202');
	$check($unauthorized['status'] === 'unauthorized', 'A requester must not approve their own transfer in MySQL.');
	$approved = \LeadFormsGo\Repositories::approve_lead_transfer(1, 'Marta', '101');
	$check($approved['status'] === 'approved' && $approved['owner'] === 'Oleh', 'Only the current owner approval must transfer assignment in MySQL.');
	$rejected = \LeadFormsGo\Repositories::reject_lead_transfer(3, 'Marta', '101');
	$check($rejected['status'] === 'rejected', 'The current owner must be able to reject a transfer in MySQL.');
	$former_owner = \LeadFormsGo\Repositories::approve_lead_transfer(1, 'Marta', '101');
	$check($former_owner['status'] === 'unauthorized', 'A former owner must lose approval rights in MySQL.');
	$row = $db->get_row($db->prepare("SELECT assigned_to_telegram_id, transfer_requested_by_telegram_id FROM `{$table}` WHERE id = %d", 1), ARRAY_A);
	$check((string) ($row['assigned_to_telegram_id'] ?? '') === '202' && (string) ($row['transfer_requested_by_telegram_id'] ?? '') === '', 'MySQL must retain the new owner and clear the request after approval.');
	$row = $db->get_row($db->prepare("SELECT assigned_to_telegram_id, transfer_requested_by_telegram_id FROM `{$table}` WHERE id = %d", 3), ARRAY_A);
	$check((string) ($row['assigned_to_telegram_id'] ?? '') === '101' && (string) ($row['transfer_requested_by_telegram_id'] ?? '') === '', 'MySQL rejection must preserve the owner and clear the request.');
	fwrite(STDOUT, "MySQL assignment integration passed using a session-only temporary table.\n");
}
