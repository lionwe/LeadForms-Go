<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Form_Translations
{
	public const DEFAULT_LOCALE = 'uk_UA';

	public static function available_locales(): array
	{
		$locales = [
			'uk_UA' => __('Українська', 'leadforms-go'),
			'en_US' => __('English', 'leadforms-go'),
			'pl_PL' => __('Polski', 'leadforms-go'),
			'de_DE' => __('Deutsch', 'leadforms-go'),
		];
		$wp_locale = self::normalize_locale(get_locale());
		if ($wp_locale !== '' && ! isset($locales[$wp_locale])) {
			$locales[$wp_locale] = $wp_locale;
		}
		if (function_exists('pll_languages_list')) {
			$polylang_locales = pll_languages_list(['fields' => 'locale']);
			if (is_array($polylang_locales)) {
				foreach ($polylang_locales as $locale) {
					$locale = self::normalize_locale((string) $locale);
					if ($locale !== '' && ! isset($locales[$locale])) $locales[$locale] = $locale;
				}
			}
		}
		$filtered = apply_filters('leadforms_go_form_locales', $locales);
		return is_array($filtered) && $filtered !== [] ? $filtered : $locales;
	}

	public static function normalize_locale(string $locale): string
	{
		$locale = str_replace('-', '_', sanitize_text_field($locale));
		$parts = explode('_', $locale, 2);
		$language = strtolower($parts[0]);
		$region = isset($parts[1]) ? strtoupper($parts[1]) : '';
		if (! preg_match('/^[a-z]{2,3}$/', $language) || ($region !== '' && ! preg_match('/^[A-Z]{2}$/', $region))) return '';
		if ($region === '' && isset(['uk' => 'UA', 'en' => 'US', 'pl' => 'PL', 'de' => 'DE'][$language])) $region = ['uk' => 'UA', 'en' => 'US', 'pl' => 'PL', 'de' => 'DE'][$language];
		return $region === '' ? $language : $language . '_' . $region;
	}

	public static function detect_locale(string $requested = ''): string
	{
		$requested = self::normalize_locale($requested);
		if ($requested !== '') return $requested;
		if (function_exists('pll_current_language')) {
			$polylang = self::normalize_locale((string) pll_current_language('locale'));
			if ($polylang !== '') return $polylang;
		}
		$detected = self::normalize_locale(function_exists('determine_locale') ? determine_locale() : get_locale());
		return $detected !== '' ? $detected : self::DEFAULT_LOCALE;
	}

	public static function default_messages(string $locale): array
	{
		$language = self::language($locale);
		if ($language === 'en') return [
			'sending' => 'Sending…',
			'success' => 'Thank you! The form has been submitted.',
			'error' => 'Could not submit the form. Please try again.',
			'required' => 'Please fill in this field.',
			'invalid' => 'Check that the value is correct.',
			'email' => 'Enter a valid email address.',
			'phone' => 'Enter a valid phone number with at least %d digits.',
			'tooLong' => 'Maximum length is %d characters.',
			'emoji' => 'Emoji are not allowed.',
		];
		if ($language === 'pl') return [
			'sending' => 'Wysyłanie…',
			'success' => 'Dziękujemy! Formularz został wysłany.',
			'error' => 'Nie udało się wysłać formularza. Spróbuj ponownie.',
			'required' => 'Wypełnij to pole.',
			'invalid' => 'Sprawdź poprawność wartości.',
			'email' => 'Wpisz poprawny adres e-mail.',
			'phone' => 'Wpisz poprawny numer telefonu — co najmniej %d cyfr.',
			'tooLong' => 'Maksymalna długość to %d znaków.',
			'emoji' => 'Emoji nie są dozwolone.',
		];
		if ($language === 'de') return [
			'sending' => 'Wird gesendet…',
			'success' => 'Vielen Dank! Das Formular wurde gesendet.',
			'error' => 'Das Formular konnte nicht gesendet werden. Bitte versuchen Sie es erneut.',
			'required' => 'Füllen Sie dieses Feld aus.',
			'invalid' => 'Überprüfen Sie die Eingabe.',
			'email' => 'Geben Sie eine gültige E-Mail-Adresse ein.',
			'phone' => 'Geben Sie eine gültige Telefonnummer mit mindestens %d Ziffern ein.',
			'tooLong' => 'Die maximale Länge beträgt %d Zeichen.',
			'emoji' => 'Emojis sind nicht erlaubt.',
		];
		return [
			'sending' => 'Відправка…',
			'success' => 'Дякуємо! Форму успішно відправлено.',
			'error' => 'Не вдалося відправити форму. Спробуйте ще раз.',
			'required' => 'Заповніть це поле.',
			'invalid' => 'Перевірте правильність значення.',
			'email' => 'Введіть коректну електронну адресу.',
			'phone' => 'Введіть коректний номер телефону — мінімум %d цифр.',
			'tooLong' => 'Максимальна довжина — %d символів.',
			'emoji' => 'Смайлики використовувати не можна.',
		];
	}

	public static function default_submit_label(string $locale): string
	{
		return match (self::language($locale)) {
			'en' => 'Send',
			'pl' => 'Wyślij',
			'de' => 'Senden',
			default => 'Надіслати',
		};
	}

	public static function default_fields(string $locale): array
	{
		$language = self::language($locale);
		if ($language === 'en') {
			return [
				'first_name' => ['label' => 'First name', 'placeholder' => 'Your first name'],
				'last_name' => ['label' => 'Last name', 'placeholder' => 'Your last name'],
				'phone' => ['label' => 'Phone number', 'placeholder' => 'Phone number'],
				'email' => ['label' => 'Email', 'placeholder' => 'name@example.com'],
				'company' => ['label' => 'Company', 'placeholder' => 'Company name'],
				'city' => ['label' => 'City', 'placeholder' => 'Your city'],
				'message' => ['label' => 'Message', 'placeholder' => 'Your message'],
				'consent' => ['label' => 'Consent to personal data processing', 'placeholder' => ''],
				'select' => ['label' => 'Select an option', 'placeholder' => 'Choose an option'],
				'radio' => ['label' => 'Choose one option', 'placeholder' => ''],
				'hidden' => ['label' => 'Hidden field', 'placeholder' => ''],
			];
		}
		if ($language === 'pl') {
			return [
				'first_name' => ['label' => 'Imię', 'placeholder' => 'Twoje imię'],
				'last_name' => ['label' => 'Nazwisko', 'placeholder' => 'Twoje nazwisko'],
				'phone' => ['label' => 'Numer telefonu', 'placeholder' => 'Numer telefonu'],
				'email' => ['label' => 'E-mail', 'placeholder' => 'nazwa@example.com'],
				'company' => ['label' => 'Firma', 'placeholder' => 'Nazwa firmy'],
				'city' => ['label' => 'Miasto', 'placeholder' => 'Twoje miasto'],
				'message' => ['label' => 'Wiadomość', 'placeholder' => 'Twoja wiadomość'],
				'consent' => ['label' => 'Zgoda na przetwarzanie danych osobowych', 'placeholder' => ''],
				'select' => ['label' => 'Wybierz opcję', 'placeholder' => 'Wybierz opcję'],
				'radio' => ['label' => 'Wybierz jedną opcję', 'placeholder' => ''],
				'hidden' => ['label' => 'Ukryte pole', 'placeholder' => ''],
			];
		}
		if ($language === 'de') {
			return [
				'first_name' => ['label' => 'Vorname', 'placeholder' => 'Ihr Vorname'],
				'last_name' => ['label' => 'Nachname', 'placeholder' => 'Ihr Nachname'],
				'phone' => ['label' => 'Telefonnummer', 'placeholder' => 'Telefonnummer'],
				'email' => ['label' => 'E-Mail', 'placeholder' => 'name@example.com'],
				'company' => ['label' => 'Unternehmen', 'placeholder' => 'Unternehmensname'],
				'city' => ['label' => 'Stadt', 'placeholder' => 'Ihre Stadt'],
				'message' => ['label' => 'Nachricht', 'placeholder' => 'Ihre Nachricht'],
				'consent' => ['label' => 'Einwilligung zur Verarbeitung personenbezogener Daten', 'placeholder' => ''],
				'select' => ['label' => 'Option auswählen', 'placeholder' => 'Option auswählen'],
				'radio' => ['label' => 'Eine Option auswählen', 'placeholder' => ''],
				'hidden' => ['label' => 'Ausgeblendetes Feld', 'placeholder' => ''],
			];
		}
		return [
			'first_name' => ['label' => 'Ім’я', 'placeholder' => 'Ваше ім’я'],
			'last_name' => ['label' => 'Прізвище', 'placeholder' => 'Ваше прізвище'],
			'phone' => ['label' => 'Номер телефону', 'placeholder' => 'Номер телефону'],
			'email' => ['label' => 'Електронна пошта', 'placeholder' => 'name@example.com'],
			'company' => ['label' => 'Компанія', 'placeholder' => 'Назва компанії'],
			'city' => ['label' => 'Місто', 'placeholder' => 'Ваше місто'],
			'message' => ['label' => 'Повідомлення', 'placeholder' => 'Ваше повідомлення'],
			'consent' => ['label' => 'Згода на обробку даних', 'placeholder' => ''],
			'select' => ['label' => 'Вибір зі списку', 'placeholder' => 'Оберіть варіант'],
			'radio' => ['label' => 'Один варіант', 'placeholder' => ''],
			'hidden' => ['label' => 'Приховане поле', 'placeholder' => ''],
		];
	}

	public static function builder_defaults(): array
	{
		$defaults = [];
		foreach (array_keys(self::available_locales()) as $locale) {
			$defaults[$locale] = [
				'submit_label' => self::default_submit_label($locale),
				'messages' => self::default_messages($locale),
				'fields' => self::default_fields($locale),
			];
		}
		return $defaults;
	}

	public static function complete(array $translations, array $schema, ?array $locales = null): array
	{
		$translations = self::sanitize($translations);
		$locales ??= array_keys(self::available_locales());
		foreach ($locales as $locale) {
			$locale = self::normalize_locale((string) $locale);
			if ($locale === '') continue;
			$current = $translations[$locale] ?? ['submit_label' => '', 'messages' => [], 'fields' => []];
			$current['submit_label'] = (string) (($current['submit_label'] ?? '') ?: self::default_submit_label($locale));
			$current['messages'] = array_replace(self::default_messages($locale), array_filter((array) ($current['messages'] ?? []), static fn (mixed $value): bool => is_string($value) && $value !== ''));
			$current['fields'] = is_array($current['fields'] ?? null) ? $current['fields'] : [];
			$defaults = self::default_fields($locale);
			foreach ($schema as $field) {
				$key = sanitize_key((string) ($field['key'] ?? ''));
				if ($key === '') continue;
				$base_key = preg_replace('/_\d+$/', '', $key) ?: $key;
				$field_defaults = $defaults[$base_key] ?? [];
				$existing = is_array($current['fields'][$key] ?? null) ? $current['fields'][$key] : [];
				$use_locale_defaults = self::language($locale) !== 'uk';
				$fallback_label = $use_locale_defaults ? ($field_defaults['label'] ?? '') : (string) ($field['label'] ?? ($field_defaults['label'] ?? $key));
				$fallback_placeholder = $use_locale_defaults ? ($field_defaults['placeholder'] ?? '') : (string) ($field['placeholder'] ?? ($field_defaults['placeholder'] ?? ''));
				$current['fields'][$key] = [
					'label' => sanitize_text_field((string) (($existing['label'] ?? '') ?: $fallback_label)),
					'placeholder' => sanitize_text_field((string) (($existing['placeholder'] ?? '') ?: $fallback_placeholder)),
					'options' => self::sanitize_options($existing['options'] ?? []),
				];
			}
			$translations[$locale] = $current;
		}
		return self::sanitize($translations);
	}

	public static function sanitize(mixed $translations): array
	{
		if (! is_array($translations)) return [];
		$clean = [];
		foreach ($translations as $locale => $translation) {
			$locale = self::normalize_locale((string) $locale);
			if ($locale === '' || ! is_array($translation)) continue;
			$fields = [];
			foreach (($translation['fields'] ?? []) as $key => $field) {
				$key = sanitize_key((string) $key);
				if ($key === '' || ! is_array($field)) continue;
				$fields[$key] = [
					'label' => sanitize_text_field((string) ($field['label'] ?? '')),
					'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
					'options' => self::sanitize_options($field['options'] ?? []),
				];
			}
			$messages = [];
			foreach (array_keys(self::default_messages($locale)) as $key) {
				$messages[$key] = sanitize_text_field((string) ($translation['messages'][$key] ?? ''));
			}
			$clean[$locale] = [
				'submit_label' => sanitize_text_field((string) ($translation['submit_label'] ?? '')),
				'messages' => $messages,
				'fields' => $fields,
			];
		}
		return $clean;
	}

	public static function seed(array $schema, string $submit_label, string $locale = self::DEFAULT_LOCALE): array
	{
		$locale = self::normalize_locale($locale) ?: self::DEFAULT_LOCALE;
		$fields = [];
		foreach ($schema as $field) {
			$key = sanitize_key((string) ($field['key'] ?? ''));
			if ($key === '') continue;
			$fields[$key] = [
				'label' => sanitize_text_field((string) ($field['label'] ?? $key)),
				'placeholder' => sanitize_text_field((string) ($field['placeholder'] ?? '')),
				'options' => [],
			];
		}
		return [$locale => ['submit_label' => sanitize_text_field($submit_label), 'messages' => self::default_messages($locale), 'fields' => $fields]];
	}

	public static function resolve(array $translations, string $locale, string $default_locale): array
	{
		$translations = self::sanitize($translations);
		$locale = self::normalize_locale($locale) ?: self::DEFAULT_LOCALE;
		$default_locale = self::normalize_locale($default_locale) ?: self::DEFAULT_LOCALE;
		$base = $translations[$default_locale] ?? reset($translations) ?: [];
		$selected = $translations[$locale] ?? [];
		if ($selected === []) {
			$language = strtolower(strtok($locale, '_'));
			foreach ($translations as $candidate_locale => $candidate) {
				if (str_starts_with(strtolower($candidate_locale), $language . '_') || strtolower($candidate_locale) === $language) {
					$selected = $candidate;
					break;
				}
			}
		}
		$defaults = self::default_messages($locale);
		if ($selected === [] && in_array(self::language($locale), ['en', 'pl', 'de'], true)) {
			$default_fields = array_map(static fn (array $field): array => $field + ['options' => []], self::default_fields($locale));
			return [
				'submit_label' => self::default_submit_label($locale),
				'messages' => $defaults,
				'fields' => array_replace_recursive((array) ($base['fields'] ?? []), $default_fields),
			];
		}
		return [
			'submit_label' => (string) (($selected['submit_label'] ?? '') ?: ($base['submit_label'] ?? 'Надіслати')),
			'messages' => array_replace($defaults, array_filter((array) ($base['messages'] ?? [])), array_filter((array) ($selected['messages'] ?? []))),
			'fields' => array_replace_recursive((array) ($base['fields'] ?? []), (array) ($selected['fields'] ?? [])),
		];
	}

	public static function apply_to_schema(array $schema, array $translation): array
	{
		foreach ($schema as &$field) {
			$key = (string) ($field['key'] ?? '');
			$text = (array) ($translation['fields'][$key] ?? []);
			$field['label'] = (string) (($text['label'] ?? '') ?: ($field['label'] ?? $key));
			$field['placeholder'] = (string) ($text['placeholder'] ?? ($field['placeholder'] ?? ''));
			$field['option_labels'] = self::sanitize_options($text['options'] ?? []);
		}
		unset($field);
		return $schema;
	}

	private static function sanitize_options(mixed $options): array
	{
		if (! is_array($options)) return [];
		return array_values(array_filter(array_map(static fn (mixed $option): string => sanitize_text_field(is_scalar($option) ? (string) $option : ''), $options)));
	}

	private static function language(string $locale): string
	{
		return strtolower((string) strtok(self::normalize_locale($locale), '_'));
	}
}
