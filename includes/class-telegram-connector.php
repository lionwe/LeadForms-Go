<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Telegram_Connector extends Abstract_Connector implements Contextual_Connector_Interface
{
	public function key(): string { return 'telegram'; }

	public function validate_settings(): true|\WP_Error
	{
		$s = $this->settings();
		return ! empty($s['token']) && ! empty($s['chat_id']) ? true : new \WP_Error('missing_settings', __('Потрібні токен Telegram-бота та ID чату.', 'leadforms-go'));
	}

	public function validate_route(array $route): true|\WP_Error
	{
		$settings = $this->resolved_settings($route);
		if ($settings['token'] === '' || $settings['chat_id'] === '') return new \WP_Error('missing_settings', __('Потрібні токен Telegram-бота та ID чату.', 'leadforms-go'));
		// Form-aware template variables are validated by Route_Config before this
		// connector receives the sanitized route. Revalidating here without the
		// form schema would incorrectly reject valid field placeholders.
		return true;
	}

	public function test_connection(): Result
	{
		$valid = $this->validate_settings();
		if (is_wp_error($valid)) return new Result(false, 0, $valid->get_error_message(), false);
		$s = $this->settings();
		return $this->result(wp_remote_post($this->endpoint((string) $s['token'], 'getChat'), $this->request_args(['body' => ['chat_id' => $s['chat_id']]])));
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
		$route = $request->route;
		$settings = $this->resolved_settings($route);
		if ($settings['token'] === '' || $settings['chat_id'] === '') return new Result(false, 0, __('Потрібні токен Telegram-бота та ID чату.', 'leadforms-go'), false);
		$variables = $request->variables();
		$locale = Form_Translations::normalize_locale($request->locale) ?: Form_Translations::DEFAULT_LOCALE;
		$template = $this->localized((array) ($route['templates'] ?? []), $locale);
		$mode = Telegram_Template::sanitize_mode((string) ($route['parse_mode'] ?? 'plain'));
		if ($template === '') {
			$presented = Submission_Presenter::for_telegram($request->payload, $request->form_id, $locale);
			$lines = [__('Нова заявка з форми:', 'leadforms-go')];
			foreach ($presented as $key => $value) $lines[] = sanitize_text_field((string) $key) . ': ' . sanitize_textarea_field((string) $value);
			if ($request->referer !== '') $lines[] = __('Джерело:', 'leadforms-go') . ' ' . esc_url_raw($request->referer);
			$text = Telegram_Template::fit_plain(implode("\n", $lines));
			$mode = 'plain';
		} else {
			$text = Telegram_Template::render($template, $mode, $variables);
		}
		if ($text === '') return new Result(false, 0, __('Шаблон Telegram сформував порожнє повідомлення.', 'leadforms-go'), false);
		if (! Telegram_Template::within_limit($text)) return new Result(false, 0, __('Повідомлення Telegram після підстановки змінних перевищує 4096 символів.', 'leadforms-go'), false);
		$body = ['chat_id' => $settings['chat_id'], 'text' => $text];
		if ($mode !== 'plain') $body['parse_mode'] = $mode;
		if (! empty($route['topic_id'])) $body['message_thread_id'] = absint($route['topic_id']);
		$buttons = Telegram_Template::render_buttons($this->localized_buttons((array) ($route['buttons'] ?? []), $locale), $variables);
		if ($buttons !== []) $body['reply_markup'] = wp_json_encode(['inline_keyboard' => $buttons]);
		$response = wp_remote_post($this->endpoint($settings['token'], 'sendMessage'), $this->request_args(['body' => $body]));
		$response_body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
		$message_id = is_array($response_body) ? absint($response_body['result']['message_id'] ?? 0) : 0;
		$chat_id = is_array($response_body) ? (string) ($response_body['result']['chat']['id'] ?? '') : '';
		$username = is_array($response_body) ? sanitize_key((string) ($response_body['result']['chat']['username'] ?? '')) : '';
		$reference = $message_id > 0 ? 'message:' . $message_id : '';
		if ($message_id > 0 && $username !== '') $reference = 'https://t.me/' . rawurlencode($username) . '/' . $message_id;
		elseif ($message_id > 0 && str_starts_with($chat_id, '-100')) $reference = 'https://t.me/c/' . rawurlencode(substr($chat_id, 4)) . '/' . $message_id;
		return $this->result($response, $reference);
	}

	private function resolved_settings(array $route): array
	{
		$global = $this->settings();
		return [
			'token' => sanitize_text_field((string) ($global['token'] ?? '')),
			'chat_id' => sanitize_text_field((string) (($route['chat_id'] ?? '') !== '' ? $route['chat_id'] : ($global['chat_id'] ?? ''))),
		];
	}

	private function endpoint(string $token, string $method): string
	{
		return 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;
	}

	private function localized(array $values, string $locale): string
	{
		$value = $values[$locale] ?? $values[Form_Translations::DEFAULT_LOCALE] ?? reset($values) ?: '';
		return is_scalar($value) ? (string) $value : '';
	}

	private function localized_buttons(array $values, string $locale): array
	{
		$value = $values[$locale] ?? $values[Form_Translations::DEFAULT_LOCALE] ?? reset($values) ?: [];
		return is_array($value) ? $value : [];
	}
}
