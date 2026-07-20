<?php

declare(strict_types=1);

namespace {
	class WP_Error
	{
		public function __construct(private string $code, private string $message, private mixed $data = null) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}

	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function __(string $value): string { return $value; }
	function wp_unslash(string $value): string { return stripslashes($value); }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', $value)); }
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
	function absint(mixed $value): int { return abs((int) $value); }
	function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	function esc_url_raw(string $value, array $protocols = []): string
	{
		$scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
		return $protocols !== [] && ! in_array($scheme, $protocols, true) ? '' : filter_var($value, FILTER_SANITIZE_URL);
	}
	function wp_http_validate_url(string $value): bool { return filter_var($value, FILTER_VALIDATE_URL) !== false; }
	function wp_kses(string $html, array $allowed): string
	{
		$tags = implode('', array_map(static fn (string $tag): string => '<' . $tag . '>', array_keys($allowed)));
		return strip_tags($html, $tags);
	}
}

namespace LeadFormsGo {
	final class Form_Translations
	{
		public const DEFAULT_LOCALE = 'uk_UA';
		public static function available_locales(): array { return ['uk_UA' => 'Українська', 'en_US' => 'English']; }
		public static function normalize_locale(string $locale): string { return in_array($locale, ['uk_UA', 'en_US'], true) ? $locale : ''; }
	}

	final class Form_Builder
	{
		public static function sanitize_schema(mixed $schema): array { return is_array($schema) ? $schema : []; }
	}

	final class Settings
	{
		public static function section(string $key): array { return ['enabled' => false]; }
	}
}
