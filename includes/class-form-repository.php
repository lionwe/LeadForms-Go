<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Form_Repository
{
	public function all(): array
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function summaries(): array
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		return $wpdb->get_results("SELECT id, name, editor_mode, active, legacy_id, updated_at FROM {$table} ORDER BY id DESC", ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function find(int $id, bool $legacy = false): ?array
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		$column = $legacy ? 'legacy_id' : 'id';
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$column} = %d", $id), ARRAY_A);
		return is_array($row) ? $row : null;
	}

	public function save(int $id, array $data): int|false
	{
		global $wpdb;
		$table = Database::tables()['forms'];
		$formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s'];
		if ($id > 0) {
			$result = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
			return $result === false ? false : $id;
		}
		$data['created_at'] = $data['updated_at'];
		$result = $wpdb->insert($table, $data, [...$formats, '%s']);
		return $result ? (int) $wpdb->insert_id : false;
	}

	public function delete(int $id): bool
	{
		global $wpdb;
		return $wpdb->delete(Database::tables()['forms'], ['id' => $id], ['%d']) !== false;
	}
}
