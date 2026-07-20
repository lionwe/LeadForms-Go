<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Repositories
{
	public static function forms(): array
	{
		return (new Form_Repository())->all();
	}

	public static function form_summaries(): array
	{
		return (new Form_Repository())->summaries();
	}

	public static function form(int $id, bool $legacy = false): ?array
	{
		return (new Form_Repository())->find($id, $legacy);
	}

	public static function save_form(int $id, string $name, string $code, string $editor_mode = 'code', array $schema = [], string $submit_label = 'Надіслати', string $default_locale = Form_Translations::DEFAULT_LOCALE, array $translations = [], bool $active = true, array $button_icon = [], array $routing_config = []): int|false
	{
		$now = current_time('mysql');
		$data = [
			'name' => $name,
			'code' => $code,
			'editor_mode' => $editor_mode,
			'form_schema' => wp_json_encode($schema, JSON_UNESCAPED_UNICODE),
			'submit_label' => $submit_label,
			'button_icon' => wp_json_encode(Form_Builder::sanitize_button_icon($button_icon), JSON_UNESCAPED_UNICODE),
			'default_locale' => Form_Translations::normalize_locale($default_locale) ?: Form_Translations::DEFAULT_LOCALE,
			'translations' => wp_json_encode(Form_Translations::sanitize($translations), JSON_UNESCAPED_UNICODE),
			'routing_config' => wp_json_encode(Route_Config::sanitize($routing_config, $schema), JSON_UNESCAPED_UNICODE),
			'routing_version' => Route_Config::VERSION,
			'active' => $active ? 1 : 0,
			'updated_at' => $now,
		];
		return (new Form_Repository())->save($id, $data);
	}

	public static function delete_form(int $id): bool
	{
		return (new Form_Repository())->delete($id);
	}

	/** @return array{id:int, created:bool} */
	public static function create_submission(?int $form_id, array $payload, string $referer, string $locale, string $request_id, bool $is_test = false): array
	{
		return (new Submission_Repository())->create($form_id, $payload, $referer, $locale, $request_id, $is_test);
	}

	public static function consume_rate_limit(string $key_hash, int $limit, int $window): bool
	{
		global $wpdb;
		$table = Database::tables()['rate_limits'];
		$now = current_time('mysql');
		$expires = wp_date('Y-m-d H:i:s', time() + $window, wp_timezone());
		$result = $wpdb->query($wpdb->prepare(
			"INSERT INTO {$table} (key_hash, attempts, expires_at) VALUES (%s, 1, %s) ON DUPLICATE KEY UPDATE attempts = IF(expires_at <= %s, 1, attempts + 1), expires_at = IF(expires_at <= %s, VALUES(expires_at), expires_at)",
			$key_hash,
			$expires,
			$now,
			$now
		));
		if ($result === false) return false;
		$attempts = (int) $wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$table} WHERE key_hash = %s", $key_hash));
		if (wp_rand(1, 100) === 1) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", $now));
		return $attempts <= $limit;
	}

	public static function create_delivery(int $submission_id, string $connector, array $route_snapshot = []): int
	{
		return (new Delivery_Repository())->create($submission_id, $connector, $route_snapshot);
	}

	public static function due_deliveries(int $limit = 10): array
	{
		return (new Delivery_Repository())->due($limit);
	}

	public static function claim_delivery(int $delivery_id): bool
	{
		return (new Delivery_Repository())->claim($delivery_id);
	}

	public static function release_stale_deliveries(): void
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$cutoff = wp_date('Y-m-d H:i:s', time() - (10 * MINUTE_IN_SECONDS), wp_timezone());
		$submission_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT submission_id FROM {$table} WHERE status = 'processing' AND updated_at < %s", $cutoff));
		$wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = 'failed', retryable = 0, next_attempt_at = NULL, error_message = %s, updated_at = %s WHERE status = 'processing' AND updated_at < %s",
			__('Результат перерваної доставки невідомий. Автоматичний повтор вимкнено, щоб уникнути дублювання.', 'leadforms-go'),
			current_time('mysql'),
			$cutoff
		));
		foreach ($submission_ids as $submission_id) self::sync_submission_status((int) $submission_id);
	}

	public static function finish_delivery(array $delivery, Result $result, int $max_attempts): string
	{
		global $wpdb;
		$tables = Database::tables();
		$now = current_time('mysql');
		$attempts = (int) $delivery['attempts'] + 1;
		$retryable = $result->retryable ?? (! $result->success && ($result->http_code === 0 || $result->http_code === 408 || $result->http_code === 425 || $result->http_code === 429 || $result->http_code >= 500));
		$status = $result->success ? 'sent' : (($retryable && $attempts < $max_attempts) ? 'queued' : 'failed');
		$next_attempt = $status === 'queued' ? wp_date('Y-m-d H:i:s', time() + self::retry_delay($attempts), wp_timezone()) : null;
		$message = sanitize_text_field($result->message);
		$updated = $wpdb->update($tables['deliveries'], [
			'status' => $status,
			'http_code' => $result->http_code ?: null,
			'error_message' => $message,
			'attempts' => $attempts,
			'retryable' => $retryable ? 1 : 0,
			'next_attempt_at' => $next_attempt,
			'last_attempt_at' => $now,
			'external_reference' => sanitize_text_field($result->external_reference),
			'updated_at' => $now,
		], ['id' => (int) $delivery['id']], ['%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s'], ['%d']);
		if ($updated === false) return 'failed';
		$attempt_number = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(attempt_number), 0) FROM {$tables['attempts']} WHERE delivery_id = %d", (int) $delivery['id'])) + 1;
		$wpdb->insert($tables['attempts'], [
			'delivery_id' => (int) $delivery['id'],
			'attempt_number' => $attempt_number,
			'status' => $result->success ? 'sent' : 'failed',
			'http_code' => $result->http_code ?: null,
			'error_message' => $message,
			'created_at' => $now,
		], ['%d', '%d', '%s', '%d', '%s', '%s']);
		self::sync_submission_status((int) $delivery['submission_id']);
		return $status;
	}

	public static function cancel_delivery(int $delivery_id, string $message): void
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$delivery = $wpdb->get_row($wpdb->prepare("SELECT submission_id FROM {$table} WHERE id = %d", $delivery_id), ARRAY_A);
		if (! is_array($delivery)) return;
		$wpdb->update($table, [
			'status' => 'cancelled',
			'error_message' => sanitize_text_field($message),
			'retryable' => 0,
			'next_attempt_at' => null,
			'updated_at' => current_time('mysql'),
		], ['id' => $delivery_id], ['%s', '%s', '%d', '%s', '%s'], ['%d']);
		self::sync_submission_status((int) $delivery['submission_id']);
	}

	public static function cancel_active_deliveries(string $message): int
	{
		global $wpdb;
		$tables = Database::tables();
		$delivery_table = $tables['deliveries'];
		$submission_ids = $wpdb->get_col("SELECT DISTINCT submission_id FROM {$delivery_table} WHERE status IN ('queued', 'processing')"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (! is_array($submission_ids) || $submission_ids === []) return 0;

		$count = $wpdb->update(
			$delivery_table,
			[
				'status' => 'cancelled',
				'error_message' => sanitize_text_field($message),
				'retryable' => 0,
				'next_attempt_at' => null,
				'updated_at' => current_time('mysql'),
			],
			['status' => 'queued'],
			['%s', '%s', '%d', '%s', '%s'],
			['%s']
		);
		$processing_count = $wpdb->update(
			$delivery_table,
			[
				'status' => 'cancelled',
				'error_message' => sanitize_text_field($message),
				'retryable' => 0,
				'next_attempt_at' => null,
				'updated_at' => current_time('mysql'),
			],
			['status' => 'processing'],
			['%s', '%s', '%d', '%s', '%s'],
			['%s']
		);
		foreach (array_unique(array_map('absint', $submission_ids)) as $submission_id) {
			self::sync_submission_status($submission_id);
		}
		return max(0, (int) $count) + max(0, (int) $processing_count);
	}

	public static function retry_delivery(int $delivery_id): bool
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$result = $wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = 'queued', attempts = 0, retryable = 1, next_attempt_at = %s, error_message = '', updated_at = %s WHERE id = %d AND status IN ('failed','cancelled')",
			current_time('mysql'),
			current_time('mysql'),
			$delivery_id
		));
		if ($result === 1) {
			$submission_id = (int) $wpdb->get_var($wpdb->prepare("SELECT submission_id FROM {$table} WHERE id = %d", $delivery_id));
			self::sync_submission_status($submission_id);
		}
		return $result === 1;
	}

	public static function retry_failed_submission(int $submission_id): int
	{
		return self::retry_failed_submissions([$submission_id]);
	}

	public static function retry_failed_submissions(array $submission_ids): int
	{
		global $wpdb;
		$ids = array_slice(array_values(array_unique(array_filter(array_map('absint', $submission_ids)))), 0, 100);
		if ($ids === []) return 0;
		$tables = Database::tables();
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$now = current_time('mysql');
		$args = array_merge([$now, $now], $ids);
		$count = $wpdb->query($wpdb->prepare(
			"UPDATE {$tables['deliveries']} SET status = 'queued', attempts = 0, retryable = 1, next_attempt_at = %s, error_message = '', updated_at = %s WHERE submission_id IN ({$placeholders}) AND status IN ('failed','cancelled')",
			$args
		));
		if (! is_int($count) || $count <= 0) return 0;
		$wpdb->query($wpdb->prepare(
			"UPDATE {$tables['submissions']} SET status = 'queued' WHERE id IN ({$placeholders}) AND EXISTS (SELECT 1 FROM {$tables['deliveries']} d WHERE d.submission_id = {$tables['submissions']}.id AND d.status = 'queued')",
			$ids
		));
		return $count;
	}

	public static function delivery_belongs_to_submission(int $delivery_id, int $submission_id): bool
	{
		return (new Delivery_Repository())->belongs_to_submission($delivery_id, $submission_id);
	}

	public static function delivery(int $delivery_id): ?array
	{
		return (new Delivery_Repository())->find($delivery_id);
	}

	public static function save_delivery(int $submission_id, string $connector, Result $result): void
	{
		$delivery_id = self::create_delivery($submission_id, $connector);
		if (! $delivery_id) return;
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$delivery = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $delivery_id), ARRAY_A);
		if (is_array($delivery) && in_array($delivery['status'], ['queued', 'processing'], true)) self::finish_delivery($delivery, $result, 1);
	}

	public static function sync_submission_status(int $submission_id): void
	{
		global $wpdb;
		$tables = Database::tables();
		$statuses = $wpdb->get_col($wpdb->prepare("SELECT status FROM {$tables['deliveries']} WHERE submission_id = %d", $submission_id));
		if ($statuses === []) return;
		if (in_array('processing', $statuses, true)) $status = 'processing';
		elseif (in_array('queued', $statuses, true)) $status = 'queued';
		elseif (count(array_filter($statuses, static fn (string $value): bool => in_array($value, ['success', 'sent'], true))) === count($statuses)) $status = 'success';
		else $status = 'failed';
		$wpdb->update($tables['submissions'], ['status' => $status], ['id' => $submission_id], ['%s'], ['%d']);
	}

	public static function finish_submission(int $id, bool $all_success): void
	{
		(new Submission_Repository())->finish($id, $all_success);
	}

	public static function submissions(int $limit = 100, array $filters = [], int $offset = 0): array
	{
		global $wpdb;
		$tables = Database::tables();
		[$where, $args] = self::submission_where($filters);
		$sql = "SELECT s.*, f.name AS form_name FROM {$tables['submissions']} s LEFT JOIN {$tables['forms']} f ON f.id = s.form_id {$where} ORDER BY s.id DESC LIMIT %d OFFSET %d";
		$args[] = max(1, $limit);
		$args[] = max(0, $offset);
		$rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
		$grouped = self::deliveries_for_submissions(array_map('absint', array_column($rows, 'id')));
		foreach ($rows as &$row) $row['deliveries'] = $grouped[(int) $row['id']] ?? [];
		unset($row);
		return $rows;
	}

	public static function submission_count(array $filters = []): int
	{
		global $wpdb;
		$table = Database::tables()['submissions'];
		[$where, $args] = self::submission_where($filters);
		$sql = "SELECT COUNT(*) FROM {$table} s {$where}";
		return (int) ($args === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $args))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function submission(int $submission_id): ?array
	{
		global $wpdb;
		$tables = Database::tables();
		$row = $wpdb->get_row($wpdb->prepare("SELECT s.*, f.name AS form_name FROM {$tables['submissions']} s LEFT JOIN {$tables['forms']} f ON f.id = s.form_id WHERE s.id = %d", $submission_id), ARRAY_A);
		if (! is_array($row)) return null;
		$row['deliveries'] = self::submission_deliveries($submission_id, true);
		return $row;
	}

	public static function queue_summary(): array
	{
		return (new Statistics_Repository())->queue_summary();
	}

	public static function next_queued_timestamp(): ?int
	{
		return (new Statistics_Repository())->next_queued_timestamp();
	}

	public static function dashboard_stats(): array
	{
		return (new Statistics_Repository())->dashboard();
	}

	public static function purge_submissions_older_than(int $days): int
	{
		global $wpdb;
		if ($days < 1) return 0;
		$tables = Database::tables();
		$cutoff = wp_date('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS), wp_timezone());
		$total = 0;
		for ($batch = 0; $batch < 10; ++$batch) {
			$ids = array_map('absint', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['submissions']} WHERE created_at < %s ORDER BY id ASC LIMIT 500", $cutoff)));
			if ($ids === []) break;
			$total += self::delete_submissions($ids);
			if (count($ids) < 500) break;
		}
		return $total;
	}

	public static function delete_submissions(array $submission_ids): int
	{
		global $wpdb;
		$ids = array_values(array_unique(array_filter(array_map('absint', $submission_ids))));
		if ($ids === []) return 0;
		$tables = Database::tables();
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$wpdb->query($wpdb->prepare("DELETE a FROM {$tables['attempts']} a INNER JOIN {$tables['deliveries']} d ON d.id = a.delivery_id WHERE d.submission_id IN ({$placeholders})", $ids));
		$wpdb->query($wpdb->prepare("DELETE FROM {$tables['deliveries']} WHERE submission_id IN ({$placeholders})", $ids));
		$deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$tables['submissions']} WHERE id IN ({$placeholders})", $ids));
		return is_int($deleted) ? $deleted : 0;
	}

	private static function submission_deliveries(int $submission_id, bool $with_attempts = false): array
	{
		global $wpdb;
		$tables = Database::tables();
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['deliveries']} WHERE submission_id = %d ORDER BY id ASC", $submission_id), ARRAY_A) ?: [];
		if ($with_attempts) {
			$delivery_ids = array_map('absint', array_column($rows, 'id'));
			$attempts = [];
			if ($delivery_ids !== []) {
				$placeholders = implode(',', array_fill(0, count($delivery_ids), '%d'));
				$attempt_rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['attempts']} WHERE delivery_id IN ({$placeholders}) ORDER BY id DESC", $delivery_ids), ARRAY_A) ?: [];
				foreach ($attempt_rows as $attempt) $attempts[(int) $attempt['delivery_id']][] = $attempt;
			}
			foreach ($rows as &$row) $row['attempt_history'] = $attempts[(int) $row['id']] ?? [];
			unset($row);
		}
		return $rows;
	}

	private static function deliveries_for_submissions(array $submission_ids): array
	{
		global $wpdb;
		$ids = array_values(array_unique(array_filter(array_map('absint', $submission_ids))));
		if ($ids === []) return [];
		$table = Database::tables()['deliveries'];
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE submission_id IN ({$placeholders}) ORDER BY id ASC", $ids), ARRAY_A) ?: [];
		$grouped = [];
		foreach ($rows as $row) $grouped[(int) $row['submission_id']][] = $row;
		return $grouped;
	}

	private static function submission_where(array $filters): array
	{
		$conditions = [];
		$args = [];
		if (! empty($filters['exclude_test'])) $conditions[] = 's.is_test = 0';
		if (! empty($filters['form_id'])) { $conditions[] = 's.form_id = %d'; $args[] = absint($filters['form_id']); }
		if (! empty($filters['status']) && in_array($filters['status'], ['queued', 'processing', 'success', 'failed'], true)) { $conditions[] = 's.status = %s'; $args[] = $filters['status']; }
		if (! empty($filters['connector'])) { $tables = Database::tables(); $conditions[] = "EXISTS (SELECT 1 FROM {$tables['deliveries']} df WHERE df.submission_id = s.id AND df.connector = %s)"; $args[] = sanitize_key($filters['connector']); }
		$date_from = self::valid_date($filters['date_from'] ?? '');
		$date_to = self::valid_date($filters['date_to'] ?? '');
		if ($date_from !== '') { $conditions[] = 's.created_at >= %s'; $args[] = $date_from . ' 00:00:00'; }
		if ($date_to !== '') { $conditions[] = 's.created_at <= %s'; $args[] = $date_to . ' 23:59:59'; }
		return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $args];
	}

	private static function valid_date(mixed $value): string
	{
		$value = is_string($value) ? $value : '';
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
		return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
	}

	private static function retry_delay(int $attempt): int
	{
		$delays = [60, 300, 900, 3600, 21600];
		$delay = $delays[min(max(1, $attempt), count($delays)) - 1];
		return max(30, (int) apply_filters('leadforms_go_retry_delay', $delay, $attempt));
	}
}
