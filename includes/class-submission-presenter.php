<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Submission_Presenter
{
	private static array $forms = [];

	public static function for_telegram(array $payload, int $form_id, string $locale): array
	{
		return self::present($payload, $form_id, $locale);
	}

	public static function for_telegram_variables(array $payload, int $form_id, string $locale): array
	{
		$fields = self::translated_fields($form_id, $locale);
		if ($fields === []) return $payload;

		foreach ($payload as $key => &$value) {
			$field = $fields[(string) $key] ?? null;
			if (is_array($field) && in_array($field['type'] ?? '', ['select', 'radio'], true)) {
				$value = self::localized_value($value, $field, $locale);
			}
		}
		unset($value);
		return $payload;
	}

	public static function for_admin(array $payload, int $form_id, string $locale): array
	{
		return self::present($payload, $form_id, $locale);
	}

	public static function telegram_message(array $payload, int $form_id, string $locale, string $referer, string $form_name = ''): string
	{
		return self::telegram_message_for_state($payload, $form_id, $locale, $referer, $form_name);
	}

	public static function telegram_message_for_state(
		array $payload,
		int $form_id,
		string $locale,
		string $referer,
		string $form_name = '',
		int $repeat_count = 0,
		string $state = 'new',
		string $responsible = ''
	): string {
		return Telegram_Message_Formatter::format(self::telegram_fields($payload, $form_id, $locale), [
			'locale' => $locale,
			'form_name' => $form_name,
			'source_url' => Telegram_Message_Formatter::source_url($payload, $referer),
			'document_referrer' => (string) ($payload['document_referrer'] ?? ''),
			'attribution' => $payload,
			'repeat_count' => $repeat_count,
			'state' => $state,
			'responsible' => $responsible,
		]);
	}

	public static function telegram_fields(array $payload, int $form_id, string $locale): array
	{
		$fields = self::translated_fields($form_id, $locale);
		$presented = [];
		foreach ($payload as $key => $raw_value) {
			$field = $fields[(string) $key] ?? [];
			$presented[] = [
				'key' => (string) $key,
				'label' => ! empty($field['label']) ? (string) $field['label'] : self::humanize_key((string) $key),
				'type' => (string) ($field['type'] ?? ''),
				'value' => $field !== [] ? self::localized_value($raw_value, $field, $locale) : $raw_value,
				'raw_value' => $raw_value,
			];
		}
		return $presented;
	}

	private static function present(array $payload, int $form_id, string $locale): array
	{
		$fields = self::translated_fields($form_id, $locale);
		if ($fields === []) return $payload;

		$presented = [];
		foreach ($payload as $key => $value) {
			$field = $fields[(string) $key] ?? null;
			$label = is_array($field) && ! empty($field['label']) ? (string) $field['label'] : self::humanize_key((string) $key);
			if (is_array($field)) $value = self::localized_value($value, $field, $locale);
			$unique_label = $label;
			$suffix = 2;
			while (array_key_exists($unique_label, $presented)) $unique_label = $label . ' (' . $suffix++ . ')';
			$presented[$unique_label] = $value;
		}
		return $presented;
	}

	private static function translated_fields(int $form_id, string $locale): array
	{
		if ($form_id > 0 && ! array_key_exists($form_id, self::$forms)) self::$forms[$form_id] = Repositories::form($form_id);
		$form = $form_id > 0 ? self::$forms[$form_id] : null;
		if (! is_array($form) || ($form['editor_mode'] ?? '') !== 'visual') return [];

		$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
		$schema = Form_Builder::sanitize_schema($schema);
		if ($schema === []) return [];

		$default_locale = Form_Translations::normalize_locale((string) ($form['default_locale'] ?? '')) ?: Form_Translations::DEFAULT_LOCALE;
		$locale = Form_Translations::normalize_locale($locale) ?: $default_locale;
		$translations = json_decode((string) ($form['translations'] ?? ''), true);
		$translation = Form_Translations::resolve(is_array($translations) ? $translations : [], $locale, $default_locale);
		$translated_schema = Form_Translations::apply_to_schema($schema, $translation);
		$fields = [];
		foreach ($translated_schema as $field) $fields[(string) $field['key']] = $field;
		return $fields;
	}

	private static function localized_value(mixed $value, array $field, string $locale): mixed
	{
		if (($field['type'] ?? '') === 'checkbox') return self::checkbox_value((string) $value, $locale);
		if (! in_array($field['type'] ?? '', ['select', 'radio'], true) || ! is_scalar($value)) return $value;

		$index = array_search((string) $value, (array) ($field['options'] ?? []), true);
		if ($index === false) return $value;
		$label = (string) ($field['option_labels'][$index] ?? '');
		return $label !== '' ? $label : $value;
	}

	private static function checkbox_value(string $value, string $locale): string
	{
		$checked = in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
		return str_starts_with(strtolower($locale), 'en') ? ($checked ? 'Yes' : 'No') : ($checked ? 'Так' : 'Ні');
	}

	private static function humanize_key(string $key): string
	{
		$label = trim(str_replace(['_', '-'], ' ', $key));
		return $label !== '' ? ucfirst($label) : $key;
	}
}
