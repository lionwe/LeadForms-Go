<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Telegram_Interactions
{
	public const REMINDER_HOOK = 'leadforms_go_telegram_reminder';
	public const SITE_TRANSFER_ALERT_HOOK = 'leadforms_go_site_transfer_alert';
	public const SITE_TRANSFER_LOCK_OPTION = 'leadforms_go_site_transfer_lock';
	private const REMINDER_DELAY = 30 * MINUTE_IN_SECONDS;
	private const API_TIMEOUT = 12;
	private const SITE_TRANSFER_LOCK_TTL = 300;

	public static function boot(): void
	{
		add_action('rest_api_init', [self::class, 'register_route']);
		add_action('leadforms_go_delivery_processed', [self::class, 'delivery_processed'], 10, 3);
		add_action(self::REMINDER_HOOK, [self::class, 'send_reminder']);
		add_action(self::SITE_TRANSFER_ALERT_HOOK, [self::class, 'send_site_transfer_alert']);
	}

	public static function send_site_transfer_alert(): void
	{
		if (! self::acquire_site_transfer_lock()) return;
		try {
			self::send_site_transfer_alert_locked();
		} finally {
			delete_option(self::SITE_TRANSFER_LOCK_OPTION);
		}
	}

	private static function send_site_transfer_alert_locked(): void
	{
		$transfer = Database::site_transfer_notice();
		if ($transfer === [] || ($transfer['telegram_alert_status'] ?? '') !== 'pending') return;

		$settings = Settings::section('telegram');
		$token = sanitize_text_field((string) ($settings['token'] ?? ''));
		$chat_id = sanitize_text_field((string) ($settings['chat_id'] ?? ''));
		if ($token === '' || $chat_id === '') {
			foreach (Connection_Profiles::all('telegram') as $profile) {
				$token = sanitize_text_field((string) ($profile['token'] ?? ''));
				$chat_id = sanitize_text_field((string) ($profile['chat_id'] ?? ''));
				if ($token !== '' && $chat_id !== '') break;
			}
		}
		if ($token === '' || $chat_id === '') {
			$transfer['telegram_alert_status'] = 'unavailable';
			update_option('leadforms_go_site_transfer', $transfer, false);
			return;
		}

		$previous = esc_html((string) ($transfer['previous'] ?? ''));
		$current = esc_html((string) ($transfer['current'] ?? ''));
		$settings_url = esc_url(admin_url('admin.php?page=leadforms-go-settings'));
		$text = sprintf(
			__('<b>LeadForms Go: інтеграції вимкнено</b>' . "\n\n" . 'Виявлено зміну домену сайту: %1$s → %2$s.' . "\n" . 'Активні доставки скасовано: %3$d.' . "\n\n" . 'Перейдіть у <a href="%4$s">налаштування інтеграцій</a> в адмінці WordPress і ввімкніть їх знову.', 'leadforms-go'),
			$previous,
			$current,
			(int) ($transfer['cancelled'] ?? 0),
			$settings_url
		);
		$result = self::api($token, 'sendMessage', [
			'chat_id' => $chat_id,
			'text' => $text,
			'parse_mode' => 'HTML',
			'disable_web_page_preview' => true,
		]);
		$attempts = absint($transfer['telegram_alert_attempts'] ?? 0) + 1;
		$transfer['telegram_alert_attempts'] = $attempts;
		$transfer['telegram_alert_status'] = is_wp_error($result) ? ($attempts >= 3 ? 'failed' : 'pending') : 'sent';
		$transfer['telegram_alert_at'] = time();
		update_option('leadforms_go_site_transfer', $transfer, false);
		if (is_wp_error($result) && $attempts < 3 && ! wp_next_scheduled(self::SITE_TRANSFER_ALERT_HOOK)) {
			wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::SITE_TRANSFER_ALERT_HOOK);
		}
	}

	private static function acquire_site_transfer_lock(): bool
	{
		$now = time();
		if (add_option(self::SITE_TRANSFER_LOCK_OPTION, $now, '', false)) return true;
		$locked_at = (int) get_option(self::SITE_TRANSFER_LOCK_OPTION, 0);
		if ($locked_at <= 0 || $now - $locked_at < self::SITE_TRANSFER_LOCK_TTL) return false;
		delete_option(self::SITE_TRANSFER_LOCK_OPTION);
		return add_option(self::SITE_TRANSFER_LOCK_OPTION, $now, '', false);
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
		return [
			[
				['text' => __('Взяти в роботу', 'leadforms-go'), 'callback_data' => self::callback_data('a', $delivery_id, $token), 'style' => 'success'],
				['text' => __('Передзвонити пізніше', 'leadforms-go'), 'callback_data' => self::callback_data('l', $delivery_id, $token)],
			],
			[
				['text' => __('Відхилити', 'leadforms-go'), 'callback_data' => self::callback_data('r', $delivery_id, $token), 'style' => 'primary'],
				['text' => __('Спам', 'leadforms-go'), 'callback_data' => self::callback_data('s', $delivery_id, $token), 'style' => 'danger'],
			],
		];
	}

	public static function status_button(int $delivery_id, string $token): array
	{
		return [[
			['text' => __('Змінити статус', 'leadforms-go'), 'callback_data' => self::callback_data('m', $delivery_id, $token), 'style' => 'primary'],
		]];
	}

	public static function utility_buttons(string $phone, string $source_url): array
	{
		$row = [];
		if ($phone !== '') {
			$row[] = [
				'text' => __('Скопіювати номер', 'leadforms-go'),
				'copy_text' => ['text' => substr(sanitize_text_field($phone), 0, 256)],
			];
		}
		$source_url = esc_url_raw($source_url, ['http', 'https']);
		if ($source_url !== '' && wp_http_validate_url($source_url)) {
			$row[] = ['text' => __('Відкрити сторінку', 'leadforms-go'), 'url' => $source_url, 'style' => 'primary'];
		}
		return $row === [] ? [] : [$row];
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
		if (! preg_match('/^lfg:([acdflmnpqrsx]):(\d+):([a-f0-9]{12})$/', $data, $match)) {
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
		$persistent = is_array($meta['custom_keyboard'] ?? null) ? $meta['custom_keyboard'] : [];
		$actor = self::actor($callback);
		$owner_id = (string) ($submission['assigned_to_telegram_id'] ?? '');
		if ($owner_id !== '' && ! hash_equals($owner_id, $actor['id']) && in_array($action, ['m', 'n', 'l', 'f', 'r', 's', 'd', 'c', 'x'], true)) {
			self::answer($token, $callback_id, __('Змінювати статус може лише поточний відповідальний. Запросіть передачу заявки.', 'leadforms-go'), true);
			return new \WP_REST_Response(['ok' => true], 200);
		}
		if ($action === 's') {
			$confirm = [[
				['text' => __('Підтвердити спам', 'leadforms-go'), 'callback_data' => self::callback_data('c', $delivery_id, $token), 'style' => 'danger'],
				['text' => __('Скасувати', 'leadforms-go'), 'callback_data' => self::callback_data('x', $delivery_id, $token)],
			]];
			self::edit_keyboard($token, $chat_id, $message_id, array_merge($persistent, $confirm));
			self::answer($token, $callback_id, __('Підтвердьте позначення як спам.', 'leadforms-go'));
		} elseif ($action === 'r') {
			$confirm = [[
				['text' => __('Підтвердити відхилення', 'leadforms-go'), 'callback_data' => self::callback_data('d', $delivery_id, $token), 'style' => 'primary'],
				['text' => __('Скасувати', 'leadforms-go'), 'callback_data' => self::callback_data('x', $delivery_id, $token)],
			]];
			self::edit_keyboard($token, $chat_id, $message_id, array_merge($persistent, $confirm));
			self::answer($token, $callback_id, __('Підтвердьте відхилення заявки.', 'leadforms-go'));
		} elseif ($action === 'm') {
			$menu = self::status_menu($delivery_id, $token);
			if (! empty($submission['transfer_requested_by_telegram_id'])) $menu = array_merge($menu, self::transfer_decision_buttons($delivery_id, $token));
			self::edit_keyboard($token, $chat_id, $message_id, array_merge($persistent, $menu));
			self::answer($token, $callback_id, __('Оберіть новий статус.', 'leadforms-go'));
		} elseif ($action === 'x') {
			$keyboard = self::keyboard_for_state((string) ($submission['lead_state'] ?? 'new'), $delivery_id, $token);
			if (! empty($submission['transfer_requested_by_telegram_id']) && (string) ($submission['assigned_to_telegram_id'] ?? '') === $actor['id']) $keyboard = array_merge($keyboard, self::transfer_decision_buttons($delivery_id, $token));
			self::edit_keyboard($token, $chat_id, $message_id, array_merge($persistent, $keyboard));
			self::answer($token, $callback_id, __('Скасовано.', 'leadforms-go'));
		} elseif ($action === 'a') {
			$claim = Repositories::claim_lead((int) $submission['id'], $actor['label'], $actor['id']);
			if ($claim['status'] === 'claimed') {
				self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'acknowledged', $claim['owner'], array_merge($persistent, self::status_button($delivery_id, $token)));
				self::answer($token, $callback_id, __('Заявку взято в роботу.', 'leadforms-go'));
			} elseif (in_array($claim['status'], ['transfer_requested', 'transfer_pending'], true)) {
				self::edit_keyboard($token, $chat_id, $message_id, array_merge($persistent, self::status_button($delivery_id, $token), self::transfer_decision_buttons($delivery_id, $token)));
				if ($claim['status'] === 'transfer_requested') self::notify_transfer_request($token, $chat_id, $message_id, $submission, $actor['label'], $claim['owner']);
				self::answer($token, $callback_id, $claim['status'] === 'transfer_requested'
					? sprintf(__('Заявка вже у менеджера %s. Йому надіслано запит на передачу.', 'leadforms-go'), $claim['owner'])
					: __('Запит на передачу вже очікує рішення поточного менеджера.', 'leadforms-go'), true);
			} elseif ($claim['status'] === 'already_owner') {
				self::answer($token, $callback_id, __('Ви вже відповідальний за цю заявку.', 'leadforms-go'));
			} else {
				self::answer($token, $callback_id, __('Не вдалося взяти заявку в роботу. Оновіть повідомлення та спробуйте ще раз.', 'leadforms-go'), true);
			}
		} elseif ($action === 'p' || $action === 'q') {
			$resolved = $action === 'p'
				? Repositories::approve_lead_transfer((int) $submission['id'], $actor['label'], $actor['id'])
				: Repositories::reject_lead_transfer((int) $submission['id'], $actor['label'], $actor['id']);
			if ($resolved['status'] === 'approved') {
				self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'acknowledged', $resolved['owner'], array_merge($persistent, self::status_button($delivery_id, $token)));
				self::answer($token, $callback_id, sprintf(__('Передачу погоджено. Тепер відповідальний: %s.', 'leadforms-go'), $resolved['owner']));
			} elseif ($resolved['status'] === 'rejected') {
				self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'acknowledged', $resolved['owner'], array_merge($persistent, self::status_button($delivery_id, $token)));
				self::answer($token, $callback_id, __('Запит на передачу відхилено.', 'leadforms-go'));
			} else {
				self::answer($token, $callback_id, $resolved['status'] === 'unauthorized'
					? __('Погодити або відхилити передачу може лише поточний менеджер.', 'leadforms-go')
					: __('Запит на передачу вже неактуальний.', 'leadforms-go'), true);
			}
		} elseif ($action === 'n') {
			Repositories::set_lead_state((int) $submission['id'], 'new', $actor['label'], $actor['id']);
			self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'new', $actor['label'], array_merge($persistent, self::action_buttons($delivery_id, $token)));
			self::answer($token, $callback_id, __('Статус заявки скинуто.', 'leadforms-go'));
		} elseif ($action === 'l') {
			Repositories::set_lead_state((int) $submission['id'], 'follow_up', $actor['label'], $actor['id']);
			self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'follow_up', $actor['label'], array_merge($persistent, self::status_button($delivery_id, $token)));
			self::answer($token, $callback_id, __('Заявку відкладено для повторного дзвінка.', 'leadforms-go'));
		} elseif ($action === 'f') {
			Repositories::set_lead_state((int) $submission['id'], 'completed', $actor['label'], $actor['id']);
			self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'completed', $actor['label'], array_merge($persistent, self::status_button($delivery_id, $token)));
			self::answer($token, $callback_id, __('Роботу із заявкою завершено.', 'leadforms-go'));
		} elseif ($action === 'd') {
			Repositories::set_lead_state((int) $submission['id'], 'rejected', $actor['label'], $actor['id']);
			self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'rejected', $actor['label'], array_merge($persistent, self::status_button($delivery_id, $token)));
			self::answer($token, $callback_id, __('Заявку відхилено.', 'leadforms-go'));
		} elseif ($action === 'c') {
			Repositories::set_lead_state((int) $submission['id'], 'spam', $actor['label'], $actor['id']);
			self::edit_submission_message($token, $chat_id, $message_id, $submission, $meta, 'spam', $actor['label'], array_merge($persistent, self::status_button($delivery_id, $token)));
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

	private static function status_menu(int $delivery_id, string $token): array
	{
		return [
			[
				['text' => __('Скинути статус', 'leadforms-go'), 'callback_data' => self::callback_data('n', $delivery_id, $token)],
				['text' => __('У роботі', 'leadforms-go'), 'callback_data' => self::callback_data('a', $delivery_id, $token), 'style' => 'success'],
			],
			[
				['text' => __('Передзвонити пізніше', 'leadforms-go'), 'callback_data' => self::callback_data('l', $delivery_id, $token)],
				['text' => __('Завершити', 'leadforms-go'), 'callback_data' => self::callback_data('f', $delivery_id, $token), 'style' => 'success'],
			],
			[
				['text' => __('Відхилити', 'leadforms-go'), 'callback_data' => self::callback_data('r', $delivery_id, $token), 'style' => 'primary'],
				['text' => __('Спам', 'leadforms-go'), 'callback_data' => self::callback_data('s', $delivery_id, $token), 'style' => 'danger'],
			],
			[
				['text' => __('Скасувати', 'leadforms-go'), 'callback_data' => self::callback_data('x', $delivery_id, $token)],
			],
		];
	}

	private static function transfer_decision_buttons(int $delivery_id, string $token): array
	{
		return [[
			['text' => __('Погодити передачу', 'leadforms-go'), 'callback_data' => self::callback_data('p', $delivery_id, $token), 'style' => 'success'],
			['text' => __('Відхилити передачу', 'leadforms-go'), 'callback_data' => self::callback_data('q', $delivery_id, $token), 'style' => 'danger'],
		]];
	}

	private static function notify_transfer_request(string $token, string $chat_id, int $message_id, array $submission, string $requester, string $owner): void
	{
		$owner_id = preg_replace('/[^0-9-]/', '', (string) ($submission['assigned_to_telegram_id'] ?? '')) ?: '';
		$owner_mention = $owner_id !== ''
			? '<a href="tg://user?id=' . esc_attr($owner_id) . '">' . esc_html($owner) . '</a>'
			: esc_html($owner);
		$text = sprintf(
			__('Менеджер %1$s просить передати заявку #%2$d. Поточний відповідальний: %3$s. Рішення доступне на початковому повідомленні.', 'leadforms-go'),
			esc_html($requester),
			(int) ($submission['id'] ?? 0),
			$owner_mention
		);
		self::api($token, 'sendMessage', [
			'chat_id' => $chat_id,
			'text' => $text,
			'parse_mode' => 'HTML',
			'reply_parameters' => wp_json_encode(['message_id' => $message_id]),
			'disable_web_page_preview' => true,
		]);
	}

	private static function keyboard_for_state(string $state, int $delivery_id, string $token): array
	{
		return $state === 'new' ? self::action_buttons($delivery_id, $token) : self::status_button($delivery_id, $token);
	}

	private static function edit_submission_message(string $token, string $chat_id, int $message_id, array $submission, array $meta, string $state, string $actor, array $keyboard): void
	{
		if (empty($meta['default_message'])) {
			self::edit_keyboard($token, $chat_id, $message_id, $keyboard);
			return;
		}
		$payload = json_decode((string) ($submission['payload'] ?? ''), true);
		if (! is_array($payload)) {
			self::edit_keyboard($token, $chat_id, $message_id, $keyboard);
			return;
		}
		$text = Submission_Presenter::telegram_message_for_state(
			$payload,
			(int) ($submission['form_id'] ?? 0),
			(string) ($submission['locale'] ?? ''),
			(string) ($submission['referer'] ?? ''),
			(string) ($submission['form_name'] ?? ''),
			Repositories::repeat_submission_count((int) ($submission['id'] ?? 0)),
			$state,
			$actor
		);
		self::api($token, 'editMessageText', [
			'chat_id' => $chat_id,
			'message_id' => $message_id,
			'text' => $text,
			'parse_mode' => 'HTML',
			'link_preview_options' => wp_json_encode(['is_disabled' => true]),
			'reply_markup' => wp_json_encode(['inline_keyboard' => $keyboard]),
		]);
	}

	/** @return array{label:string,id:string} */
	private static function actor(array $callback): array
	{
		$from = is_array($callback['from'] ?? null) ? $callback['from'] : [];
		$username = sanitize_key((string) ($from['username'] ?? ''));
		$label = $username !== '' ? '@' . $username : trim(implode(' ', array_filter([
			sanitize_text_field((string) ($from['first_name'] ?? '')),
			sanitize_text_field((string) ($from['last_name'] ?? '')),
		])));
		return [
			'label' => $label !== '' ? $label : __('Невідомий користувач Telegram', 'leadforms-go'),
			'id' => substr(preg_replace('/[^0-9-]/', '', (string) ($from['id'] ?? '')) ?: '', 0, 32),
		];
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
