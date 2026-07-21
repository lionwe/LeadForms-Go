<?php

/**
 * Plugin Name: LeadForms Go
 * Description: Керування формами та інтеграціями з Telegram, Google Sheets і G-PLUS CRM.
 * Version: 1.7.0
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * Author: lionwe
 * Text Domain: leadforms-go
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('LEADFORMS_GO_VERSION', '1.7.0');
define('LEADFORMS_GO_FILE', __FILE__);
define('LEADFORMS_GO_DIR', plugin_dir_path(__FILE__));
define('LEADFORMS_GO_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
	$prefix = 'LeadFormsGo\\';
	if (! str_starts_with($class, $prefix)) {
		return;
	}
	$file = LEADFORMS_GO_DIR . 'includes/class-' . strtolower(str_replace(['\\', '_'], ['-', '-'], substr($class, strlen($prefix)))) . '.php';
	if (is_readable($file)) {
		require_once $file;
	}
});

register_activation_hook(__FILE__, [LeadFormsGo\Database::class, 'activate']);
register_deactivation_hook(__FILE__, [LeadFormsGo\Delivery_Queue::class, 'deactivate']);
add_action('plugins_loaded', static function (): void {
	load_plugin_textdomain('leadforms-go', false, dirname(plugin_basename(__FILE__)) . '/languages');
	LeadFormsGo\Plugin::instance()->boot();
});

if (! function_exists('leadforms_go_capture_submission')) {
	function leadforms_go_capture_submission(array $data, ?int $form_id = null, string $referer = ''): int
	{
		return LeadFormsGo\Plugin::instance()->capture_submission($data, $form_id, $referer);
	}
}

if (! function_exists('leadforms_go_enqueue_frontend')) {
	function leadforms_go_enqueue_frontend(): void
	{
		LeadFormsGo\Plugin::instance()->enqueue_frontend();
	}
}
