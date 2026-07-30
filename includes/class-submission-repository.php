<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Submission_Repository
{
	/** @return array{id:int, created:bool} */
	public function create(?int $form_id, array $payload, string $referer, string $locale, string $request_id, bool $is_test): array
	{
		global $wpdb;
		$encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
		if (! is_string($encoded)) return ['id' => 0, 'created' => false];
		$table = Database::tables()['submissions'];
		$visited_at = self::visited_at((string) ($payload['visited_at'] ?? ''));
		$dedup = $is_test ? ['phone_fingerprint' => '', 'email_fingerprint' => '', 'duplicate_of' => null] : Lead_Deduplicator::analyze($payload, 0, (int) $form_id);
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$table} (form_id, payload, referer, locale, request_id, is_test, landing_page, document_referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, ttclid, visited_at, status, lead_state, phone_fingerprint, email_fingerprint, duplicate_of, dedup_indexed, created_at) VALUES (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'pending', 'new', %s, %s, NULLIF(%d, 0), 1, %s)",
			$form_id ?: null,
			$encoded,
			sanitize_url($referer),
			Form_Translations::normalize_locale($locale) ?: Form_Translations::DEFAULT_LOCALE,
			$request_id,
			$is_test ? 1 : 0,
			self::url((string) ($payload['landing_page'] ?? $referer)),
			self::url((string) ($payload['document_referrer'] ?? '')),
			self::value($payload, 'utm_source'),
			self::value($payload, 'utm_medium'),
			self::value($payload, 'utm_campaign'),
			self::value($payload, 'utm_term'),
			self::value($payload, 'utm_content'),
			self::value($payload, 'gclid'),
			self::value($payload, 'fbclid'),
			self::value($payload, 'ttclid'),
			$visited_at,
			$dedup['phone_fingerprint'],
			$dedup['email_fingerprint'],
			$dedup['duplicate_of'],
			current_time('mysql')
		));
		if ($inserted === false) return ['id' => 0, 'created' => false];
		if ($inserted === 1) {
			$id = (int) $wpdb->insert_id;
			if (! $is_test) {
				$resolved = Lead_Deduplicator::analyze($payload, $id, (int) $form_id);
				$wpdb->query($wpdb->prepare(
					"UPDATE {$table} SET duplicate_of = NULLIF(%d, 0) WHERE id = %d",
					(int) ($resolved['duplicate_of'] ?? 0),
					$id
				));
			}
			return ['id' => $id, 'created' => true];
		}
		$id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE request_id = %s", $request_id));
		return ['id' => $id, 'created' => false];
	}

	private static function value(array $payload, string $key): string
	{
		return substr(sanitize_text_field((string) ($payload[$key] ?? '')), 0, 255);
	}

	private static function url(string $url): string
	{
		return substr(esc_url_raw($url, ['http', 'https']), 0, 2000);
	}

	private static function visited_at(string $value): string
	{
		$timestamp = ctype_digit($value) ? (int) $value : 0;
		if ($timestamp <= 0 || $timestamp > time() || $timestamp < time() - YEAR_IN_SECONDS) return current_time('mysql');
		return wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
	}

	public function finish(int $id, bool $all_success): void
	{
		global $wpdb;
		$wpdb->update(Database::tables()['submissions'], ['status' => $all_success ? 'success' : 'failed'], ['id' => $id], ['%s'], ['%d']);
	}
}
