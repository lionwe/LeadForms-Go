<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Submission_Classifier
{
	private const BACKFILL_HOOK = 'leadforms_go_classification_backfill';
	private const COMPLETE_OPTION = 'leadforms_go_classification_backfill_complete';
	private const LOCK_OPTION = 'leadforms_go_classification_backfill_lock';
	private const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	public static function schedule_backfill(): void
	{
		if (get_option(self::COMPLETE_OPTION)) return;
		if (! wp_next_scheduled(self::BACKFILL_HOOK)) wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::BACKFILL_HOOK);
	}

	public static function maybe_process_fallback(): void
	{
		if (get_option(self::COMPLETE_OPTION)) return;
		$scheduled = wp_next_scheduled(self::BACKFILL_HOOK);
		if ($scheduled !== false && $scheduled > time()) return;
		self::backfill();
	}

	public static function backfill(): void
	{
		if (! self::acquire_lock()) return;
		global $wpdb;
		$tables = Database::tables();
		try {
			$rows = $wpdb->get_results(
				"SELECT s.id, s.form_id, s.payload, s.is_test, s.is_suspected_bot, f.form_schema FROM {$tables['submissions']} s LEFT JOIN {$tables['forms']} f ON f.id = s.form_id WHERE s.classification_reasons = '' ORDER BY s.id ASC LIMIT 200",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if (! is_array($rows)) return;
			$excluded_ids = [];
			$had_errors = false;
			foreach ($rows as $row) {
				$payload = json_decode((string) ($row['payload'] ?? ''), true);
				$schema = json_decode((string) ($row['form_schema'] ?? ''), true);
				$classification = self::classify(
					is_array($payload) ? $payload : [],
					Form_Builder::sanitize_schema(is_array($schema) ? $schema : [])
				);
				$is_test = ! empty($row['is_test']) || $classification['is_test'];
				$is_suspected_bot = ! empty($row['is_suspected_bot']) || $classification['is_suspected_bot'];
				$reasons = $classification['reasons'];
				if (! empty($row['is_test']) && ! in_array('test_route', $reasons, true)) $reasons[] = 'test_route';
				$updated = $wpdb->query($wpdb->prepare(
					"UPDATE {$tables['submissions']} SET is_test = %d, is_suspected_bot = %d, classification_reasons = %s WHERE id = %d AND classification_reasons = ''",
					$is_test ? 1 : 0,
					$is_suspected_bot ? 1 : 0,
					(string) wp_json_encode($reasons),
					(int) $row['id']
				));
				if ($updated === false) {
					$had_errors = true;
					continue;
				}
				if ($updated === 1 && ($is_test || $is_suspected_bot)) {
					$id = (int) $row['id'];
					$excluded_ids[] = $id;
					$wpdb->query($wpdb->prepare(
						"UPDATE {$tables['submissions']} SET phone_fingerprint = '', email_fingerprint = '', duplicate_of = NULL, dedup_indexed = 1 WHERE id = %d",
						$id
					));
				}
			}

			if ($excluded_ids !== []) {
				$placeholders = implode(',', array_fill(0, count($excluded_ids), '%d'));
				$wpdb->query($wpdb->prepare(
					"UPDATE {$tables['submissions']} SET duplicate_of = NULL, dedup_indexed = 0 WHERE duplicate_of IN ({$placeholders})",
					$excluded_ids
				));
				Lead_Deduplicator::schedule_backfill();
			}

			if (count($rows) === 200 || $had_errors) self::schedule_backfill();
			else update_option(self::COMPLETE_OPTION, current_time('mysql'), false);
		} finally {
			delete_option(self::LOCK_OPTION);
		}
	}

	private static function acquire_lock(): bool
	{
		$now = time();
		if (add_option(self::LOCK_OPTION, $now, '', false)) return true;
		$locked_at = (int) get_option(self::LOCK_OPTION, 0);
		if ($locked_at > 0 && ($now - $locked_at) < self::LOCK_TTL) return false;
		delete_option(self::LOCK_OPTION);
		return add_option(self::LOCK_OPTION, $now, '', false);
	}

	/** @return array{is_test:bool,is_suspected_bot:bool,reasons:array<int,string>} */
	public static function classify(array $payload, array $schema = []): array
	{
		$is_test = false;
		$is_suspected_bot = false;
		$reasons = [];
		$phone_keys = [];
		foreach ($schema as $field) {
			if (! is_array($field)) continue;
			$key = sanitize_key((string) ($field['key'] ?? ''));
			$type = sanitize_key((string) ($field['type'] ?? ''));
			$label = sanitize_text_field((string) ($field['label'] ?? ''));
			if ($key !== '' && ($type === 'tel' || preg_match('/phone|tel|номер|телефон/iu', $label))) $phone_keys[] = $key;
		}

		foreach ($payload as $key => $value) {
			if (! is_scalar($value)) continue;
			$text = trim((string) $value);
			$field = sanitize_key((string) $key);
			$is_attribution = str_starts_with($field, 'utm_') || in_array($field, ['page_url', 'landing_page', 'document_referrer', 'referer'], true);
			$is_email = str_contains($field, 'email') || is_email($text);
			if (! $is_attribution && ! $is_email && $text !== '' && preg_match('/(?:^|[^\p{L}\p{N}])(?:test|testing|тест\p{L}*|перевірка)(?:$|[^\p{L}\p{N}])/iu', $text)) {
				$is_test = true;
				$reasons[] = 'test_marker';
			}

			if (! in_array($field, $phone_keys, true) && ! preg_match('/phone|tel|номер|телефон/iu', $field)) continue;
			$digits = preg_replace('/\D+/', '', $text) ?: '';
			if ($digits === '') continue;
			$placeholder_phone = preg_match('/^(?:[1-9]\d{0,3})?0{6,}$/', $digits) === 1;
			if ($placeholder_phone) {
				$is_test = true;
				$reasons[] = 'zero_phone';
			}
			if ($placeholder_phone || strlen($digits) < 6 || (strlen($digits) >= 6 && preg_match('/^(\d)\1+$/', $digits)) || self::contains_sequential_phone_digits($digits)) {
				$is_suspected_bot = true;
				$reasons[] = 'suspicious_phone';
			}
		}

		return [
			'is_test' => $is_test,
			'is_suspected_bot' => $is_suspected_bot,
			'reasons' => array_values(array_unique($reasons)),
		];
	}

	private static function contains_sequential_phone_digits(string $digits): bool
	{
		for ($offset = 0, $length = strlen($digits) - 7; $offset < $length; ++$offset) {
			$ascending = true;
			$descending = true;
			for ($index = 1; $index < 8; ++$index) {
				$current = (int) $digits[$offset + $index];
				$previous = (int) $digits[$offset + $index - 1];
				if ($current !== $previous + 1) $ascending = false;
				if ($current !== $previous - 1) $descending = false;
			}
			if ($ascending || $descending) return true;
		}
		return false;
	}
}
