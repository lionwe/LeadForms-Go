<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Telegram_Template
{
	private const MAX_LENGTH = 4096;
	private const MODES = ['plain', 'HTML', 'MarkdownV2'];

	public static function sanitize_mode(string $mode): string
	{
		return in_array($mode, self::MODES, true) ? $mode : 'plain';
	}

	public static function sanitize_template(string $template): string
	{
		$template = trim(wp_unslash($template));
		$template = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $template);
		return $template;
	}

	public static function sanitize_buttons(mixed $buttons): array
	{
		if (! is_array($buttons)) return [];
		$clean = [];
		foreach (array_slice($buttons, 0, 5) as $button) {
			if (! is_array($button)) continue;
			$label = sanitize_text_field((string) ($button['label'] ?? ''));
			$url = trim((string) ($button['url'] ?? ''));
			if ($label === '' || $url === '') continue;
			$clean[] = ['label' => self::truncate($label, 64), 'url' => self::truncate($url, 1000)];
		}
		return $clean;
	}

	public static function validate(string $template, string $mode, array $allowed_variables): true|\WP_Error
	{
		if (self::length($template) > self::MAX_LENGTH) return new \WP_Error('template_too_long', __('Шаблон Telegram перевищує 4096 символів.', 'leadforms-go'));
		foreach (self::variables($template) as $variable) {
			if (! in_array($variable, $allowed_variables, true)) return new \WP_Error('unknown_variable', sprintf(__('Невідома змінна Telegram: {%s}.', 'leadforms-go'), $variable));
		}
		if (self::sanitize_mode($mode) === 'HTML' && self::sanitize_html($template) !== $template) {
			return new \WP_Error('invalid_html', __('HTML-шаблон Telegram містить непідтримувані теги або атрибути.', 'leadforms-go'));
		}
		if (self::sanitize_mode($mode) === 'MarkdownV2' && ! self::valid_markdown($template)) {
			return new \WP_Error('invalid_markdown', __('Перевірте парні дужки, посилання та екранування у MarkdownV2-шаблоні.', 'leadforms-go'));
		}
		return true;
	}

	public static function validate_buttons(array $buttons, array $allowed_variables): true|\WP_Error
	{
		foreach ($buttons as $button) {
			if (! is_array($button)) continue;
			foreach (self::variables((string) ($button['url'] ?? '')) as $variable) {
				if (! in_array($variable, $allowed_variables, true)) {
					return new \WP_Error('unknown_button_variable', sprintf(__('Невідома змінна URL-кнопки Telegram: {%s}.', 'leadforms-go'), $variable));
				}
			}
		}
		return true;
	}

	public static function render(string $template, string $mode, array $variables): string
	{
		$mode = self::sanitize_mode($mode);
		$rendered = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $match) use ($variables, $mode): string {
			$value = is_scalar($variables[$match[1]] ?? null) ? (string) $variables[$match[1]] : '';
			$value = self::truncate(sanitize_textarea_field($value), 1000);
			return match ($mode) {
				'HTML' => esc_html($value),
				'MarkdownV2' => self::escape_markdown($value),
				default => $value,
			};
		}, $template);
		$rendered = is_string($rendered) ? $rendered : '';
		if ($mode === 'HTML') $rendered = self::sanitize_html($rendered);
		return $rendered;
	}

	public static function within_limit(string $value): bool
	{
		return self::length($value) <= self::MAX_LENGTH;
	}

	public static function fit_plain(string $value): string
	{
		return self::truncate($value, self::MAX_LENGTH);
	}

	public static function render_buttons(array $buttons, array $variables): array
	{
		$rows = [];
		foreach (self::sanitize_buttons($buttons) as $button) {
			$url = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static fn (array $match): string => rawurlencode(is_scalar($variables[$match[1]] ?? null) ? (string) $variables[$match[1]] : ''), $button['url']);
			$url = is_string($url) ? esc_url_raw($url, ['http', 'https']) : '';
			if ($url !== '' && wp_http_validate_url($url)) $rows[] = [['text' => $button['label'], 'url' => $url]];
		}
		return $rows;
	}

	public static function variables(string $template): array
	{
		preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $template, $matches);
		return array_values(array_unique(array_map('sanitize_key', $matches[1] ?? [])));
	}

	private static function sanitize_html(string $html): string
	{
		return wp_kses($html, [
			'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'ins' => [], 's' => [], 'strike' => [], 'del' => [],
			'span' => ['class' => true], 'tg-spoiler' => [], 'a' => ['href' => true], 'code' => [], 'pre' => [], 'blockquote' => ['expandable' => true],
		]);
	}

	private static function escape_markdown(string $value): string
	{
		return (string) preg_replace('/([_\*\[\]\(\)~`>#+\-=|{}.!])/', '\\\\$1', $value);
	}

	private static function valid_markdown(string $template): bool
	{
		if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*\\\\$/', $template)) return false;
		$plain = (string) preg_replace('/\\\\./u', '', $template);
		$plain = (string) preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $plain);
		return substr_count($plain, '[') === substr_count($plain, ']')
			&& substr_count($plain, '(') === substr_count($plain, ')')
			&& substr_count($plain, '`') % 2 === 0;
	}

	private static function truncate(string $value, int $maximum): string
	{
		return self::length($value) <= $maximum ? $value : (function_exists('mb_substr') ? mb_substr($value, 0, $maximum, 'UTF-8') : substr($value, 0, $maximum));
	}

	private static function length(string $value): int
	{
		return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
	}
}
