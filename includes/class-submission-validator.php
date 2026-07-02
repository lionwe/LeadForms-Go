<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Submission_Validator
{
	private const ATTRIBUTION_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	/**
	 * @return array{data: array<string, string>, errors: array<string, string>}
	 */
	public static function validate(array $form, array $submitted): array
	{
		$schema = [];
		if (($form['editor_mode'] ?? 'code') === 'visual') {
			$decoded = json_decode((string) ($form['form_schema'] ?? ''), true);
			$schema = Form_Builder::sanitize_schema($decoded);
		}

		return $schema === []
			? self::validate_code_form($submitted)
			: self::validate_visual_form($schema, $submitted);
	}

	/**
	 * @return array{data: array<string, string>, errors: array<string, string>}
	 */
	private static function validate_visual_form(array $schema, array $submitted): array
	{
		$data = [];
		$errors = [];
		foreach ($schema as $field) {
			$name = (string) $field['name'];
			$value = isset($submitted[$name]) && is_scalar($submitted[$name]) ? trim((string) $submitted[$name]) : '';
			if ($value === '') {
				if (! empty($field['required'])) $errors[$name] = __('Заповніть це поле.', 'leadforms-go');
				continue;
			}
			$error = self::value_error($value, (string) $field['type']);
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
	private static function validate_code_form(array $submitted): array
	{
		$data = [];
		$errors = [];
		foreach ($submitted as $raw_key => $raw_value) {
			if (! is_scalar($raw_value)) continue;
			$key = sanitize_text_field((string) $raw_key);
			if ($key === '' || strlen($key) > 190) continue;
			$value = trim((string) $raw_value);
			$type = preg_match('/phone|tel|телефон|номер/iu', $key) === 1 ? 'tel' : (preg_match('/e-?mail|пошт/iu', $key) === 1 ? 'email' : 'text');
			$error = self::value_error($value, $type, $type === 'text' ? 1000 : null);
			if ($error !== '') {
				$errors[$key] = $error;
				continue;
			}
			$data[$key] = sanitize_textarea_field($value);
		}
		return ['data' => $data, 'errors' => $errors];
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

	private static function value_error(string $value, string $type, ?int $maximum = null): string
	{
		$maximum ??= match ($type) {
			'tel' => 32,
			'textarea' => 1000,
			default => 255,
		};
		$length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
		if ($length > $maximum) return sprintf(__('Максимальна довжина — %d символів.', 'leadforms-go'), $maximum);
		if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', $value) === 1) return __('Смайлики використовувати не можна.', 'leadforms-go');
		if ($type === 'tel' && strlen((string) preg_replace('/\D+/', '', $value)) < 12) return __('Введіть коректний номер телефону — мінімум 12 цифр.', 'leadforms-go');
		if ($type === 'email' && ! is_email($value)) return __('Введіть коректну електронну адресу.', 'leadforms-go');
		return '';
	}
}
