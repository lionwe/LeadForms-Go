<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Plugin
{
	private static ?self $instance = null;
	private ?Delivery_Queue $queue = null;
	private bool $frontend_configured = false;
	private int $form_instance = 0;
	public static function instance(): self { return self::$instance ??= new self(); }

	public function boot(): void
	{
		Database::maybe_upgrade();
		$this->queue = new Delivery_Queue();
		$this->queue->boot();
		add_action('init', [$this, 'shortcodes']);
		add_action('wp_enqueue_scripts', [$this, 'register_assets']);
		add_action('wp_ajax_leadforms_go_submit', [$this, 'submit']);
		add_action('wp_ajax_nopriv_leadforms_go_submit', [$this, 'submit']);
		if (is_admin()) (new Admin())->boot();
	}

	public function shortcodes(): void
	{
		add_shortcode('leadforms_go_form', fn (mixed $atts): string => $this->render(is_array($atts) ? $atts : [], false));
		add_shortcode('reintegration_form', fn (mixed $atts): string => $this->render(is_array($atts) ? $atts : [], true));
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
		$atts = shortcode_atts(['id' => 0], $atts, $legacy ? 'reintegration_form' : 'leadforms_go_form');
		$id = absint($atts['id']);
		$form = $id ? Repositories::form($id, $legacy) : null;
		if (! $form && $legacy) $form = Repositories::form($id);
		if (! $form) return current_user_can('manage_options') ? '<p>' . esc_html__('LeadForms Go: форму не знайдено.', 'leadforms-go') . '</p>' : '';
		$instance = ++$this->form_instance;
		$instance_key = (int) $form['id'] . '-' . $instance;
		$form_code = Form_Builder::sanitize_code((string) $form['code']);
		if (($form['editor_mode'] ?? 'code') === 'visual') {
			$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
			$schema = Form_Builder::sanitize_schema($schema);
			if ($schema !== []) {
				$form_code = Form_Builder::render($schema, (string) ($form['submit_label'] ?? 'Надіслати'), $instance_key);
			}
		}
		$this->enqueue_frontend();
		return sprintf('<div id="leadforms-go-form-%1$s" class="leadforms-go-form reintegration-form" data-leadforms-go-form="%2$d">%3$s<div class="leadforms-go-form__status" role="status" aria-live="polite"></div></div>', esc_attr($instance_key), (int) $form['id'], $form_code);
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
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('leadforms_go_submit'),
			'successDuration' => 4000,
			'requestTimeout' => 20000,
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

	public function submit(): void
	{
		if (! check_ajax_referer('leadforms_go_submit', 'nonce', false)) wp_send_json_error(['message' => __('Сесію завершено. Оновіть сторінку та спробуйте ще раз.', 'leadforms-go')], 403);
		$form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		$form = $form_id > 0 ? Repositories::form($form_id) : null;
		if (! $form) wp_send_json_error(['message' => __('Форму не знайдено.', 'leadforms-go')], 400);
		$raw = isset($_POST['form_data']) && is_string($_POST['form_data']) ? wp_unslash($_POST['form_data']) : '';
		if ($raw === '' || strlen($raw) > 20480) wp_send_json_error(['message' => __('Некоректні дані форми.', 'leadforms-go')], 400);
		$decoded = json_decode($raw, true);
		if (! is_array($decoded) || count($decoded) > 50) wp_send_json_error(['message' => __('Некоректні дані форми.', 'leadforms-go')], 400);
		$honeypot = isset($decoded['website']) && is_scalar($decoded['website']) ? trim((string) $decoded['website']) : '';
		$started_at = isset($decoded['_lfg_started_at']) ? absint($decoded['_lfg_started_at']) : 0;
		$elapsed = $started_at > 0 ? ((int) round(microtime(true) * 1000) - $started_at) : 0;
		if ($honeypot !== '' || $elapsed < 1500) wp_send_json_error(['message' => __('Некоректні дані форми.', 'leadforms-go')], 400);
		unset($decoded['website'], $decoded['_lfg_started_at']);
		$validation = Submission_Validator::validate($form, $decoded);
		$data = $validation['data'];
		$errors = $validation['errors'];
		if ($errors !== []) wp_send_json_error(['message' => __('Перевірте правильність заповнення полів.', 'leadforms-go'), 'errors' => $errors], 422);
		if ($data === []) wp_send_json_error(['message' => __('Дані форми відсутні.', 'leadforms-go')], 422);
		if (! $this->consume_rate_limit($form_id)) wp_send_json_error(['message' => __('Забагато спроб. Спробуйте пізніше.', 'leadforms-go')], 429);
		$referer = wp_get_referer() ?: '';
		$data = apply_filters('leadforms_go_submission_data', $data, $form_id, $referer);
		if (! is_array($data) || $data === []) wp_send_json_error(['message' => __('Дані форми відсутні.', 'leadforms-go')], 422);
		$submission_id = Repositories::create_submission($form_id, $data, $referer);
		if ($submission_id <= 0) wp_send_json_error(['message' => __('Не вдалося зберегти заявку. Спробуйте ще раз.', 'leadforms-go')], 500);
		$delivery_count = $this->queue?->queue_submission($submission_id) ?? 0;
		if (! $this->legacy_addons_active()) {
			try {
				do_action('ri_send_integration', $data, $referer);
			} catch (\Throwable) {
				// Compatibility callbacks must not invalidate an already stored submission.
			}
		}
		do_action('leadforms_go_submission_processed', $submission_id, $data, $referer);
		wp_send_json_success(['message' => __('Дякуємо! Форму успішно відправлено.', 'leadforms-go'), 'submission_id' => $submission_id, 'deliveries' => $delivery_count]);
	}

	public function capture_submission(array $data, ?int $form_id = null, string $referer = ''): int
	{
		$data = apply_filters('leadforms_go_submission_data', $data, $form_id, $referer);
		if (! is_array($data) || $data === []) return 0;
		$submission_id = Repositories::create_submission($form_id, $data, $referer);
		if ($submission_id <= 0) return 0;
		$this->queue?->queue_submission($submission_id);
		do_action('leadforms_go_submission_processed', $submission_id, $data, $referer);
		return $submission_id;
	}

	private function consume_rate_limit(int $form_id): bool
	{
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
		$key = 'leadforms_go_rate_' . hash_hmac('sha256', $form_id . ':' . $ip, wp_salt('nonce'));
		$count = (int) get_transient($key);
		if ($count >= 10) return false;
		set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS);
		return true;
	}

	private function legacy_addons_active(): bool
	{
		$active = (array) get_option('active_plugins', []);
		return (bool) array_intersect($active, ['reIntegrationSheets/reIntegrationSheets.php', 'reIntegrationTelegram/reIntegrationTelegram.php', 'reIntegrationCRM/reIntegrationCRM.php']);
	}
}
