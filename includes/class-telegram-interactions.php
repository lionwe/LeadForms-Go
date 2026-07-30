<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Telegram_Interactions
{
	public const REMINDER_HOOK = 'leadforms_go_telegram_reminder';
	private const REMINDER_DELAY = 30 * MINUTE_IN_SECONDS;
	private const API_TIMEOUT = 12;

	public static function boot(): void
	{
		add_action('rest_api_init', [self::class, 'register_route']);
		add_action('leadforms_go_delivery_processed', [self::class, 'delivery_processed'], 10, 3);
		add_action(self::REMINDER_HOOK, [self::class, 'send_reminder']);
	}

	public static function register_route(): void
	{
		register_rest_route('leadforms-go/v1', '/telegram/(?P<bot_key>[a-f0-9]{16})', [
			'methods' => 'POST',
			'callback' => [self::class, 'webhook'],
			'permission_callback' => '__return_true',
		]);
	}

	public static function bot_key(string $token): string
	{
		return substr(hash_hmac('sha256', 'bot|' . $token, wp_salt('auth')), 0, 16);
	}

	public static function ensure_webhook(string $token, bool $replace = false): true|\WP_Error
	{
		$token = sanitize_text_field($token);
		if ($token === '') return new \WP_Error('telegram_token_missing', __('Не вказано токен Telegram-бота.', 'leadforms-go'));
		if (! wp_is_using_https() || ! str_starts_with(rest_url(), 'https://')) {
			return new \WP_Error('telegram_https_required', __('Інтерактивний Telegram-режим потребує публічного HTTPS URL. Звичайна доставка продовжить працювати.', 'leadforms-go'));
		}
		$key = self::bot_key($token);
		$target = rest_url('leadforms-go/v1/telegram/' . $key);
		if (get_transient('leadforms_go_tg_webhook_' . $key) === hash('sha256', $target)) return true;
		$info = self::api($token, 'getWebhookInfo');
		if (is_wp_error($info)) return $info;
		$current = esc_url_raw((string) ($info['result']['url'] ?? ''));
		if ($current !== '' && untrailingslashit($current) !== untrailingslashit($target) && ! $replace) {
			return new \WP_Error('telegram_webhook_conflict', __('Для цього бота вже налаштовано інший webhook. Увімкніть явну заміну webhook і повторіть перевірку.', 'leadforms-go'));
		}
		$result = self::api($token, 'setWebhook', [
			'url' => $target,
			'secret_token' => self::webhook_secret($token),
			'allowed_updates' => wp_json_encode(['callback_query']),
			'drop_pending_updates' => false,
		]);
		if (is_wp_error($result)) return $result;
		set_transient('leadforms_go_tg_webhook_' . $key, hash('sha256', $target), 12 * HOUR_IN_SECONDS);
		return true;
	}

	public static function action_buttons(int $delivery_id, string $token): array
	{
		return [[
			['text' => __('Взяти в роботу', 'leadforms-go'), 'callback_data' => self::callback_data('a', $delivery_id, $token)],
			['text' => __('Спам', 'leadforms-go'), 'callback_data' => self::callback_data('s', $delivery_id, $token)],
		]];
	}

	public static function delivery_processed(int $delivery_id, string $status, Result $result): void
	{
		if ($status !== 'sent' || empty($result->external_meta['interactive'])) return;
		$delivery = Repositories::delivery($delivery_id);
		$submission = is_array($delivery) ? Repositories::submission((int) $delivery['submission_id']) : null;
		if (! is_array($submission) || ! empty($submission['is_test'])) return;
		if (! wp_next_scheduled(self::REMINDER_HOOK, [$delivery_id])) {
			wp_schedule_single_event(time() + self::REMINDER_DELAY, self::REMINDER_HOOK, [$delivery_id]);
		}
	}

	public static function send_reminder(int $delivery_id): void
	{
		$delivery = Repositories::delivery($delivery_id);
		if (! is_array($delivery) || $delivery['status'] !== 'sent' || ! empty($delivery['telegram_reminder_sent_at'])) return;
		$submission = Repositories::submission((int) $delivery['submission_id']);
		if (! is_array($submission) || ! empty($submission['is_test']) || ($submission['lead_state'] ?? 'new') !== 'new') return;
		$meta = json_decode((string) ($delivery['external_meta'] ?? ''), true);
		if (! is_array($meta) || empty($meta['interactive'])) return;
		$token = self::token_for_key((string) ($meta['bot_key'] ?? ''));
		if ($token === '' || ! Repositories::mark_telegram_reminded($delivery_id)) return;
		$body = [
			'chat_id' => (string) ($meta['chat_id'] ?? ''),
			'text' => sprintf(__('Нагадування: заявку #%d ще не взято в роботу.', 'leadforms-go'), (int) $delivery['submission_id']),
		];
		if (! empty($meta['message_id'])) $body['reply_parameters'] = wp_json_encode(['message_id' => (int) $meta['message_id']]);
		if (! empty($meta['topic_id'])) $body['message_thread_id'] = (int) $meta['topic_id'];
		$result = self::api($token, 'sendMessage', $body);
		if (is_wp_error($result)) {
			self::reset_reminder($delivery_id);
			wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::REMINDER_HOOK, [$delivery_id]);
		}
	}

	public static function webhook(\WP_REST_Request $request): \WP_REST_Response
	{
		$key = sanitize_key((string) $request['bot_key']);
		$token = self::token_for_key($key);
		if ($token === '') return new \WP_REST_Response(['ok' => false], 404);
		$provided_secret = (string) $request->get_header('x-telegram-bot-api-secret-token');
		if ($provided_secret === '' || ! hash_equals(self::webhook_secret($token), $provided_secret)) {
			return new \WP_REST_Response(['ok' => false], 403);
		}
		$update = $request->get_json_params();
		$callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : [];
		$callback_id = sanitize_text_field((string) ($callback['id'] ?? ''));
		$data = sanitize_text_field((string) ($callback['data'] ?? ''));
		if ($callback_id === '') return new \WP_REST_Response(['ok' => true], 200);
		if (! preg_match('/^lfg:([ascx]):(\d+):([a-f0-9]{12})$/', $data, $match)) {
			self::answer($token, $callback_id, __('Некоректна дія.', 'leadforms-go'), true);
			return new \WP_REST_Response(['ok' => true], 200);
		}
		$action = $match[1];
		$delivery_id = absint($match[2]);
		if (! hash_equals(self::callback_signature($action, $delivery_id, $token), $match[3])) {
			self::answer($token, $callback_id, __('Некоректна дія.', 'leadforms-go'), true);
			return new \WP_REST_Response(['ok' => true], 200);
		}
		$delivery = Repositories::delivery($delivery_id);
		$meta = is_array($delivery) ? json_decode((string) ($delivery['external_meta'] ?? ''), true) : [];
		$message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
		$chat_id = (string) ($message['chat']['id'] ?? '');
		$message_id = absint($message['message_id'] ?? 0);
		if (! is_array($delivery) || ! is_array($meta) || ($meta['bot_key'] ?? '') !== $key || (string) ($meta['chat_id'] ?? '') !== $chat_id || (int) ($meta['message_id'] ?? 0) !== $message_id) {
			self::answer($token, $callback_id, __('Ця дія більше недоступна.', 'leadforms-go'), true);
			return new \WP_REST_Response(['ok' => true], 200);
		}
		$submission = Repositories::submission((int) $delivery['submission_id']);
		if (! is_array($submission)) {
			self::answer($token, $callback_id, __('Заявку не знайдено.', 'leadforms-go'), true);
			return new \WP_REST_Response(['ok' => true], 200);
		}
		$custom = is_array($meta['custom_keyboard'] ?? null) ? $meta['custom_keyboard'] : [];
		if ($action === 's') {
			$confirm = [[
				['text' => __('Підтвердити спам', 'leadforms-go'), 'callback_data' => self::callback_data('c', $delivery_id, $token)],
				['text' => __('Скасувати', 'leadforms-go'), 'callback_data' => self::callback_data('x', $delivery_id, $token)],
			]];
			self::edit_keyboard($token, $chat_id, $message_id, array_merge($custom, $confirm));
			self::answer($token, $callback_id, __('Підтвердьте позначення як спам.', 'leadforms-go'));
		} elseif ($action === 'x') {
			self::edit_keyboard($token, $chat_id, $message_id, array_merge($custom, self::action_buttons($delivery_id, $token)));
			self::answer($token, $callback_id, __('Скасовано.', 'leadforms-go'));
		} elseif ($action === 'a') {
			if (($submission['lead_state'] ?? 'new') === 'spam') self::answer($token, $callback_id, __('Заявку вже позначено як спам.', 'leadforms-go'), true);
			elseif (($submission['lead_state'] ?? 'new') === 'acknowledged') self::answer($token, $callback_id, __('Заявку вже взято в роботу.', 'leadforms-go'));
			else {
				Repositories::set_lead_state((int) $submission['id'], 'acknowledged');
				self::edit_keyboard($token, $chat_id, $message_id, $custom);
				self::answer($token, $callback_id, __('Заявку взято в роботу.', 'leadforms-go'));
			}
		} elseif ($action === 'c') {
			if (($submission['lead_state'] ?? 'new') !== 'spam') Repositories::set_lead_state((int) $submission['id'], 'spam');
			self::edit_keyboard($token, $chat_id, $message_id, $custom);
			self::answer($token, $callback_id, __('Заявку позначено як спам.', 'leadforms-go'));
		}
		return new \WP_REST_Response(['ok' => true], 200);
	}

	private static function callback_data(string $action, int $delivery_id, string $token): string
	{
		return 'lfg:' . $action . ':' . $delivery_id . ':' . self::callback_signature($action, $delivery_id, $token);
	}

	private static function callback_signature(string $action, int $delivery_id, string $token): string
	{
		return substr(hash_hmac('sha256', $action . '|' . $delivery_id . '|' . self::bot_key($token), wp_salt('auth')), 0, 12);
	}

	private static function webhook_secret(string $token): string
	{
		return hash_hmac('sha256', 'webhook|' . hash('sha256', $token), wp_salt('secure_auth'));
	}

	private static function token_for_key(string $key): string
	{
		$global = (string) (Settings::section('telegram')['token'] ?? '');
		if ($global !== '' && hash_equals(self::bot_key($global), $key)) return $global;
		foreach (Connection_Profiles::all('telegram') as $profile) {
			$token = (string) ($profile['token'] ?? '');
			if ($token !== '' && hash_equals(self::bot_key($token), $key)) return $token;
		}
		return '';
	}

	private static function edit_keyboard(string $token, string $chat_id, int $message_id, array $keyboard): void
	{
		self::api($token, 'editMessageReplyMarkup', [
			'chat_id' => $chat_id,
			'message_id' => $message_id,
			'reply_markup' => wp_json_encode(['inline_keyboard' => $keyboard]),
		]);
	}

	private static function answer(string $token, string $callback_id, string $text, bool $alert = false): void
	{
		self::api($token, 'answerCallbackQuery', [
			'callback_query_id' => $callback_id,
			'text' => $text,
			'show_alert' => $alert,
			'cache_time' => 0,
		]);
	}

	private static function api(string $token, string $method, array $body = []): array|\WP_Error
	{
		$response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method, [
			'timeout' => self::API_TIMEOUT,
			'redirection' => 0,
			'limit_response_size' => 262144,
			'sslverify' => true,
			'body' => $body,
		]);
		if (is_wp_error($response)) return new \WP_Error('telegram_request_failed', __('Не вдалося з’єднатися з Telegram.', 'leadforms-go'));
		$decoded = json_decode(wp_remote_retrieve_body($response), true);
		if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || ! is_array($decoded) || empty($decoded['ok'])) {
			return new \WP_Error('telegram_api_error', sanitize_text_field((string) ($decoded['description'] ?? __('Telegram відхилив запит.', 'leadforms-go'))));
		}
		return $decoded;
	}

	private static function reset_reminder(int $delivery_id): void
	{
		global $wpdb;
		$wpdb->update(Database::tables()['deliveries'], ['telegram_reminder_sent_at' => null], ['id' => $delivery_id], ['%s'], ['%d']);
	}
}
