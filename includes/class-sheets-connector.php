<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Sheets_Connector extends Abstract_Connector implements Contextual_Connector_Interface
{
	private Google_Sheets_Service $service;

	public function __construct(?Google_Sheets_Service $service = null)
	{
		$this->service = $service ?? new Google_Sheets_Service();
	}

	public function key(): string { return 'sheets'; }

	public function service(): Google_Sheets_Service { return $this->service; }

	public function validate_settings(): true|\WP_Error
	{
		$s = $this->settings();
		return $this->validate_destination((string) ($s['spreadsheet_id'] ?? ''), (string) ($s['sheet_name'] ?? ''));
	}

	public function validate_route(array $route): true|\WP_Error
	{
		$s = $this->resolved_settings($route);
		return $this->validate_destination($s['spreadsheet_id'], $s['sheet_name']);
	}

	public function test_connection(): Result
	{
		$s = $this->settings();
		return $this->test_destination((string) ($s['spreadsheet_id'] ?? ''), (string) ($s['sheet_name'] ?? ''));
	}

	public function test_route(array $route, array $payload, int $form_id, string $locale): Result
	{
		return $this->send_request(new Delivery_Request(0, 0, $form_id, $locale, $payload, home_url('/'), $route));
	}

	public function send(array $data, string $referer): Result
	{
		$data['page_url'] = $referer;
		return $this->send_request(new Delivery_Request(0, 0, 0, Form_Translations::DEFAULT_LOCALE, $data, $referer, []));
	}

	public function send_request(Delivery_Request $request): Result
	{
		$route = $request->route;
		$s = $this->resolved_settings($route);
		$valid = $this->validate_destination($s['spreadsheet_id'], $s['sheet_name']);
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$columns = is_array($route['columns'] ?? null) ? $route['columns'] : [];
		if ($columns === []) {
			$order = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) ($this->settings()['fields_order'] ?? '')) ?: [])));
			foreach ($order as $field) $columns[] = ['header' => $field, 'type' => 'field', 'source' => $field, 'value' => ''];
		}
		$response = $this->service->deliver($s['spreadsheet_id'], $s['sheet_name'], $columns, $request->variables(), (string) ($route['write_mode'] ?? 'append'), sanitize_key((string) ($route['dedupe_key'] ?? '')));
		if (is_wp_error($response)) {
			$code = (int) ($response->get_error_data()['http_code'] ?? 0);
			$retryable = $code === 0 || $code === 408 || $code === 425 || $code === 429 || $code >= 500;
			return new Result(false, $code, $response->get_error_message(), $retryable);
		}
		$updated_range = Google_Sheets_Service::updated_range($response);
		$reference = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($s['spreadsheet_id']) . '/edit';
		if ($updated_range !== '') $reference .= '#range=' . rawurlencode($updated_range);
		return new Result(true, (int) $response['http_code'], '', false, $reference);
	}

	private function test_destination(string $spreadsheet_id, string $sheet_name): Result
	{
		$valid = $this->validate_destination($spreadsheet_id, $sheet_name);
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$sheets = $this->service->sheets($spreadsheet_id);
		if (is_wp_error($sheets)) return new Result(false, (int) ($sheets->get_error_data()['http_code'] ?? 0), $sheets->get_error_message(), true);
		$exists = array_filter($sheets, static fn (array $sheet): bool => $sheet['title'] === $sheet_name);
		return $exists !== []
			? new Result(true, 200, __('Підключення до Google Sheets успішне.', 'leadforms-go'), false)
			: new Result(false, 404, __('У таблиці немає вказаного аркуша.', 'leadforms-go'), false);
	}

	private function validate_destination(string $spreadsheet_id, string $sheet_name): true|\WP_Error
	{
		if ($spreadsheet_id === '' || $sheet_name === '') return new \WP_Error('missing_settings', __('Потрібні ID таблиці та назва аркуша.', 'leadforms-go'));
		if (! function_exists('openssl_sign')) return new \WP_Error('missing_openssl', __('OpenSSL недоступний на сервері.', 'leadforms-go'));
		$credentials = Google_Credentials::credentials();
		return is_wp_error($credentials) ? $credentials : true;
	}

	private function resolved_settings(array $route): array
	{
		$global = $this->settings();
		$profile = ! empty($route['profile_id']) ? Connection_Profiles::find((string) $route['profile_id'], 'sheets') : null;
		return [
			'spreadsheet_id' => sanitize_text_field((string) (($profile['spreadsheet_id'] ?? '') ?: (($route['spreadsheet_id'] ?? '') !== '' ? $route['spreadsheet_id'] : ($global['spreadsheet_id'] ?? '')))),
			'sheet_name' => sanitize_text_field((string) (($profile['sheet_name'] ?? '') ?: (($route['sheet_name'] ?? '') !== '' ? $route['sheet_name'] : ($global['sheet_name'] ?? '')))),
		];
	}
}
