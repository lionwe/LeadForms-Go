<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Delivery_Repository
{
	public function create(int $submission_id, string $connector, array $route_snapshot): int
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$now = current_time('mysql');
		$connector = sanitize_key($connector);
		if ($submission_id <= 0 || $connector === '') return 0;
		$snapshot = wp_json_encode($route_snapshot, JSON_UNESCAPED_UNICODE);
		if (! is_string($snapshot)) $snapshot = '{}';
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$table} (submission_id, connector, status, attempts, retryable, next_attempt_at, idempotency_key, route_snapshot, created_at, updated_at) VALUES (%d, %s, 'queued', 0, 1, %s, %s, %s, %s, %s)",
			$submission_id,
			$connector,
			$now,
			hash('sha256', $submission_id . ':' . $connector),
			$snapshot,
			$now,
			$now
		));
		if ($inserted === false) return 0;
		if ($inserted === 1) return (int) $wpdb->insert_id;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE submission_id = %d AND connector = %s", $submission_id, $connector));
	}

	public function due(int $limit): array
	{
		global $wpdb;
		$tables = Database::tables();
		return $wpdb->get_results($wpdb->prepare(
			"SELECT d.*, s.payload, s.referer, s.form_id, s.locale, s.is_test FROM {$tables['deliveries']} d INNER JOIN {$tables['submissions']} s ON s.id = d.submission_id WHERE d.status = 'queued' AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= %s) ORDER BY d.next_attempt_at ASC, d.id ASC LIMIT %d",
			current_time('mysql'),
			min(50, max(1, $limit))
		), ARRAY_A) ?: [];
	}

	public function claim(int $delivery_id): bool
	{
		global $wpdb;
		$now = current_time('mysql');
		return $wpdb->query($wpdb->prepare(
			"UPDATE " . Database::tables()['deliveries'] . " SET status = 'processing', last_attempt_at = %s, updated_at = %s WHERE id = %d AND status = 'queued'",
			$now,
			$now,
			$delivery_id
		)) === 1;
	}

	public function find(int $delivery_id): ?array
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $delivery_id), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	public function belongs_to_submission(int $delivery_id, int $submission_id): bool
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d AND submission_id = %d", $delivery_id, $submission_id)) === 1;
	}
}
