<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Route_Config
{
	public const VERSION = 1;
	private const STATES = ['inherit', 'enabled', 'disabled'];
	private const SYSTEM_VARIABLES = ['page_url', 'form_name', 'submitted_at', 'locale', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

	public static function defaults(): array
	{
		return [
			'version' => self::VERSION,
			'telegram' => [
				'state' => 'inherit',
				'chat_id' => '',
				'topic_id' => 0,
				'parse_mode' => 'plain',
				'templates' => [],
				'buttons' => [],
			],
			'sheets' => [
				'state' => 'inherit',
				'spreadsheet_id' => '',
				'sheet_name' => '',
				'write_mode' => 'append',
				'dedupe_key' => '',
				'columns' => [],
			],
			'crm' => [
				'state' => 'inherit',
				'adv_id' => '',
				'mapping' => [],
			],
		];
	}

	public static function sanitize(mixed $config, array $schema = [], array $locales = []): array
	{
		$config = is_array($config) ? $config : [];
		$defaults = self::defaults();
		$allowed_variables = self::available_variables($schema);
		$dedupe_variables = self::dedupe_variables($schema);
		$locale_keys = $locales !== [] ? array_keys($locales) : array_keys(Form_Translations::available_locales());

		$telegram = is_array($config['telegram'] ?? null) ? $config['telegram'] : [];
		$templates = [];
		$buttons = [];
		foreach ($locale_keys as $locale) {
			$normalized = Form_Translations::normalize_locale((string) $locale);
			if ($normalized === '') continue;
			$template = is_scalar($telegram['templates'][$locale] ?? null) ? wp_unslash((string) $telegram['templates'][$locale]) : '';
			$templates[$normalized] = Telegram_Template::sanitize_template($template);
			$locale_buttons = is_array($telegram['buttons'][$locale] ?? null) ? $telegram['buttons'][$locale] : [];
			$buttons[$normalized] = Telegram_Template::sanitize_buttons($locale_buttons);
		}

		$sheets = is_array($config['sheets'] ?? null) ? $config['sheets'] : [];
		$columns = [];
		foreach (array_slice(is_array($sheets['columns'] ?? null) ? $sheets['columns'] : [], 0, 50) as $column) {
			if (! is_array($column)) continue;
			$type = sanitize_key((string) ($column['type'] ?? 'field'));
			if (! in_array($type, ['field', 'system', 'static'], true)) $type = 'field';
			$source = sanitize_key((string) ($column['source'] ?? ''));
			if ($type !== 'static' && ! in_array($source, $allowed_variables, true)) continue;
			$header = sanitize_text_field((string) ($column['header'] ?? $source));
			if ($header === '') continue;
			$columns[] = [
				'id' => sanitize_key((string) ($column['id'] ?? 'column_' . count($columns))),
				'header' => $header,
				'type' => $type,
				'source' => $type === 'static' ? '' : $source,
				'value' => $type === 'static' ? sanitize_text_field((string) ($column['value'] ?? '')) : '',
			];
		}

		$crm = is_array($config['crm'] ?? null) ? $config['crm'] : [];
		$mapping = [];
		foreach (array_slice(is_array($crm['mapping'] ?? null) ? $crm['mapping'] : [], 0, 50, true) as $target => $source) {
			$target = sanitize_key((string) $target);
			$source = sanitize_key((string) $source);
			if ($target !== '' && in_array($source, $allowed_variables, true)) $mapping[$target] = $source;
		}

		return [
			'version' => self::VERSION,
			'telegram' => [
				'state' => self::state($telegram['state'] ?? $defaults['telegram']['state']),
				'chat_id' => sanitize_text_field((string) ($telegram['chat_id'] ?? '')),
				'topic_id' => absint($telegram['topic_id'] ?? 0),
				'parse_mode' => Telegram_Template::sanitize_mode((string) ($telegram['parse_mode'] ?? 'plain')),
				'templates' => $templates,
				'buttons' => $buttons,
			],
			'sheets' => [
				'state' => self::state($sheets['state'] ?? $defaults['sheets']['state']),
				'spreadsheet_id' => self::spreadsheet_id((string) ($sheets['spreadsheet_id'] ?? '')),
				'sheet_name' => sanitize_text_field((string) ($sheets['sheet_name'] ?? '')),
				'write_mode' => ($sheets['write_mode'] ?? '') === 'update' ? 'update' : 'append',
				'dedupe_key' => in_array(sanitize_key((string) ($sheets['dedupe_key'] ?? '')), $dedupe_variables, true) ? sanitize_key((string) $sheets['dedupe_key']) : '',
				'columns' => $columns,
			],
			'crm' => [
				'state' => self::state($crm['state'] ?? $defaults['crm']['state']),
				'adv_id' => sanitize_text_field((string) ($crm['adv_id'] ?? '')),
				'mapping' => $mapping,
			],
		];
	}

	public static function for_form(?array $form): array
	{
		if (! is_array($form)) return self::defaults();
		$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
		$config = json_decode((string) ($form['routing_config'] ?? ''), true);
		return self::sanitize($config, Form_Builder::sanitize_schema($schema));
	}

	public static function snapshot(array $config, string $connector, array $context = []): array
	{
		$connector = sanitize_key($connector);
		$route = is_array($config[$connector] ?? null) ? $config[$connector] : [];
		$global = Settings::section($connector);
		$route['state'] = self::is_enabled($connector, $route) ? 'enabled' : 'disabled';
		if ($connector === 'telegram' && empty($route['chat_id'])) $route['chat_id'] = sanitize_text_field((string) ($global['chat_id'] ?? ''));
		if ($connector === 'sheets') {
			if (empty($route['spreadsheet_id'])) $route['spreadsheet_id'] = self::spreadsheet_id((string) ($global['spreadsheet_id'] ?? ''));
			if (empty($route['sheet_name'])) $route['sheet_name'] = sanitize_text_field((string) ($global['sheet_name'] ?? ''));
		}
		if ($connector === 'crm' && empty($route['adv_id'])) $route['adv_id'] = sanitize_text_field((string) ($global['adv_id'] ?? ''));
		return [
			'version' => self::VERSION,
			'connector' => $connector,
			'route' => $route,
			'context' => [
				'form_name' => sanitize_text_field((string) ($context['form_name'] ?? '')),
				'submitted_at' => sanitize_text_field((string) ($context['submitted_at'] ?? '')),
			],
		];
	}

	public static function route_from_snapshot(array $snapshot, string $connector): array
	{
		if (sanitize_key((string) ($snapshot['connector'] ?? '')) !== $connector || ! is_array($snapshot['route'] ?? null)) return [];
		$route = $snapshot['route'];
		$route['_context'] = is_array($snapshot['context'] ?? null) ? $snapshot['context'] : [];
		return $route;
	}

	public static function is_enabled(string $connector, array $route): bool
	{
		$state = self::state($route['state'] ?? 'inherit');
		if ($state === 'enabled') return true;
		if ($state === 'disabled') return false;
		return ! empty(Settings::section($connector)['enabled']);
	}

	public static function available_variables(array $schema): array
	{
		$keys = self::SYSTEM_VARIABLES;
		foreach ($schema as $field) {
			$key = sanitize_key((string) ($field['key'] ?? ''));
			if ($key !== '') $keys[] = $key;
		}
		return array_values(array_unique($keys));
	}

	public static function dedupe_variables(array $schema): array
	{
		$keys = [];
		foreach ($schema as $field) {
			$key = sanitize_key((string) ($field['key'] ?? ''));
			$type = sanitize_key((string) ($field['type'] ?? ''));
			if ($key !== '' && (in_array($type, ['email', 'tel'], true) || str_contains($key, 'email') || str_contains($key, 'phone') || str_contains($key, 'tel'))) $keys[] = $key;
		}
		return array_values(array_unique($keys));
	}

	public static function validate(array $config, array $schema): true|\WP_Error
	{
		$allowed = self::available_variables($schema);
		$telegram = is_array($config['telegram'] ?? null) ? $config['telegram'] : [];
		foreach ((array) ($telegram['templates'] ?? []) as $template) {
			$result = Telegram_Template::validate((string) $template, (string) ($telegram['parse_mode'] ?? 'plain'), $allowed);
			if (is_wp_error($result)) return $result;
		}
		foreach ((array) ($telegram['buttons'] ?? []) as $buttons) {
			$result = Telegram_Template::validate_buttons(is_array($buttons) ? $buttons : [], $allowed);
			if (is_wp_error($result)) return $result;
		}
		if (($config['sheets']['write_mode'] ?? '') === 'update') {
			$dedupe_key = sanitize_key((string) ($config['sheets']['dedupe_key'] ?? ''));
			if ($dedupe_key === '') return new \WP_Error('missing_dedupe_key', __('Для оновлення рядка виберіть email або телефон.', 'leadforms-go'));
			$mapped = array_filter((array) ($config['sheets']['columns'] ?? []), static fn (array $column): bool => ($column['source'] ?? '') === $dedupe_key);
			if ($mapped === []) return new \WP_Error('unmapped_dedupe_key', __('Поле пошуку має бути додане до колонок Google Sheets.', 'leadforms-go'));
		}
		return true;
	}

	public static function resolve_value(array $column, array $variables): string
	{
		if (($column['type'] ?? '') === 'static') return sanitize_text_field((string) ($column['value'] ?? ''));
		$source = sanitize_key((string) ($column['source'] ?? ''));
		$value = $variables[$source] ?? '';
		return is_scalar($value) ? sanitize_textarea_field((string) $value) : '';
	}

	private static function state(mixed $state): string
	{
		$state = sanitize_key(is_scalar($state) ? (string) $state : 'inherit');
		return in_array($state, self::STATES, true) ? $state : 'inherit';
	}

	private static function spreadsheet_id(string $value): string
	{
		if (preg_match('~/(?:spreadsheets/)?(?:u/\d+/)?d/([A-Za-z0-9_-]+)~', $value, $match)) $value = $match[1];
		return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
	}
}
