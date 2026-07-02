<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) exit;

$settings = get_option('leadforms_go_settings', []);
if (! empty($settings['general']['retain_data'])) return;

global $wpdb;
foreach (['leadforms_go_delivery_attempts', 'leadforms_go_deliveries', 'leadforms_go_submissions', 'leadforms_go_forms'] as $suffix) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
delete_option('leadforms_go_settings');
delete_option('leadforms_go_schema_version');
delete_option('leadforms_go_legacy_migrated');
delete_option('leadforms_go_legacy_history_cursor');
delete_option('leadforms_go_queue_lock');
delete_option('leadforms_go_queue_last_run');
delete_option('leadforms_go_queue_pending');
