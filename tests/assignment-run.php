<?php

declare(strict_types=1);

namespace {
	define('ARRAY_A', 'ARRAY_A');
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function current_time(string $type): string { return $type === 'mysql' ? '2026-09-25 12:00:00' : ''; }
}

namespace LeadFormsGo {
	final class Form_Translations
	{
		public const DEFAULT_LOCALE = 'uk_UA';
	}
}

namespace {
	require dirname(__DIR__) . '/includes/class-database.php';
	require dirname(__DIR__) . '/includes/class-repositories.php';

	final class Assignment_Test_WPDB
	{
		public string $prefix = 'wp_';
		public array $rows = [];
		public array $last_query = [];

		public function prepare(string $query, mixed ...$arguments): string
		{
			$this->last_query = [$query, isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : $arguments];
			return $query;
		}

		public function get_row(string $query, mixed $format): array|null
		{
			$id = (int) ($this->last_query[1][0] ?? 0);
			$row = $this->rows[$id] ?? null;
			if (! is_array($row)) return null;
			return array_intersect_key($row, array_flip([
				'lead_state', 'assigned_to', 'assigned_to_telegram_id', 'assigned_by',
				'assigned_by_telegram_id', 'transfer_requested_by', 'transfer_requested_by_telegram_id',
			]));
		}

		public function query(string $query): int|false
		{
			[$sql, $args] = $this->last_query;
			if (str_contains($sql, 'SET transfer_requested_by = %s')) {
				[$requester, $requester_id, , $id, $owner_id] = $args;
				$row = &$this->rows[(int) $id];
				if (! is_array($row) || $row['assigned_to_telegram_id'] !== $owner_id || $row['transfer_requested_by_telegram_id'] !== '') return 0;
				$row['transfer_requested_by'] = $requester;
				$row['transfer_requested_by_telegram_id'] = $requester_id;
				return 1;
			}
			if (str_contains($sql, 'SET lead_state = \'acknowledged\'')) {
				[$now, $actor, $actor_id, , $owner, $owner_id, $assigned_by, $assigned_by_id, $id, $state, $expected_owner_id] = $args;
				$row = &$this->rows[(int) $id];
				if (! is_array($row) || $row['lead_state'] !== $state || $row['assigned_to_telegram_id'] !== $expected_owner_id) return 0;
				$row = array_merge($row, [
					'lead_state' => 'acknowledged', 'acknowledged_at' => $now, 'status_changed_by' => $actor,
					'status_changed_by_telegram_id' => $actor_id, 'assigned_to' => $owner,
					'assigned_to_telegram_id' => $owner_id, 'assigned_by' => $assigned_by,
					'assigned_by_telegram_id' => $assigned_by_id, 'transfer_requested_by' => '',
					'transfer_requested_by_telegram_id' => '',
				]);
				return 1;
			}
			if (str_contains($sql, 'SET assigned_to = %s')) {
				[$new_owner, $new_owner_id, $approved_by, $approved_by_id, $changed_by, $changed_by_id, , $id, $expected_owner_id, $requester_id] = $args;
				$row = &$this->rows[(int) $id];
				if (! is_array($row) || $row['assigned_to_telegram_id'] !== $expected_owner_id || $row['transfer_requested_by_telegram_id'] !== $requester_id) return 0;
				$row = array_merge($row, [
					'assigned_to' => $new_owner, 'assigned_to_telegram_id' => $new_owner_id,
					'assigned_by' => $approved_by, 'assigned_by_telegram_id' => $approved_by_id,
					'status_changed_by' => $changed_by, 'status_changed_by_telegram_id' => $changed_by_id,
					'transfer_requested_by' => '', 'transfer_requested_by_telegram_id' => '',
				]);
				return 1;
			}
			if (str_contains($sql, 'SET status_changed_by = %s')) {
				[, , , $id, $expected_owner_id, $requester_id] = $args;
				$row = &$this->rows[(int) $id];
				if (! is_array($row) || $row['assigned_to_telegram_id'] !== $expected_owner_id || $row['transfer_requested_by_telegram_id'] !== $requester_id) return 0;
				$row['transfer_requested_by'] = '';
				$row['transfer_requested_by_telegram_id'] = '';
				return 1;
			}
			return false;
		}
	}

	$wpdb = new Assignment_Test_WPDB();
	$wpdb->rows = [
		1 => ['lead_state' => 'new', 'assigned_to' => '', 'assigned_to_telegram_id' => '', 'assigned_by' => '', 'assigned_by_telegram_id' => '', 'transfer_requested_by' => '', 'transfer_requested_by_telegram_id' => ''],
		2 => ['lead_state' => 'acknowledged', 'assigned_to' => 'Marta', 'assigned_to_telegram_id' => '101', 'assigned_by' => 'Marta', 'assigned_by_telegram_id' => '101', 'transfer_requested_by' => '', 'transfer_requested_by_telegram_id' => ''],
		3 => ['lead_state' => 'acknowledged', 'assigned_to' => 'Marta', 'assigned_to_telegram_id' => '101', 'assigned_by' => 'Marta', 'assigned_by_telegram_id' => '101', 'transfer_requested_by' => 'Oleh', 'transfer_requested_by_telegram_id' => '202'],
	];
	$failures = [];
	$assert = static function (bool $condition, string $message) use (&$failures): void {
		if (! $condition) $failures[] = $message;
	};

	$first_claim = \LeadFormsGo\Repositories::claim_lead(1, 'Marta', '101');
	$assert($first_claim['status'] === 'claimed' && $wpdb->rows[1]['assigned_to_telegram_id'] === '101', 'A manager must atomically claim an unassigned submission.');
	$handoff = \LeadFormsGo\Repositories::claim_lead(1, 'Oleh', '202');
	$assert($handoff['status'] === 'transfer_requested' && $wpdb->rows[1]['assigned_to_telegram_id'] === '101', 'A second manager must request transfer without replacing the owner.');
	$assert($wpdb->rows[1]['transfer_requested_by_telegram_id'] === '202', 'A transfer request must identify its requester.');
	$pending = \LeadFormsGo\Repositories::claim_lead(1, 'Iryna', '303');
	$assert($pending['status'] === 'transfer_pending' && $wpdb->rows[1]['transfer_requested_by_telegram_id'] === '202', 'A pending request must not be overwritten by a third manager.');
	$unauthorized = \LeadFormsGo\Repositories::approve_lead_transfer(1, 'Oleh', '202');
	$assert($unauthorized['status'] === 'unauthorized' && $wpdb->rows[1]['assigned_to_telegram_id'] === '101', 'Only the current owner may resolve the transfer request.');
	$approved = \LeadFormsGo\Repositories::approve_lead_transfer(1, 'Marta', '101');
	$assert($approved['status'] === 'approved' && $wpdb->rows[1]['assigned_to_telegram_id'] === '202', 'The owner approval must hand assignment to the requester.');
	$assert($wpdb->rows[1]['assigned_by_telegram_id'] === '101' && $wpdb->rows[1]['transfer_requested_by_telegram_id'] === '', 'Approval must retain its approving manager and clear the pending request.');
	$assert(\LeadFormsGo\Repositories::approve_lead_transfer(1, 'Marta', '101')['status'] === 'unauthorized', 'A former owner must not approve later transfers.');
	$assert(\LeadFormsGo\Repositories::claim_lead(2, 'Marta', '101')['status'] === 'already_owner', 'The current manager must not create a redundant transfer.');
	$rejected = \LeadFormsGo\Repositories::reject_lead_transfer(3, 'Marta', '101');
	$assert($rejected['status'] === 'rejected' && $wpdb->rows[3]['assigned_to_telegram_id'] === '101' && $wpdb->rows[3]['transfer_requested_by_telegram_id'] === '', 'The owner rejection must preserve assignment and clear the request.');
	$assert(\LeadFormsGo\Repositories::claim_lead(1, 'No Telegram ID', '')['status'] === 'invalid_actor', 'A user without a Telegram ID must not be assigned.');
	$assert(str_contains($wpdb->last_query[0], 'assigned_to_telegram_id = %s') && str_contains($wpdb->last_query[0], 'transfer_requested_by_telegram_id = %s'), 'Assignment updates must compare the expected owner and pending requester in SQL.');

	if ($failures !== []) {
		fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
		exit(1);
	}

	fwrite(STDOUT, "Lead assignment tests passed.\n");
}
