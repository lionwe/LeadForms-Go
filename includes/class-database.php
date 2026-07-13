<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Database
{
	private const SCHEMA_VERSION = '1.6.0';

	public static function tables(): array
	{
		global $wpdb;
		return [
			'forms' => $wpdb->prefix . 'leadforms_go_forms',
			'submissions' => $wpdb->prefix . 'leadforms_go_submissions',
			'deliveries' => $wpdb->prefix . 'leadforms_go_deliveries',
			'attempts' => $wpdb->prefix . 'leadforms_go_delivery_attempts',
			'rate_limits' => $wpdb->prefix . 'leadforms_go_rate_limits',
		];
	}

	public static function activate(): void
	{
		self::install();
		self::migrate_legacy();
	}

	public static function maybe_upgrade(): void
	{
		if (get_option('leadforms_go_schema_version') !== self::SCHEMA_VERSION) {
			self::install();
		}
		if (! get_option('leadforms_go_legacy_migrated')) self::migrate_legacy();
		self::grant_capabilities();
	}

	private static function install(): void
	{
		global $wpdb;
		$tables = self::tables();
		$collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta("CREATE TABLE {$tables['forms']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			code longtext NOT NULL,
			editor_mode varchar(20) NOT NULL DEFAULT 'code',
			form_schema longtext NOT NULL,
			submit_label varchar(120) NOT NULL DEFAULT 'Надіслати',
			button_icon longtext NOT NULL,
			default_locale varchar(20) NOT NULL DEFAULT 'uk_UA',
			translations longtext NOT NULL,
			routing_config longtext NOT NULL,
			routing_version int(10) unsigned NOT NULL DEFAULT 1,
			active tinyint(1) unsigned NOT NULL DEFAULT 1,
			legacy_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id)
		) $collate;");
		dbDelta("CREATE TABLE {$tables['submissions']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned DEFAULT NULL,
			legacy_id bigint(20) unsigned DEFAULT NULL,
			payload longtext NOT NULL,
			referer text NOT NULL,
			locale varchar(20) NOT NULL DEFAULT 'uk_UA',
			request_id varchar(64) DEFAULT NULL,
			is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			KEY form_id (form_id),
			KEY locale (locale),
			UNIQUE KEY request_id (request_id),
			KEY status_created (status,created_at),
			KEY created_at (created_at)
		) $collate;");
		dbDelta("CREATE TABLE {$tables['deliveries']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			connector varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			http_code smallint(5) unsigned DEFAULT NULL,
			error_message varchar(500) NOT NULL DEFAULT '',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			retryable tinyint(1) unsigned NOT NULL DEFAULT 1,
			next_attempt_at datetime DEFAULT NULL,
			last_attempt_at datetime DEFAULT NULL,
			idempotency_key varchar(64) NOT NULL DEFAULT '',
			route_snapshot longtext NOT NULL,
			external_reference varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY submission_connector (submission_id,connector),
			KEY queue (status,next_attempt_at),
			KEY connector_status (connector,status),
			KEY created_at (created_at),
			KEY status_updated (status,updated_at)
		) $collate;");
		dbDelta("CREATE TABLE {$tables['attempts']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			delivery_id bigint(20) unsigned NOT NULL,
			attempt_number smallint(5) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			http_code smallint(5) unsigned DEFAULT NULL,
			error_message varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY delivery_attempt (delivery_id,attempt_number)
		) $collate;");
		dbDelta("CREATE TABLE {$tables['rate_limits']} (
			key_hash char(64) NOT NULL,
			attempts int(10) unsigned NOT NULL DEFAULT 1,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (key_hash),
			KEY expires_at (expires_at)
		) $collate;");
		self::migrate_form_translations();
		self::grant_capabilities();
		update_option('leadforms_go_schema_version', self::SCHEMA_VERSION, false);
		$queued = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['deliveries']} WHERE status = 'queued'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ($queued > 0) update_option('leadforms_go_queue_pending', 1, false);
		else delete_option('leadforms_go_queue_pending');
	}

	private static function grant_capabilities(): void
	{
		$role = get_role('administrator');
		if ($role && ! $role->has_cap('leadforms_go_view_submissions')) $role->add_cap('leadforms_go_view_submissions');
	}

	private static function migrate_form_translations(): void
	{
		global $wpdb;
		$table = self::tables()['forms'];
		if (! self::table_exists($table)) return;
		$rows = $wpdb->get_results("SELECT id, editor_mode, form_schema, submit_label, button_icon, default_locale, translations, routing_config FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ($rows ?: [] as $row) {
			$schema = json_decode((string) $row['form_schema'], true);
			$schema = Form_Builder::sanitize_schema(is_array($schema) ? $schema : []);
			$locale = Form_Translations::normalize_locale((string) $row['default_locale']) ?: Form_Translations::DEFAULT_LOCALE;
			$translations = json_decode((string) $row['translations'], true);
			$translations = Form_Translations::sanitize(is_array($translations) ? $translations : []);
			if ($schema !== []) $translations = Form_Translations::complete($translations, $schema);
			$data = [
				'default_locale' => $locale,
				'translations' => (string) wp_json_encode($translations, JSON_UNESCAPED_UNICODE),
				'routing_config' => (string) wp_json_encode(Route_Config::sanitize(json_decode((string) ($row['routing_config'] ?? ''), true), $schema), JSON_UNESCAPED_UNICODE),
				'routing_version' => Route_Config::VERSION,
			];
			$formats = ['%s', '%s', '%s', '%d'];
			if (($row['editor_mode'] ?? '') === 'visual' && $schema !== []) {
				$resolved = Form_Translations::resolve($translations, $locale, $locale);
				$button_icon = json_decode((string) ($row['button_icon'] ?? ''), true);
				$data['form_schema'] = (string) wp_json_encode($schema, JSON_UNESCAPED_UNICODE);
				$data['code'] = Form_Builder::render(Form_Translations::apply_to_schema($schema, $resolved), (string) $resolved['submit_label'], '', Form_Builder::sanitize_button_icon(is_array($button_icon) ? $button_icon : []));
				$formats[] = '%s';
				$formats[] = '%s';
			}
			$wpdb->update($table, $data, ['id' => (int) $row['id']], $formats, ['%d']);
		}
	}

	private static function migrate_legacy(): void
	{
		if (get_option('leadforms_go_legacy_migrated')) {
			return;
		}
		global $wpdb;
		$tables = self::tables();
		$legacy_forms = $wpdb->prefix . 'reintegration_forms';
		$legacy_history = $wpdb->prefix . 'reintegration_history';
		$legacy_integrations = $wpdb->prefix . 'reintegration_integrations';

		if (self::table_exists($legacy_forms)) {
			$rows = $wpdb->get_results("SELECT id, name, code FROM {$legacy_forms}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ($rows as $row) {
				$wpdb->query($wpdb->prepare(
					"INSERT IGNORE INTO {$tables['forms']} (name, code, editor_mode, form_schema, submit_label, button_icon, default_locale, translations, routing_config, routing_version, legacy_id, created_at, updated_at) VALUES (%s, %s, 'code', '[]', %s, '{}', 'uk_UA', '{}', %s, %d, %d, %s, %s)",
					(string) $row['name'], Form_Builder::sanitize_code((string) $row['code']), __('Надіслати', 'leadforms-go'), (string) wp_json_encode(Route_Config::defaults()), Route_Config::VERSION, (int) $row['id'], current_time('mysql'), current_time('mysql')
				));
			}
		}

		if (self::table_exists($legacy_history)) {
			$cursor = (int) get_option('leadforms_go_legacy_history_cursor', 0);
			$batch_size = 500;
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id, data, status, created_at FROM {$legacy_history} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$cursor,
				$batch_size
			), ARRAY_A) ?: [];
			foreach ($rows as $row) {
				$cursor = max($cursor, (int) $row['id']);
				$payload = json_decode((string) $row['data'], true);
				if (! is_array($payload)) {
					continue;
				}
				$referer = sanitize_url((string) ($payload['referer'] ?? ''));
				unset($payload['referer']);
				$encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
				if (! is_string($encoded)) continue;
				$wpdb->query($wpdb->prepare(
					"INSERT IGNORE INTO {$tables['submissions']} (form_id, legacy_id, payload, referer, status, created_at) VALUES (NULL, %d, %s, %s, %s, %s)",
					(int) $row['id'],
					$encoded,
					$referer,
					in_array($row['status'], ['success', 'failed'], true) ? $row['status'] : 'success',
					$row['created_at'] ?: current_time('mysql')
				));
			}
			if ($rows !== []) update_option('leadforms_go_legacy_history_cursor', $cursor, false);
			if (count($rows) >= $batch_size) return;
			delete_option('leadforms_go_legacy_history_cursor');
		}

		if (self::table_exists($legacy_integrations)) {
			$rows = $wpdb->get_results("SELECT name, settings FROM {$legacy_integrations}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ($rows as $row) {
				$settings = json_decode((string) $row['settings'], true);
				if (is_array($settings) && in_array($row['name'], ['telegram', 'sheets', 'crm'], true)) {
					Settings::import_legacy((string) $row['name'], $settings);
				}
			}
		}
		update_option('leadforms_go_legacy_migrated', time(), false);
	}

	private static function table_exists(string $table): bool
	{
		global $wpdb;
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
	}
}
