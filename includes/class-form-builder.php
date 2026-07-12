<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Form_Builder
{
	public const MAX_FIELDS = 30;

	public static function tiles(): array
	{
		return [
			'first_name' => ['type' => 'text', 'label' => __('Ім’я', 'leadforms-go'), 'name' => __('Ім’я', 'leadforms-go'), 'placeholder' => __('Ваше ім’я', 'leadforms-go'), 'required' => true],
			'last_name' => ['type' => 'text', 'label' => __('Прізвище', 'leadforms-go'), 'name' => __('Прізвище', 'leadforms-go'), 'placeholder' => __('Ваше прізвище', 'leadforms-go'), 'required' => false],
			'phone' => ['type' => 'tel', 'label' => __('Номер телефону', 'leadforms-go'), 'name' => __('Номер телефону', 'leadforms-go'), 'placeholder' => __('Номер телефону', 'leadforms-go'), 'required' => true, 'mask' => '+38 (000) 000-00-00'],
			'email' => ['type' => 'email', 'label' => __('Електронна пошта', 'leadforms-go'), 'name' => __('Електронна пошта', 'leadforms-go'), 'placeholder' => 'name@example.com', 'required' => true],
			'company' => ['type' => 'text', 'label' => __('Компанія', 'leadforms-go'), 'name' => __('Компанія', 'leadforms-go'), 'placeholder' => __('Назва компанії', 'leadforms-go'), 'required' => false],
			'city' => ['type' => 'text', 'label' => __('Місто', 'leadforms-go'), 'name' => __('Місто', 'leadforms-go'), 'placeholder' => __('Ваше місто', 'leadforms-go'), 'required' => false],
			'message' => ['type' => 'textarea', 'label' => __('Повідомлення', 'leadforms-go'), 'name' => __('Повідомлення', 'leadforms-go'), 'placeholder' => __('Ваше повідомлення', 'leadforms-go'), 'required' => false],
			'consent' => ['type' => 'checkbox', 'label' => __('Згода на обробку даних', 'leadforms-go'), 'name' => __('Згода на обробку даних', 'leadforms-go'), 'placeholder' => '', 'required' => true],
		];
	}

	public static function sanitize_schema(mixed $schema): array
	{
		if (! is_array($schema)) return [];
		$allowed_types = ['text', 'tel', 'email', 'textarea', 'checkbox'];
		$tiles = self::tiles();
		$clean = [];
		$key_counts = [];
		foreach (array_slice($schema, 0, self::MAX_FIELDS) as $field) {
			if (! is_array($field)) continue;
			$type = sanitize_key((string) ($field['type'] ?? 'text'));
			if (! in_array($type, $allowed_types, true)) $type = 'text';
			$name = sanitize_text_field((string) ($field['name'] ?? ''));
			$label = sanitize_text_field((string) ($field['label'] ?? $name));
			if ($name === '' && $label === '') continue;
			$key = sanitize_key((string) ($field['key'] ?? ''));
			if ($key === '') $key = sanitize_key((string) ($field['id'] ?? ''));
			if ($key === '') {
				foreach ($tiles as $tile_key => $tile) {
					if ($tile['type'] === $type && $tile['name'] === $name) {
						$key = $tile_key;
						break;
					}
				}
			}
			$key = str_replace('-', '_', $key ?: $type);
			$key_counts[$key] = ($key_counts[$key] ?? 0) + 1;
			if ($key_counts[$key] > 1) $key .= '_' . $key_counts[$key];
			$id = str_replace('_', '-', $key);
			$clean[] = [
				'id' => sanitize_html_class($id),
				'key' => $key,
				'type' => $type,
				'label' => $label,
				'name' => $key,
				'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
				'required' => ! empty($field['required']),
				'mask' => $type === 'tel' ? sanitize_text_field((string) ($field['mask'] ?? '')) : '',
			];
		}
		return $clean;
	}

	public static function sanitize_button_icon(mixed $icon): array
	{
		if (! is_array($icon)) {
			return ['type' => 'none', 'position' => 'after', 'fa_class' => '', 'svg' => ''];
		}

		$type = sanitize_key((string) ($icon['type'] ?? 'none'));
		if (! in_array($type, ['none', 'svg', 'fontawesome'], true)) {
			$type = 'none';
		}

		$position = sanitize_key((string) ($icon['position'] ?? 'after'));
		if (! in_array($position, ['before', 'after'], true)) {
			$position = 'after';
		}

		$fa_class = self::sanitize_fontawesome_class((string) ($icon['fa_class'] ?? ''));
		$svg = self::sanitize_svg((string) ($icon['svg'] ?? ''));

		if ($type === 'fontawesome' && $fa_class === '') {
			$type = 'none';
		}

		if ($type === 'svg' && $svg === '') {
			$type = 'none';
		}

		return [
			'type' => $type,
			'position' => $position,
			'fa_class' => $type === 'fontawesome' ? $fa_class : '',
			'svg' => $type === 'svg' ? $svg : '',
		];
	}

	public static function duplicate_names(array $schema): array
	{
		$seen = [];
		$duplicates = [];
		foreach ($schema as $field) {
			$name = isset($field['key']) ? (string) $field['key'] : '';
			if ($name === '') continue;
			if (isset($seen[$name])) $duplicates[$name] = $name;
			$seen[$name] = true;
		}
		return array_values($duplicates);
	}

	public static function sanitize_code(string $code): string
	{
		return wp_kses($code, self::allowed_html());
	}

	public static function secure_transport(string $code): string
	{
		$result = preg_replace_callback('/<form\b([^>]*)>/i', static function (array $matches): string {
			$attributes = preg_replace('/\s+(?:action|method)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $matches[1]);
			return '<form' . ($attributes ?: '') . ' method="post" action="">';
		}, $code);
		return is_string($result) ? $result : $code;
	}

	public static function render(array $schema, string $submit_label, string $instance = '', array $button_icon = []): string
	{
		$submit_label = sanitize_text_field($submit_label) ?: __('Надіслати', 'leadforms-go');
		$instance = sanitize_html_class($instance);
		$id_prefix = $instance === '' ? 'lfg-' : 'lfg-' . $instance . '-';
		$button_icon = self::sanitize_button_icon($button_icon);
		$icon_markup = self::button_icon_markup($button_icon);
		$lines = ['<form method="post" action="">'];
		foreach ($schema as $field) {
			$id = $id_prefix . sanitize_html_class($field['id']);
			$required = $field['required'] ? ' required' : '';
			$required_mark = $field['required'] ? '*' : '';
			if ($field['type'] === 'checkbox') {
				$lines[] = '  <label class="leadforms-go-checkbox" for="' . esc_attr($id) . '">';
				$lines[] = '    <input id="' . esc_attr($id) . '" type="checkbox" name="' . esc_attr($field['key']) . '" value="1"' . $required . '>';
				$lines[] = '    <span class="leadforms-go-checkbox__label">' . esc_html($field['label'] . $required_mark) . '</span>';
				$lines[] = '  </label>';
				continue;
			}
			$lines[] = '  <label for="' . esc_attr($id) . '">';
			$lines[] = '    <span>' . esc_html($field['label'] . $required_mark) . '</span>';
			if ($field['type'] === 'textarea') {
				$lines[] = '    <textarea id="' . esc_attr($id) . '" name="' . esc_attr($field['key']) . '" placeholder="' . esc_attr($field['placeholder']) . '"' . $required . '></textarea>';
			} else {
				$mask = $field['type'] === 'tel' && $field['mask'] !== '' ? ' data-mask="' . esc_attr($field['mask']) . '" data-min-length="12"' : '';
				$lines[] = '    <input id="' . esc_attr($id) . '" type="' . esc_attr($field['type']) . '" name="' . esc_attr($field['key']) . '" placeholder="' . esc_attr($field['placeholder']) . '"' . $mask . $required . '>';
			}
			$lines[] = '  </label>';
		}
		$lines[] = '  <button class="btn btn--primary" type="submit">';
		if ($button_icon['position'] === 'before' && $icon_markup !== '') {
			$lines[] = '    ' . $icon_markup;
		}
		$lines[] = '    <span class="btn__text">' . esc_html($submit_label) . '</span>';
		if ($button_icon['position'] === 'after' && $icon_markup !== '') {
			$lines[] = '    ' . $icon_markup;
		}
		$lines[] = '  </button>';
		$lines[] = '</form>';
		return implode("\n", $lines);
	}

	private static function button_icon_markup(array $button_icon): string
	{
		if ($button_icon['type'] === 'fontawesome' && $button_icon['fa_class'] !== '') {
			return '<span class="btn__icon leadforms-go-button__icon leadforms-go-button__icon--fontawesome" aria-hidden="true"><i class="' . esc_attr($button_icon['fa_class']) . '"></i></span>';
		}

		if ($button_icon['type'] === 'svg' && $button_icon['svg'] !== '') {
			return '<span class="btn__icon leadforms-go-button__icon leadforms-go-button__icon--svg" aria-hidden="true">' . $button_icon['svg'] . '</span>';
		}

		return '';
	}

	private static function sanitize_fontawesome_class(string $class): string
	{
		$tokens = preg_split('/\s+/', trim($class)) ?: [];
		$allowed = [];
		foreach ($tokens as $token) {
			$token = sanitize_html_class($token);
			if ($token === '') {
				continue;
			}
			if (in_array($token, ['fa', 'fas', 'far', 'fab', 'fal', 'fa-solid', 'fa-regular', 'fa-brands'], true) || str_starts_with($token, 'fa-')) {
				$allowed[] = $token;
			}
		}
		$allowed = array_values(array_unique(array_slice($allowed, 0, 6)));
		$has_icon = (bool) array_filter($allowed, static fn (string $token): bool => str_starts_with($token, 'fa-') && ! in_array($token, ['fa-solid', 'fa-regular', 'fa-brands'], true));
		if (! $has_icon) {
			return '';
		}
		if (! array_intersect($allowed, ['fa', 'fas', 'far', 'fab', 'fal', 'fa-solid', 'fa-regular', 'fa-brands'])) {
			array_unshift($allowed, 'fa-solid');
		}
		return implode(' ', $allowed);
	}

	private static function sanitize_svg(string $svg): string
	{
		$svg = trim($svg);
		if ($svg === '' || stripos($svg, '<svg') === false || strlen($svg) > 8000) {
			return '';
		}

		$allowed = [
			'svg' => ['class' => true, 'aria-hidden' => true, 'focusable' => true, 'height' => true, 'role' => true, 'viewbox' => true, 'viewBox' => true, 'width' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'xmlns' => true],
			'g' => ['fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true],
			'path' => ['d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true],
			'circle' => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true],
			'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true],
			'line' => ['x1' => true, 'x2' => true, 'y1' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true],
			'polyline' => ['points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true],
			'polygon' => ['points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true],
		];

		$clean = wp_kses($svg, $allowed);
		return str_contains(strtolower($clean), '<svg') ? $clean : '';
	}

	private static function allowed_html(): array
	{
		$tags = wp_kses_allowed_html('post');
		$common = ['class' => true, 'id' => true, 'aria-label' => true, 'aria-describedby' => true, 'aria-invalid' => true];
		$tags['form'] = $common + ['action' => true, 'method' => true, 'novalidate' => true, 'autocomplete' => true];
		$tags['input'] = $common + [
			'type' => true,
			'name' => true,
			'value' => true,
			'placeholder' => true,
			'required' => true,
			'checked' => true,
			'disabled' => true,
			'autocomplete' => true,
			'pattern' => true,
			'minlength' => true,
			'maxlength' => true,
			'data-mask' => true,
			'data-min-length' => true,
			'data-max-length' => true,
			'data-error-message' => true,
		];
		$tags['textarea'] = $common + ['name' => true, 'placeholder' => true, 'required' => true, 'disabled' => true, 'minlength' => true, 'maxlength' => true];
		$tags['select'] = $common + ['name' => true, 'required' => true, 'disabled' => true, 'multiple' => true];
		$tags['option'] = ['value' => true, 'selected' => true, 'disabled' => true];
		$tags['button'] = $common + ['type' => true, 'name' => true, 'value' => true, 'disabled' => true];
		$tags['span'] = $common + ['aria-hidden' => true];
		$tags['i'] = ['class' => true, 'aria-hidden' => true];
		$tags['svg'] = ['class' => true, 'aria-hidden' => true, 'focusable' => true, 'height' => true, 'role' => true, 'viewbox' => true, 'viewBox' => true, 'width' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'xmlns' => true];
		$tags['g'] = ['fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true];
		$tags['path'] = ['d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true];
		$tags['circle'] = ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true];
		$tags['rect'] = ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true];
		$tags['line'] = ['x1' => true, 'x2' => true, 'y1' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true];
		$tags['polyline'] = ['points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true];
		$tags['polygon'] = ['points' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true];
		return $tags;
	}
}
