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
		$s = $this->settings();
		return $this->test_credentials((string) ($s['token'] ?? ''), (string) ($s['chat_id'] ?? ''));
	}

	public function test_credentials(string $token, string $chat_id): Result
	{
		$token = substr(sanitize_text_field($token), 0, 256);
		$chat_id = substr(sanitize_text_field($chat_id), 0, 64);
		if ($token === '' || $chat_id === '') return new Result(false, 0, __('Потрібні токен Telegram-бота та ID чату.', 'leadforms-go'), false);

		$bot_response = wp_remote_post($this->endpoint($token, 'getMe'), $this->request_args());
		$bot_result = $this->result($bot_response);
		if (! $bot_result->success) return $bot_result;
		$chat_response = wp_remote_post($this->endpoint($token, 'getChat'), $this->request_args(['body' => ['chat_id' => $chat_id]]));
		$chat_result = $this->result($chat_response);
		if (! $chat_result->success) return $chat_result;

		$key = hash_hmac('sha256', $chat_id . '|' . hash('sha256', $token), wp_salt('auth'));
		$confirmed = (array) get_option('leadforms_go_telegram_confirmed', []);
		$first_success = empty($confirmed[$key]);
		$text = '<b>✅ LeadForms Go підключено</b>' . "\n\n" . 'Тестове повідомлення успішно надіслано з WordPress-сайту <b>' . esc_html((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) . '</b>.';
		if ($first_success) $text .= "\n\n" . '<b>Що далі:</b>' . "\n" . '1. Відкрийте потрібну форму в LeadForms Go.' . "\n" . '2. На вкладці «Інтеграції» увімкніть Telegram.' . "\n" . '3. Налаштуйте шаблон і надішліть тестову заявку.';
		$sent = wp_remote_post($this->endpoint($token, 'sendMessage'), $this->request_args(['body' => ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML']]));
		$sent_result = $this->result($sent);
		if (! $sent_result->success) return $sent_result;
		$confirmed[$key] = time();
		if (count($confirmed) > 50) $confirmed = array_slice($confirmed, -50, null, true);
		update_option('leadforms_go_telegram_confirmed', $confirmed, false);
		return new Result(true, $sent_result->http_code, $first_success ? __('Підключення успішне. У Telegram надіслано підтвердження та коротку інструкцію.', 'leadforms-go') : __('Підключення успішне. У Telegram надіслано тестове підтвердження.', 'leadforms-go'), false);
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
			$text = Submission_Presenter::telegram_message($request->payload, $request->form_id, $locale, $request->referer, (string) ($variables['form_name'] ?? ''));
			$mode = 'plain';
		} else {
			$text = Telegram_Template::render($template, $mode, $variables);
		}
		if ($text === '') return new Result(false, 0, __('Шаблон Telegram сформував порожнє повідомлення.', 'leadforms-go'), false);
		if (! Telegram_Template::within_limit($text)) return new Result(false, 0, __('Повідомлення Telegram після підстановки змінних перевищує 4096 символів.', 'leadforms-go'), false);
		$body = ['chat_id' => $settings['chat_id'], 'text' => $text];
		if ($mode !== 'plain') $body['parse_mode'] = $mode;
		$topic_id = absint(($route['topic_id'] ?? 0) ?: ($settings['topic_id'] ?? 0));
		if ($topic_id > 0) $body['message_thread_id'] = $topic_id;
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
		$profile = ! empty($route['profile_id']) ? Connection_Profiles::find((string) $route['profile_id'], 'telegram') : null;
		return [
			'token' => sanitize_text_field((string) (($profile['token'] ?? '') ?: ($global['token'] ?? ''))),
			'chat_id' => sanitize_text_field((string) (($profile['chat_id'] ?? '') ?: (($route['chat_id'] ?? '') !== '' ? $route['chat_id'] : ($global['chat_id'] ?? '')))),
			'topic_id' => absint($profile['topic_id'] ?? 0),
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
