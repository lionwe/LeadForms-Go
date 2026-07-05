<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Submission_Validator
{
	private const ATTRIBUTION_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	/**
	 * @return array{data: array<string, string>, errors: array<string, string>}
	 */
	public static function validate(array $form, array $submitted, array $messages = []): array
	{
		$schema = [];
		if (($form['editor_mode'] ?? 'code') === 'visual') {
			$decoded = json_decode((string) ($form['form_schema'] ?? ''), true);
			$schema = Form_Builder::sanitize_schema($decoded);
		}

		return $schema === []
			? self::validate_code_form((string) ($form['code'] ?? ''), $submitted, $messages)
			: self::validate_visual_form($schema, $submitted, $messages);
	}

	/**
	 * @return array{data: array<string, string>, errors: array<string, string>}
	 */
	private static function validate_visual_form(array $schema, array $submitted, array $messages): array
	{
		$data = [];
		$errors = [];
		foreach ($schema as $field) {
			$name = (string) $field['key'];
			$value = isset($submitted[$name]) && is_scalar($submitted[$name]) ? trim((string) $submitted[$name]) : '';
			if (($field['type'] ?? '') === 'checkbox') {
				if (! self::is_checked($value)) {
					if (! empty($field['required'])) $errors[$name] = (string) ($messages['required'] ?? __('Заповніть це поле.', 'leadforms-go'));
					continue;
				}
				$value = '1';
			}
			if ($value === '') {
				if (! empty($field['required'])) $errors[$name] = (string) ($messages['required'] ?? __('Заповніть це поле.', 'leadforms-go'));
				continue;
			}
			$error = self::value_error($value, (string) $field['type'], null, $messages);
			if ($error !== '') {
				$errors[$name] = $error;
				continue;
			}
			$data[$name] = sanitize_textarea_field($value);
		}

		self::append_attribution($data, $submitted);
		return ['data' => $data, 'errors' => $errors];
	}

	/**
	 * @return array{data: array<string, string>, errors: array<string, string>}
	 */
	private static function validate_code_form(string $code, array $submitted, array $messages): array
	{
		$data = [];
		$errors = [];
		foreach (self::code_fields($code) as $field) {
			$key = $field['name'];
			$value = isset($submitted[$key]) && is_scalar($submitted[$key]) ? trim((string) $submitted[$key]) : '';
			if ($field['type'] === 'checkbox') {
				if (! self::is_checked($value)) {
					if ($field['required']) $errors[$key] = (string) ($messages['required'] ?? __('Заповніть це поле.', 'leadforms-go'));
					continue;
				}
				$value = '1';
			}
			if ($value === '') {
				if ($field['required']) $errors[$key] = (string) ($messages['required'] ?? __('Заповніть це поле.', 'leadforms-go'));
				continue;
			}
			$error = self::value_error($value, $field['type'], null, $messages);
			if ($error !== '') {
				$errors[$key] = $error;
				continue;
			}
			$data[$key] = sanitize_textarea_field($value);
		}
		self::append_attribution($data, $submitted);
		return ['data' => $data, 'errors' => $errors];
	}

	/** @return array<int, array{name:string, type:string, required:bool}> */
	private static function code_fields(string $code): array
	{
		if (! class_exists('\WP_HTML_Tag_Processor')) return [];
		$processor = new \WP_HTML_Tag_Processor(Form_Builder::sanitize_code($code));
		$fields = [];
		while ($processor->next_tag()) {
			$tag = strtolower((string) $processor->get_tag());
			if (! in_array($tag, ['input', 'textarea', 'select'], true)) continue;
			$name = sanitize_text_field((string) $processor->get_attribute('name'));
			if ($name === '' || strlen($name) > 190 || isset($fields[$name])) continue;
			$type = $tag === 'textarea' ? 'textarea' : sanitize_key((string) $processor->get_attribute('type'));
			if ($tag === 'select') $type = 'text';
			if (in_array($type, ['submit', 'button', 'reset', 'file', 'image'], true)) continue;
			if (! in_array($type, ['tel', 'email', 'textarea', 'checkbox'], true)) $type = 'text';
			$fields[$name] = [
				'name' => $name,
				'type' => $type,
				'required' => $processor->get_attribute('required') !== null,
			];
		}
		return array_values($fields);
	}

	private static function append_attribution(array &$data, array $submitted): void
	{
		foreach (self::ATTRIBUTION_FIELDS as $key) {
			if (! isset($submitted[$key]) || ! is_scalar($submitted[$key])) continue;
			$value = trim((string) $submitted[$key]);
			if ($value !== '') {
				$truncated = function_exists('mb_substr') ? mb_substr($value, 0, 255, 'UTF-8') : substr($value, 0, 255);
				$data[$key] = sanitize_text_field($truncated);
			}
		}
	}

	private static function is_checked(string $value): bool
	{
		return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}

	private static function value_error(string $value, string $type, ?int $maximum = null, array $messages = []): string
	{
		$maximum ??= match ($type) {
			'tel' => 32,
			'textarea' => 1000,
			default => 255,
		};
		$length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
		if ($length > $maximum) return sprintf((string) ($messages['tooLong'] ?? __('Максимальна довжина — %d символів.', 'leadforms-go')), $maximum);
		if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', $value) === 1) return (string) ($messages['emoji'] ?? __('Смайлики використовувати не можна.', 'leadforms-go'));
		if ($type === 'tel' && strlen((string) preg_replace('/\D+/', '', $value)) < 12) return sprintf((string) ($messages['phone'] ?? __('Введіть коректний номер телефону — мінімум %d цифр.', 'leadforms-go')), 12);
		if ($type === 'email' && ! is_email($value)) return (string) ($messages['email'] ?? __('Введіть коректну електронну адресу.', 'leadforms-go'));
		return '';
	}
}
