<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Plugin
{
	private static ?self $instance = null;
	private ?Delivery_Queue $queue = null;
	private bool $frontend_configured = false;
	private int $form_instance = 0;
	public static function instance(): self
	{
		return self::$instance ??= new self();
	}

	public function boot(): void
	{
		Settings::maybe_encrypt_secrets();
		Database::maybe_upgrade();
		$this->queue = new Delivery_Queue();
		$this->queue->boot();
		add_action('leadforms_go_cleanup_submissions', [$this, 'cleanup_submissions']);
		add_filter('wp_privacy_personal_data_exporters', [Privacy::class, 'register_exporter']);
		add_filter('wp_privacy_personal_data_erasers', [Privacy::class, 'register_eraser']);
		if (! wp_next_scheduled('leadforms_go_cleanup_submissions')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'leadforms_go_cleanup_submissions');
		add_action('init', [$this, 'shortcodes']);
		add_action('wp_enqueue_scripts', [$this, 'register_assets']);
		add_action('wp_ajax_leadforms_go_submit', [$this, 'submit']);
		add_action('wp_ajax_nopriv_leadforms_go_submit', [$this, 'submit']);
		if (is_admin()) (new Admin())->boot();
	}

	public function shortcodes(): void
	{
		add_shortcode('leadforms_go_form', fn(mixed $atts): string => $this->render(is_array($atts) ? $atts : [], false));
		add_shortcode('reintegration_form', fn(mixed $atts): string => $this->render(is_array($atts) ? $atts : [], true));
	}

	public function register_assets(): void
	{
		$script_version = @filemtime(LEADFORMS_GO_DIR . 'assets/frontend.js') ?: LEADFORMS_GO_VERSION;
		$style_version = @filemtime(LEADFORMS_GO_DIR . 'assets/frontend.css') ?: LEADFORMS_GO_VERSION;
		wp_register_script('leadforms-go', LEADFORMS_GO_URL . 'assets/frontend.js', [], (string) $script_version, true);
		wp_register_style('leadforms-go', LEADFORMS_GO_URL . 'assets/frontend.css', [], (string) $style_version);
	}

	private function render(array $atts, bool $legacy): string
	{
		$atts = shortcode_atts(['id' => 0, 'locale' => ''], $atts, $legacy ? 'reintegration_form' : 'leadforms_go_form');
		$id = absint($atts['id']);
		$form = $id ? Repositories::form($id, $legacy) : null;
		if (! $form && $legacy) $form = Repositories::form($id);
		if (! $form) return current_user_can('manage_options') ? '<p>' . esc_html__('LeadForms Go: форму не знайдено.', 'leadforms-go') . '</p>' : '';
		if (empty($form['active'])) return current_user_can('manage_options') ? '<p>' . esc_html__('LeadForms Go: форму вимкнено.', 'leadforms-go') . '</p>' : '';
		$instance = ++$this->form_instance;
		$instance_key = (int) $form['id'] . '-' . $instance;
		$locale = Form_Translations::detect_locale(is_scalar($atts['locale']) ? (string) $atts['locale'] : '');
		$default_locale = Form_Translations::normalize_locale((string) ($form['default_locale'] ?? '')) ?: Form_Translations::DEFAULT_LOCALE;
		$translations = json_decode((string) ($form['translations'] ?? ''), true);
		$translation = Form_Translations::resolve(is_array($translations) ? $translations : [], $locale, $default_locale);
		$form_code = Form_Builder::secure_transport(Form_Builder::sanitize_code((string) $form['code']));
		if (($form['editor_mode'] ?? 'code') === 'visual') {
			$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
			$schema = Form_Builder::sanitize_schema($schema);
			if ($schema !== []) {
				$button_icon = json_decode((string) ($form['button_icon'] ?? ''), true);
				$form_code = Form_Builder::render(Form_Translations::apply_to_schema($schema, $translation), (string) $translation['submit_label'], $instance_key, Form_Builder::sanitize_button_icon(is_array($button_icon) ? $button_icon : []));
			}
		}
		$masked_form_code = preg_replace('/\bdata-mask(?=\s*=)/i', 'data-leadforms-go-mask', $form_code);
		if (is_string($masked_form_code)) $form_code = $masked_form_code;
		$this->enqueue_frontend();
		$messages = wp_json_encode($translation['messages'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		$security = Submission_Security::context((int) $form['id']);
		return sprintf('<div id="leadforms-go-form-%1$s" class="leadforms-go-form reintegration-form" data-leadforms-go-form="%2$d" data-leadforms-go-locale="%3$s" data-leadforms-go-messages="%4$s" data-leadforms-go-nonce="%5$s" data-leadforms-go-token="%6$s">%7$s<div class="leadforms-go-form__status" role="status" aria-live="polite"></div></div>', esc_attr($instance_key), (int) $form['id'], esc_attr($locale), esc_attr(is_string($messages) ? $messages : '{}'), esc_attr($security['nonce']), esc_attr($security['token']), $form_code);
	}

	public function enqueue_frontend(): void
	{
		wp_enqueue_script('leadforms-go');
		wp_enqueue_style('leadforms-go');
		$this->configure_frontend();
	}

	private function configure_frontend(): void
	{
		if ($this->frontend_configured) return;
		$this->frontend_configured = true;
		$config = wp_json_encode([
			'ajaxUrl' => wp_make_link_relative(admin_url('admin-ajax.php')),
			'successDuration' => 4000,
			'requestTimeout' => 20000,
			'attributionTtl' => (int) (Settings::section('general')['attribution_days'] ?? 30) * DAY_IN_SECONDS,
			'messages' => [
				'sending' => __('Відправка…', 'leadforms-go'),
				'success' => __('Дякуємо! Форму успішно відправлено.', 'leadforms-go'),
				'error' => __('Не вдалося відправити форму. Спробуйте ще раз.', 'leadforms-go'),
				'required' => __('Заповніть це поле.', 'leadforms-go'),
				'emoji' => __('Смайлики використовувати не можна.', 'leadforms-go'),
				'tooLong' => __('Максимальна довжина — %d символів.', 'leadforms-go'),
				'phone' => __('Введіть коректний номер телефону — мінімум %d цифр.', 'leadforms-go'),
				'invalid' => __('Перевірте правильність значення.', 'leadforms-go'),
			],
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		if (is_string($config)) wp_add_inline_script('leadforms-go', 'window.leadFormsGo=' . $config . ';', 'before');
	}

	public function cleanup_submissions(): void
	{
		$days = (int) (Settings::section('general')['retention_days'] ?? 180);
		if ($days > 0) Repositories::purge_submissions_older_than($days);
	}

	public function submit(): void
	{
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_send_json_error(['message' => __('Некоректний метод запиту.', 'leadforms-go')], 405);
		if (! Submission_Security::valid_origin()) wp_send_json_error(['message' => __('Некоректне джерело запиту.', 'leadforms-go')], 403);
		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		$form = $form_id > 0 ? Repositories::form($form_id) : null;
		if (! $form || empty($form['active'])) wp_send_json_error(['message' => __('Форму не знайдено або вимкнено.', 'leadforms-go')], 404);
		$nonce = isset($_POST['nonce']) && is_scalar($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
		$token = isset($_POST['form_token']) && is_scalar($_POST['form_token']) ? sanitize_text_field(wp_unslash((string) $_POST['form_token'])) : '';
		if (! Submission_Security::verify_context($form_id, $nonce, $token)) wp_send_json_error(['message' => __('Сесію завершено. Оновіть сторінку та спробуйте ще раз.', 'leadforms-go')], 403);
		$requested_locale = isset($_POST['locale']) && is_scalar($_POST['locale']) ? wp_unslash((string) $_POST['locale']) : '';
		$locale = Form_Translations::detect_locale($requested_locale);
		$default_locale = Form_Translations::normalize_locale((string) ($form['default_locale'] ?? '')) ?: Form_Translations::DEFAULT_LOCALE;
		$translations = json_decode((string) ($form['translations'] ?? ''), true);
		$translation = Form_Translations::resolve(is_array($translations) ? $translations : [], $locale, $default_locale);
		$raw = isset($_POST['form_data']) && is_string($_POST['form_data']) ? wp_unslash($_POST['form_data']) : '';
		if ($raw === '' || strlen($raw) > 20480) wp_send_json_error(['message' => __('Некоректні дані форми.', 'leadforms-go')], 400);
		$decoded = json_decode($raw, true);
		if (! is_array($decoded) || count($decoded) > 50) wp_send_json_error(['message' => __('Некоректні дані форми.', 'leadforms-go')], 400);
		if (Submission_Security::is_honeypot_filled($decoded)) wp_send_json_success(['message' => $translation['messages']['success']]);
		unset($decoded['_lfg_website']);
		if (! Submission_Security::consume_rate_limit($form_id)) wp_send_json_error(['message' => __('Забагато спроб. Спробуйте пізніше.', 'leadforms-go')], 429);
		$request_id = isset($_POST['request_id']) && is_scalar($_POST['request_id']) ? sanitize_text_field(wp_unslash((string) $_POST['request_id'])) : '';
		if (! Submission_Security::valid_request_id($request_id)) wp_send_json_error(['message' => __('Некоректний ідентифікатор запиту.', 'leadforms-go')], 400);
		$validation = Submission_Validator::validate($form, $decoded, $translation['messages']);
		$data = $validation['data'];
		$errors = $validation['errors'];
		if ($errors !== []) wp_send_json_error(['message' => __('Перевірте правильність заповнення полів.', 'leadforms-go'), 'errors' => $errors], 422);
		if ($data === []) wp_send_json_error(['message' => __('Дані форми відсутні.', 'leadforms-go')], 422);
		$referer = Submission_Security::referer();
		$data = apply_filters('leadforms_go_submission_data', $data, $form_id, $referer);
		$data = is_array($data) ? Submission_Validator::sanitize_payload($data) : [];
		if ($data === []) wp_send_json_error(['message' => __('Дані форми відсутні.', 'leadforms-go')], 422);
		$submission = Repositories::create_submission($form_id, $data, $referer, $locale, $request_id);
		$submission_id = $submission['id'];
		if ($submission_id <= 0) wp_send_json_error(['message' => __('Не вдалося зберегти заявку. Спробуйте ще раз.', 'leadforms-go')], 500);
		if (! $submission['created']) wp_send_json_success(['message' => $translation['messages']['success'], 'submission_id' => $submission_id, 'duplicate' => true]);
		$delivery_count = $this->queue?->queue_submission($submission_id) ?? 0;
		if (! $this->legacy_addons_active()) {
			try {
				do_action('ri_send_integration', $data, $referer);
			} catch (\Throwable) {
				// Compatibility callbacks must not invalidate an already stored submission.
			}
		}
		do_action('leadforms_go_submission_processed', $submission_id, $data, $referer);
		wp_send_json_success(['message' => $translation['messages']['success'], 'submission_id' => $submission_id, 'deliveries' => $delivery_count]);
	}

	public function capture_submission(array $data, ?int $form_id = null, string $referer = ''): int
	{
		$data = apply_filters('leadforms_go_submission_data', $data, $form_id, $referer);
		$data = is_array($data) ? Submission_Validator::sanitize_payload($data) : [];
		if ($data === []) return 0;
		$submission = Repositories::create_submission($form_id, $data, $referer, '', 'server_' . wp_generate_uuid4());
		$submission_id = $submission['id'];
		if ($submission_id <= 0) return 0;
		if ($submission['created']) {
			$this->queue?->queue_submission($submission_id);
			do_action('leadforms_go_submission_processed', $submission_id, $data, $referer);
		}
		return $submission_id;
	}


	private function legacy_addons_active(): bool
	{
		$active = (array) get_option('active_plugins', []);
		return (bool) array_intersect($active, ['reIntegrationSheets/reIntegrationSheets.php', 'reIntegrationTelegram/reIntegrationTelegram.php', 'reIntegrationCRM/reIntegrationCRM.php']);
	}
}
