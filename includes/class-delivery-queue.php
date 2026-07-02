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
	private const TIME_BUDGET = 20;

	public function boot(): void
	{
		add_action(self::HOOK, [$this, 'process']);
		if (get_option(self::PENDING_OPTION)) $this->schedule();
	}

	public static function deactivate(): void
	{
		wp_clear_scheduled_hook(self::HOOK);
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
		Repositories::sync_submission_status($submission_id);
		$this->schedule(true);
		return $count;
	}

	public function process(): void
	{
		if (! $this->acquire_lock()) return;
		$started_at = microtime(true);
		try {
			Repositories::release_stale_deliveries();
			$connectors = Connectors::all();
			foreach (Repositories::due_deliveries(self::BATCH_SIZE) as $delivery) {
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
				try {
					$result = $connectors[$key]->send($payload, (string) $delivery['referer']);
				} catch (\Throwable) {
					$result = new Result(false, 0, __('Під час доставки сталася внутрішня помилка.', 'leadforms-go'), true);
				}
				$status = Repositories::finish_delivery($delivery, $result, self::MAX_ATTEMPTS);
				do_action('leadforms_go_delivery_processed', $delivery_id, $status, $result);
				if (microtime(true) - $started_at >= self::TIME_BUDGET) break;
			}
			update_option('leadforms_go_queue_last_run', time(), false);
		} finally {
			$this->release_lock();
			if (Repositories::queue_summary()['queued'] > 0) {
				update_option(self::PENDING_OPTION, 1, false);
				$this->schedule();
			} else {
				delete_option(self::PENDING_OPTION);
			}
		}
	}

	public function retry_delivery(int $delivery_id): bool
	{
		$queued = Repositories::retry_delivery($delivery_id);
		if ($queued) {
			update_option(self::PENDING_OPTION, 1, false);
			$this->schedule(true);
		}
		return $queued;
	}

	public function retry_submission(int $submission_id): int
	{
		$count = Repositories::retry_failed_submission($submission_id);
		if ($count > 0) {
			update_option(self::PENDING_OPTION, 1, false);
			$this->schedule(true);
		}
		return $count;
	}

	public function retry_submissions(array $submission_ids): int
	{
		$count = Repositories::retry_failed_submissions($submission_ids);
		if ($count > 0) {
			update_option(self::PENDING_OPTION, 1, false);
			$this->schedule(true);
		}
		return $count;
	}

	public function health(): array
	{
		$summary = Repositories::queue_summary();
		$scheduled = wp_next_scheduled(self::HOOK);
		$disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		return $summary + [
			'scheduled' => $scheduled ?: null,
			'last_run' => (int) get_option('leadforms_go_queue_last_run', 0),
			'cron_disabled' => $disabled,
			'healthy' => ! $disabled && ($summary['due'] === 0 || ($scheduled !== false && $scheduled <= time() + MINUTE_IN_SECONDS)),
		];
	}

	private function schedule(bool $spawn = false): void
	{
		$next = Repositories::next_queued_timestamp();
		if ($next === null) return;
		$timestamp = max(time(), $next);
		$scheduled = wp_next_scheduled(self::HOOK);
		if ($scheduled === false || $scheduled > $timestamp + 5) {
			if ($scheduled !== false) wp_unschedule_event($scheduled, self::HOOK);
			wp_schedule_single_event($timestamp, self::HOOK);
		}
		if ($spawn && $timestamp <= time() + 5 && function_exists('spawn_cron')) spawn_cron(time());
	}

	private function acquire_lock(): bool
	{
		$existing = (int) get_option(self::LOCK_OPTION, 0);
		if ($existing > 0 && $existing < time() - (5 * MINUTE_IN_SECONDS)) delete_option(self::LOCK_OPTION);
		return add_option(self::LOCK_OPTION, time(), '', false);
	}

	private function release_lock(): void
	{
		delete_option(self::LOCK_OPTION);
	}
}
