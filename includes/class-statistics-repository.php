<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Statistics_Repository
{
	public function dashboard(array $filters = []): array
	{
		global $wpdb;
		$tables = Database::tables();
		$today = wp_date('Y-m-d 00:00:00', null, wp_timezone());
		[$date_from, $date_to] = $this->date_range($filters);
		[$submission_where, $submission_args] = $this->submission_filter($filters, $date_from, $date_to);
		$submission_stats = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM(lead_state <> 'spam') AS clean, SUM(lead_state = 'spam') AS spam, SUM(lead_state <> 'spam' AND duplicate_of IS NOT NULL) AS repeats, SUM(lead_state <> 'spam' AND duplicate_of IS NULL) AS unique_contacts FROM {$tables['submissions']} s {$submission_where}",
			$submission_args
		), ARRAY_A) ?: [];
		$all_time = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS total, SUM(status = 'success') AS success, SUM(created_at >= %s) AS today FROM {$tables['submissions']} WHERE is_test = 0",
			$today
		), ARRAY_A) ?: [];
		$delivery_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT SUBSTRING_INDEX(d.connector, '__', 1) AS connector, SUM(d.updated_at >= %s AND d.status IN ('success','sent')) AS success, SUM(d.updated_at >= %s AND d.status = 'failed') AS failed, SUM(d.updated_at >= %s AND d.status = 'queued') AS queued, SUM(d.updated_at >= %s AND d.status = 'processing') AS processing, MAX(CASE WHEN d.status IN ('success','sent') THEN d.updated_at ELSE NULL END) AS last_success FROM {$tables['deliveries']} d INNER JOIN {$tables['submissions']} s ON s.id = d.submission_id AND s.is_test = 0 GROUP BY SUBSTRING_INDEX(d.connector, '__', 1)",
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
		$total = (int) ($all_time['total'] ?? 0);
		$success = (int) ($all_time['success'] ?? 0);
		[$view_where, $view_args] = $this->view_filter($filters, $date_from, $date_to);
		$views = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(views), 0) FROM {$tables['views']} v {$view_where}", $view_args));
		$source_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT COALESCE(NULLIF(s.utm_source, ''), %s) AS label, COUNT(*) AS submissions FROM {$tables['submissions']} s {$submission_where} AND s.lead_state <> 'spam' GROUP BY label ORDER BY submissions DESC LIMIT 8",
			array_merge([__('Без UTM', 'leadforms-go')], $submission_args)
		), ARRAY_A) ?: [];
		$campaign_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT COALESCE(NULLIF(s.utm_campaign, ''), %s) AS label, COUNT(*) AS submissions FROM {$tables['submissions']} s {$submission_where} AND s.lead_state <> 'spam' GROUP BY label ORDER BY submissions DESC LIMIT 8",
			array_merge([__('Без кампанії', 'leadforms-go')], $submission_args)
		), ARRAY_A) ?: [];
		$form_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT COALESCE(f.name, %s) AS label, COUNT(*) AS submissions FROM {$tables['submissions']} s LEFT JOIN {$tables['forms']} f ON f.id = s.form_id {$submission_where} AND s.lead_state <> 'spam' GROUP BY s.form_id, label ORDER BY submissions DESC LIMIT 8",
			array_merge([__('Видалена форма', 'leadforms-go')], $submission_args)
		), ARRAY_A) ?: [];
		$series_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT DATE(s.created_at) AS day, SUM(s.duplicate_of IS NULL) AS new_leads, SUM(s.duplicate_of IS NOT NULL) AS repeat_leads FROM {$tables['submissions']} s {$submission_where} AND s.lead_state <> 'spam' GROUP BY day ORDER BY day ASC",
			$submission_args
		), ARRAY_A) ?: [];
		$series_map = [];
		foreach ($series_rows as $row) $series_map[(string) $row['day']] = ['new' => (int) $row['new_leads'], 'repeat' => (int) $row['repeat_leads']];
		$series = [];
		$cursor = new \DateTimeImmutable($date_from, wp_timezone());
		$last = new \DateTimeImmutable($date_to, wp_timezone());
		while ($cursor <= $last) {
			$day = $cursor->format('Y-m-d');
			$series[] = ['day' => $day, 'new' => $series_map[$day]['new'] ?? 0, 'repeat' => $series_map[$day]['repeat'] ?? 0];
			$cursor = $cursor->modify('+1 day');
		}
		$clean = (int) ($submission_stats['clean'] ?? 0);
		return [
			'forms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['forms']} WHERE active = 1"), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'today' => (int) ($all_time['today'] ?? 0),
			'success_rate' => $total > 0 ? (int) round(($success / $total) * 100) : 0,
			'views' => $views,
			'conversion_rate' => $views > 0 ? round(($clean / $views) * 100, 1) : 0.0,
			'total' => (int) ($submission_stats['total'] ?? 0),
			'unique_contacts' => (int) ($submission_stats['unique_contacts'] ?? 0),
			'repeats' => (int) ($submission_stats['repeats'] ?? 0),
			'spam' => (int) ($submission_stats['spam'] ?? 0),
			'top_sources' => $this->sanitize_breakdown($source_rows),
			'top_campaigns' => $this->sanitize_breakdown($campaign_rows),
			'top_forms' => $this->sanitize_breakdown($form_rows),
			'series' => $series,
			'date_from' => $date_from,
			'date_to' => $date_to,
			'failed_today' => $failed_today,
			'activity' => $activity,
		];
	}

	/** @return array{0:string,1:string} */
	private function date_range(array $filters): array
	{
		$to = $this->valid_date($filters['date_to'] ?? '') ?: current_time('Y-m-d');
		$days = in_array((int) ($filters['period'] ?? 7), [7, 30], true) ? (int) $filters['period'] : 7;
		$from = $this->valid_date($filters['date_from'] ?? '');
		if ($from === '') $from = wp_date('Y-m-d', strtotime($to . ' 00:00:00') - (($days - 1) * DAY_IN_SECONDS), wp_timezone());
		if ($from > $to) [$from, $to] = [$to, $from];
		$from_time = strtotime($from . ' 00:00:00');
		$to_time = strtotime($to . ' 00:00:00');
		if ($from_time !== false && $to_time !== false && $to_time - $from_time > 365 * DAY_IN_SECONDS) {
			$from = wp_date('Y-m-d', $to_time - (365 * DAY_IN_SECONDS), wp_timezone());
		}
		return [$from, $to];
	}

	/** @return array{0:string,1:array} */
	private function submission_filter(array $filters, string $from, string $to): array
	{
		$conditions = ['s.is_test = 0', 's.created_at >= %s', 's.created_at <= %s'];
		$args = [$from . ' 00:00:00', $to . ' 23:59:59'];
		if (! empty($filters['form_id'])) {
			$conditions[] = 's.form_id = %d';
			$args[] = absint($filters['form_id']);
		}
		if (($source = $this->filter_value($filters['utm_source'] ?? '')) !== '') {
			$conditions[] = 's.utm_source = %s';
			$args[] = $source;
		}
		if (($campaign = $this->filter_value($filters['utm_campaign'] ?? '')) !== '') {
			$conditions[] = 's.utm_campaign = %s';
			$args[] = $campaign;
		}
		return ['WHERE ' . implode(' AND ', $conditions), $args];
	}

	/** @return array{0:string,1:array} */
	private function view_filter(array $filters, string $from, string $to): array
	{
		$conditions = ['v.view_date >= %s', 'v.view_date <= %s'];
		$args = [$from, $to];
		if (! empty($filters['form_id'])) {
			$conditions[] = 'v.form_id = %d';
			$args[] = absint($filters['form_id']);
		}
		if (($source = $this->filter_value($filters['utm_source'] ?? '')) !== '') {
			$conditions[] = 'v.utm_source = %s';
			$args[] = $source;
		}
		if (($campaign = $this->filter_value($filters['utm_campaign'] ?? '')) !== '') {
			$conditions[] = 'v.utm_campaign = %s';
			$args[] = $campaign;
		}
		return ['WHERE ' . implode(' AND ', $conditions), $args];
	}

	private function valid_date(mixed $value): string
	{
		$value = is_string($value) ? $value : '';
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
		return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
	}

	private function filter_value(mixed $value): string
	{
		return substr(sanitize_text_field(is_scalar($value) ? (string) $value : ''), 0, 191);
	}

	private function sanitize_breakdown(array $rows): array
	{
		return array_map(static fn (array $row): array => [
			'label' => sanitize_text_field((string) ($row['label'] ?? '')),
			'submissions' => (int) ($row['submissions'] ?? 0),
		], $rows);
	}

	public function record_view(int $form_id, string $source, string $campaign): bool
	{
		global $wpdb;
		if ($form_id <= 0) return false;
		$table = Database::tables()['views'];
		$date = current_time('Y-m-d');
		$source = substr(sanitize_text_field($source), 0, 191);
		$campaign = substr(sanitize_text_field($campaign), 0, 191);
		return $wpdb->query($wpdb->prepare(
			"INSERT INTO {$table} (view_date, form_id, utm_source, utm_campaign, views) VALUES (%s, %d, %s, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1",
			$date,
			$form_id,
			$source,
			$campaign
		)) !== false;
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
