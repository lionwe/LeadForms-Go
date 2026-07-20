<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Google_Sheets_Service
{
	public function __construct(private readonly Google_Api_Client $client = new Google_Api_Client()) {}

	public function sheets(string $spreadsheet_id): array|\WP_Error
	{
		$response = $this->client->request('GET', $this->base($spreadsheet_id) . '?fields=spreadsheetId,sheets.properties(sheetId,title,index)');
		if (is_wp_error($response)) return $response;
		$items = [];
		foreach ((array) ($response['body']['sheets'] ?? []) as $sheet) {
			$properties = is_array($sheet['properties'] ?? null) ? $sheet['properties'] : [];
			$title = sanitize_text_field((string) ($properties['title'] ?? ''));
			if ($title !== '') $items[] = ['id' => absint($properties['sheetId'] ?? 0), 'title' => $title, 'index' => absint($properties['index'] ?? 0)];
		}
		return $items;
	}

	public function create_sheet(string $spreadsheet_id, string $title): array|\WP_Error
	{
		$title = sanitize_text_field($title);
		if ($title === '') return new \WP_Error('missing_sheet_name', __('Вкажіть назву нового аркуша.', 'leadforms-go'));
		$response = $this->client->request('POST', $this->base($spreadsheet_id) . ':batchUpdate', ['requests' => [['addSheet' => ['properties' => ['title' => $title]]]]]);
		if (is_wp_error($response)) return $response;
		$properties = $response['body']['replies'][0]['addSheet']['properties'] ?? [];
		return ['id' => absint($properties['sheetId'] ?? 0), 'title' => sanitize_text_field((string) ($properties['title'] ?? $title))];
	}

	public function write_headers(string $spreadsheet_id, string $sheet_name, array $headers): array|\WP_Error
	{
		$headers = array_values(array_filter(array_map('sanitize_text_field', array_slice($headers, 0, 50)), static fn (string $value): bool => $value !== ''));
		if ($headers === []) return new \WP_Error('missing_headers', __('Додайте хоча б одну колонку.', 'leadforms-go'));
		$range = self::a1_range($sheet_name, 'A1:' . self::column_letter(count($headers)) . '1');
		return $this->client->request('PUT', $this->values_url($spreadsheet_id, $range) . '?valueInputOption=RAW', ['range' => $range, 'majorDimension' => 'ROWS', 'values' => [$headers]]);
	}

	public function deliver(string $spreadsheet_id, string $sheet_name, array $columns, array $variables, string $write_mode, string $dedupe_key): array|\WP_Error
	{
		if ($columns === []) {
			foreach ($variables as $key => $value) $columns[] = ['header' => (string) $key, 'type' => 'field', 'source' => (string) $key, 'value' => ''];
		}
		$values = array_map(static fn (array $column): string => Route_Config::resolve_value($column, $variables), $columns);
		if ($write_mode === 'update' && $dedupe_key !== '') {
			$column_index = null;
			foreach ($columns as $index => $column) if (($column['source'] ?? '') === $dedupe_key) $column_index = $index + 1;
			if ($column_index !== null && isset($variables[$dedupe_key])) {
				$row = $this->find_last_row($spreadsheet_id, $sheet_name, $column_index, (string) $variables[$dedupe_key], $dedupe_key);
				if (is_wp_error($row)) return $row;
				if ($row > 0) return $this->update_row($spreadsheet_id, $sheet_name, $row, $values);
			}
		}
		return $this->append_row($spreadsheet_id, $sheet_name, $values);
	}

	private function append_row(string $spreadsheet_id, string $sheet_name, array $values): array|\WP_Error
	{
		$range = self::a1_range($sheet_name, 'A1');
		return $this->client->request('POST', $this->values_url($spreadsheet_id, $range) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', ['majorDimension' => 'ROWS', 'values' => [$values]]);
	}

	private function update_row(string $spreadsheet_id, string $sheet_name, int $row, array $values): array|\WP_Error
	{
		$range = self::a1_range($sheet_name, 'A' . $row . ':' . self::column_letter(count($values)) . $row);
		return $this->client->request('PUT', $this->values_url($spreadsheet_id, $range) . '?valueInputOption=RAW', ['range' => $range, 'majorDimension' => 'ROWS', 'values' => [$values]]);
	}

	private function find_last_row(string $spreadsheet_id, string $sheet_name, int $column, string $needle, string $key): int|\WP_Error
	{
		$letter = self::column_letter($column);
		$range = self::a1_range($sheet_name, $letter . '2:' . $letter);
		$response = $this->client->request('GET', $this->values_url($spreadsheet_id, $range) . '?majorDimension=COLUMNS&valueRenderOption=UNFORMATTED_VALUE');
		if (is_wp_error($response)) return $response;
		$values = (array) ($response['body']['values'][0] ?? []);
		$normalized_needle = self::normalize_dedupe($needle, $key);
		for ($index = count($values) - 1; $index >= 0; --$index) {
			if (self::normalize_dedupe((string) $values[$index], $key) === $normalized_needle) return $index + 2;
		}
		return 0;
	}

	public static function updated_range(array $response): string
	{
		return sanitize_text_field((string) ($response['body']['updates']['updatedRange'] ?? $response['body']['updatedRange'] ?? ''));
	}

	public static function column_letter(int $column): string
	{
		$column = max(1, $column);
		$result = '';
		while ($column > 0) {
			--$column;
			$result = chr(65 + ($column % 26)) . $result;
			$column = intdiv($column, 26);
		}
		return $result;
	}

	private static function normalize_dedupe(string $value, string $key): string
	{
		$value = trim($value);
		if (str_contains($key, 'phone') || str_contains($key, 'tel')) return (string) preg_replace('/\D+/', '', $value);
		return strtolower($value);
	}

	public static function a1_range(string $sheet_name, string $cells): string
	{
		$cells = preg_match('/^[A-Z]+\d+(?::[A-Z]+\d*)?$/', $cells) ? $cells : 'A1';
		return "'" . str_replace("'", "''", sanitize_text_field($sheet_name)) . "'!" . $cells;
	}

	private function values_url(string $spreadsheet_id, string $range): string
	{
		return $this->base($spreadsheet_id) . '/values/' . rawurlencode($range);
	}

	private function base(string $spreadsheet_id): string
	{
		$spreadsheet_id = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $spreadsheet_id);
		return 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheet_id);
	}
}
