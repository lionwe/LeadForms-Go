<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Delivery_Queue
{
	public const HOOK = 'leadforms_go_process_queue';
	private const LOCK_OPTION = 'leadforms_go_queue_lock';
	private const PENDING_OPTION = 'leadforms_go_queue_pending';
	private const MAX_ATTEMPTS = 5;
	private const BATCH_SIZE = 5;
	private const FALLBACK_BATCH_SIZE = 1;
	private const TIME_BUDGET = 20;
	private const LOCK_TTL = 5 * MINUTE_IN_SECONDS;
	private const FALLBACK_GRACE = 15;
	private const CRON_TOLERANCE = MINUTE_IN_SECONDS;
	private bool $pending = false;
	private bool $queued_in_request = false;

	public function boot(): void
	{
		add_action(self::HOOK, [$this, 'process']);
		add_action('shutdown', [$this, 'maybe_process_fallback'], PHP_INT_MAX);
		$this->pending = (bool) get_option(self::PENDING_OPTION);
		if ($this->pending) $this->schedule();
	}

	public static function deactivate(): void
	{
		wp_clear_scheduled_hook(self::HOOK);
		wp_clear_scheduled_hook('leadforms_go_cleanup_submissions');
		delete_option(self::LOCK_OPTION);
	}

	public function queue_submission(int $submission_id): int
	{
		$count = 0;
		$enabled = 0;
		foreach (Connectors::all() as $connector) {
			if (! $connector->is_enabled()) continue;
			++$enabled;
			$count += Repositories::create_delivery($submission_id, $connector->key()) > 0 ? 1 : 0;
		}
		if ($count === 0) {
			Repositories::finish_submission($submission_id, $enabled === 0);
			return 0;
		}
		update_option(self::PENDING_OPTION, 1, false);
		$this->pending = true;
		$this->queued_in_request = true;
		Repositories::sync_submission_status($submission_id);
		$this->schedule(true);
		return $count;
	}

	public function process(string $source = 'cron'): void
	{
		if (! $this->acquire_lock()) return;
		$started_at = microtime(true);
		try {
			Repositories::release_stale_deliveries();
			$connectors = Connectors::all();
			$batch_size = $source === 'fallback' ? self::FALLBACK_BATCH_SIZE : self::BATCH_SIZE;
			foreach (Repositories::due_deliveries($batch_size) as $delivery) {
				$delivery_id = (int) $delivery['id'];
				if (! Repositories::claim_delivery($delivery_id)) continue;
				$key = sanitize_key((string) $delivery['connector']);
				if (! isset($connectors[$key]) || ! $connectors[$key]->is_enabled()) {
					Repositories::cancel_delivery($delivery_id, __('Інтеграція вимкнена або недоступна.', 'leadforms-go'));
					continue;
				}
				$payload = json_decode((string) $delivery['payload'], true);
				if (! is_array($payload)) {
					Repositories::cancel_delivery($delivery_id, __('Дані заявки пошкоджені.', 'leadforms-go'));
					continue;
				}
				$connector_payload = $key === 'telegram'
					? Submission_Presenter::for_telegram($payload, (int) $delivery['form_id'], (string) ($delivery['locale'] ?? ''))
					: $payload;
				try {
					$result = $connectors[$key]->send($connector_payload, (string) $delivery['referer']);
				} catch (\Throwable) {
					$result = new Result(false, 0, __('Під час доставки сталася внутрішня помилка.', 'leadforms-go'), true);
				}
				$status = Repositories::finish_delivery($delivery, $result, self::MAX_ATTEMPTS);
				do_action('leadforms_go_delivery_processed', $delivery_id, $status, $result);
				if (microtime(true) - $started_at >= self::TIME_BUDGET) break;
			}
			update_option('leadforms_go_queue_last_run', time(), false);
			update_option('leadforms_go_queue_last_source', $source === 'fallback' ? 'fallback' : 'cron', false);
			if ($source === 'fallback') update_option('leadforms_go_queue_fallback_last_run', time(), false);
		} finally {
			$this->release_lock();
			if (Repositories::queue_summary()['queued'] > 0) {
				update_option(self::PENDING_OPTION, 1, false);
				$this->pending = true;
				$this->schedule();
			} else {
				delete_option(self::PENDING_OPTION);
				wp_clear_scheduled_hook(self::HOOK);
				$this->pending = false;
			}
		}
	}

	public function maybe_process_fallback(): void
	{
		if (! $this->pending || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;
		if ($this->has_active_lock()) return;

		$summary = Repositories::queue_summary();
		if ($summary['processing'] > 0) {
			Repositories::release_stale_deliveries();
			$summary = Repositories::queue_summary();
		}
		if ($summary['due'] < 1 || $summary['processing'] > 0) return;

		$oldest_due = $this->database_time_to_timestamp((string) $summary['oldest_due_at']);
		$grace = $this->queued_in_request ? 0 : self::FALLBACK_GRACE;
		if ($oldest_due === null || $oldest_due > time() - $grace) return;

		if ($this->queued_in_request && function_exists('fastcgi_finish_request')) fastcgi_finish_request();
		$this->process('fallback');
	}

	public function retry_delivery(int $delivery_id): bool
	{
		$queued = Repositories::retry_delivery($delivery_id);
		if ($queued) {
			update_option(self::PENDING_OPTION, 1, false);
			$this->pending = true;
			$this->schedule(true);
		}
		return $queued;
	}

	public function retry_submission(int $submission_id): int
	{
		$count = Repositories::retry_failed_submission($submission_id);
		if ($count > 0) {
			update_option(self::PENDING_OPTION, 1, false);
			$this->pending = true;
			$this->schedule(true);
		}
		return $count;
	}

	public function retry_submissions(array $submission_ids): int
	{
		$count = Repositories::retry_failed_submissions($submission_ids);
		if ($count > 0) {
			update_option(self::PENDING_OPTION, 1, false);
			$this->pending = true;
			$this->schedule(true);
		}
		return $count;
	}

	public function health(): array
	{
		$summary = Repositories::queue_summary();
		$scheduled = wp_next_scheduled(self::HOOK);
		$disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		$now = time();
		$cron_overdue = $scheduled !== false && $scheduled < $now - self::CRON_TOLERANCE;
		$cron_delayed = $scheduled !== false && $scheduled > $now + self::CRON_TOLERANCE;
		$cron_missing = $summary['due'] > 0 && $scheduled === false;
		$locked = $this->has_active_lock();
		$oldest_due = $this->database_time_to_timestamp((string) $summary['oldest_due_at']);
		return $summary + [
			'scheduled' => $scheduled ?: null,
			'last_run' => (int) get_option('leadforms_go_queue_last_run', 0),
			'last_source' => sanitize_key((string) get_option('leadforms_go_queue_last_source', '')),
			'fallback_last_run' => (int) get_option('leadforms_go_queue_fallback_last_run', 0),
			'cron_disabled' => $disabled,
			'cron_overdue' => $cron_overdue,
			'cron_delayed' => $cron_delayed,
			'cron_missing' => $cron_missing,
			'lock_active' => $locked,
			'fallback_ready' => $summary['due'] > 0 && $summary['processing'] === 0 && ! $locked && $oldest_due !== null && $oldest_due <= $now - self::FALLBACK_GRACE,
			'healthy' => $summary['due'] === 0 || (! $disabled && ! $cron_overdue && ! $cron_delayed && ! $cron_missing),
		];
	}

	private function schedule(bool $spawn = false): void
	{
		$next = Repositories::next_queued_timestamp();
		if ($next === null) return;
		$timestamp = max(time(), $next);
		$scheduled = wp_next_scheduled(self::HOOK);
		$stale = $scheduled !== false && $scheduled < time() - self::CRON_TOLERANCE;
		if ($scheduled === false || $stale || $scheduled > $timestamp + 5) {
			if ($scheduled !== false) wp_unschedule_event($scheduled, self::HOOK);
			wp_schedule_single_event($timestamp, self::HOOK);
		}
		if ($spawn && $timestamp <= time() + 5 && function_exists('spawn_cron')) spawn_cron(time());
	}

	private function acquire_lock(): bool
	{
		if ($this->has_active_lock()) return false;
		return add_option(self::LOCK_OPTION, time(), '', false);
	}

	private function has_active_lock(): bool
	{
		$existing = (int) get_option(self::LOCK_OPTION, 0);
		if ($existing > 0 && $existing < time() - self::LOCK_TTL) {
			delete_option(self::LOCK_OPTION);
			return false;
		}
		return $existing > 0;
	}

	private function database_time_to_timestamp(string $value): ?int
	{
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
		return $date instanceof \DateTimeImmutable ? $date->getTimestamp() : null;
	}

	private function release_lock(): void
	{
		delete_option(self::LOCK_OPTION);
	}
}
