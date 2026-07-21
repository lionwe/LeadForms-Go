<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Crm_Connector extends Abstract_Connector implements Contextual_Connector_Interface
{
	public function key(): string { return 'crm'; }

	public function validate_settings(): true|\WP_Error
	{
		$s = $this->settings();
		return ! empty($s['partner_id']) && ! empty($s['token']) ? true : new \WP_Error('missing_settings', __('Потрібні ID партнера та токен CRM.', 'leadforms-go'));
	}

	public function validate_route(array $route): true|\WP_Error
	{
		$s = $this->resolved_settings($route);
		return $s['partner_id'] !== '' && $s['token'] !== '' ? true : new \WP_Error('missing_settings', __('Потрібні ID партнера та токен CRM.', 'leadforms-go'));
	}

	public function test_connection(): Result
	{
		$valid = $this->validate_settings();
		return is_wp_error($valid) ? new Result(false, 0, $valid->get_error_message(), false) : new Result(true, 0, __('Налаштування CRM заповнені.', 'leadforms-go'));
	}

	public function test_route(array $route, array $payload, int $form_id, string $locale): Result
	{
		return $this->send_request(new Delivery_Request(0, 0, $form_id, $locale, $payload, home_url('/'), $route));
	}

	public function send(array $data, string $referer): Result
	{
		return $this->send_request(new Delivery_Request(0, 0, 0, Form_Translations::DEFAULT_LOCALE, $data, $referer, []));
	}

	public function send_request(Delivery_Request $request): Result
	{
		$valid = $this->validate_route($request->route);
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$s = $this->resolved_settings($request->route);
		$variables = $request->variables();
		$mapping = is_array($request->route['mapping'] ?? null) ? $request->route['mapping'] : [];
		if ($mapping !== []) {
			$mapped = [];
			foreach ($mapping as $target => $source) $mapped[$target] = $variables[$source] ?? '';
			$data = $mapped;
		} else {
			$data = $request->payload;
		}
		$name_parts = [];
		$phone = '';
		$notes = [];
		foreach ($data as $key => $value) {
			if (preg_match('/телефон|номер|phone|tel/iu', (string) $key)) $phone = sanitize_text_field((string) $value);
			elseif (preg_match('/ім.?я|прізвище|(^|[_\s-])(first[_\s-]?name|last[_\s-]?name|name)($|[_\s-])/iu', (string) $key)) $name_parts[] = sanitize_text_field((string) $value);
			else $notes[] = sanitize_text_field((string) $key) . ': ' . sanitize_textarea_field((string) $value);
		}
		if ($request->referer !== '') $notes[] = __('Джерело:', 'leadforms-go') . ' ' . sanitize_url($request->referer);
		$adv_id = sanitize_text_field((string) (! empty($request->route['profile_id']) ? ($s['adv_id'] ?? '') : (($request->route['adv_id'] ?? '') !== '' ? $request->route['adv_id'] : ($s['adv_id'] ?? ''))));
		$body = ['action' => 'partner-custom-form', 'partner_id' => $s['partner_id'], 'token' => $s['token'], 'adv_id' => $adv_id, 'name' => implode(' ', array_filter($name_parts)), 'phone' => $phone, 'note' => implode("\n", $notes)];
		$response = wp_remote_post('https://crm.g-plus.app/api/actions', $this->request_args(['body' => $body]));
		$response_body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
		$external_id = is_array($response_body) ? sanitize_text_field((string) ($response_body['lead_id'] ?? $response_body['id'] ?? $response_body['data']['id'] ?? '')) : '';
		return $this->result($response, $external_id !== '' ? 'lead:' . $external_id : '');
	}

	private function resolved_settings(array $route): array
	{
		$global = $this->settings();
		$profile = ! empty($route['profile_id']) ? Connection_Profiles::find((string) $route['profile_id'], 'crm') : null;
		return [
			'partner_id' => sanitize_text_field((string) (($profile['partner_id'] ?? '') ?: ($global['partner_id'] ?? ''))),
			'token' => sanitize_text_field((string) (($profile['token'] ?? '') ?: ($global['token'] ?? ''))),
			'adv_id' => sanitize_text_field((string) (($profile['adv_id'] ?? '') ?: ($global['adv_id'] ?? ''))),
		];
	}
}
