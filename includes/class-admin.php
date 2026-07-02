<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Admin
{
	public function boot(): void
	{
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [Settings::class, 'register']);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('admin_notices', [$this, 'legacy_notice']);
		add_action('admin_post_leadforms_go_save_form', [$this, 'save_form']);
		add_action('admin_post_leadforms_go_delete_form', [$this, 'delete_form']);
		add_action('admin_post_leadforms_go_retry_delivery', [$this, 'retry_delivery']);
		add_action('admin_post_leadforms_go_retry_submission', [$this, 'retry_submission']);
		add_action('admin_post_leadforms_go_bulk_retry', [$this, 'bulk_retry']);
		add_action('wp_ajax_leadforms_go_test_connector', [$this, 'test_connector']);
	}

	public function menu(): void
	{
		add_menu_page('LeadForms Go', 'LeadForms Go', 'manage_options', 'leadforms-go', [$this, 'dashboard'], 'dashicons-feedback', 32);
		add_submenu_page('leadforms-go', __('Форми', 'leadforms-go'), __('Форми', 'leadforms-go'), 'manage_options', 'leadforms-go-forms', [$this, 'forms']);
		add_submenu_page('leadforms-go', __('Історія', 'leadforms-go'), __('Історія', 'leadforms-go'), 'manage_options', 'leadforms-go-history', [$this, 'history']);
		add_submenu_page('leadforms-go', __('Налаштування', 'leadforms-go'), __('Налаштування', 'leadforms-go'), 'manage_options', 'leadforms-go-settings', [$this, 'settings']);
	}

	public function assets(string $hook): void
	{
		if (! str_contains($hook, 'leadforms-go')) return;
		$style_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin.css') ?: LEADFORMS_GO_VERSION;
		$script_version = @filemtime(LEADFORMS_GO_DIR . 'assets/admin.js') ?: LEADFORMS_GO_VERSION;
		wp_enqueue_style('leadforms-go-admin', LEADFORMS_GO_URL . 'assets/admin.css', [], (string) $style_version);
		wp_add_inline_style('leadforms-go-admin', '.leadforms-go-admin{width:auto;max-width:none}');
		wp_enqueue_script('leadforms-go-admin', LEADFORMS_GO_URL . 'assets/admin.js', [], (string) $script_version, true);
		wp_add_inline_script('leadforms-go-admin', 'window.leadFormsGoAdmin=' . wp_json_encode([
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('leadforms_go_admin'),
			'testing' => __('Перевірка…', 'leadforms-go'),
			'confirmDelete' => __('Видалити цю форму?', 'leadforms-go'),
			'requestFailed' => __('Не вдалося виконати запит.', 'leadforms-go'),
			'copied' => __('Скопійовано', 'leadforms-go'),
			'builder' => [
				'maxFields' => Form_Builder::MAX_FIELDS,
				'maxFieldsMessage' => sprintf(__('У формі може бути не більше %d полів.', 'leadforms-go'), Form_Builder::MAX_FIELDS),
				'required' => __('Обов’язкове поле', 'leadforms-go'),
				'moveUp' => __('Перемістити вище', 'leadforms-go'),
				'moveDown' => __('Перемістити нижче', 'leadforms-go'),
				'remove' => __('Видалити', 'leadforms-go'),
				'empty' => __('Додайте поля з бібліотеки ліворуч.', 'leadforms-go'),
				'fieldLabel' => __('Підпис', 'leadforms-go'),
				'fieldLabelHelp' => __('Текст, який бачить відвідувач біля поля.', 'leadforms-go'),
				'fieldName' => __('Назва в заявці', 'leadforms-go'),
				'fieldNameHelp' => __('Під цим ім’ям значення прийде в Telegram, CRM та історію.', 'leadforms-go'),
				'placeholder' => __('Підказка в полі', 'leadforms-go'),
				'placeholderHelp' => __('Текст усередині порожнього поля.', 'leadforms-go'),
			],
		]) . ';', 'before');
	}

	public function dashboard(): void
	{
		$this->open(__('Огляд', 'leadforms-go'));
		$stats = Repositories::dashboard_stats();
		$queue = (new Delivery_Queue())->health();
		echo '<div class="lfg-dashboard-header"><div><h2>' . esc_html__('LeadForms Go', 'leadforms-go') . '</h2><p>' . esc_html__('Короткий огляд форм, заявок та інтеграцій.', 'leadforms-go') . '</p></div><div class="lfg-dashboard-actions"><a class="button lfg-dashboard-button lfg-dashboard-button--secondary" href="' . esc_url(admin_url('admin.php?page=leadforms-go-settings')) . '">' . esc_html__('Налаштування', 'leadforms-go') . '</a><a class="button button-primary lfg-dashboard-button lfg-dashboard-button--primary" href="' . esc_url(admin_url('admin.php?page=leadforms-go-forms&new=1')) . '"><span class="lfg-button-icon" aria-hidden="true"></span>' . esc_html__('Додати форму', 'leadforms-go') . '</a></div></div>';
		if (! $queue['healthy']) {
			echo '<div class="notice notice-warning inline lfg-queue-warning"><p><strong>' . esc_html__('Черга доставки потребує уваги.', 'leadforms-go') . '</strong> ' . esc_html($queue['cron_disabled'] ? __('WP-Cron вимкнений. Налаштуйте системний cron для обробки заявок.', 'leadforms-go') : __('Є прострочені завдання, але обробник черги не запланований.', 'leadforms-go')) . '</p></div>';
		}
		echo '<div class="lfg-dashboard-stats">';
		$cards = [
			['icon' => 'feedback', 'value' => $stats['forms'], 'label' => __('Активні форми', 'leadforms-go')],
			['icon' => 'email-alt', 'value' => $stats['today'], 'label' => __('Заявки сьогодні', 'leadforms-go')],
			['icon' => 'clock', 'value' => $queue['queued'], 'label' => __('У черзі', 'leadforms-go')],
			['icon' => 'warning', 'value' => $stats['failed_today'], 'label' => __('Помилки сьогодні', 'leadforms-go')],
			['icon' => 'yes-alt', 'value' => $stats['success_rate'] . '%', 'label' => __('Успішна доставка', 'leadforms-go')],
		];
		foreach ($cards as $card) {
			printf('<article class="lfg-stat-card"><span class="dashicons dashicons-%s"></span><div><strong>%s</strong><span>%s</span></div></article>', esc_attr($card['icon']), esc_html((string) $card['value']), esc_html($card['label']));
		}
		echo '</div><div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('Інтеграції', 'leadforms-go') . '</h2><a href="' . esc_url(admin_url('admin.php?page=leadforms-go-settings')) . '">' . esc_html__('Керувати', 'leadforms-go') . '</a></div><div class="lfg-grid">';
		$titles = ['telegram' => 'Telegram', 'sheets' => 'Google Sheets', 'crm' => 'CRM G-PLUS'];
		foreach (Connectors::all() as $connector) {
			$valid = $connector->validate_settings();
			$status = ! $connector->is_enabled() ? __('Вимкнено', 'leadforms-go') : (is_wp_error($valid) ? __('Потрібне налаштування', 'leadforms-go') : __('Увімкнено', 'leadforms-go'));
			$activity = $stats['activity'][$connector->key()] ?? ['success' => 0, 'failed' => 0, 'queued' => 0, 'processing' => 0, 'last_success' => ''];
			$last_success_at = $activity['last_success'] ? strtotime((string) $activity['last_success']) : false;
			$last_success = $last_success_at ? sprintf(__('Остання доставка: %s тому', 'leadforms-go'), human_time_diff($last_success_at, current_time('timestamp'))) : __('Успішних доставок ще немає', 'leadforms-go');
			printf('<article class="lfg-card lfg-integration-card"><div class="lfg-integration-card__heading"><h3>%s</h3><span class="lfg-status%s">%s</span></div><p>%s</p><div class="lfg-integration-metrics"><span><strong>%d</strong>%s</span><span class="is-error"><strong>%d</strong>%s</span><span><strong>%d</strong>%s</span></div></article>', esc_html($titles[$connector->key()] ?? ucfirst($connector->key())), $connector->is_enabled() && ! is_wp_error($valid) ? ' is-active' : '', esc_html($status), esc_html($last_success), (int) $activity['success'], esc_html__('успішно', 'leadforms-go'), (int) $activity['failed'], esc_html__('помилок', 'leadforms-go'), (int) $activity['queued'], esc_html__('у черзі', 'leadforms-go'));
		}
		echo '</div></div><div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('Стан черги', 'leadforms-go') . '</h2></div><div class="lfg-queue-card"><div><span>' . esc_html__('Очікують', 'leadforms-go') . '</span><strong>' . esc_html((string) $queue['queued']) . '</strong></div><div><span>' . esc_html__('Обробляються', 'leadforms-go') . '</span><strong>' . esc_html((string) $queue['processing']) . '</strong></div><div><span>' . esc_html__('Останній запуск', 'leadforms-go') . '</span><strong>' . esc_html($queue['last_run'] ? sprintf(__('%s тому', 'leadforms-go'), human_time_diff($queue['last_run'], time())) : __('Ще не запускався', 'leadforms-go')) . '</strong></div><div><span>' . esc_html__('Наступний запуск', 'leadforms-go') . '</span><strong>' . esc_html($queue['scheduled'] ? wp_date('d.m.Y H:i', (int) $queue['scheduled']) : '—') . '</strong></div></div></div>';
		echo '<div class="lfg-dashboard-section"><div class="lfg-section-heading"><h2>' . esc_html__('Останні заявки', 'leadforms-go') . '</h2><a href="' . esc_url(admin_url('admin.php?page=leadforms-go-history')) . '">' . esc_html__('Вся історія', 'leadforms-go') . '</a></div><div class="lfg-recent-submissions">';
		$recent = Repositories::submissions(5);
		if ($recent === []) {
			echo '<p class="lfg-empty-state">' . esc_html__('Заявок поки немає. Після першого надсилання вони з’являться тут.', 'leadforms-go') . '</p>';
		} else {
			echo '<table class="widefat"><thead><tr><th>ID</th><th>' . esc_html__('Дата', 'leadforms-go') . '</th><th>' . esc_html__('Статус', 'leadforms-go') . '</th><th>' . esc_html__('Джерело', 'leadforms-go') . '</th></tr></thead><tbody>';
			foreach ($recent as $row) {
				printf('<tr><td><a href="%s">#%d</a></td><td>%s</td><td><span class="lfg-delivery-status is-%s">%s</span></td><td>%s</td></tr>', esc_url(admin_url('admin.php?page=leadforms-go-history&submission=' . (int) $row['id'])), (int) $row['id'], esc_html($row['created_at']), esc_attr($row['status']), esc_html(self::status_label((string) $row['status'])), esc_html($row['referer'] ?: '—'));
			}
			echo '</tbody></table>';
		}
		echo '</div></div>';
		$this->close();
	}

	public function forms(): void
	{
		$id = isset($_GET['id']) ? absint($_GET['id']) : 0;
		$form = $id ? Repositories::form($id) : null;
		$this->open($form ? __('Редагування форми', 'leadforms-go') : __('Форми', 'leadforms-go'));
		if ($form || isset($_GET['new'])) {
			$mode = in_array($form['editor_mode'] ?? '', ['visual', 'code'], true) ? $form['editor_mode'] : ($form ? 'code' : 'visual');
			$schema = json_decode((string) ($form['form_schema'] ?? ''), true);
			$schema = is_array($schema) ? $schema : [];
			$submit_label = (string) ($form['submit_label'] ?? 'Надіслати');
			echo '<form class="lfg-card lfg-form-editor" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('leadforms_go_save_form');
			echo '<input type="hidden" name="action" value="leadforms_go_save_form"><input type="hidden" name="id" value="' . esc_attr((string) ($form['id'] ?? 0)) . '">';
			echo '<section class="lfg-form-meta"><label><strong>' . esc_html__('Назва форми', 'leadforms-go') . '</strong><small>' . esc_html__('Використовується лише в адмінці, щоб швидко знайти потрібну форму.', 'leadforms-go') . '</small><input required name="name" value="' . esc_attr((string) ($form['name'] ?? '')) . '" placeholder="' . esc_attr__('Наприклад: Форма головного екрана', 'leadforms-go') . '"></label></section>';
			echo '<input type="hidden" name="editor_mode" value="' . esc_attr($mode) . '" data-lfg-mode-input>';
			echo '<div class="lfg-editor-tabs" role="tablist" aria-label="' . esc_attr__('Режим редактора', 'leadforms-go') . '"><button type="button" class="lfg-editor-tab" role="tab" aria-controls="lfg-visual-panel" data-lfg-mode="visual">' . esc_html__('Візуально', 'leadforms-go') . '</button><button type="button" class="lfg-editor-tab" role="tab" aria-controls="lfg-code-panel" data-lfg-mode="code">' . esc_html__('Код', 'leadforms-go') . '</button></div>';
			echo '<div id="lfg-visual-panel" role="tabpanel" data-lfg-panel="visual" class="lfg-builder"' . ($mode === 'visual' ? '' : ' hidden') . '><aside class="lfg-builder__palette"><h2>' . esc_html__('Готові поля', 'leadforms-go') . '</h2><p>' . esc_html__('Натисніть на плитку, щоб додати поле.', 'leadforms-go') . '</p><div class="lfg-builder__tiles">';
			foreach (Form_Builder::tiles() as $key => $tile) {
				printf('<button type="button" class="lfg-field-tile" data-lfg-add="%s" data-lfg-template="%s"><span class="dashicons dashicons-plus-alt2"></span>%s</button>', esc_attr($key), esc_attr((string) wp_json_encode($tile, JSON_UNESCAPED_UNICODE)), esc_html($tile['label']));
			}
			echo '</div></aside><section class="lfg-builder__workspace"><h2>' . esc_html__('Поля форми', 'leadforms-go') . '</h2><div data-lfg-canvas></div><label><span>' . esc_html__('Текст кнопки', 'leadforms-go') . '</span><input type="text" name="submit_label" value="' . esc_attr($submit_label) . '"></label><textarea hidden name="schema" data-lfg-schema>' . esc_textarea((string) wp_json_encode($schema, JSON_UNESCAPED_UNICODE)) . '</textarea></section></div>';
			echo '<div id="lfg-code-panel" role="tabpanel" data-lfg-panel="code"' . ($mode === 'code' ? '' : ' hidden') . '><label class="lfg-code-editor"><span>' . esc_html__('HTML-код форми', 'leadforms-go') . '</span><textarea name="code" rows="22" data-lfg-code>' . esc_textarea((string) ($form['code'] ?? '')) . '</textarea></label><p class="description">' . esc_html__('Код відформатовано для читання. Якщо змінити його вручну й зберегти форму в режимі «Код», візуальна схема більше не використовуватиметься.', 'leadforms-go') . '</p></div>';
			submit_button(__('Зберегти форму', 'leadforms-go'));
			echo '</form>';
		} else {
			echo '<div class="lfg-page-actions"><p>' . esc_html__('Створюйте й керуйте формами без ручного написання HTML.', 'leadforms-go') . '</p><a class="button button-primary lfg-add-form-button" href="' . esc_url(admin_url('admin.php?page=leadforms-go-forms&new=1')) . '"><span class="lfg-button-icon" aria-hidden="true"></span>' . esc_html__('Додати форму', 'leadforms-go') . '</a></div><div class="lfg-forms-list"><table class="widefat"><thead><tr><th>' . esc_html__('Форма', 'leadforms-go') . '</th><th>' . esc_html__('Режим', 'leadforms-go') . '</th><th>' . esc_html__('Шорткод', 'leadforms-go') . '</th><th class="lfg-actions-column">' . esc_html__('Дії', 'leadforms-go') . '</th></tr></thead><tbody>';
			foreach (Repositories::form_summaries() as $item) {
				$edit = admin_url('admin.php?page=leadforms-go-forms&id=' . (int) $item['id']);
				$delete = wp_nonce_url(admin_url('admin-post.php?action=leadforms_go_delete_form&id=' . (int) $item['id']), 'leadforms_go_delete_form_' . (int) $item['id']);
				$visual = ($item['editor_mode'] ?? 'code') === 'visual';
				printf('<tr><td><strong>%s</strong><span class="lfg-form-id">ID %d</span></td><td><span class="lfg-mode-badge">%s</span></td><td><button type="button" class="lfg-shortcode" data-lfg-copy="[leadforms_go_form id=&quot;%d&quot;]" title="%s"><code>[leadforms_go_form id=&quot;%d&quot;]</code><span class="dashicons dashicons-admin-page"></span></button></td><td class="lfg-row-actions"><a class="button" href="%s"><span class="dashicons dashicons-edit"></span>%s</a><a class="lfg-delete-button" href="%s" data-lfg-confirm aria-label="%s" title="%s"><span class="dashicons dashicons-trash"></span></a></td></tr>', esc_html($item['name']), (int) $item['id'], esc_html($visual ? __('Візуально', 'leadforms-go') : __('Код', 'leadforms-go')), (int) $item['id'], esc_attr__('Копіювати шорткод', 'leadforms-go'), (int) $item['id'], esc_url($edit), esc_html__('Редагувати', 'leadforms-go'), esc_url($delete), esc_attr__('Видалити форму', 'leadforms-go'), esc_attr__('Видалити', 'leadforms-go'));
			}
			echo '</tbody></table></div>';
		}
		$this->close();
	}

	public function history(): void
	{
		$this->open(__('Історія заявок', 'leadforms-go'));
		$this->history_notice();
		$submission_id = isset($_GET['submission']) ? absint($_GET['submission']) : 0;
		if ($submission_id > 0) $this->submission_details($submission_id);
		else $this->submission_list();
		$this->close();
	}

	public function settings(): void
	{
		$s = Settings::all(); $name = 'leadforms_go_settings';
		$this->open(__('Налаштування', 'leadforms-go'));
		echo '<form method="post" action="options.php">'; settings_fields('leadforms_go');
		echo '<section class="lfg-card"><h2>' . esc_html__('Зберігання даних', 'leadforms-go') . '</h2><label><input type="checkbox" name="' . esc_attr($name . '[general][retain_data]') . '" value="1" ' . checked(! empty($s['general']['retain_data']), true, false) . '> ' . esc_html__('Зберігати форми та заявки після видалення плагіна', 'leadforms-go') . '</label></section>';
		$sections = [
			'telegram' => ['title' => 'Telegram', 'fields' => ['token' => 'Токен бота', 'chat_id' => 'ID чату']],
			'sheets' => ['title' => 'Google Sheets', 'fields' => ['spreadsheet_id' => 'ID таблиці', 'sheet_name' => 'Назва аркуша', 'fields_order' => 'Порядок полів']],
			'crm' => ['title' => 'CRM G-PLUS', 'fields' => ['partner_id' => 'ID партнера', 'token' => 'API-токен', 'adv_id' => 'ID рекламної форми']],
		];
		foreach ($sections as $section => $section_data) {
			echo '<section class="lfg-card lfg-settings"><header><h2>' . esc_html($section_data['title']) . '</h2><label class="lfg-switch"><input type="checkbox" name="' . esc_attr($name . '[' . $section . '][enabled]') . '" value="1" ' . checked(! empty($s[$section]['enabled']), true, false) . '><span>' . esc_html__('Увімкнено', 'leadforms-go') . '</span></label></header>';
			$fields = $section_data['fields'];
			foreach ($fields as $key => $label) {
				$is_secret = $key === 'token'; $value = $is_secret ? '' : (string) ($s[$section][$key] ?? '');
				echo '<label><span>' . esc_html($label) . '</span><input class="regular-text" type="' . ($is_secret ? 'password' : 'text') . '" name="' . esc_attr($name . '[' . $section . '][' . $key . ']') . '" value="' . esc_attr($value) . '" placeholder="' . ($is_secret && ! empty($s[$section][$key]) ? esc_attr__('Збережено — залиште порожнім, щоб не змінювати', 'leadforms-go') : '') . '"></label>';
			}
			echo '<button type="button" class="button" data-lfg-test="' . esc_attr($section) . '">' . esc_html__('Перевірити підключення', 'leadforms-go') . '</button><span class="lfg-test-result" aria-live="polite"></span></section>';
		}
		$credentials_label = __('Не налаштовано', 'leadforms-go');
		if (defined('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH')) {
			$credentials_path = (string) LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH;
			$credentials_label = is_readable($credentials_path)
				? sprintf(__('Файл доступний: %s', 'leadforms-go'), basename($credentials_path))
				: __('Файл недоступний для читання', 'leadforms-go');
		}
		echo '<section class="lfg-card"><h2>' . esc_html__('Сервісний обліковий запис Google', 'leadforms-go') . '</h2><p><code>LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH</code>: ' . esc_html($credentials_label) . '</p></section>';
		submit_button(__('Зберегти налаштування', 'leadforms-go')); echo '</form>'; $this->close();
	}

	public function save_form(): void
	{
		$this->guard('leadforms_go_save_form');
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$name = sanitize_text_field(self::scalar_string($_POST['name'] ?? ''));
		$mode = isset($_POST['editor_mode']) && $_POST['editor_mode'] === 'visual' ? 'visual' : 'code';
		$submit_label = sanitize_text_field(self::scalar_string($_POST['submit_label'] ?? '')) ?: __('Надіслати', 'leadforms-go');
		$schema = [];
		if ($mode === 'visual') {
			$raw_schema = isset($_POST['schema']) && is_string($_POST['schema']) ? json_decode(wp_unslash($_POST['schema']), true) : [];
			$schema = Form_Builder::sanitize_schema($raw_schema);
			if ($schema === []) wp_die(esc_html__('Додайте щонайменше одне поле до форми.', 'leadforms-go'));
			$duplicates = Form_Builder::duplicate_names($schema);
			if ($duplicates !== []) wp_die(esc_html(sprintf(__('Назви полів мають бути унікальними. Повторюються: %s', 'leadforms-go'), implode(', ', $duplicates))));
			$code = Form_Builder::render($schema, $submit_label);
		} else {
			$code = Form_Builder::sanitize_code(self::scalar_string($_POST['code'] ?? ''));
		}
		if ($name === '' || $code === '') wp_die(esc_html__('Вкажіть назву та вміст форми.', 'leadforms-go'));
		if ($id > 0 && Repositories::form($id) === null) wp_die(esc_html__('Форму не знайдено.', 'leadforms-go'), '', 404);
		$result = Repositories::save_form($id, $name, $code, $mode, $schema, $submit_label);
		if ($result === false) wp_die(esc_html__('Не вдалося зберегти форму.', 'leadforms-go'));
		wp_safe_redirect(admin_url('admin.php?page=leadforms-go-forms&id=' . $result . '&updated=1')); exit;
	}

	public function delete_form(): void
	{
		if (! current_user_can('manage_options')) wp_die(esc_html__('Недостатньо прав.', 'leadforms-go'), '', 403);
		$id = isset($_GET['id']) ? absint($_GET['id']) : 0;
		check_admin_referer('leadforms_go_delete_form_' . $id);
		Repositories::delete_form($id);
		wp_safe_redirect(admin_url('admin.php?page=leadforms-go-forms')); exit;
	}

	public function test_connector(): void
	{
		$this->guard('leadforms_go_admin', 'nonce');
		$key = sanitize_key(self::scalar_string($_POST['connector'] ?? ''));
		$connectors = Connectors::all();
		if (! isset($connectors[$key])) wp_send_json_error(['message' => __('Невідома інтеграція.', 'leadforms-go')], 400);
		try {
			$result = $connectors[$key]->test_connection();
		} catch (\Throwable) {
			wp_send_json_error(['message' => __('Під час перевірки сталася внутрішня помилка.', 'leadforms-go')], 500);
		}
		$result->success ? wp_send_json_success(['message' => $result->message ?: __('Підключення успішне.', 'leadforms-go')]) : wp_send_json_error(['message' => $result->message, 'http_code' => $result->http_code], 400);
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
			echo '<tr><th class="check-column"><input type="checkbox" name="submission_ids[]" value="' . (int) $row['id'] . '" aria-label="' . esc_attr(sprintf(__('Вибрати заявку #%d', 'leadforms-go'), (int) $row['id'])) . '"></th><td><a class="lfg-submission-id" href="' . esc_url(admin_url('admin.php?page=leadforms-go-history&submission=' . (int) $row['id'])) . '">#' . (int) $row['id'] . '</a><span>' . esc_html($row['form_name'] ?: __('Видалена або імпортована форма', 'leadforms-go')) . '</span><time>' . esc_html(wp_date('d.m.Y H:i', strtotime((string) $row['created_at']))) . '</time></td><td><div class="lfg-payload-preview">' . esc_html(self::payload_preview($payload)) . '</div></td><td><div class="lfg-delivery-stack">';
			if ($row['deliveries'] === []) echo '<span class="lfg-delivery-status is-success">' . esc_html__('Без інтеграцій', 'leadforms-go') . '</span>';
			foreach ($row['deliveries'] as $delivery) printf('<span class="lfg-delivery-status is-%s" title="%s"><strong>%s</strong>%s</span>', esc_attr($delivery['status']), esc_attr((string) $delivery['error_message']), esc_html($titles[$delivery['connector']] ?? ucfirst($delivery['connector'])), esc_html(self::status_label((string) $delivery['status'])));
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
		echo '</div><section class="lfg-submission-summary"><div><span>' . esc_html__('Заявка', 'leadforms-go') . '</span><strong>#' . $submission_id . '</strong></div><div><span>' . esc_html__('Форма', 'leadforms-go') . '</span><strong>' . esc_html($submission['form_name'] ?: __('Видалена або імпортована форма', 'leadforms-go')) . '</strong></div><div><span>' . esc_html__('Створено', 'leadforms-go') . '</span><strong>' . esc_html(wp_date('d.m.Y H:i:s', strtotime((string) $submission['created_at']))) . '</strong></div><div><span>' . esc_html__('Статус', 'leadforms-go') . '</span><strong><span class="lfg-delivery-status is-' . esc_attr($submission['status']) . '">' . esc_html(self::status_label((string) $submission['status'])) . '</span></strong></div></section>';
		$payload = json_decode((string) $submission['payload'], true);
		$payload = is_array($payload) ? $payload : [];
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
		echo '<article class="lfg-card lfg-delivery-card"><header><div><h3>' . esc_html($titles[$delivery['connector']] ?? ucfirst($delivery['connector'])) . '</h3><span class="lfg-delivery-status is-' . esc_attr($delivery['status']) . '">' . esc_html(self::status_label((string) $delivery['status'])) . '</span></div>';
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
		foreach (array_slice($payload, 0, 3, true) as $key => $value) $parts[] = (string) $key . ': ' . (is_scalar($value) ? (string) $value : '—');
		return implode(' · ', $parts) ?: '—';
	}

	private static function status_label(string $status): string
	{
		return [
			'pending' => __('Очікує', 'leadforms-go'),
			'queued' => __('У черзі', 'leadforms-go'),
			'processing' => __('Обробляється', 'leadforms-go'),
			'success' => __('Успішно', 'leadforms-go'),
			'failed' => __('Помилка', 'leadforms-go'),
			'cancelled' => __('Скасовано', 'leadforms-go'),
		][$status] ?? $status;
	}

	private function guard(string $action, string $field = '_wpnonce'): void
	{
		if (! current_user_can('manage_options')) wp_die(esc_html__('Недостатньо прав.', 'leadforms-go'), '', 403);
		if ($field === '_wpnonce') check_admin_referer($action); elseif (! check_ajax_referer($action, $field, false)) wp_send_json_error(['message' => __('Некоректний запит.', 'leadforms-go')], 403);
	}
	private function open(string $title): void { echo '<div class="wrap leadforms-go-admin"><h1>' . esc_html($title) . '</h1>'; }
	private function close(): void { echo '</div>'; }
}
