<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Repositories
{
	public static function forms(): array
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function form_summaries(): array
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		return $wpdb->get_results("SELECT id, name, editor_mode, legacy_id, updated_at FROM {$table} ORDER BY id DESC", ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function form(int $id, bool $legacy = false): ?array
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		$column = $legacy ? 'legacy_id' : 'id';
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$column} = %d", $id), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	public static function save_form(int $id, string $name, string $code, string $editor_mode = 'code', array $schema = [], string $submit_label = 'Надіслати'): int|false
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		$now = current_time('mysql');
		$data = [
			'name' => $name,
			'code' => $code,
			'editor_mode' => $editor_mode,
			'form_schema' => wp_json_encode($schema, JSON_UNESCAPED_UNICODE),
			'submit_label' => $submit_label,
			'updated_at' => $now,
		];
		if ($id > 0) {
			$result = $wpdb->update($table, $data, ['id' => $id], ['%s', '%s', '%s', '%s', '%s', '%s'], ['%d']);
			return $result === false ? false : $id;
		}
		$data['created_at'] = $now;
		$result = $wpdb->insert($table, $data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);
		return $result ? (int) $wpdb->insert_id : false;
	}

	public static function delete_form(int $id): bool
	{
		global $wpdb;
		return $wpdb->delete(Database::tables()['forms'], ['id' => $id], ['%d']) !== false;
	}

	public static function create_submission(?int $form_id, array $payload, string $referer): int
	{
		global $wpdb;
		$encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
		if (! is_string($encoded)) return 0;
		$inserted = $wpdb->insert(Database::tables()['submissions'], [
			'form_id' => $form_id ?: null,
			'payload' => $encoded,
			'referer' => sanitize_url($referer),
			'status' => 'pending',
			'created_at' => current_time('mysql'),
		], ['%d', '%s', '%s', '%s', '%s']);
		return $inserted === false ? 0 : (int) $wpdb->insert_id;
	}

	public static function create_delivery(int $submission_id, string $connector): int
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$now = current_time('mysql');
		$connector = sanitize_key($connector);
		if ($submission_id <= 0 || $connector === '') return 0;
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$table} (submission_id, connector, status, attempts, retryable, next_attempt_at, idempotency_key, created_at, updated_at) VALUES (%d, %s, 'queued', 0, 1, %s, %s, %s, %s)",
			$submission_id,
			$connector,
			$now,
			hash('sha256', $submission_id . ':' . $connector),
			$now,
			$now
		));
		if ($inserted === false) return 0;
		if ($inserted === 1) return (int) $wpdb->insert_id;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE submission_id = %d AND connector = %s", $submission_id, $connector));
	}

	public static function due_deliveries(int $limit = 10): array
	{
		global $wpdb;
		$tables = Database::tables();
		$now = current_time('mysql');
		return $wpdb->get_results($wpdb->prepare(
			"SELECT d.*, s.payload, s.referer, s.form_id FROM {$tables['deliveries']} d INNER JOIN {$tables['submissions']} s ON s.id = d.submission_id WHERE d.status = 'queued' AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= %s) ORDER BY d.next_attempt_at ASC, d.id ASC LIMIT %d",
			$now,
			min(50, max(1, $limit))
		), ARRAY_A) ?: [];
	}

	public static function claim_delivery(int $delivery_id): bool
	{
		global $wpdb;
		return $wpdb->query($wpdb->prepare(
			"UPDATE " . Database::tables()['deliveries'] . " SET status = 'processing', updated_at = %s WHERE id = %d AND status = 'queued'",
			current_time('mysql'),
			$delivery_id
		)) === 1;
	}

	public static function release_stale_deliveries(): void
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$cutoff = wp_date('Y-m-d H:i:s', time() - (10 * MINUTE_IN_SECONDS), wp_timezone());
		$now = current_time('mysql');
		$submission_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT submission_id FROM {$table} WHERE status = 'processing' AND updated_at < %s", $cutoff));
		$wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = 'queued', next_attempt_at = %s, error_message = %s, updated_at = %s WHERE status = 'processing' AND updated_at < %s",
			$now,
			__('Попередню спробу доставки було перервано.', 'leadforms-go'),
			$now,
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
		$status = $result->success ? 'success' : (($retryable && $attempts < $max_attempts) ? 'queued' : 'failed');
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
			'status' => $result->success ? 'success' : 'failed',
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
		global $wpdb;
		$table = Database::tables()['deliveries'];
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d AND submission_id = %d", $delivery_id, $submission_id)) === 1;
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
		elseif (count(array_filter($statuses, static fn (string $value): bool => $value === 'success')) === count($statuses)) $status = 'success';
		else $status = 'failed';
		$wpdb->update($tables['submissions'], ['status' => $status], ['id' => $submission_id], ['%s'], ['%d']);
	}

	public static function finish_submission(int $id, bool $all_success): void
	{
		global $wpdb;
		$wpdb->update(Database::tables()['submissions'], ['status' => $all_success ? 'success' : 'failed'], ['id' => $id], ['%s'], ['%d']);
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
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$now = current_time('mysql');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT SUM(status = 'queued') AS queued, SUM(status = 'processing') AS processing, SUM(status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) AS due FROM {$table}",
			$now
		), ARRAY_A) ?: [];
		return [
			'queued' => (int) ($row['queued'] ?? 0),
			'due' => (int) ($row['due'] ?? 0),
			'processing' => (int) ($row['processing'] ?? 0),
		];
	}

	public static function next_queued_timestamp(): ?int
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$value = $wpdb->get_var("SELECT MIN(COALESCE(next_attempt_at, created_at)) FROM {$table} WHERE status = 'queued'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if (! is_string($value) || $value === '') return null;
		$timestamp = strtotime($value . ' ' . wp_timezone_string());
		return $timestamp === false ? null : $timestamp;
	}

	public static function dashboard_stats(): array
	{
		global $wpdb;
		$tables = Database::tables();
		$today = wp_date('Y-m-d 00:00:00', null, wp_timezone());
		$week = wp_date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS), wp_timezone());
		$submission_stats = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM(status = 'success') AS success, SUM(created_at >= %s) AS today, SUM(created_at >= %s) AS week FROM {$tables['submissions']}",
			$today,
			$week
		), ARRAY_A) ?: [];
		$delivery_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT connector, SUM(updated_at >= %s AND status = 'success') AS success, SUM(updated_at >= %s AND status = 'failed') AS failed, SUM(updated_at >= %s AND status = 'queued') AS queued, SUM(updated_at >= %s AND status = 'processing') AS processing, MAX(CASE WHEN status = 'success' THEN updated_at ELSE NULL END) AS last_success FROM {$tables['deliveries']} GROUP BY connector",
			$today,
			$today,
			$today,
			$today
		), ARRAY_A) ?: [];
		$activity = [];
		$failed_today = 0;
		foreach ($delivery_rows as $row) {
			$key = sanitize_key((string) $row['connector']);
			$activity[$key] = [
				'success' => (int) $row['success'],
				'failed' => (int) $row['failed'],
				'queued' => (int) $row['queued'],
				'processing' => (int) $row['processing'],
				'last_success' => (string) ($row['last_success'] ?? ''),
			];
			$failed_today += (int) $row['failed'];
		}
		$total = (int) ($submission_stats['total'] ?? 0);
		$success = (int) ($submission_stats['success'] ?? 0);
		return [
			'forms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['forms']}"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'today' => (int) ($submission_stats['today'] ?? 0),
			'week' => (int) ($submission_stats['week'] ?? 0),
			'success_rate' => $total > 0 ? (int) round(($success / $total) * 100) : 0,
			'failed_today' => $failed_today,
			'activity' => $activity,
		];
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
