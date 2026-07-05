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

	public static function for_admin(array $payload, int $form_id, string $locale): array
	{
		return self::present($payload, $form_id, $locale);
	}

	private static function present(array $payload, int $form_id, string $locale): array
	{
		if ($form_id > 0 && ! array_key_exists($form_id, self::$forms)) self::$forms[$form_id] = Repositories::form($form_id);
		$form = $form_id > 0 ? self::$forms[$form_id] : null;
		if (! is_array($form) || ($form['editor_mode'] ?? '') !== 'visual') return $payload;

		$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
		$schema = Form_Builder::sanitize_schema($schema);
		if ($schema === []) return $payload;

		$default_locale = Form_Translations::normalize_locale((string) ($form['default_locale'] ?? '')) ?: Form_Translations::DEFAULT_LOCALE;
		$locale = Form_Translations::normalize_locale($locale) ?: $default_locale;
		$translations = json_decode((string) ($form['translations'] ?? ''), true);
		$translation = Form_Translations::resolve(is_array($translations) ? $translations : [], $locale, $default_locale);
		$translated_schema = Form_Translations::apply_to_schema($schema, $translation);
		$fields = [];
		foreach ($translated_schema as $field) $fields[(string) $field['key']] = $field;

		$presented = [];
		foreach ($payload as $key => $value) {
			$field = $fields[(string) $key] ?? null;
			$label = is_array($field) && ! empty($field['label']) ? (string) $field['label'] : self::humanize_key((string) $key);
			if (is_array($field) && ($field['type'] ?? '') === 'checkbox') {
				$value = self::checkbox_value((string) $value, $locale);
			}
			$unique_label = $label;
			$suffix = 2;
			while (array_key_exists($unique_label, $presented)) $unique_label = $label . ' (' . $suffix++ . ')';
			$presented[$unique_label] = $value;
		}
		return $presented;
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
