<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Admin
{
	public function boot(): void
	{
		(new Admin_Integrations())->boot();
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [Settings::class, 'register']);
		add_action('admin_init', [Connection_Profiles::class, 'register']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('admin_notices', [$this, 'legacy_notice']);
		add_action('admin_post_leadforms_go_save_form', [$this, 'save_form']);
		add_action('admin_post_leadforms_go_delete_form', [$this, 'delete_form']);
		add_action('admin_post_leadforms_go_retry_delivery', [$this, 'retry_delivery']);
		add_action('admin_post_leadforms_go_retry_submission', [$this, 'retry_submission']);
		add_action('admin_post_leadforms_go_bulk_retry', [$this, 'bulk_retry']);
		add_action('wp_ajax_leadforms_go_test_connector', [$this, 'test_connector']);
		add_action('wp_ajax_leadforms_go_upload_google_credentials', [$this, 'upload_google_credentials']);
		add_action('wp_ajax_leadforms_go_remove_google_credentials', [$this, 'remove_google_credentials']);
	}

	public function menu(): void
	{
		add_menu_page('LeadForms Go', 'LeadForms Go', 'manage_options', 'leadforms-go', [$this, 'dashboard'], 'dashicons-feedback', 32);
		add_submenu_page('leadforms-go', __('Форми', 'leadforms-go'), __('Форми', 'leadforms-go'), 'manage_options', 'leadforms-go-forms', [$this, 'forms']);
		add_submenu_page('leadforms-go', __('Історія', 'leadforms-go'), __('Історія', 'leadforms-go'), 'leadforms_go_view_submissions', 'leadforms-go-history', [$this, 'history']);
		add_submenu_page('leadforms-go', __('Налаштування', 'leadforms-go'), __('Налаштування', 'leadforms-go'), 'manage_options', 'leadforms-go-settings', [$this, 'settings']);
	}

	public function assets(string $hook): void
	{
		if (! str_contains($hook, 'leadforms-go')) return;
		$style_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin.css') ?: LEADFORMS_GO_VERSION;
		$script_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin.js') ?: LEADFORMS_GO_VERSION;
		wp_enqueue_style('leadforms-go-admin', LEADFORMS_GO_URL . 'assets/admin.css', [], (string) $style_version);
		wp_add_inline_style('leadforms-go-admin', '.leadforms-go-admin{width:auto;max-width:none}.leadforms-go-admin .button .dashicons{display:inline-flex;align-items:center;justify-content:center;flex:0 0 1.125rem;width:1.125rem;height:1.125rem;font-size:1.125rem;line-height:1}.lfg-delivery-status.is-sent{background:#edfaef;color:#008a20}.lfg-attempt-dot.is-sent{background:#008a20}.lfg-editor-toolbar{display:flex;align-items:center;justify-content:space-between;margin:1rem 0}.lfg-editor-toolbar .button{display:inline-flex;align-items:center;gap:.375rem;min-height:2.5rem;line-height:1}.lfg-shortcode{white-space:nowrap}.lfg-shortcode code{display:block}.lfg-shortcode .dashicons{display:inline-flex;align-items:center;justify-content:center;flex:0 0 1.125rem}.lfg-form-saved-notice{margin:0 0 1rem}');
		wp_enqueue_script('leadforms-go-admin', LEADFORMS_GO_URL . 'assets/admin.js', [], (string) $script_version, true);
		$route_script_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin-integrations.js') ?: LEADFORMS_GO_VERSION;
		$route_style_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin-integrations.css') ?: LEADFORMS_GO_VERSION;
		$modern_style_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin-modern.css') ?: LEADFORMS_GO_VERSION;
		wp_enqueue_script('leadforms-go-admin-integrations', LEADFORMS_GO_URL . 'assets/admin-integrations.js', ['leadforms-go-admin'], (string) $route_script_version, true);
		wp_enqueue_style('leadforms-go-admin-integrations', LEADFORMS_GO_URL . 'assets/admin-integrations.css', ['leadforms-go-admin'], (string) $route_style_version);
		wp_enqueue_style('leadforms-go-admin-modern', LEADFORMS_GO_URL . 'assets/admin-modern.css', ['leadforms-go-admin', 'leadforms-go-admin-integrations'], (string) $modern_style_version);
		wp_add_inline_script('leadforms-go-admin-integrations', 'window.leadFormsGoRoutes=' . wp_json_encode(['ajaxUrl' => wp_make_link_relative(admin_url('admin-ajax.php')), 'nonce' => wp_create_nonce('leadforms_go_routes'), 'requestFailed' => __('Не вдалося виконати запит.', 'leadforms-go'), 'testing' => __('Відправлення…', 'leadforms-go'), 'queued' => __('У черзі…', 'leadforms-go'), 'success' => __('Тест успішно доставлено.', 'leadforms-go')]) . ';', 'before');
		wp_add_inline_script('leadforms-go-admin', 'window.leadFormsGoAdmin=' . wp_json_encode([
			'ajaxUrl' => wp_make_link_relative(admin_url('admin-ajax.php')),
			'nonce' => wp_create_nonce('leadforms_go_admin'),
			'testing' => __('Перевірка…', 'leadforms-go'),
			'confirmDelete' => __('Видалити цю форму?', 'leadforms-go'),
			'requestFailed' => __('Не вдалося виконати запит.', 'leadforms-go'),
			'copied' => __('Скопійовано', 'leadforms-go'),
			'copyFailed' => __('Не вдалося скопіювати', 'leadforms-go'),
			'selectGoogleJson' => __('Спочатку виберіть JSON-файл.', 'leadforms-go'),
			'invalidGoogleJson' => __('Виберіть JSON-файл розміром до 128 КБ.', 'leadforms-go'),
			'confirmGoogleRemove' => __('Видалити збережений JSON-ключ Google?', 'leadforms-go'),
			'builder' => [
				'maxFields' => Form_Builder::MAX_FIELDS,
				'localeDefaults' => Form_Translations::builder_defaults(),
				'maxFieldsMessage' => sprintf(__('У формі може бути не більше %d полів.', 'leadforms-go'), Form_Builder::MAX_FIELDS),
				'required' => __('Обов’язкове поле', 'leadforms-go'),
				'moveUp' => __('Перемістити вище', 'leadforms-go'),
				'moveDown' => __('Перемістити нижче', 'leadforms-go'),
				'remove' => __('Видалити', 'leadforms-go'),
				'empty' => __('Додайте поля з бібліотеки ліворуч.', 'leadforms-go'),
				'fieldLabel' => __('Підпис', 'leadforms-go'),
				'fieldLabelHelp' => __('Текст, який бачить відвідувач біля поля.', 'leadforms-go'),
				'fieldName' => __('Технічний ключ', 'leadforms-go'),
				'fieldNameHelp' => __('Незмінний ключ для Telegram, CRM, Sheets та історії.', 'leadforms-go'),
				'placeholder' => __('Підказка в полі', 'leadforms-go'),
				'placeholderHelp' => __('Текст усередині порожнього поля.', 'leadforms-go'),
				'options' => __('Варіанти', 'leadforms-go'),
				'optionsHelp' => __('Один варіант на рядок. Технічні values створюються з основної мови.', 'leadforms-go'),
				'defaultValue' => __('Значення прихованого поля', 'leadforms-go'),
				'condition' => __('Умовне відображення', 'leadforms-go'),
				'conditionDisabled' => __('Завжди показувати', 'leadforms-go'),
				'conditionValue' => __('Значення для порівняння', 'leadforms-go'),
				'conditionOperators' => ['equals' => __('Дорівнює', 'leadforms-go'), 'not_equals' => __('Не дорівнює', 'leadforms-go'), 'contains' => __('Містить', 'leadforms-go'), 'filled' => __('Заповнено', 'leadforms-go')],
				'copyLanguageConfirm' => __('Замінити тексти цієї мови текстами з основної мови?', 'leadforms-go'),
				'iconSvgInvalid' => __('Вставте коректний SVG-код.', 'leadforms-go'),
				'faCatalog' => self::fontawesome_catalog(),
				'messageLabels' => [
					'sending' => __('Відправлення', 'leadforms-go'),
					'success' => __('Успішне відправлення', 'leadforms-go'),
					'error' => __('Помилка відправлення', 'leadforms-go'),
					'required' => __('Обов’язкове поле', 'leadforms-go'),
					'invalid' => __('Некоректне значення', 'leadforms-go'),
					'email' => __('Некоректний email', 'leadforms-go'),
					'phone' => __('Некоректний телефон', 'leadforms-go'),
					'tooLong' => __('Завелике значення', 'leadforms-go'),
					'emoji' => __('Заборонені смайлики', 'leadforms-go'),
				],
			],
		]) . ';', 'before');
	}

	public function dashboard(): void
	{
		$this->require_capability('manage_options');
		$this->open(__('Огляд', 'leadforms-go'));
		$transfer = Database::site_transfer_notice();
		if ($transfer !== []) {
			$message = sprintf(
				__('Виявлено перенесення сайту з %1$s на %2$s. Інтеграції вимкнено, активні доставки скасовано: %3$d. Перевірте налаштування перед новими заявками.', 'leadforms-go'),
				(string) ($transfer['previous'] ?? ''),
				(string) ($transfer['current'] ?? ''),
				(int) ($transfer['cancelled'] ?? 0)
			);
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Захист після перенесення сайту.', 'leadforms-go') . '</strong> ' . esc_html($message) . '</p></div>';
		}
		$stats = Repositories::dashboard_stats();
		$queue = (new Delivery_Queue())->health();
		echo '<div class="lfg-dashboard-header"><div><h2>' . esc_html__('LeadForms Go', 'leadforms-go') . '</h2><p>' . esc_html__('Короткий огляд форм, заявок та інтеграцій.', 'leadforms-go') . '</p></div><div class="lfg-dashboard-actions"><a class="button lfg-dashboard-button lfg-dashboard-button--secondary" href="' . esc_url(admin_url('admin.php?page=leadforms-go-settings')) . '">' . esc_html__('Налаштування', 'leadforms-go') . '</a><a class="button button-primary lfg-dashboard-button lfg-dashboard-button--primary" href="' . esc_url(admin_url('admin.php?page=leadforms-go-forms&new=1')) . '"><span class="lfg-button-icon" aria-hidden="true"></span>' . esc_html__('Додати форму', 'leadforms-go') . '</a></div></div>';
		if (! $queue['healthy']) {
			if ($queue['cron_disabled']) $queue_warning = __('WP-Cron вимкнений. Серверний fallback обробить прострочені доставки під час наступних запитів, але для стабільної роботи налаштуйте системний cron.', 'leadforms-go');
			elseif ($queue['cron_overdue']) $queue_warning = __('Cron-подія прострочена. Серверний fallback обробить чергу під час наступного WordPress-запиту.', 'leadforms-go');
			elseif ($queue['cron_delayed']) $queue_warning = __('Наступний запуск WP-Cron запланований запізно відносно прострочених доставок.', 'leadforms-go');
			else $queue_warning = __('Є прострочені доставки, але обробник WP-Cron не запланований.', 'leadforms-go');
			echo '<div class="notice notice-warning inline lfg-queue-warning"><p><strong>' . esc_html__('Черга доставки потребує уваги.', 'leadforms-go') . '</strong> ' . esc_html($queue_warning) . '</p></div>';
		}
		echo '<div class="lfg-dashboard-stats">';
		$cards = [
			['icon' => 'feedback', 'value' => $stats['forms'], 'label' => __('Активні форми', 'leadforms-go')],
			['icon' => 'email-alt', 'value' => $stats['today'], 'label' => __('Заявки сьогодні', 'leadforms-go')],
			['icon' => 'clock', 'value' => $queue['queued'], 'label' => __('У черзі', 'leadforms-go')],
			['icon' => 'warning', 'value' => $stats['failed_today'], 'label' => __('Помилки сьогодні', 'leadforms-go')],
			['icon' => 'yes-alt', 'value' => $stats['success_rate'] . '%', 'label' => __('Успішна доставка', 'leadforms-go')],
			['icon' => 'visibility', 'value' => $stats['views_week'], 'label' => __('Перегляди форм за 7 днів', 'leadforms-go')],
			['icon' => 'chart-line', 'value' => $stats['conversion_rate'] . '%', 'label' => __('Конверсія за 7 днів', 'leadforms-go')],
		];
		foreach ($cards as $card) {
			printf('<article class="lfg-stat-card"><span class="dashicons dashicons-%s"></span><div><strong>%s</strong><span>%s</span></div></article>', esc_attr($card['icon']), esc_html((string) $card['value']), esc_html($card['label']));
		}
		echo '</div><div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('UTM-джерела за 7 днів', 'leadforms-go') . '</h2></div><div class="lfg-utm-sources">';
		if (($stats['top_sources'] ?? []) === []) echo '<p class="lfg-empty-state">' . esc_html__('Даних про джерела ще немає.', 'leadforms-go') . '</p>';
		else foreach ($stats['top_sources'] as $source) printf('<div class="lfg-utm-source"><span>%s</span><strong>%d</strong></div>', esc_html((string) $source['source']), (int) $source['submissions']);
		echo '</div></div><div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('Інтеграції', 'leadforms-go') . '</h2><a href="' . esc_url(admin_url('admin.php?page=leadforms-go-settings')) . '">' . esc_html__('Керувати', 'leadforms-go') . '</a></div><div class="lfg-grid">';
		$titles = ['telegram' => 'Telegram', 'sheets' => 'Google Sheets', 'crm' => 'CRM G-PLUS'];
		foreach (Connectors::all() as $connector) {
			$valid = $connector->validate_settings();
			$status = ! $connector->is_enabled() ? __('Вимкнено', 'leadforms-go') : (is_wp_error($valid) ? __('Потрібне налаштування', 'leadforms-go') : __('Увімкнено', 'leadforms-go'));
			$activity = $stats['activity'][$connector->key()] ?? ['success' => 0, 'failed' => 0, 'queued' => 0, 'processing' => 0, 'last_success' => ''];
			$last_success_at = $activity['last_success'] ? strtotime((string) $activity['last_success']) : false;
			$last_success = $last_success_at ? sprintf(__('Остання доставка: %s тому', 'leadforms-go'), human_time_diff($last_success_at, current_time('timestamp'))) : __('Успішних доставок ще немає', 'leadforms-go');
			printf('<article class="lfg-card lfg-integration-card"><div class="lfg-integration-card__heading"><h3>%s</h3><span class="lfg-status%s">%s</span></div><p>%s</p><div class="lfg-integration-metrics"><span><strong>%d</strong>%s</span><span class="is-error"><strong>%d</strong>%s</span><span><strong>%d</strong>%s</span></div></article>', esc_html($titles[$connector->key()] ?? ucfirst($connector->key())), $connector->is_enabled() && ! is_wp_error($valid) ? ' is-active' : '', esc_html($status), esc_html($last_success), (int) $activity['success'], esc_html__('успішно', 'leadforms-go'), (int) $activity['failed'], esc_html__('помилок', 'leadforms-go'), (int) $activity['queued'], esc_html__('у черзі', 'leadforms-go'));
		}
		$last_source = match ($queue['last_source']) {
			'fallback' => __('fallback', 'leadforms-go'),
			'client' => __('браузер', 'leadforms-go'),
			default => __('WP-Cron', 'leadforms-go'),
		};
		$last_run = $queue['last_run'] ? sprintf(__('%1$s тому · %2$s', 'leadforms-go'), human_time_diff($queue['last_run'], time()), $last_source) : __('Ще не запускався', 'leadforms-go');
		$next_run = $queue['scheduled'] ? wp_date('d.m.Y H:i', (int) $queue['scheduled']) : '—';
		if ($queue['cron_overdue']) $next_run .= ' · ' . __('прострочено', 'leadforms-go');
		echo '</div></div><div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('Стан черги', 'leadforms-go') . '</h2></div><div class="lfg-queue-card"><div><span>' . esc_html__('Прострочені доставки', 'leadforms-go') . '</span><strong>' . esc_html((string) $queue['due']) . '</strong></div><div><span>' . esc_html__('Обробляються', 'leadforms-go') . '</span><strong>' . esc_html((string) $queue['processing']) . '</strong></div><div><span>' . esc_html__('Останній запуск', 'leadforms-go') . '</span><strong>' . esc_html($last_run) . '</strong></div><div><span>' . esc_html__('Наступний запуск', 'leadforms-go') . '</span><strong>' . esc_html($next_run) . '</strong></div></div></div>';
		echo '<div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('Останні заявки', 'leadforms-go') . '</h2><a href="' . esc_url(admin_url('admin.php?page=leadforms-go-history')) . '">' . esc_html__('Вся історія', 'leadforms-go') . '</a></div><div class="lfg-recent-submissions">';
		$recent = Repositories::submissions(5, ['exclude_test' => true]);
		if ($recent === []) {
			echo '<p class="lfg-empty-state">' . esc_html__('Заявок поки немає. Після першого надсилання вони з’являться тут.', 'leadforms-go') . '</p>';
		} else {
			$titles = ['telegram' => 'Telegram', 'sheets' => 'Google Sheets', 'crm' => 'CRM G-PLUS'];
			echo '<table class="widefat lfg-recent-table"><thead><tr><th>' . esc_html__('Заявка', 'leadforms-go') . '</th><th>' . esc_html__('Контактні дані', 'leadforms-go') . '</th><th>' . esc_html__('Доставка', 'leadforms-go') . '</th><th>' . esc_html__('Джерело', 'leadforms-go') . '</th><th><span class="screen-reader-text">' . esc_html__('Дії', 'leadforms-go') . '</span></th></tr></thead><tbody>';
			foreach ($recent as $row) {
				$payload = json_decode((string) $row['payload'], true);
				$payload = Submission_Presenter::for_admin(is_array($payload) ? $payload : [], (int) $row['form_id'], (string) ($row['locale'] ?? ''));
				$source = (string) $row['referer'];
				$source_html = $source !== ''
					? '<a class="lfg-dashboard-source" href="' . esc_url($source) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($source) . '">' . esc_html($source) . '</a>'
					: '<span class="lfg-dashboard-source">—</span>';
				$details_url = admin_url('admin.php?page=leadforms-go-history&submission=' . (int) $row['id']);
				echo '<tr><td class="lfg-recent-submission"><a class="lfg-submission-id" href="' . esc_url($details_url) . '">#' . (int) $row['id'] . '</a><strong>' . esc_html($row['form_name'] ?: __('Видалена або імпортована форма', 'leadforms-go')) . '</strong><time>' . esc_html(wp_date('d.m.Y H:i', strtotime((string) $row['created_at']))) . '</time></td><td><div class="lfg-payload-preview">' . esc_html(self::payload_preview($payload)) . '</div></td><td><div class="lfg-delivery-stack">';
				if ($row['deliveries'] === []) echo '<span class="lfg-delivery-status is-success">' . esc_html__('Без інтеграцій', 'leadforms-go') . '</span>';
				foreach ($row['deliveries'] as $delivery) printf('<span class="lfg-delivery-status is-%s" title="%s"><strong>%s</strong><span>%s</span></span>', esc_attr($delivery['status']), esc_attr((string) $delivery['error_message']), esc_html(self::delivery_title($delivery, $titles)), esc_html(self::status_label((string) $delivery['status'])));
				echo '</div></td><td>' . $source_html . '</td><td><a class="button lfg-details-button" href="' . esc_url($details_url) . '">' . esc_html__('Деталі', 'leadforms-go') . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
		$this->close();
	}

	public function forms(): void
	{
		$this->require_capability('manage_options');
		$id = isset($_GET['id']) ? absint($_GET['id']) : 0;
		$form = $id ? Repositories::form($id) : null;
		$this->open($form ? __('Редагування форми', 'leadforms-go') : __('Форми', 'leadforms-go'));
		if ($form || isset($_GET['new'])) {
			echo '<div class="lfg-editor-toolbar"><a class="button" href="' . esc_url(admin_url('admin.php?page=leadforms-go-forms')) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>' . esc_html__('До списку форм', 'leadforms-go') . '</a></div>';
			if (isset($_GET['updated']) && absint($_GET['updated']) === 1) {
				echo '<div class="notice notice-success is-dismissible inline lfg-form-saved-notice"><p><strong>' . esc_html__('Форму успішно збережено.', 'leadforms-go') . '</strong></p></div>';
			}
			$mode = in_array($form['editor_mode'] ?? '', ['visual', 'code'], true) ? $form['editor_mode'] : ($form ? 'code' : 'visual');
			$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
			$schema = Form_Builder::sanitize_schema(is_array($schema) ? $schema : []);
			$route_schema = $schema !== [] ? $schema : Submission_Validator::schema_for_code((string) ($form['code'] ?? ''));
			$submit_label = (string) ($form['submit_label'] ?? 'Надіслати');
			$stored_button_icon = json_decode((string) ($form['button_icon'] ?? ''), true);
			$button_icon = Form_Builder::sanitize_button_icon(is_array($stored_button_icon) ? $stored_button_icon : []);
			$default_locale = Form_Translations::normalize_locale((string) ($form['default_locale'] ?? '')) ?: Form_Translations::DEFAULT_LOCALE;
			$translations = json_decode((string) ($form['translations'] ?? ''), true);
			$translations = Form_Translations::sanitize(is_array($translations) ? $translations : []);
			if ($schema !== []) $translations = Form_Translations::complete($translations, $schema);
			$locales = Form_Translations::available_locales();
			echo '<form class="lfg-card lfg-form-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('leadforms_go_save_form');
			echo '<input type="hidden" name="action" value="leadforms_go_save_form"><input type="hidden" name="id" value="' . esc_attr((string) ($form['id'] ?? 0)) . '">';
			echo '<section class="lfg-form-meta"><label><strong>' . esc_html__('Назва форми', 'leadforms-go') . '</strong><small>' . esc_html__('Використовується лише в адмінці, щоб швидко знайти потрібну форму.', 'leadforms-go') . '</small><input required name="name" value="' . esc_attr((string) ($form['name'] ?? '')) . '" placeholder="' . esc_attr__('Наприклад: Форма головного екрана', 'leadforms-go') . '"></label><label class="lfg-form-active"><input type="checkbox" name="active" value="1" ' . checked(! $form || ! empty($form['active']), true, false) . '><span><strong>' . esc_html__('Форма активна', 'leadforms-go') . '</strong><small>' . esc_html__('Вимкнена форма не відображається через shortcode і не приймає заявки.', 'leadforms-go') . '</small></span></label></section>';
			echo '<input type="hidden" name="editor_mode" value="' . esc_attr($mode) . '" data-lfg-mode-input>';
			echo '<div class="lfg-editor-tabs" role="tablist" aria-label="' . esc_attr__('Розділ редактора', 'leadforms-go') . '"><button type="button" class="lfg-editor-tab" role="tab" aria-controls="lfg-visual-panel" data-lfg-mode="visual">' . esc_html__('Візуально', 'leadforms-go') . '</button><button type="button" class="lfg-editor-tab" role="tab" aria-controls="lfg-code-panel" data-lfg-mode="code">' . esc_html__('Код', 'leadforms-go') . '</button><button type="button" class="lfg-editor-tab" role="tab" aria-controls="lfg-integrations-panel" data-lfg-integrations-tab>' . esc_html__('Інтеграції', 'leadforms-go') . '</button></div>';
			echo '<section class="lfg-language-panel"><div><strong>' . esc_html__('Мова форми', 'leadforms-go') . '</strong><span>' . esc_html__('Структура спільна для всіх мов. Тут змінюються лише тексти.', 'leadforms-go') . '</span></div><div class="lfg-language-tabs" role="tablist">';
			foreach ($locales as $locale => $label) printf('<button type="button" class="lfg-language-tab" data-lfg-locale="%s" role="tab"><span>%s</span><small data-lfg-locale-progress></small></button>', esc_attr($locale), esc_html((string) $label));
			echo '</div><div class="lfg-language-actions"><label><span>' . esc_html__('Основна мова', 'leadforms-go') . '</span><select name="default_locale" data-lfg-default-locale>';
			foreach ($locales as $locale => $label) echo '<option value="' . esc_attr($locale) . '" ' . selected($default_locale, $locale, false) . '>' . esc_html((string) $label) . '</option>';
			echo '</select></label><button type="button" class="button" data-lfg-copy-language>' . esc_html__('Скопіювати тексти з основної мови', 'leadforms-go') . '</button></div><textarea hidden name="translations" data-lfg-translations>' . esc_textarea((string) wp_json_encode($translations, JSON_UNESCAPED_UNICODE)) . '</textarea></section>';
			echo '<div id="lfg-visual-panel" role="tabpanel" data-lfg-panel="visual" class="lfg-builder"' . ($mode === 'visual' ? '' : ' hidden') . '><aside class="lfg-builder__palette"><h2>' . esc_html__('Готові поля', 'leadforms-go') . '</h2><p>' . esc_html__('Натисніть на плитку, щоб додати поле.', 'leadforms-go') . '</p><div class="lfg-builder__tiles">';
			foreach (Form_Builder::tiles() as $key => $tile) {
				printf('<button type="button" class="lfg-field-tile" data-lfg-add="%s" data-lfg-template="%s"><span class="dashicons dashicons-plus-alt2"></span>%s</button>', esc_attr($key), esc_attr((string) wp_json_encode($tile, JSON_UNESCAPED_UNICODE)), esc_html($tile['label']));
			}
			echo '</div></aside><section class="lfg-builder__workspace"><h2>' . esc_html__('Поля форми', 'leadforms-go') . '</h2><div data-lfg-canvas></div><label><span>' . esc_html__('Текст кнопки', 'leadforms-go') . '</span><input type="text" name="submit_label" value="' . esc_attr($submit_label) . '"></label>' . $this->button_icon_settings($button_icon) . $this->success_action_settings($form) . '<details class="lfg-message-settings"><summary>' . esc_html__('Повідомлення форми', 'leadforms-go') . '</summary><div data-lfg-message-fields></div></details><section class="lfg-form-preview"><h3>' . esc_html__('Попередній перегляд', 'leadforms-go') . '</h3><div data-lfg-preview></div></section><textarea hidden name="schema" data-lfg-schema>' . esc_textarea((string) wp_json_encode($schema, JSON_UNESCAPED_UNICODE)) . '</textarea></section></div>';
			echo '<div id="lfg-code-panel" role="tabpanel" data-lfg-panel="code"' . ($mode === 'code' ? '' : ' hidden') . '><label class="lfg-code-editor"><span>' . esc_html__('HTML-код форми', 'leadforms-go') . '</span><textarea name="code" rows="22" data-lfg-code>' . esc_textarea((string) ($form['code'] ?? '')) . '</textarea></label><p class="description">' . esc_html__('Код відформатовано для читання. Якщо змінити його вручну й зберегти форму в режимі «Код», візуальна схема більше не використовуватиметься.', 'leadforms-go') . '</p></div>';
			echo '<div id="lfg-integrations-panel" role="tabpanel" data-lfg-integrations-panel hidden>' . (new Admin_Integrations())->render($form, $route_schema, $locales) . '</div>';
			submit_button(__('Зберегти форму', 'leadforms-go'));
			echo '</form>';
		} else {
			echo '<div class="lfg-page-actions"><p>' . esc_html__('Створюйте й керуйте формами без ручного написання HTML.', 'leadforms-go') . '</p><a class="button button-primary lfg-add-form-button" href="' . esc_url(admin_url('admin.php?page=leadforms-go-forms&new=1')) . '"><span class="lfg-button-icon" aria-hidden="true"></span>' . esc_html__('Додати форму', 'leadforms-go') . '</a></div><div class="lfg-forms-list"><table class="widefat"><thead><tr><th>' . esc_html__('Форма', 'leadforms-go') . '</th><th>' . esc_html__('Режим', 'leadforms-go') . '</th><th>' . esc_html__('Шорткод', 'leadforms-go') . '</th><th class="lfg-actions-column">' . esc_html__('Дії', 'leadforms-go') . '</th></tr></thead><tbody>';
			foreach (Repositories::form_summaries() as $item) {
				$edit = admin_url('admin.php?page=leadforms-go-forms&id=' . (int) $item['id']);
				$visual = ($item['editor_mode'] ?? 'code') === 'visual';
				printf('<tr><td><strong>%s</strong><span class="lfg-form-id">ID %d · %s</span></td><td><span class="lfg-mode-badge">%s</span></td><td><button type="button" class="lfg-shortcode" data-lfg-copy="[leadforms_go_form id=&quot;%d&quot;]" title="%s"><code>[leadforms_go_form id=&quot;%d&quot;]</code><span class="dashicons dashicons-admin-page"></span></button></td><td class="lfg-row-actions"><a class="button" href="%s"><span class="dashicons dashicons-edit"></span>%s</a><form method="post" action="%s"><input type="hidden" name="action" value="leadforms_go_delete_form"><input type="hidden" name="id" value="%d"><input type="hidden" name="_wpnonce" value="%s"><button type="submit" class="lfg-delete-button" data-lfg-confirm aria-label="%s" title="%s"><span class="dashicons dashicons-trash"></span></button></form></td></tr>', esc_html($item['name']), (int) $item['id'], esc_html(! empty($item['active']) ? __('активна', 'leadforms-go') : __('вимкнена', 'leadforms-go')), esc_html($visual ? __('Візуально', 'leadforms-go') : __('Код', 'leadforms-go')), (int) $item['id'], esc_attr__('Копіювати шорткод', 'leadforms-go'), (int) $item['id'], esc_url($edit), esc_html__('Редагувати', 'leadforms-go'), esc_url(admin_url('admin-post.php')), (int) $item['id'], esc_attr(wp_create_nonce('leadforms_go_delete_form_' . (int) $item['id'])), esc_attr__('Видалити форму', 'leadforms-go'), esc_attr__('Видалити', 'leadforms-go'));
			}
			echo '</tbody></table></div>';
		}
		$this->close();
	}

	public function history(): void
	{
		$this->require_capability('leadforms_go_view_submissions');
		$this->open(__('Історія заявок', 'leadforms-go'));
		$this->history_notice();
		$submission_id = isset($_GET['submission']) ? absint($_GET['submission']) : 0;
		if ($submission_id > 0) $this->submission_details($submission_id);
		else $this->submission_list();
		$this->close();
	}

	public function settings(): void
	{
		$this->require_capability('manage_options');
		$s = Settings::all(); $name = 'leadforms_go_settings';
		$this->open(__('Налаштування', 'leadforms-go'));
		echo '<form method="post" action="options.php">'; settings_fields('leadforms_go'); echo '<div class="lfg-settings-grid">';
		echo '<section class="lfg-card lfg-settings"><h2>' . esc_html__('Зберігання даних', 'leadforms-go') . '</h2><label><input type="checkbox" name="' . esc_attr($name . '[general][retain_data]') . '" value="1" ' . checked(! empty($s['general']['retain_data']), true, false) . '> ' . esc_html__('Зберігати форми та заявки після видалення плагіна', 'leadforms-go') . '</label><label><span>' . esc_html__('Строк зберігання заявок, днів', 'leadforms-go') . '</span><input class="small-text" type="number" min="0" max="3650" name="' . esc_attr($name . '[general][retention_days]') . '" value="' . esc_attr((string) ($s['general']['retention_days'] ?? 180)) . '"><small>' . esc_html__('0 — не видаляти автоматично.', 'leadforms-go') . '</small></label><label><span>' . esc_html__('Строк зберігання UTM у браузері, днів', 'leadforms-go') . '</span><input class="small-text" type="number" min="0" max="365" name="' . esc_attr($name . '[general][attribution_days]') . '" value="' . esc_attr((string) ($s['general']['attribution_days'] ?? 30)) . '"></label></section>';
		$antispam = is_array($s['antispam'] ?? null) ? $s['antispam'] : [];
		echo '<section class="lfg-card lfg-settings"><h2>' . esc_html__('Антиспам і CAPTCHA', 'leadforms-go') . '</h2><label><span>' . esc_html__('CAPTCHA-провайдер', 'leadforms-go') . '</span><select name="' . esc_attr($name . '[antispam][provider]') . '"><option value="none">' . esc_html__('Вимкнено', 'leadforms-go') . '</option><option value="turnstile" ' . selected($antispam['provider'] ?? 'none', 'turnstile', false) . '>Cloudflare Turnstile</option></select></label><label><span>Turnstile Site Key</span><input class="regular-text" type="text" name="' . esc_attr($name . '[antispam][turnstile_site_key]') . '" value="' . esc_attr((string) ($antispam['turnstile_site_key'] ?? '')) . '"></label><label><span>Turnstile Secret Key</span><input class="regular-text" type="password" name="' . esc_attr($name . '[antispam][turnstile_secret_key]') . '" value="" placeholder="' . esc_attr(! empty($antispam['turnstile_secret_key']) ? __('Збережено — залиште порожнім, щоб не змінювати', 'leadforms-go') : '') . '"><small>' . esc_html__('Токен перевіряється лише на сервері з hostname та action validation.', 'leadforms-go') . '</small></label></section>';
		$sections = [
			'telegram' => ['title' => 'Telegram', 'fields' => ['token' => 'Токен бота', 'chat_id' => 'ID чату']],
			'sheets' => ['title' => 'Google Sheets', 'fields' => ['spreadsheet_id' => 'Посилання або ID таблиці', 'sheet_name' => 'Назва аркуша', 'fields_order' => 'Порядок полів']],
			'crm' => ['title' => 'CRM G-PLUS', 'fields' => ['partner_id' => 'ID партнера', 'token' => 'API-токен', 'adv_id' => 'ID рекламної форми']],
		];
		$sheets_help = [
			'spreadsheet_id' => __('Скопіюйте частину адреси таблиці між /d/ і /edit.', 'leadforms-go'),
			'sheet_name' => __('Вкажіть точну назву вкладки внизу Google-таблиці.', 'leadforms-go'),
			'fields_order' => __('Технічні ключі полів через кому. Поля, яких немає у списку, будуть додані після них.', 'leadforms-go'),
		];
		$sheets_placeholders = ['spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/…/edit', 'sheet_name' => __('Аркуш1', 'leadforms-go'), 'fields_order' => 'first_name, phone, consent'];
		foreach ($sections as $section => $section_data) {
			echo '<section class="lfg-card lfg-settings"><header><h2>' . esc_html($section_data['title']) . '</h2><label class="lfg-switch"><input type="checkbox" name="' . esc_attr($name . '[' . $section . '][enabled]') . '" value="1" ' . checked(! empty($s[$section]['enabled']), true, false) . '><span>' . esc_html__('Увімкнено', 'leadforms-go') . '</span></label></header>';
			if ($section === 'telegram' && (defined('LEADFORMS_GO_TELEGRAM_TOKEN') || defined('LEADFORMS_GO_TELEGRAM_CHAT_ID'))) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__('Telegram налаштовано через wp-config.php. Значення з адмінки не можуть замінити активні константи LEADFORMS_GO_TELEGRAM_TOKEN або LEADFORMS_GO_TELEGRAM_CHAT_ID.', 'leadforms-go') . '</p></div>';
			}
			$fields = $section_data['fields'];
			foreach ($fields as $key => $label) {
				$is_secret = $key === 'token'; $value = $is_secret ? '' : (string) ($s[$section][$key] ?? '');
				$placeholder = $is_secret && ! empty($s[$section][$key]) ? __('Збережено — залиште порожнім, щоб не змінювати', 'leadforms-go') : (string) ($sheets_placeholders[$key] ?? '');
				echo '<label><span>' . esc_html($label) . '</span><input class="regular-text" type="' . ($is_secret ? 'password' : 'text') . '" name="' . esc_attr($name . '[' . $section . '][' . $key . ']') . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '">' . (isset($sheets_help[$key]) && $section === 'sheets' ? '<small>' . esc_html($sheets_help[$key]) . '</small>' : '') . '</label>';
			}
			$test_label = $section === 'sheets' ? __('Зберегти й перевірити', 'leadforms-go') : __('Перевірити підключення', 'leadforms-go');
			echo '<button type="button" class="button" data-lfg-test="' . esc_attr($section) . '">' . esc_html($test_label) . '</button><span class="lfg-test-result" aria-live="polite"></span>';
			if ($section === 'telegram') echo '<small class="lfg-test-help">' . esc_html__('Перевірка використовує поточні введені значення й не зберігає їх.', 'leadforms-go') . '</small>';
			echo '</section>';
			if ($section === 'sheets') $this->google_setup();
		}
		echo '</div>' . $this->connection_profiles(); submit_button(__('Зберегти налаштування', 'leadforms-go')); echo '</form>'; $this->close();
	}

	private function connection_profiles(): string
	{
		$profiles = Connection_Profiles::all();
		foreach (['telegram', 'sheets', 'crm'] as $connector) $profiles[] = ['id' => '', 'connector' => $connector, 'name' => ''];
		$html = '<section class="lfg-card lfg-connection-profiles"><header><h2>' . esc_html__('Профілі підключень', 'leadforms-go') . '</h2><p>' . esc_html__('Профілі можна повторно використовувати в різних формах і вибирати кілька destinations одного типу.', 'leadforms-go') . '</p></header><div class="lfg-connection-profiles__grid">';
		foreach ($profiles as $index => $profile) {
			$connector = sanitize_key((string) ($profile['connector'] ?? ''));
			$id = sanitize_key((string) ($profile['id'] ?? ''));
			$html .= '<article class="lfg-profile-card"><input type="hidden" name="leadforms_go_connection_profiles[' . (int) $index . '][id]" value="' . esc_attr($id) . '"><input type="hidden" name="leadforms_go_connection_profiles[' . (int) $index . '][connector]" value="' . esc_attr($connector) . '"><h3>' . esc_html(strtoupper($connector)) . '</h3><label><span>' . esc_html__('Назва профілю', 'leadforms-go') . '</span><input type="text" name="leadforms_go_connection_profiles[' . (int) $index . '][name]" value="' . esc_attr((string) ($profile['name'] ?? '')) . '" placeholder="' . esc_attr__('Наприклад: HR або Sales', 'leadforms-go') . '"></label>';
			if ($connector === 'telegram') {
				$html .= $this->profile_field($index, 'token', __('Токен бота', 'leadforms-go'), '', true, ! empty($profile['token'])) . $this->profile_field($index, 'chat_id', __('Chat ID', 'leadforms-go'), (string) ($profile['chat_id'] ?? '')) . $this->profile_field($index, 'topic_id', __('Topic ID', 'leadforms-go'), (string) ($profile['topic_id'] ?? 0), false, false, 'number');
			} elseif ($connector === 'sheets') {
				$html .= $this->profile_field($index, 'spreadsheet_id', __('Spreadsheet ID або URL', 'leadforms-go'), (string) ($profile['spreadsheet_id'] ?? '')) . $this->profile_field($index, 'sheet_name', __('Аркуш', 'leadforms-go'), (string) ($profile['sheet_name'] ?? ''));
			} else {
				$html .= $this->profile_field($index, 'partner_id', __('Partner ID', 'leadforms-go'), (string) ($profile['partner_id'] ?? '')) . $this->profile_field($index, 'token', __('API-токен', 'leadforms-go'), '', true, ! empty($profile['token'])) . $this->profile_field($index, 'adv_id', __('Advertising / Form ID', 'leadforms-go'), (string) ($profile['adv_id'] ?? ''));
			}
			if ($id !== '') $html .= '<label class="lfg-profile-delete"><input type="checkbox" name="leadforms_go_connection_profiles[' . (int) $index . '][delete]" value="1"> ' . esc_html__('Видалити профіль', 'leadforms-go') . '</label>';
			$html .= '</article>';
		}
		return $html . '</div></section>';
	}

	private function profile_field(int $index, string $key, string $label, string $value = '', bool $secret = false, bool $stored = false, string $type = 'text'): string
	{
		$placeholder = $secret && $stored ? __('Збережено — залиште порожнім, щоб не змінювати', 'leadforms-go') : '';
		return '<label><span>' . esc_html($label) . '</span><input type="' . esc_attr($secret ? 'password' : $type) . '" name="leadforms_go_connection_profiles[' . $index . '][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"></label>';
	}

	private function google_setup(): void
	{
		$status = Google_Credentials::status();
		$ready = ! empty($status['configured']);
		$managed_by_constant = $status['source'] === 'constant';
		echo '<section class="lfg-card lfg-google-connect">';
		echo '<header class="lfg-google-connect__header"><div><h2>' . esc_html__('Швидке підключення Google', 'leadforms-go') . '</h2><p>' . esc_html__('Усе налаштовується тут — без редагування wp-config.php.', 'leadforms-go') . '</p></div><span class="lfg-google-status ' . ($ready ? 'is-ready' : 'is-pending') . '"><span class="dashicons dashicons-' . ($ready ? 'yes-alt' : 'warning') . '" aria-hidden="true"></span>' . esc_html((string) $status['message']) . '</span></header>';
		echo '<div class="lfg-google-connect__grid">';
		echo '<article class="lfg-google-connect__step"><span class="lfg-step-number">1</span><div><h3>' . esc_html__('Завантажте JSON-ключ', 'leadforms-go') . '</h3><p>' . esc_html__('Створіть Service Account у Google Cloud, додайте JSON-ключ і завантажте його сюди. Після перевірки ключ буде зашифровано в базі.', 'leadforms-go') . '</p><div class="lfg-google-cloud-links"><a class="button" href="' . esc_url('https://console.cloud.google.com/apis/library/sheets.googleapis.com') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Увімкнути Sheets API', 'leadforms-go') . '<span class="dashicons dashicons-external" aria-hidden="true"></span></a><a class="button" href="' . esc_url('https://console.cloud.google.com/iam-admin/serviceaccounts') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Створити Service Account', 'leadforms-go') . '<span class="dashicons dashicons-external" aria-hidden="true"></span></a></div>';
		if ($managed_by_constant) {
			echo '<p class="lfg-google-legacy"><span class="dashicons dashicons-info" aria-hidden="true"></span>' . esc_html__('Зараз використовується старе налаштування з wp-config.php. Видаліть константу, щоб керувати ключем тут.', 'leadforms-go') . '</p>';
		} else {
			echo '<div class="lfg-google-upload"><input type="file" accept="application/json,.json" data-lfg-google-file aria-label="' . esc_attr__('JSON-ключ Google Service Account', 'leadforms-go') . '"><button type="button" class="button button-primary" data-lfg-google-upload><span class="dashicons dashicons-upload" aria-hidden="true"></span>' . esc_html($ready ? __('Замінити JSON-ключ', 'leadforms-go') : __('Завантажити JSON-ключ', 'leadforms-go')) . '</button>' . ($ready ? '<button type="button" class="button lfg-google-remove" data-lfg-google-remove>' . esc_html__('Видалити ключ', 'leadforms-go') . '</button>' : '') . '</div>';
		}
		echo '<span class="lfg-google-action-result" data-lfg-google-result aria-live="polite"></span></div></article>';
		echo '<article class="lfg-google-connect__step"><span class="lfg-step-number">2</span><div><h3>' . esc_html__('Надайте доступ до таблиці', 'leadforms-go') . '</h3>';
		if ($ready && $status['email'] !== '') {
			echo '<p>' . esc_html__('Скопіюйте email, відкрийте Google-таблицю → “Поділитися” → додайте його з роллю Editor.', 'leadforms-go') . '</p><button type="button" class="lfg-google-email" data-lfg-copy="' . esc_attr((string) $status['email']) . '" title="' . esc_attr__('Скопіювати email', 'leadforms-go') . '"><code>' . esc_html((string) $status['email']) . '</code><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="screen-reader-text" data-lfg-copy-feedback>' . esc_html__('Скопіювати', 'leadforms-go') . '</span></button>';
		} else {
			echo '<p class="lfg-google-placeholder">' . esc_html__('Після завантаження JSON тут з’явиться email Service Account.', 'leadforms-go') . '</p>';
		}
		echo '</div></article>';
		echo '<article class="lfg-google-connect__step"><span class="lfg-step-number">3</span><div><h3>' . esc_html__('Вставте посилання на таблицю', 'leadforms-go') . '</h3><p>' . esc_html__('У полі “Посилання або ID таблиці” вище вставте повну адресу Google-таблиці, вкажіть назву вкладки й натисніть “Зберегти й перевірити”.', 'leadforms-go') . '</p><p class="lfg-google-hint"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>' . esc_html__('Перший рядок таблиці використовуйте для заголовків колонок.', 'leadforms-go') . '</p></div></article>';
		echo '</div></section>';
	}

	private function button_icon_settings(array $button_icon): string
	{
		$button_icon = Form_Builder::sanitize_button_icon($button_icon);
		$types = [
			'none' => __('Без іконки', 'leadforms-go'),
			'svg' => __('SVG-код', 'leadforms-go'),
			'fontawesome' => __('Font Awesome', 'leadforms-go'),
		];
		$positions = [
			'after' => __('Після тексту', 'leadforms-go'),
			'before' => __('Перед текстом', 'leadforms-go'),
		];

		$html = '<details class="lfg-button-settings" data-lfg-button-settings><summary>' . esc_html__('Іконка кнопки', 'leadforms-go') . '</summary>';
		$html .= '<div class="lfg-button-settings__grid">';
		$html .= '<label><span>' . esc_html__('Тип іконки', 'leadforms-go') . '</span><select name="button_icon_type" data-lfg-button-icon-type>';
		foreach ($types as $value => $label) {
			$html .= '<option value="' . esc_attr($value) . '"' . selected($button_icon['type'], $value, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label><span>' . esc_html__('Позиція', 'leadforms-go') . '</span><select name="button_icon_position" data-lfg-button-icon-position>';
		foreach ($positions as $value => $label) {
			$html .= '<option value="' . esc_attr($value) . '"' . selected($button_icon['position'], $value, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label data-lfg-button-icon-panel="fontawesome"><span>' . esc_html__('Каталог Font Awesome', 'leadforms-go') . '</span><select data-lfg-fa-catalog><option value="">' . esc_html__('Виберіть іконку', 'leadforms-go') . '</option>';
		foreach (self::fontawesome_catalog() as $class => $label) {
			$html .= '<option value="' . esc_attr($class) . '"' . selected($button_icon['fa_class'], $class, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select><small>' . esc_html__('Font Awesome має бути підключений у темі або на сайті. Якщо потрібна іконка без залежностей — використайте SVG.', 'leadforms-go') . '</small></label>';
		$html .= '<label data-lfg-button-icon-panel="fontawesome"><span>' . esc_html__('Клас Font Awesome', 'leadforms-go') . '</span><input type="text" name="button_icon_fa_class" value="' . esc_attr($button_icon['fa_class']) . '" placeholder="fa-solid fa-arrow-right" data-lfg-button-icon-fa><small>' . esc_html__('Можна вибрати з каталогу або вписати свій клас Font Awesome.', 'leadforms-go') . '</small></label>';
		$html .= '<label class="lfg-button-settings__svg" data-lfg-button-icon-panel="svg"><span>' . esc_html__('SVG-код', 'leadforms-go') . '</span><textarea name="button_icon_svg" rows="6" data-lfg-button-icon-svg placeholder="<svg viewBox=&quot;0 0 24 24&quot;>...</svg>">' . esc_textarea($button_icon['svg']) . '</textarea><small>' . esc_html__('Вставляйте тільки сам SVG. Скрипти, стилі й небезпечні атрибути будуть видалені.', 'leadforms-go') . '</small></label>';
		$html .= '</div></details>';
		return $html;
	}

	private function success_action_settings(?array $form): string
	{
		$action = in_array($form['success_action'] ?? '', ['message', 'hide', 'redirect'], true) ? (string) $form['success_action'] : 'message';
		$html = '<details class="lfg-message-settings"><summary>' . esc_html__('Дія після успішного надсилання', 'leadforms-go') . '</summary><div class="lfg-builder-field__settings">';
		$html .= '<label><span>' . esc_html__('Дія', 'leadforms-go') . '</span><select name="success_action">';
		foreach (['message' => __('Показати повідомлення і повернути форму', 'leadforms-go'), 'hide' => __('Показати повідомлення і приховати форму', 'leadforms-go'), 'redirect' => __('Перенаправити на іншу сторінку', 'leadforms-go')] as $value => $label) $html .= '<option value="' . esc_attr($value) . '"' . selected($action, $value, false) . '>' . esc_html($label) . '</option>';
		$html .= '</select></label><label><span>' . esc_html__('URL перенаправлення', 'leadforms-go') . '</span><input type="url" name="success_redirect_url" value="' . esc_attr((string) ($form['success_redirect_url'] ?? '')) . '" placeholder="https://example.com/thank-you/"></label>';
		$html .= '<label><span>' . esc_html__('Повернути форму через, секунд', 'leadforms-go') . '</span><input type="number" min="1" max="60" name="success_duration" value="' . esc_attr((string) ($form['success_duration'] ?? 4)) . '"></label></div></details>';
		return $html;
	}

	private static function fontawesome_catalog(): array
	{
		return [
			'fa-solid fa-arrow-right' => __('Стрілка вправо', 'leadforms-go'),
			'fa-solid fa-paper-plane' => __('Паперовий літак', 'leadforms-go'),
			'fa-solid fa-phone' => __('Телефон', 'leadforms-go'),
			'fa-solid fa-envelope' => __('Конверт', 'leadforms-go'),
			'fa-solid fa-check' => __('Галочка', 'leadforms-go'),
			'fa-solid fa-circle-check' => __('Галочка в колі', 'leadforms-go'),
			'fa-solid fa-chevron-right' => __('Шеврон вправо', 'leadforms-go'),
			'fa-solid fa-calendar-days' => __('Календар', 'leadforms-go'),
			'fa-solid fa-location-dot' => __('Мітка локації', 'leadforms-go'),
			'fa-brands fa-telegram' => __('Telegram', 'leadforms-go'),
		];
	}

	public function save_form(): void
	{
		$this->guard('leadforms_go_save_form');
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$name = sanitize_text_field(self::scalar_string($_POST['name'] ?? ''));
		$mode = isset($_POST['editor_mode']) && $_POST['editor_mode'] === 'visual' ? 'visual' : 'code';
		$active = ! empty($_POST['active']);
		$success = [
			'action' => sanitize_key(self::scalar_string($_POST['success_action'] ?? 'message')),
			'redirect_url' => self::scalar_string($_POST['success_redirect_url'] ?? ''),
			'duration' => absint($_POST['success_duration'] ?? 4),
		];
		$submit_label = sanitize_text_field(self::scalar_string($_POST['submit_label'] ?? '')) ?: __('Надіслати', 'leadforms-go');
		$button_icon = Form_Builder::sanitize_button_icon([
			'type' => self::scalar_string($_POST['button_icon_type'] ?? ''),
			'position' => self::scalar_string($_POST['button_icon_position'] ?? ''),
			'fa_class' => self::scalar_string($_POST['button_icon_fa_class'] ?? ''),
			'svg' => self::scalar_string($_POST['button_icon_svg'] ?? ''),
		]);
		$default_locale = Form_Translations::normalize_locale(self::scalar_string($_POST['default_locale'] ?? '')) ?: Form_Translations::DEFAULT_LOCALE;
		$raw_translations = isset($_POST['translations']) && is_string($_POST['translations']) ? json_decode(wp_unslash($_POST['translations']), true) : [];
		$translations = Form_Translations::sanitize(is_array($raw_translations) ? $raw_translations : []);
		$schema = [];
		if ($mode === 'visual') {
			$raw_schema = isset($_POST['schema']) && is_string($_POST['schema']) ? json_decode(wp_unslash($_POST['schema']), true) : [];
			$schema = Form_Builder::sanitize_schema($raw_schema);
			if ($schema === []) wp_die(esc_html__('Додайте щонайменше одне поле до форми.', 'leadforms-go'));
			$duplicates = Form_Builder::duplicate_names($schema);
			if ($duplicates !== []) wp_die(esc_html(sprintf(__('Назви полів мають бути унікальними. Повторюються: %s', 'leadforms-go'), implode(', ', $duplicates))));
			$translations = Form_Translations::complete($translations, $schema);
			$resolved = Form_Translations::resolve($translations, $default_locale, $default_locale);
			$submit_label = (string) $resolved['submit_label'];
			$code = Form_Builder::render(Form_Translations::apply_to_schema($schema, $resolved), $submit_label, '', $button_icon);
		} else {
			$code = Form_Builder::sanitize_code(self::scalar_string($_POST['code'] ?? ''));
			$route_schema = Submission_Validator::schema_for_code($code);
		}
		if (! isset($route_schema)) $route_schema = $schema;
		$raw_routing_json = isset($_POST['routing_config']) && is_string($_POST['routing_config']) ? wp_unslash($_POST['routing_config']) : '';
		if (strlen($raw_routing_json) > 131072) wp_die(esc_html__('Налаштування маршрутів завеликі.', 'leadforms-go'), '', 413);
		$raw_routing = $raw_routing_json !== '' ? json_decode($raw_routing_json, true) : [];
		$routing_config = Route_Config::sanitize(is_array($raw_routing) ? $raw_routing : [], $route_schema);
		$routing_valid = Route_Config::validate($routing_config, $route_schema);
		if (is_wp_error($routing_valid)) wp_die(esc_html($routing_valid->get_error_message()), '', 422);
		if ($name === '' || $code === '') wp_die(esc_html__('Вкажіть назву та вміст форми.', 'leadforms-go'));
		if ($id > 0 && Repositories::form($id) === null) wp_die(esc_html__('Форму не знайдено.', 'leadforms-go'), '', 404);
		$result = Repositories::save_form($id, $name, $code, $mode, $schema, $submit_label, $default_locale, $translations, $active, $button_icon, $routing_config, $success);
		if ($result === false) wp_die(esc_html__('Не вдалося зберегти форму.', 'leadforms-go'));
		wp_safe_redirect(admin_url('admin.php?page=leadforms-go-forms&id=' . $result . '&updated=1')); exit;
	}

	public function delete_form(): void
	{
		if (! current_user_can('manage_options')) wp_die(esc_html__('Недостатньо прав.', 'leadforms-go'), '', 403);
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_die(esc_html__('Некоректний метод запиту.', 'leadforms-go'), '', 405);
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		check_admin_referer('leadforms_go_delete_form_' . $id);
		Repositories::delete_form($id);
		wp_safe_redirect(admin_url('admin.php?page=leadforms-go-forms')); exit;
	}

	public function test_connector(): void
	{
		$this->guard('leadforms_go_admin', 'nonce');
		$key = sanitize_key(self::scalar_string($_POST['connector'] ?? ''));
		if ($key === 'sheets') {
			Settings::update_section('sheets', [
				'enabled' => self::scalar_string($_POST['enabled'] ?? ''),
				'spreadsheet_id' => self::scalar_string($_POST['spreadsheet_id'] ?? ''),
				'sheet_name' => self::scalar_string($_POST['sheet_name'] ?? ''),
				'fields_order' => self::scalar_string($_POST['fields_order'] ?? ''),
			]);
		}
		$connectors = Connectors::all();
		if (! isset($connectors[$key])) wp_send_json_error(['message' => __('Невідома інтеграція.', 'leadforms-go')], 400);
		try {
			if ($key === 'telegram' && $connectors[$key] instanceof Telegram_Connector) {
				$settings = Settings::section('telegram');
				$token = self::scalar_string($_POST['token'] ?? '');
				$chat_id = self::scalar_string($_POST['chat_id'] ?? '');
				$result = $connectors[$key]->test_credentials(
					$token !== '' ? $token : (string) ($settings['token'] ?? ''),
					$chat_id !== '' ? $chat_id : (string) ($settings['chat_id'] ?? '')
				);
			} else {
				$result = $connectors[$key]->test_connection();
			}
		} catch (\Throwable) {
			wp_send_json_error(['message' => __('Під час перевірки сталася внутрішня помилка.', 'leadforms-go')], 500);
		}
		$result->success ? wp_send_json_success(['message' => $result->message ?: __('Підключення успішне.', 'leadforms-go')]) : wp_send_json_error(['message' => $result->message, 'http_code' => $result->http_code], 400);
	}

	public function upload_google_credentials(): void
	{
		$this->guard('leadforms_go_admin', 'nonce');
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_send_json_error(['message' => __('Некоректний метод запиту.', 'leadforms-go')], 405);
		if (defined('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH')) wp_send_json_error(['message' => __('Спочатку видаліть LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH із wp-config.php.', 'leadforms-go')], 409);
		$file = isset($_FILES['credentials']) && is_array($_FILES['credentials']) ? $_FILES['credentials'] : [];
		$result = Google_Credentials::store_upload($file);
		if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
		$status = Google_Credentials::status();
		wp_send_json_success(['message' => __('JSON-ключ перевірено, зашифровано та збережено.', 'leadforms-go'), 'email' => $status['email']]);
	}

	public function remove_google_credentials(): void
	{
		$this->guard('leadforms_go_admin', 'nonce');
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_send_json_error(['message' => __('Некоректний метод запиту.', 'leadforms-go')], 405);
		if (defined('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH')) wp_send_json_error(['message' => __('Ключ керується через wp-config.php і не може бути видалений тут.', 'leadforms-go')], 409);
		Google_Credentials::delete();
		wp_send_json_success(['message' => __('JSON-ключ видалено.', 'leadforms-go')]);
	}

	public function retry_delivery(): void
	{
		$delivery_id = isset($_POST['delivery_id']) ? absint($_POST['delivery_id']) : 0;
		$submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
		$this->guard('leadforms_go_retry_delivery_' . $delivery_id);
		$retried = $delivery_id > 0 && $submission_id > 0 && Repositories::delivery_belongs_to_submission($delivery_id, $submission_id) && (new Delivery_Queue())->retry_delivery($delivery_id);
		$this->history_redirect($submission_id, $retried ? 1 : 0);
	}

	public function retry_submission(): void
	{
		$submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
		$this->guard('leadforms_go_retry_submission_' . $submission_id);
		$count = $submission_id > 0 ? (new Delivery_Queue())->retry_submission($submission_id) : 0;
		$this->history_redirect($submission_id, $count);
	}

	public function bulk_retry(): void
	{
		$this->guard('leadforms_go_bulk_retry');
		$raw_ids = isset($_POST['submission_ids']) && is_array($_POST['submission_ids']) ? wp_unslash($_POST['submission_ids']) : [];
		$ids = array_slice(array_unique(array_filter(array_map('absint', $raw_ids))), 0, 100);
		$count = (new Delivery_Queue())->retry_submissions($ids);
		$this->history_redirect(0, $count);
	}

	public function legacy_notice(): void
	{
		if (! current_user_can('manage_options')) return;
		$active = (array) get_option('active_plugins', []);
		$legacy = array_intersect($active, ['reIntegration/reIntegration.php', 'reIntegrationSheets/reIntegrationSheets.php', 'reIntegrationTelegram/reIntegrationTelegram.php', 'reIntegrationCRM/reIntegrationCRM.php']);
		if ($legacy) echo '<div class="notice notice-warning"><p>' . esc_html__('LeadForms Go імпортував старі дані. Вимкніть старі плагіни reIntegration, щоб уникнути дублювання обробників.', 'leadforms-go') . '</p></div>';
	}

	private function submission_list(): void
	{
		$filters = [
			'form_id' => isset($_GET['form_id']) ? absint($_GET['form_id']) : 0,
			'status' => sanitize_key(self::scalar_string($_GET['status'] ?? '')),
			'connector' => sanitize_key(self::scalar_string($_GET['connector'] ?? '')),
			'date_from' => $this->date_filter('date_from'),
			'date_to' => $this->date_filter('date_to'),
		];
		$page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
		$per_page = 30;
		$rows = Repositories::submissions($per_page, $filters, ($page - 1) * $per_page);
		$total = Repositories::submission_count($filters);
		$statuses = ['queued' => __('У черзі', 'leadforms-go'), 'processing' => __('Обробляється', 'leadforms-go'), 'success' => __('Успішно', 'leadforms-go'), 'failed' => __('Помилка', 'leadforms-go')];
		$titles = ['telegram' => 'Telegram', 'sheets' => 'Google Sheets', 'crm' => 'CRM G-PLUS'];

		echo '<form class="lfg-history-filters" method="get"><input type="hidden" name="page" value="leadforms-go-history"><label><span>' . esc_html__('Форма', 'leadforms-go') . '</span><select name="form_id"><option value="">' . esc_html__('Усі форми', 'leadforms-go') . '</option>';
		foreach (Repositories::form_summaries() as $form) printf('<option value="%d"%s>%s</option>', (int) $form['id'], selected($filters['form_id'], (int) $form['id'], false), esc_html($form['name']));
		echo '</select></label><label><span>' . esc_html__('Статус', 'leadforms-go') . '</span><select name="status"><option value="">' . esc_html__('Усі статуси', 'leadforms-go') . '</option>';
		foreach ($statuses as $key => $label) printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($filters['status'], $key, false), esc_html($label));
		echo '</select></label><label><span>' . esc_html__('Інтеграція', 'leadforms-go') . '</span><select name="connector"><option value="">' . esc_html__('Усі інтеграції', 'leadforms-go') . '</option>';
		foreach ($titles as $key => $label) printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($filters['connector'], $key, false), esc_html($label));
		echo '</select></label><label><span>' . esc_html__('Від', 'leadforms-go') . '</span><input type="date" name="date_from" value="' . esc_attr($filters['date_from']) . '"></label><label><span>' . esc_html__('До', 'leadforms-go') . '</span><input type="date" name="date_to" value="' . esc_attr($filters['date_to']) . '"></label><div class="lfg-filter-actions"><button class="button button-primary" type="submit">' . esc_html__('Застосувати', 'leadforms-go') . '</button><a class="button" href="' . esc_url(admin_url('admin.php?page=leadforms-go-history')) . '">' . esc_html__('Скинути', 'leadforms-go') . '</a></div></form>';

		echo '<form class="lfg-history-list" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="leadforms_go_bulk_retry">';
		wp_nonce_field('leadforms_go_bulk_retry');
		echo '<div class="lfg-history-toolbar"><div><strong>' . sprintf(esc_html__('%d заявок', 'leadforms-go'), $total) . '</strong><span>' . esc_html__('Зберігаються локально незалежно від стану інтеграцій.', 'leadforms-go') . '</span></div><button class="button" type="submit">' . esc_html__('Повторити невдалі', 'leadforms-go') . '</button></div><div class="lfg-history-table-wrap"><table class="widefat lfg-history-table"><thead><tr><td class="check-column"><input type="checkbox" data-lfg-select-all aria-label="' . esc_attr__('Вибрати всі', 'leadforms-go') . '"></td><th>' . esc_html__('Заявка', 'leadforms-go') . '</th><th>' . esc_html__('Контактні дані', 'leadforms-go') . '</th><th>' . esc_html__('Доставка', 'leadforms-go') . '</th><th>' . esc_html__('Джерело', 'leadforms-go') . '</th><th></th></tr></thead><tbody>';
		if ($rows === []) echo '<tr><td colspan="6"><p class="lfg-empty-state">' . esc_html__('За вибраними фільтрами заявок немає.', 'leadforms-go') . '</p></td></tr>';
		foreach ($rows as $row) {
			$payload = json_decode((string) $row['payload'], true);
			$payload = is_array($payload) ? $payload : [];
			$payload = Submission_Presenter::for_admin($payload, (int) $row['form_id'], (string) ($row['locale'] ?? ''));
			$test_badge = ! empty($row['is_test']) ? '<span class="lfg-delivery-status is-processing">' . esc_html__('Тест', 'leadforms-go') . '</span>' : '';
			echo '<tr><th class="check-column"><input type="checkbox" name="submission_ids[]" value="' . (int) $row['id'] . '" aria-label="' . esc_attr(sprintf(__('Вибрати заявку #%d', 'leadforms-go'), (int) $row['id'])) . '"></th><td><a class="lfg-submission-id" href="' . esc_url(admin_url('admin.php?page=leadforms-go-history&submission=' . (int) $row['id'])) . '">#' . (int) $row['id'] . '</a>' . $test_badge . '<span>' . esc_html($row['form_name'] ?: __('Видалена або імпортована форма', 'leadforms-go')) . '</span><time>' . esc_html(wp_date('d.m.Y H:i', strtotime((string) $row['created_at']))) . '</time></td><td><div class="lfg-payload-preview">' . esc_html(self::payload_preview($payload)) . '</div></td><td><div class="lfg-delivery-stack">';
			if ($row['deliveries'] === []) echo '<span class="lfg-delivery-status is-success">' . esc_html__('Без інтеграцій', 'leadforms-go') . '</span>';
			foreach ($row['deliveries'] as $delivery) printf('<span class="lfg-delivery-status is-%s" title="%s"><strong>%s</strong><span>%s</span></span>', esc_attr($delivery['status']), esc_attr((string) $delivery['error_message']), esc_html(self::delivery_title($delivery, $titles)), esc_html(self::status_label((string) $delivery['status'])));
			$source = (string) $row['referer'];
			echo '</div></td><td>' . ($source !== '' ? '<a class="lfg-source-link" href="' . esc_url($source) . '" target="_blank" rel="noopener noreferrer">' . esc_html($source) . '</a>' : '<span class="lfg-source-link">—</span>') . '</td><td><a class="button lfg-details-button" href="' . esc_url(admin_url('admin.php?page=leadforms-go-history&submission=' . (int) $row['id'])) . '">' . esc_html__('Деталі', 'leadforms-go') . '</a></td></tr>';
		}
		echo '</tbody></table></div></form>';
		$total_pages = (int) ceil($total / $per_page);
		if ($total_pages > 1) {
			$base_args = array_filter(['page' => 'leadforms-go-history'] + $filters, static fn ($value): bool => $value !== '' && $value !== 0);
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(['base' => add_query_arg($base_args + ['paged' => '%#%'], admin_url('admin.php')), 'current' => $page, 'total' => $total_pages])) . '</div></div>';
		}
	}

	private function submission_details(int $submission_id): void
	{
		$submission = Repositories::submission($submission_id);
		echo '<div class="lfg-detail-heading"><a href="' . esc_url(admin_url('admin.php?page=leadforms-go-history')) . '"><span class="dashicons dashicons-arrow-left-alt2"></span>' . esc_html__('До історії', 'leadforms-go') . '</a>';
		if (! $submission) {
			echo '</div><div class="notice notice-error inline"><p>' . esc_html__('Заявку не знайдено.', 'leadforms-go') . '</p></div>';
			return;
		}
		$failed = array_filter($submission['deliveries'], static fn (array $delivery): bool => in_array($delivery['status'], ['failed', 'cancelled'], true));
		if ($failed !== []) {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="leadforms_go_retry_submission"><input type="hidden" name="submission_id" value="' . $submission_id . '">';
			wp_nonce_field('leadforms_go_retry_submission_' . $submission_id);
			echo '<button class="button button-primary" type="submit"><span class="dashicons dashicons-update"></span>' . esc_html__('Повторити невдалі доставки', 'leadforms-go') . '</button></form>';
		}
		$submission_type = ! empty($submission['is_test']) ? __('Тестова', 'leadforms-go') : __('Звичайна', 'leadforms-go');
		echo '</div><section class="lfg-submission-summary"><div><span>' . esc_html__('Заявка', 'leadforms-go') . '</span><strong>#' . $submission_id . '</strong></div><div><span>' . esc_html__('Тип', 'leadforms-go') . '</span><strong>' . esc_html($submission_type) . '</strong></div><div><span>' . esc_html__('Форма', 'leadforms-go') . '</span><strong>' . esc_html($submission['form_name'] ?: __('Видалена або імпортована форма', 'leadforms-go')) . '</strong></div><div><span>' . esc_html__('Мова', 'leadforms-go') . '</span><strong>' . esc_html((string) ($submission['locale'] ?: Form_Translations::DEFAULT_LOCALE)) . '</strong></div><div><span>' . esc_html__('Створено', 'leadforms-go') . '</span><strong>' . esc_html(wp_date('d.m.Y H:i:s', strtotime((string) $submission['created_at']))) . '</strong></div><div><span>' . esc_html__('Статус', 'leadforms-go') . '</span><strong><span class="lfg-delivery-status is-' . esc_attr($submission['status']) . '">' . esc_html(self::status_label((string) $submission['status'])) . '</span></strong></div></section>';
		$payload = json_decode((string) $submission['payload'], true);
		$payload = is_array($payload) ? $payload : [];
		$payload = Submission_Presenter::for_admin($payload, (int) $submission['form_id'], (string) ($submission['locale'] ?? ''));
		echo '<div class="lfg-detail-grid"><section class="lfg-card lfg-submission-data"><div class="lfg-card-heading"><h2>' . esc_html__('Дані заявки', 'leadforms-go') . '</h2></div><dl>';
		foreach ($payload as $key => $value) echo '<div><dt>' . esc_html((string) $key) . '</dt><dd>' . nl2br(esc_html(is_scalar($value) ? (string) $value : (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE))) . '</dd></div>';
		$source = (string) $submission['referer'];
		echo '</dl><div class="lfg-submission-source"><span>' . esc_html__('Джерело', 'leadforms-go') . '</span>' . ($source !== '' ? '<a href="' . esc_url($source) . '" target="_blank" rel="noopener noreferrer">' . esc_html($source) . '</a>' : '<span>—</span>') . '</div></section><section class="lfg-deliveries"><div class="lfg-card-heading"><h2>' . esc_html__('Доставка', 'leadforms-go') . '</h2><span>' . sprintf(esc_html__('%d каналів', 'leadforms-go'), count($submission['deliveries'])) . '</span></div>';
		if ($submission['deliveries'] === []) echo '<div class="lfg-card lfg-empty-state">' . esc_html__('Для цієї заявки інтеграції не запускалися.', 'leadforms-go') . '</div>';
		foreach ($submission['deliveries'] as $delivery) $this->delivery_details($submission_id, $delivery);
		echo '</section></div>';
	}

	private function delivery_details(int $submission_id, array $delivery): void
	{
		$titles = ['telegram' => 'Telegram', 'sheets' => 'Google Sheets', 'crm' => 'CRM G-PLUS'];
		echo '<article class="lfg-card lfg-delivery-card"><header><div><h3>' . esc_html(self::delivery_title($delivery, $titles)) . '</h3><span class="lfg-delivery-status is-' . esc_attr($delivery['status']) . '">' . esc_html(self::status_label((string) $delivery['status'])) . '</span></div>';
		if (in_array($delivery['status'], ['failed', 'cancelled'], true)) {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="leadforms_go_retry_delivery"><input type="hidden" name="delivery_id" value="' . (int) $delivery['id'] . '"><input type="hidden" name="submission_id" value="' . $submission_id . '">';
			wp_nonce_field('leadforms_go_retry_delivery_' . (int) $delivery['id']);
			echo '<button class="button" type="submit"><span class="dashicons dashicons-update"></span>' . esc_html__('Повторити', 'leadforms-go') . '</button></form>';
		}
		echo '</header><div class="lfg-delivery-meta"><span><small>' . esc_html__('Спроб', 'leadforms-go') . '</small><strong>' . (int) count($delivery['attempt_history']) . '</strong></span><span><small>HTTP</small><strong>' . esc_html($delivery['http_code'] ?: '—') . '</strong></span><span><small>' . esc_html__('Остання спроба', 'leadforms-go') . '</small><strong>' . esc_html($delivery['last_attempt_at'] ?: '—') . '</strong></span><span><small>' . esc_html__('Наступна спроба', 'leadforms-go') . '</small><strong>' . esc_html($delivery['next_attempt_at'] ?: '—') . '</strong></span></div>';
		if ($delivery['external_reference']) {
			$reference = (string) $delivery['external_reference'];
			echo '<p class="lfg-external-reference"><span>' . esc_html__('Зовнішній запис:', 'leadforms-go') . '</span>' . (wp_http_validate_url($reference) ? '<a href="' . esc_url($reference) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Відкрити в сервісі', 'leadforms-go') . '</a>' : '<code>' . esc_html($reference) . '</code>') . '</p>';
		}
		if ($delivery['error_message']) echo '<p class="lfg-delivery-error"><span class="dashicons dashicons-warning"></span>' . esc_html($delivery['error_message']) . '</p>';
		if ($delivery['attempt_history'] !== []) {
			echo '<details class="lfg-attempts"><summary>' . esc_html__('Історія спроб', 'leadforms-go') . '</summary><ol>';
			foreach ($delivery['attempt_history'] as $attempt) echo '<li><span class="lfg-attempt-dot is-' . esc_attr($attempt['status']) . '"></span><div><strong>' . sprintf(esc_html__('Спроба #%d', 'leadforms-go'), (int) $attempt['attempt_number']) . '</strong><time>' . esc_html($attempt['created_at']) . '</time>' . ($attempt['error_message'] ? '<p>' . esc_html($attempt['error_message']) . '</p>' : '') . '</div><code>' . esc_html($attempt['http_code'] ?: '—') . '</code></li>';
			echo '</ol></details>';
		}
		echo '</article>';
	}

	private function history_notice(): void
	{
		if (! isset($_GET['retried'])) return;
		$count = absint($_GET['retried']);
		$message = $count > 0 ? sprintf(_n('%d доставку додано в чергу.', '%d доставок додано в чергу.', $count, 'leadforms-go'), $count) : __('Немає невдалих доставок для повторення.', 'leadforms-go');
		echo '<div class="notice notice-' . ($count > 0 ? 'success' : 'info') . ' inline is-dismissible"><p>' . esc_html($message) . '</p></div>';
	}

	private function history_redirect(int $submission_id, int $count): never
	{
		$args = ['page' => 'leadforms-go-history', 'retried' => $count];
		if ($submission_id > 0) $args['submission'] = $submission_id;
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
		exit;
	}

	private function date_filter(string $key): string
	{
		$value = sanitize_text_field(self::scalar_string($_GET[$key] ?? ''));
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
	}

	private static function scalar_string(mixed $value): string
	{
		return is_scalar($value) ? wp_unslash((string) $value) : '';
	}

	private static function payload_preview(array $payload): string
	{
		$parts = [];
		foreach (array_slice($payload, 0, 3, true) as $key => $value) {
			$display = is_scalar($value) ? (string) $value : '—';
			if (preg_match('/phone|tel|номер|телефон/iu', (string) $key)) $display = self::mask_phone($display);
			elseif (preg_match('/email|пошт/iu', (string) $key) && str_contains($display, '@')) {
				[$local, $domain] = explode('@', $display, 2);
				$display = substr($local, 0, 1) . '•••@' . $domain;
			}
			$parts[] = (string) $key . ': ' . $display;
		}
		return implode(' · ', $parts) ?: '—';
	}

	private static function mask_phone(string $value): string
	{
		$total = preg_match_all('/\d/u', $value);
		if (! is_int($total) || $total <= 4) return $value;
		$seen = 0;
		$result = preg_replace_callback('/\d/u', static function (array $match) use (&$seen, $total): string {
			++$seen;
			return $seen <= $total - 4 ? '•' : $match[0];
		}, $value);
		return is_string($result) ? $result : $value;
	}

	private static function status_label(string $status): string
	{
		return [
			'pending' => __('Очікує', 'leadforms-go'),
			'queued' => __('У черзі', 'leadforms-go'),
			'processing' => __('Обробляється', 'leadforms-go'),
			'success' => __('Успішно', 'leadforms-go'),
			'sent' => __('Надіслано', 'leadforms-go'),
			'failed' => __('Помилка', 'leadforms-go'),
			'cancelled' => __('Скасовано', 'leadforms-go'),
		][$status] ?? $status;
	}

	private static function delivery_title(array $delivery, array $titles): string
	{
		$key = explode('__', sanitize_key((string) ($delivery['connector'] ?? '')), 2)[0];
		$title = (string) ($titles[$key] ?? ucfirst($key));
		$snapshot = json_decode((string) ($delivery['route_snapshot'] ?? ''), true);
		$profile_name = is_array($snapshot) ? sanitize_text_field((string) ($snapshot['route']['profile_name'] ?? '')) : '';
		return $profile_name !== '' ? $title . ' · ' . $profile_name : $title;
	}

	private function guard(string $action, string $field = '_wpnonce'): void
	{
		$this->require_capability('manage_options');
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			if (wp_doing_ajax()) wp_send_json_error(['message' => __('Некоректний метод запиту.', 'leadforms-go')], 405);
			wp_die(esc_html__('Некоректний метод запиту.', 'leadforms-go'), '', 405);
		}
		if ($field === '_wpnonce') check_admin_referer($action); elseif (! check_ajax_referer($action, $field, false)) wp_send_json_error(['message' => __('Некоректний запит.', 'leadforms-go')], 403);
	}

	private function require_capability(string $capability): void
	{
		if (! current_user_can($capability)) wp_die(esc_html__('Недостатньо прав.', 'leadforms-go'), '', 403);
	}
	private function open(string $title): void
	{
		$page = sanitize_key(self::scalar_string($_GET['page'] ?? 'leadforms-go'));
		$descriptions = [
			'leadforms-go' => __('Контролюйте форми, доставки та стан інтеграцій в одному місці.', 'leadforms-go'),
			'leadforms-go-forms' => __('Створюйте форми, керуйте мовами та налаштовуйте маршрути доставки.', 'leadforms-go'),
			'leadforms-go-history' => __('Переглядайте заявки, окремі спроби доставки та безпечно запускайте повтори.', 'leadforms-go'),
			'leadforms-go-settings' => __('Підключайте сервіси та керуйте глобальними правилами плагіна.', 'leadforms-go'),
		];
		echo '<div class="wrap leadforms-go-admin"><header class="lfg-page-header"><div><span class="lfg-page-header__eyebrow">LeadForms Go</span><h1>' . esc_html($title) . '</h1><p>' . esc_html($descriptions[$page] ?? '') . '</p></div><span class="lfg-version-badge">v' . esc_html(LEADFORMS_GO_VERSION) . '</span></header>';
	}
	private function close(): void { echo '</div>'; }
}
