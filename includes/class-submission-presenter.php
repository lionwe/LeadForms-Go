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

	public static function telegram_message(array $payload, int $form_id, string $locale, string $referer, string $form_name = ''): string
	{
		$english = str_starts_with(strtolower($locale), 'en');
		$labels = $english
			? ['title' => 'New form submission', 'form' => 'Form', 'contact' => 'Contact details', 'source' => 'Source', 'page' => 'Page', 'visited' => 'First visit', 'utm' => 'Campaign', 'referrer' => 'Referred from']
			: ['title' => 'Нова заявка з форми', 'form' => 'Форма', 'contact' => 'Контактні дані', 'source' => 'Джерело', 'page' => 'Сторінка', 'visited' => 'Перший візит', 'utm' => 'Кампанія', 'referrer' => 'Перехід із'];
		$attribution_keys = ['landing_page', 'document_referrer', 'visited_at', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ttclid'];
		$contact_payload = array_diff_key($payload, array_fill_keys($attribution_keys, true));
		$presented = self::present($contact_payload, $form_id, $locale);
		$lines = ['🆕 ' . $labels['title']];
		if ($form_name !== '') $lines[] = $labels['form'] . ': ' . sanitize_text_field($form_name);
		if ($presented !== []) {
			$lines[] = '';
			$lines[] = '👤 ' . $labels['contact'];
			foreach ($presented as $key => $value) $lines[] = sanitize_text_field((string) $key) . ': ' . sanitize_textarea_field(is_scalar($value) ? (string) $value : (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE));
		}
		$landing_page = esc_url_raw((string) ($payload['landing_page'] ?? $referer), ['http', 'https']);
		$document_referrer = esc_url_raw((string) ($payload['document_referrer'] ?? ''), ['http', 'https']);
		$visited_at = self::telegram_visit_time((string) ($payload['visited_at'] ?? ''));
		$campaign = array_filter([
			sanitize_text_field((string) ($payload['utm_source'] ?? '')),
			sanitize_text_field((string) ($payload['utm_medium'] ?? '')),
			sanitize_text_field((string) ($payload['utm_campaign'] ?? '')),
		], static fn (string $value): bool => $value !== '');
		$external_referrer = $document_referrer !== '' && ! self::same_site_url($document_referrer) ? $document_referrer : '';
		if ($landing_page !== '' || $visited_at !== '' || $campaign !== [] || $external_referrer !== '') {
			$lines[] = '';
			$lines[] = '🔗 ' . $labels['source'];
			if ($landing_page !== '') $lines[] = $labels['page'] . ': ' . $landing_page;
			if ($visited_at !== '') $lines[] = $labels['visited'] . ': ' . $visited_at;
			if ($campaign !== []) $lines[] = $labels['utm'] . ': ' . implode(' / ', $campaign);
			if ($external_referrer !== '') $lines[] = $labels['referrer'] . ': ' . $external_referrer;
		}
		return Telegram_Template::fit_plain(implode("\n", $lines));
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

	private static function telegram_visit_time(string $value): string
	{
		$timestamp = ctype_digit($value) ? (int) $value : 0;
		if ($timestamp <= 0 || $timestamp > time() || $timestamp < time() - YEAR_IN_SECONDS) return '';
		return wp_date('d.m.Y H:i', $timestamp, wp_timezone());
	}

	private static function same_site_url(string $url): bool
	{
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		return $host !== '' && $site_host !== '' && hash_equals($site_host, $host);
	}

	private static function humanize_key(string $key): string
	{
		$label = trim(str_replace(['_', '-'], ' ', $key));
		return $label !== '' ? ucfirst($label) : $key;
	}
}
