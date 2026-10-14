<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Telegram_Message_Formatter
{
	private const MAX_LENGTH = 4096;
	private const ATTRIBUTION_KEYS = [
		'landing_page', 'document_referrer', 'visited_at', 'utm_source', 'utm_medium', 'utm_campaign',
		'utm_term', 'utm_content', 'utm_id', 'gclid', 'fbclid', 'ttclid',
	];
	private const EMPTY_STRINGS = ['', '-', 'null', 'false', 'undefined'];

	/**
	 * @param array<int,array{key:string,label:string,type:string,value:mixed,raw_value:mixed}> $fields
	 * @param array{locale?:string,form_name?:string,source_url?:string,document_referrer?:string,attribution?:array,repeat_count?:int,state?:string,actor?:string} $context
	 */
	public static function format(array $fields, array $context = []): string
	{
		$locale = (string) ($context['locale'] ?? Form_Translations::DEFAULT_LOCALE);
		$english = str_starts_with(strtolower($locale), 'en');
		$labels = self::labels($english);
		$data = self::partition($fields);
		$form_name = self::is_empty($context['form_name'] ?? '') ? '' : self::clean_value($context['form_name'] ?? '', 250);
		if ($form_name !== '') {
			array_unshift($data['details'], ['label' => $labels['form'], 'value' => $form_name]);
		}

		$source_url = self::valid_url((string) ($context['source_url'] ?? ''));
		$referrer = self::valid_url((string) ($context['document_referrer'] ?? ''));
		$attribution = self::attribution((array) ($context['attribution'] ?? []));
		$source_label = self::source_label($attribution, $referrer, $source_url, $english);
		$direct_source = self::is_direct_source($attribution, $referrer, $source_url);
		$state = (string) ($context['state'] ?? 'new');
		if (! in_array($state, ['new', 'acknowledged', 'follow_up', 'completed', 'rejected', 'spam'], true)) $state = 'new';
		$repeat_count = max(0, (int) ($context['repeat_count'] ?? 0));
		$actor = self::is_empty($context['actor'] ?? '') ? '' : self::clean_value($context['actor'] ?? '', 250);
		$comment_limit = 1800;
		$utm = $attribution;
		$include_source_link = ! $direct_source;

		$render = static function () use (&$data, $labels, $state, $repeat_count, $actor, &$source_label, $source_url, &$utm, &$comment_limit, &$include_source_link): string {
			return self::render(
				$data,
				$labels,
				$state,
				$repeat_count,
				$actor,
				$source_label,
				$include_source_link ? $source_url : '',
				$utm,
				$comment_limit
			);
		};
		$message = $render();
		foreach ([1000, 500, 250, 0] as $limit) {
			if (self::length($message) <= self::MAX_LENGTH) break;
			$comment_limit = $limit;
			$message = $render();
		}
		while (self::length($message) > self::MAX_LENGTH && $data['details'] !== []) {
			array_pop($data['details']);
			$message = $render();
		}
		while (self::length($message) > self::MAX_LENGTH && $data['channels'] !== []) {
			array_pop($data['channels']);
			$message = $render();
		}
		while (self::length($message) > self::MAX_LENGTH && $utm !== []) {
			array_pop($utm);
			$message = $render();
		}
		if (self::length($message) > self::MAX_LENGTH && $source_url !== '') {
			$include_source_link = false;
			$message = $render();
		}
		if (self::length($message) > self::MAX_LENGTH && $source_label !== '') {
			$source_label = '';
			$message = $render();
		}
		return $message;
	}

	public static function source_url(array $payload, string $referer): string
	{
		return self::valid_url((string) ($payload['landing_page'] ?? $referer));
	}

	/** @param array<int,array{key:string,label:string,type:string,value:mixed,raw_value:mixed}> $fields */
	public static function copy_phone(array $fields): string
	{
		foreach ($fields as $field) {
			if (! self::is_phone($field) || self::is_empty($field['raw_value'] ?? null)) continue;
			$value = self::clean_value($field['value'] ?? '', 64);
			$digits = preg_replace('/\D+/', '', $value);
			if (! is_string($digits) || $digits === '') return '';
			return str_starts_with(trim($value), '+') ? '+' . $digits : $digits;
		}
		return '';
	}

	private static function partition(array $fields): array
	{
		$data = ['names' => [], 'phone' => '', 'email' => '', 'channels' => [], 'details' => [], 'comments' => []];
		foreach ($fields as $field) {
			$key = strtolower((string) ($field['key'] ?? ''));
			if (in_array($key, self::ATTRIBUTION_KEYS, true) || self::technical($key) || self::legal_field($field)) continue;
			if (self::is_empty($field['raw_value'] ?? null)) continue;
			$value = self::clean_value($field['value'] ?? '', self::is_comment($field) ? 4000 : 500);
			if ($value === '') continue;
			$label = self::clean_value($field['label'] ?? '', 120);
			if ($label === '') $label = ucfirst(trim(str_replace(['_', '-'], ' ', $key)));
			if (self::is_name($field)) {
				$data['names'][] = ['label' => $label, 'value' => self::clean_value($value, 250)];
			} elseif (self::is_phone($field) && $data['phone'] === '') {
				$data['phone'] = self::clean_value($value, 64);
			} elseif (self::is_email($field) && $data['email'] === '') {
				$data['email'] = self::clean_value($value, 254);
			} elseif (self::is_channel($field)) {
				$data['channels'][] = ['label' => $label, 'value' => $value];
			} elseif (self::is_comment($field)) {
				$data['comments'][] = ['label' => $label, 'value' => $value];
			} else {
				$data['details'][] = ['label' => $label, 'value' => $value];
			}
		}
		return $data;
	}

	private static function render(array $data, array $labels, string $state, int $repeat_count, string $actor, string $source_label, string $source_url, array $utm, int $comment_limit): string
	{
		$title = match ($state) {
			'acknowledged' => $labels['working_title'],
			'follow_up' => $labels['follow_up_title'],
			'completed' => $labels['completed_title'],
			'rejected' => $labels['rejected_title'],
			'spam' => $labels['spam_title'],
			default => $labels['new_title'],
		};
		$sections = ['<b>' . self::escape($title) . '</b>'];
		if ($state === 'new' && $repeat_count > 1) {
			$sections[0] .= "\n<i>" . self::escape(self::repeat_label($labels, $repeat_count)) . '</i>';
		}
		if ($actor !== '') {
			$actor_label = match ($state) {
				'acknowledged' => $labels['taken_by'],
				'follow_up' => $labels['follow_up_by'],
				'completed' => $labels['completed_by'],
				'rejected' => $labels['rejected_by'],
				'spam' => $labels['spam_by'],
				default => $labels['reopened_by'],
			};
			$sections[0] .= "\n<b>" . self::escape($actor_label) . ':</b> ' . self::escape($actor);
		}

		$contact = [];
		foreach ($data['names'] as $name) {
			$contact[] = self::escape($name['label']) . ': <b>' . self::escape($name['value']) . '</b>';
		}
		if ($data['names'] === [] && ($data['phone'] !== '' || $data['email'] !== '' || $data['channels'] !== [])) $contact[] = '<b>' . self::escape($labels['contacts']) . '</b>';
		if ($data['phone'] !== '') $contact[] = self::escape($labels['phone']) . ': <code>' . self::escape($data['phone']) . '</code>';
		if ($data['email'] !== '') $contact[] = 'Email: ' . self::escape($data['email']);
		foreach ($data['channels'] as $channel) $contact[] = self::escape($channel['label']) . ': ' . self::escape($channel['value']);
		if ($contact !== []) $sections[] = implode("\n", $contact);

		if ($data['details'] !== []) {
			$lines = ['<b>' . self::escape($labels['details']) . '</b>'];
			foreach ($data['details'] as $detail) $lines[] = self::escape($detail['label']) . ': ' . self::escape($detail['value']);
			$sections[] = implode("\n", $lines);
		}
		if ($comment_limit > 0) {
			foreach ($data['comments'] as $comment) {
				$value = self::truncate($comment['value'], $comment_limit);
				if ($value === '') continue;
				$label = $comment['label'] !== '' ? $comment['label'] : $labels['comment'];
				$sections[] = '<b>' . self::escape($label) . "</b>\n<blockquote>" . self::escape($value) . '</blockquote>';
			}
		}

		if ($source_label !== '' || $source_url !== '' || $utm !== []) {
			$lines = ['<b>' . self::escape($labels['source']) . '</b>'];
			if ($source_label !== '') $lines[] = self::escape($source_label);
			if ($source_url !== '') $lines[] = '<a href="' . esc_attr($source_url) . '">' . self::escape($labels['open_page']) . '</a>';
			$sections[] = implode("\n", $lines);
		}
		if ($utm !== []) {
			$lines = ['<b>' . self::escape($labels['campaign']) . '</b>'];
			foreach ($utm as $key => $value) $lines[] = self::escape(self::utm_label((string) $key)) . ': ' . self::escape((string) $value);
			$sections[] = '<blockquote expandable>' . implode("\n", $lines) . '</blockquote>';
		}
		return implode("\n\n", array_filter($sections));
	}

	private static function labels(bool $english): array
	{
		return $english ? [
			'new_title' => 'New submission', 'working_title' => 'Submission in progress', 'follow_up_title' => 'Call back later', 'completed_title' => 'Submission completed', 'rejected_title' => 'Submission rejected', 'spam_title' => 'Submission marked as spam',
			'repeat_one' => 'Repeat inquiry · %d submission', 'repeat_few' => 'Repeat inquiry · %d submissions', 'repeat_many' => 'Repeat inquiry · %d submissions',
			'taken_by' => 'Taken by', 'follow_up_by' => 'Callback scheduled by', 'completed_by' => 'Completed by', 'rejected_by' => 'Rejected by', 'spam_by' => 'Marked as spam by', 'reopened_by' => 'Reopened by',
			'contacts' => 'Contact details', 'name' => 'Name', 'phone' => 'Phone number', 'details' => 'Inquiry details', 'form' => 'Form', 'comment' => 'Comment',
			'source' => 'Source', 'open_page' => 'Open page', 'campaign' => 'Advertising campaign data',
		] : [
			'new_title' => 'Нова заявка', 'working_title' => 'Заявка в роботі', 'follow_up_title' => 'Передзвонити пізніше', 'completed_title' => 'Заявку опрацьовано', 'rejected_title' => 'Заявку відхилено', 'spam_title' => 'Заявку позначено як спам',
			'repeat_one' => 'Повторне звернення · %d заявка', 'repeat_few' => 'Повторне звернення · %d заявки', 'repeat_many' => 'Повторне звернення · %d заявок',
			'taken_by' => 'Взяв у роботу', 'follow_up_by' => 'Запланував повторний дзвінок', 'completed_by' => 'Завершив роботу', 'rejected_by' => 'Відхилив заявку', 'spam_by' => 'Позначив як спам', 'reopened_by' => 'Скинув статус',
			'contacts' => 'Контактні дані', 'name' => 'Ім’я', 'phone' => 'Номер телефону', 'details' => 'Деталі звернення', 'form' => 'Форма', 'comment' => 'Коментар',
			'source' => 'Джерело', 'open_page' => 'Відкрити сторінку', 'campaign' => 'Дані рекламної кампанії',
		];
	}

	private static function repeat_label(array $labels, int $count): string
	{
		$last_two = $count % 100;
		$last = $count % 10;
		$key = $last === 1 && $last_two !== 11 ? 'repeat_one' : (in_array($last, [2, 3, 4], true) && ! in_array($last_two, [12, 13, 14], true) ? 'repeat_few' : 'repeat_many');
		return sprintf((string) $labels[$key], $count);
	}

	private static function source_label(array $attribution, string $referrer, string $source_url, bool $english): string
	{
		$source = strtolower((string) ($attribution['utm_source'] ?? ''));
		$medium = strtolower((string) ($attribution['utm_medium'] ?? ''));
		$sources = $english
			? ['ig' => 'Instagram', 'instagram' => 'Instagram', 'fb' => 'Facebook', 'facebook' => 'Facebook', 'google' => 'Google', 'direct' => 'Direct visit', 'organic' => 'Organic search']
			: ['ig' => 'Instagram', 'instagram' => 'Instagram', 'fb' => 'Facebook', 'facebook' => 'Facebook', 'google' => 'Google', 'direct' => 'Прямий перехід', 'organic' => 'Органічний пошук'];
		$media = $english
			? ['paid' => 'paid advertising', 'cpc' => 'paid advertising', 'organic' => 'organic visit', 'referral' => 'referral', 'email' => 'email campaign']
			: ['paid' => 'платна реклама', 'cpc' => 'платна реклама', 'organic' => 'органічний перехід', 'referral' => 'перехід з іншого сайту', 'email' => 'email-розсилка'];
		$parts = [];
		if ($source !== '') $parts[] = $sources[$source] ?? (string) ($attribution['utm_source'] ?? '');
		if ($medium !== '') $parts[] = $media[$medium] ?? (string) ($attribution['utm_medium'] ?? '');
		if ($parts !== []) return implode(' · ', array_filter($parts));
		$referrer_host = strtolower((string) wp_parse_url($referrer, PHP_URL_HOST));
		$source_host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
		if ($referrer_host !== '' && ($source_host === '' || ! hash_equals($source_host, $referrer_host))) {
			$host = (string) wp_parse_url($referrer, PHP_URL_HOST);
			if ($host !== '') return $host;
		}
		return $source_url !== '' ? ($english ? 'Direct visit' : 'Прямий перехід') : '';
	}

	private static function is_direct_source(array $attribution, string $referrer, string $source_url): bool
	{
		if (strtolower((string) ($attribution['utm_source'] ?? '')) === 'direct') return true;
		if ($attribution !== []) return false;
		$referrer_host = strtolower((string) wp_parse_url($referrer, PHP_URL_HOST));
		$source_host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
		return $referrer_host === '' || ($source_host !== '' && hash_equals($source_host, $referrer_host));
	}

	private static function attribution(array $values): array
	{
		$clean = [];
		foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'] as $key) {
			if (self::is_empty($values[$key] ?? '')) continue;
			$value = self::clean_value($values[$key] ?? '', 255);
			if ($value !== '') $clean[$key] = $value;
		}
		return $clean;
	}

	private static function utm_label(string $key): string
	{
		return match ($key) {
			'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign',
			'utm_term' => 'UTM term', 'utm_content' => 'UTM content', 'utm_id' => 'UTM ID', default => $key,
		};
	}

	private static function is_name(array $field): bool
	{
		$key = strtolower(str_replace('-', '_', (string) ($field['key'] ?? '')));
		if (in_array($key, ['name', 'first_name', 'firstname', 'last_name', 'lastname', 'middle_name', 'middlename', 'patronymic', 'full_name', 'fullname', 'surname'], true)) return true;
		return preg_match('/^(ім.?я|прізвище|по батькові|піб|name|first name|last name|full name|surname)$/iu', trim((string) ($field['label'] ?? ''))) === 1;
	}

	private static function is_phone(array $field): bool
	{
		return ($field['type'] ?? '') === 'tel' || self::matches($field, '/phone|telephone|mobile|tel|телефон|номер/iu');
	}

	private static function is_email(array $field): bool
	{
		return ($field['type'] ?? '') === 'email' || self::matches($field, '/e-?mail|електронн/iu');
	}

	private static function is_channel(array $field): bool
	{
		return self::matches($field, '/telegram|viber|whats\s*app/iu');
	}

	private static function is_comment(array $field): bool
	{
		return self::matches($field, '/comment|message|note|question|коментар|повідомлення|питання|примітк/iu');
	}

	private static function matches(array $field, string $pattern): bool
	{
		return preg_match($pattern, (string) ($field['key'] ?? '') . ' ' . (string) ($field['label'] ?? '')) === 1;
	}

	private static function technical(string $key): bool
	{
		return preg_match('/(^|_)(ip|ip_address|user_agent|nonce|token|session|session_id|hmac|fingerprint|request_id)($|_)/i', $key) === 1;
	}

	private static function legal_field(array $field): bool
	{
		$key = strtolower(str_replace('-', '_', (string) ($field['key'] ?? '')));
		if (preg_match('/(^|_)(consent|gdpr|terms|privacy|agreement)($|_)/i', $key) === 1) return true;
		return preg_match('/згода|персональн.*дан|політик.*конфіденцій|умов.*використан/iu', (string) ($field['label'] ?? '')) === 1;
	}

	private static function is_empty(mixed $value): bool
	{
		if ($value === null || $value === false || is_array($value) && $value === []) return true;
		if (! is_scalar($value)) return true;
		return in_array(strtolower(trim((string) $value)), self::EMPTY_STRINGS, true);
	}

	private static function clean_value(mixed $value, int $maximum): string
	{
		if (! is_scalar($value)) return '';
		$value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);
		$value = trim((string) preg_replace('/\R{3,}/u', "\n\n", $value));
		return self::truncate($value, $maximum);
	}

	private static function valid_url(string $url): string
	{
		$url = esc_url_raw($url, ['http', 'https']);
		return $url !== '' && wp_http_validate_url($url) ? $url : '';
	}

	private static function escape(string $value): string
	{
		return esc_html($value);
	}

	private static function truncate(string $value, int $maximum): string
	{
		if ($maximum < 1) return '';
		if (self::length($value) <= $maximum) return $value;
		$cut = function_exists('mb_substr') ? mb_substr($value, 0, max(1, $maximum - 1), 'UTF-8') : substr($value, 0, max(1, $maximum - 1));
		return rtrim($cut) . '…';
	}

	private static function length(string $value): int
	{
		return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
	}
}
