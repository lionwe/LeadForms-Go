<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Privacy
{
	private const PAGE_SIZE = 100;

	public static function register_exporter(array $exporters): array
	{
		$exporters['leadforms-go'] = [
			'exporter_friendly_name' => __('Заявки LeadForms Go', 'leadforms-go'),
			'callback' => [self::class, 'export'],
		];
		return $exporters;
	}

	public static function register_eraser(array $erasers): array
	{
		$erasers['leadforms-go'] = [
			'eraser_friendly_name' => __('Заявки LeadForms Go', 'leadforms-go'),
			'callback' => [self::class, 'erase'],
		];
		return $erasers;
	}

	public static function export(string $email_address, int $page = 1): array
	{
		$rows = self::rows($email_address, $page);
		$data = [];
		foreach ($rows as $row) {
			$payload = json_decode((string) $row['payload'], true);
			if (! is_array($payload) || ! self::contains_email($payload, $email_address)) continue;
			$item = [];
			foreach ($payload as $key => $value) {
				if (is_scalar($value)) $item[] = ['name' => sanitize_text_field((string) $key), 'value' => sanitize_textarea_field((string) $value)];
			}
			$item[] = ['name' => __('Джерело', 'leadforms-go'), 'value' => esc_url_raw((string) $row['referer'])];
			$item[] = ['name' => __('Створено', 'leadforms-go'), 'value' => (string) $row['created_at']];
			$data[] = ['group_id' => 'leadforms-go', 'group_label' => __('Заявки LeadForms Go', 'leadforms-go'), 'item_id' => 'submission-' . (int) $row['id'], 'data' => $item];
		}
		return ['data' => $data, 'done' => count($rows) < self::PAGE_SIZE];
	}

	public static function erase(string $email_address, int $page = 1): array
	{
		$email = sanitize_email($email_address);
		if ($email === '') return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
		$cursor_key = 'leadforms_go_erase_' . substr(hash_hmac('sha256', strtolower($email), wp_salt('nonce')), 0, 32);
		if ($page <= 1) delete_transient($cursor_key);
		$cursor = max(0, (int) get_transient($cursor_key));
		$rows = self::rows_after($email, $cursor);
		$ids = [];
		foreach ($rows as $row) {
			$payload = json_decode((string) $row['payload'], true);
			if (is_array($payload) && self::contains_email($payload, $email_address)) $ids[] = (int) $row['id'];
		}
		$removed = Repositories::delete_submissions($ids);
		$done = count($rows) < self::PAGE_SIZE;
		if ($done) {
			delete_transient($cursor_key);
		} elseif ($rows !== []) {
			$last_row = end($rows);
			if (is_array($last_row)) set_transient($cursor_key, (int) $last_row['id'], HOUR_IN_SECONDS);
		}
		return [
			'items_removed' => $removed > 0,
			'items_retained' => false,
			'messages' => [],
			'done' => $done,
		];
	}

	private static function rows_after(string $email_address, int $cursor): array
	{
		global $wpdb;
		$email = sanitize_email($email_address);
		if ($email === '') return [];
		$table = Database::tables()['submissions'];
		$like = '%' . $wpdb->esc_like($email) . '%';
		return $wpdb->get_results($wpdb->prepare("SELECT id, payload, referer, created_at FROM {$table} WHERE id > %d AND payload LIKE %s ORDER BY id ASC LIMIT %d", $cursor, $like, self::PAGE_SIZE), ARRAY_A) ?: [];
	}

	private static function rows(string $email_address, int $page): array
	{
		global $wpdb;
		$email = sanitize_email($email_address);
		if ($email === '') return [];
		$table = Database::tables()['submissions'];
		$like = '%' . $wpdb->esc_like($email) . '%';
		$offset = max(0, $page - 1) * self::PAGE_SIZE;
		return $wpdb->get_results($wpdb->prepare("SELECT id, payload, referer, created_at FROM {$table} WHERE payload LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d", $like, self::PAGE_SIZE, $offset), ARRAY_A) ?: [];
	}

	private static function contains_email(array $payload, string $email_address): bool
	{
		$email = strtolower(sanitize_email($email_address));
		foreach ($payload as $value) {
			if (is_scalar($value) && strtolower(sanitize_email((string) $value)) === $email) return true;
		}
		return false;
	}
}
