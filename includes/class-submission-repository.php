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
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$table} (form_id, payload, referer, locale, request_id, is_test, status, created_at) VALUES (%d, %s, %s, %s, %s, %d, 'pending', %s)",
			$form_id ?: null,
			$encoded,
			sanitize_url($referer),
			Form_Translations::normalize_locale($locale) ?: Form_Translations::DEFAULT_LOCALE,
			$request_id,
			$is_test ? 1 : 0,
			current_time('mysql')
		));
		if ($inserted === false) return ['id' => 0, 'created' => false];
		if ($inserted === 1) return ['id' => (int) $wpdb->insert_id, 'created' => true];
		$id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE request_id = %s", $request_id));
		return ['id' => $id, 'created' => false];
	}

	public function finish(int $id, bool $all_success): void
	{
		global $wpdb;
		$wpdb->update(Database::tables()['submissions'], ['status' => $all_success ? 'success' : 'failed'], ['id' => $id], ['%s'], ['%d']);
	}
}
