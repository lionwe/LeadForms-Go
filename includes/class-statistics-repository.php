<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Statistics_Repository
{
	public function dashboard(): array
	{
		global $wpdb;
		$tables = Database::tables();
		$today = wp_date('Y-m-d 00:00:00', null, wp_timezone());
		$week = wp_date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS), wp_timezone());
		$submission_stats = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM(status = 'success') AS success, SUM(created_at >= %s) AS today, SUM(created_at >= %s) AS week FROM {$tables['submissions']} WHERE is_test = 0",
			$today,
			$week
		), ARRAY_A) ?: [];
		$delivery_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT d.connector, SUM(d.updated_at >= %s AND d.status IN ('success','sent')) AS success, SUM(d.updated_at >= %s AND d.status = 'failed') AS failed, SUM(d.updated_at >= %s AND d.status = 'queued') AS queued, SUM(d.updated_at >= %s AND d.status = 'processing') AS processing, MAX(CASE WHEN d.status IN ('success','sent') THEN d.updated_at ELSE NULL END) AS last_success FROM {$tables['deliveries']} d INNER JOIN {$tables['submissions']} s ON s.id = d.submission_id AND s.is_test = 0 GROUP BY d.connector",
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

	public function queue_summary(): array
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$now = current_time('mysql');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT SUM(status = 'queued') AS queued, SUM(status = 'processing') AS processing, SUM(status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)) AS due, MIN(CASE WHEN status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= %s) THEN COALESCE(next_attempt_at, created_at) ELSE NULL END) AS oldest_due_at FROM {$table}",
			$now,
			$now
		), ARRAY_A) ?: [];
		return [
			'queued' => (int) ($row['queued'] ?? 0),
			'due' => (int) ($row['due'] ?? 0),
			'processing' => (int) ($row['processing'] ?? 0),
			'oldest_due_at' => (string) ($row['oldest_due_at'] ?? ''),
		];
	}

	public function next_queued_timestamp(): ?int
	{
		global $wpdb;
		$table = Database::tables()['deliveries'];
		$value = $wpdb->get_var("SELECT MIN(COALESCE(next_attempt_at, created_at)) FROM {$table} WHERE status = 'queued'"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if (! is_string($value) || $value === '') return null;
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
		return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : null;
	}
}
