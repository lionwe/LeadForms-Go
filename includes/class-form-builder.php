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
			if ($name === '' || $label === '') continue;
			$key = sanitize_key((string) ($field['key'] ?? ''));
			if ($key === '') {
				foreach ($tiles as $tile_key => $tile) {
					if ($tile['type'] === $type && $tile['name'] === $name) {
						$key = $tile_key;
						break;
					}
				}
			}
			$key = $key ?: $type;
			$key = str_replace('_', '-', $key ?: $type);
			$key_counts[$key] = ($key_counts[$key] ?? 0) + 1;
			$id = $key . ($key_counts[$key] > 1 ? '-' . $key_counts[$key] : '');
			$clean[] = [
				'id' => sanitize_html_class($id),
				'key' => $key,
				'type' => $type,
				'label' => $label,
				'name' => $name,
				'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
				'required' => ! empty($field['required']),
				'mask' => $type === 'tel' ? sanitize_text_field((string) ($field['mask'] ?? '')) : '',
			];
		}
		return $clean;
	}

	public static function duplicate_names(array $schema): array
	{
		$seen = [];
		$duplicates = [];
		foreach ($schema as $field) {
			$name = isset($field['name']) ? (string) $field['name'] : '';
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

	public static function render(array $schema, string $submit_label, string $instance = ''): string
	{
		$submit_label = sanitize_text_field($submit_label) ?: __('Надіслати', 'leadforms-go');
		$instance = sanitize_html_class($instance);
		$id_prefix = $instance === '' ? 'lfg-' : 'lfg-' . $instance . '-';
		$lines = ['<form>'];
		foreach ($schema as $field) {
			$id = $id_prefix . sanitize_html_class($field['id']);
			$required = $field['required'] ? ' required' : '';
			$required_mark = $field['required'] ? '*' : '';
			if ($field['type'] === 'checkbox') {
				$lines[] = '  <label class="leadforms-go-checkbox" for="' . esc_attr($id) . '">';
				$lines[] = '    <input id="' . esc_attr($id) . '" type="checkbox" name="' . esc_attr($field['name']) . '" value="Так"' . $required . '>';
				$lines[] = '    <span class="leadforms-go-checkbox__label">' . esc_html($field['label'] . $required_mark) . '</span>';
				$lines[] = '  </label>';
				continue;
			}
			$lines[] = '  <label for="' . esc_attr($id) . '">';
			$lines[] = '    <span>' . esc_html($field['label'] . $required_mark) . '</span>';
			if ($field['type'] === 'textarea') {
				$lines[] = '    <textarea id="' . esc_attr($id) . '" name="' . esc_attr($field['name']) . '" placeholder="' . esc_attr($field['placeholder']) . '"' . $required . '></textarea>';
			} else {
				$mask = $field['type'] === 'tel' && $field['mask'] !== '' ? ' data-mask="' . esc_attr($field['mask']) . '" data-min-length="12"' : '';
				$lines[] = '    <input id="' . esc_attr($id) . '" type="' . esc_attr($field['type']) . '" name="' . esc_attr($field['name']) . '" placeholder="' . esc_attr($field['placeholder']) . '"' . $mask . $required . '>';
			}
			$lines[] = '  </label>';
		}
		$lines[] = '  <button class="btn btn--primary" type="submit">';
		$lines[] = '    <span class="btn__text">' . esc_html($submit_label) . '</span>';
		$lines[] = '  </button>';
		$lines[] = '</form>';
		return implode("\n", $lines);
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
		return $tags;
	}
}
