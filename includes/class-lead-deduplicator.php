<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Lead_Deduplicator
{
	private const SECRET_OPTION = 'leadforms_go_dedup_secret';
	private const BACKFILL_HOOK = 'leadforms_go_dedup_backfill';

	/** @return array{phone_fingerprint:string,email_fingerprint:string,duplicate_of:?int} */
	public static function analyze(array $payload, int $before_id = 0, int $form_id = 0): array
	{
		$schema = [];
		if ($form_id > 0) {
			$form = Repositories::form($form_id);
			$decoded = is_array($form) ? json_decode((string) ($form['form_schema'] ?? ''), true) : [];
			$schema = Form_Builder::sanitize_schema(is_array($decoded) ? $decoded : []);
		}
		$values = self::contact_values($payload, $schema);
		$phone = self::fingerprint($values['phone']);
		$email = self::fingerprint($values['email']);
		return [
			'phone_fingerprint' => $phone,
			'email_fingerprint' => $email,
			'duplicate_of' => self::previous_submission($phone, $email, $before_id),
		];
	}

	public static function schedule_backfill(): void
	{
		if (! wp_next_scheduled(self::BACKFILL_HOOK)) {
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::BACKFILL_HOOK);
		}
	}

	public static function backfill(): void
	{
		global $wpdb;
		$table = Database::tables()['submissions'];
		$rows = $wpdb->get_results(
			"SELECT id, form_id, payload FROM {$table} WHERE dedup_indexed = 0 AND is_test = 0 AND lead_state <> 'spam' ORDER BY id ASC LIMIT 200",
			ARRAY_A
		) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ($rows as $row) {
			$payload = json_decode((string) ($row['payload'] ?? ''), true);
			$result = self::analyze(is_array($payload) ? $payload : [], (int) $row['id'], (int) ($row['form_id'] ?? 0));
			$wpdb->query($wpdb->prepare(
				"UPDATE {$table} SET phone_fingerprint = %s, email_fingerprint = %s, duplicate_of = NULLIF(%d, 0), dedup_indexed = 1 WHERE id = %d",
				$result['phone_fingerprint'],
				$result['email_fingerprint'],
				(int) ($result['duplicate_of'] ?? 0),
				(int) $row['id']
			));
		}
		if (count($rows) === 200) self::schedule_backfill();
		else update_option('leadforms_go_dedup_backfill_complete', current_time('mysql'), false);
	}

	/** @return array{phone:string,email:string} */
	public static function contact_values(array $payload, array $schema = []): array
	{
		$phone = '';
		$email = '';
		$phone_keys = [];
		$email_keys = [];
		foreach ($schema as $field) {
			$key = sanitize_key((string) ($field['key'] ?? ''));
			$type = sanitize_key((string) ($field['type'] ?? ''));
			if ($key !== '' && $type === 'tel') $phone_keys[] = $key;
			if ($key !== '' && $type === 'email') $email_keys[] = $key;
		}
		foreach ($payload as $key => $value) {
			if (! is_scalar($value)) continue;
			$raw_key = strtolower((string) $key);
			$key = sanitize_key($raw_key);
			$value = trim((string) $value);
			$sanitized_email = strtolower((string) sanitize_email($value));
			if ($email === '' && (in_array($key, $email_keys, true) || str_contains($key, 'email') || is_email($value)) && is_email($sanitized_email)) {
				$email = $sanitized_email;
			}
			if ($phone === '' && (in_array($key, $phone_keys, true) || preg_match('/phone|tel|mobile|телефон|номер/iu', $raw_key))) {
				$digits = preg_replace('/\D+/', '', $value);
				if (is_string($digits) && strlen($digits) >= 7 && strlen($digits) <= 15) $phone = $digits;
			}
		}
		return ['phone' => $phone, 'email' => $email];
	}

	private static function fingerprint(string $value): string
	{
		return $value === '' ? '' : hash_hmac('sha256', $value, self::secret());
	}

	private static function secret(): string
	{
		$secret = get_option(self::SECRET_OPTION, '');
		if (is_string($secret) && strlen($secret) >= 32) return $secret;
		$secret = wp_generate_password(64, true, true);
		add_option(self::SECRET_OPTION, $secret, '', false);
		return $secret;
	}

	private static function previous_submission(string $phone, string $email, int $before_id): ?int
	{
		global $wpdb;
		if ($phone === '' && $email === '') return null;
		$table = Database::tables()['submissions'];
		$matches = [];
		$args = [];
		if ($phone !== '') {
			$matches[] = 'phone_fingerprint = %s';
			$args[] = $phone;
		}
		if ($email !== '') {
			$matches[] = 'email_fingerprint = %s';
			$args[] = $email;
		}
		$sql = "SELECT id FROM {$table} WHERE is_test = 0 AND lead_state <> 'spam' AND dedup_indexed = 1 AND (" . implode(' OR ', $matches) . ')';
		if ($before_id > 0) {
			$sql .= ' AND id < %d';
			$args[] = $before_id;
		}
		$sql .= ' ORDER BY id DESC LIMIT 1';
		$id = (int) $wpdb->get_var($wpdb->prepare($sql, $args));
		return $id > 0 ? $id : null;
	}
}
