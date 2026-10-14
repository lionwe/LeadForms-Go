<?php

declare(strict_types=1);

namespace LeadFormsGo;

final class Admin_Integrations
{
	public function boot(): void
	{
		add_action('wp_ajax_leadforms_go_route_test', [$this, 'test_route']);
		add_action('wp_ajax_leadforms_go_route_status', [$this, 'route_status']);
		add_action('wp_ajax_leadforms_go_sheets_list', [$this, 'sheets_list']);
		add_action('wp_ajax_leadforms_go_sheets_create', [$this, 'sheets_create']);
		add_action('wp_ajax_leadforms_go_sheets_headers', [$this, 'sheets_headers']);
	}

	public function render(?array $form, array $schema, array $locales): string
	{
		$config = Route_Config::for_form($form);
		$form_id = (int) ($form['id'] ?? 0);
		$variables = Route_Config::available_variables($schema);
		$dedupe_variables = Route_Config::dedupe_variables($schema);
		$state_options = ['inherit' => __('Успадкувати глобальне', 'leadforms-go'), 'enabled' => __('Увімкнути для форми', 'leadforms-go'), 'disabled' => __('Вимкнути для форми', 'leadforms-go')];
		ob_start();
		?>
		<section class="lfg-integrations" data-lfg-integrations data-form-id="<?php echo esc_attr((string) $form_id); ?>" data-locales="<?php echo esc_attr((string) wp_json_encode($locales, JSON_UNESCAPED_UNICODE)); ?>" data-variables="<?php echo esc_attr((string) wp_json_encode($variables)); ?>">
			<header class="lfg-integrations__heading">
				<div><h2><?php esc_html_e('Інтеграції форми', 'leadforms-go'); ?></h2><p><?php esc_html_e('Виберіть, куди надсилати нові заявки, і налаштуйте кожен канал окремо.', 'leadforms-go'); ?></p></div>
			</header>
			<p class="lfg-route-note"><?php esc_html_e('Глобальний перемикач інтеграції в налаштуваннях — загальний вимикач для всіх форм і профілів. Ручне тестування маршруту надсилає діагностичне повідомлення навіть коли глобальний перемикач вимкнений.', 'leadforms-go'); ?></p>
			<?php $this->render_delivery_health($form_id, $config); ?>
			<textarea hidden name="routing_config" data-lfg-routing-config><?php echo esc_textarea((string) wp_json_encode($config, JSON_UNESCAPED_UNICODE)); ?></textarea>
			<?php $this->test_data($schema); ?>

			<?php $this->route_start('telegram', 'Telegram', $config['telegram'], $state_options); ?>
			<div class="lfg-route-layout lfg-route-layout--telegram">
				<div class="lfg-route-main">
					<section class="lfg-route-section">
						<div class="lfg-route-section__heading"><div><h4><?php esc_html_e('Основні налаштування', 'leadforms-go'); ?></h4><p><?php esc_html_e('Порожні значення автоматично беруться з глобальних налаштувань Telegram.', 'leadforms-go'); ?></p></div></div>
						<div class="lfg-route-grid lfg-route-grid--telegram">
							<label><span><?php esc_html_e('Chat ID', 'leadforms-go'); ?></span><input type="text" data-lfg-route-input="telegram.chat_id" value="<?php echo esc_attr((string) $config['telegram']['chat_id']); ?>" placeholder="<?php esc_attr_e('Глобальний Chat ID', 'leadforms-go'); ?>"></label>
							<label><span><?php esc_html_e('Topic ID', 'leadforms-go'); ?></span><input type="number" min="0" data-lfg-route-input="telegram.topic_id" value="<?php echo esc_attr((string) $config['telegram']['topic_id']); ?>"><small><?php esc_html_e('0 — без окремої теми', 'leadforms-go'); ?></small></label>
							<label><span><?php esc_html_e('Формат шаблону', 'leadforms-go'); ?></span><select data-lfg-route-input="telegram.parse_mode"><option value="plain" <?php selected($config['telegram']['parse_mode'], 'plain'); ?>>Plain text</option><option value="HTML" <?php selected($config['telegram']['parse_mode'], 'HTML'); ?>>HTML</option><option value="MarkdownV2" <?php selected($config['telegram']['parse_mode'], 'MarkdownV2'); ?>>MarkdownV2</option></select></label>
						</div>
					</section>
					<section class="lfg-route-section lfg-route-section--compact">
						<label class="lfg-setting-switch">
							<input type="checkbox" data-lfg-route-input="telegram.interactive" value="1" <?php checked(! empty($config['telegram']['interactive'])); ?>>
							<span class="lfg-setting-switch__control" aria-hidden="true"></span>
							<span class="lfg-setting-switch__copy"><strong><?php esc_html_e('Кнопки керування статусом', 'leadforms-go'); ?></strong><small><?php esc_html_e('Додає дії «У роботу», «Передзвонити», «Завершено», «Відхилити» та «Спам».', 'leadforms-go'); ?></small></span>
							<span class="lfg-setting-switch__state"><?php esc_html_e('Необов’язково', 'leadforms-go'); ?></span>
						</label>
						<p class="lfg-route-note"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e('«Скопіювати номер» і «Відкрити сторінку» додаються незалежно від цього перемикача.', 'leadforms-go'); ?></p>
					</section>
					<details class="lfg-route-advanced">
						<summary><span><strong><?php esc_html_e('Розширені налаштування', 'leadforms-go'); ?></strong><small><?php esc_html_e('Власний шаблон, змінні, профілі та додаткові кнопки', 'leadforms-go'); ?></small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
						<div class="lfg-route-advanced__body">
							<?php echo $this->profile_selector('telegram', (array) ($config['telegram']['profile_ids'] ?? [])); ?>
							<div class="lfg-route-variables"><strong><?php esc_html_e('Змінні шаблону', 'leadforms-go'); ?></strong><div><?php foreach ($variables as $variable) echo '<code>{' . esc_html($variable) . '}</code>'; ?></div></div>
							<?php foreach ($locales as $locale => $label) : ?>
								<div class="lfg-route-locale" data-lfg-route-locale="<?php echo esc_attr((string) $locale); ?>">
									<div><h4><?php echo esc_html((string) $label); ?></h4><p><?php esc_html_e('Залиште порожнім, щоб використовувати стандартний формат повідомлення.', 'leadforms-go'); ?></p></div>
									<label><span><?php esc_html_e('Власний шаблон повідомлення', 'leadforms-go'); ?></span><textarea rows="7" maxlength="4096" data-lfg-route-input="telegram.templates.<?php echo esc_attr((string) $locale); ?>"><?php echo esc_textarea((string) ($config['telegram']['templates'][$locale] ?? '')); ?></textarea></label>
									<div class="lfg-telegram-buttons" data-lfg-telegram-buttons="<?php echo esc_attr((string) $locale); ?>"><div class="lfg-sheet-columns__heading"><div><strong><?php esc_html_e('Додаткові inline-кнопки', 'leadforms-go'); ?></strong><small><?php esc_html_e('До п’яти власних посилань під повідомленням.', 'leadforms-go'); ?></small></div><button type="button" class="button" data-lfg-add-telegram-button><?php esc_html_e('Додати кнопку', 'leadforms-go'); ?></button></div><div data-lfg-button-rows></div></div>
								</div>
							<?php endforeach; ?>
						</div>
					</details>
				</div>
				<aside class="lfg-route-preview lfg-route-preview--sticky">
					<div class="lfg-route-preview__heading"><div><strong><?php esc_html_e('Попередній перегляд', 'leadforms-go'); ?></strong><small><?php esc_html_e('Оновлюється під час редагування шаблону', 'leadforms-go'); ?></small></div><span class="dashicons dashicons-format-chat" aria-hidden="true"></span></div>
					<pre data-lfg-telegram-preview></pre>
					<div class="lfg-route-test"><button type="button" class="button button-primary" data-lfg-test-route="telegram"><?php esc_html_e('Надіслати тестову заявку', 'leadforms-go'); ?></button><span data-lfg-route-result aria-live="polite"></span></div>
				</aside>
			</div>
			<?php $this->route_end('telegram', false); ?>

			<?php $this->route_start('sheets', 'Google Sheets', $config['sheets'], $state_options); ?>
			<section class="lfg-route-section"><?php echo $this->profile_selector('sheets', (array) ($config['sheets']['profile_ids'] ?? [])); ?><div class="lfg-route-section__heading"><div><h4><?php esc_html_e('Підключення до таблиці', 'leadforms-go'); ?></h4><p><?php esc_html_e('Вкажіть таблицю, аркуш і спосіб додавання нових заявок.', 'leadforms-go'); ?></p></div></div><div class="lfg-route-grid">
				<label><span><?php esc_html_e('Посилання або Spreadsheet ID', 'leadforms-go'); ?></span><input type="text" data-lfg-route-input="sheets.spreadsheet_id" value="<?php echo esc_attr((string) $config['sheets']['spreadsheet_id']); ?>"></label>
				<label><span><?php esc_html_e('Аркуш', 'leadforms-go'); ?></span><select data-lfg-route-input="sheets.sheet_name" data-lfg-sheet-select><option value="<?php echo esc_attr((string) $config['sheets']['sheet_name']); ?>"><?php echo esc_html((string) ($config['sheets']['sheet_name'] ?: __('Спочатку завантажте аркуші', 'leadforms-go'))); ?></option></select></label>
				<label><span><?php esc_html_e('Режим запису', 'leadforms-go'); ?></span><select data-lfg-route-input="sheets.write_mode"><option value="append" <?php selected($config['sheets']['write_mode'], 'append'); ?>><?php esc_html_e('Новий рядок', 'leadforms-go'); ?></option><option value="update" <?php selected($config['sheets']['write_mode'], 'update'); ?>><?php esc_html_e('Оновити за ключем або додати', 'leadforms-go'); ?></option></select></label>
				<label><span><?php esc_html_e('Ключ пошуку', 'leadforms-go'); ?></span><select data-lfg-route-input="sheets.dedupe_key"><option value=""><?php esc_html_e('Не вибрано', 'leadforms-go'); ?></option><?php foreach ($dedupe_variables as $variable) echo '<option value="' . esc_attr($variable) . '" ' . selected($config['sheets']['dedupe_key'], $variable, false) . '>' . esc_html($variable) . '</option>'; ?></select></label>
			</div><div class="lfg-route-actions"><button type="button" class="button" data-lfg-sheets-list><?php esc_html_e('Завантажити аркуші', 'leadforms-go'); ?></button><input type="text" data-lfg-new-sheet placeholder="<?php esc_attr_e('Назва нового аркуша', 'leadforms-go'); ?>"><button type="button" class="button" data-lfg-sheets-create><?php esc_html_e('Створити аркуш', 'leadforms-go'); ?></button></div></section>
			<section class="lfg-route-section"><div class="lfg-sheet-columns"><div class="lfg-sheet-columns__heading"><div><strong><?php esc_html_e('Колонки таблиці', 'leadforms-go'); ?></strong><small><?php esc_html_e('Зіставте поля форми з колонками Google Sheets.', 'leadforms-go'); ?></small></div><button type="button" class="button" data-lfg-add-column><?php esc_html_e('Додати колонку', 'leadforms-go'); ?></button></div><div data-lfg-column-rows></div><button type="button" class="button" data-lfg-sheets-headers><?php esc_html_e('Створити / оновити заголовки', 'leadforms-go'); ?></button></div></section>
			<div class="lfg-route-preview"><strong><?php esc_html_e('Попередній перегляд даних', 'leadforms-go'); ?></strong><pre data-lfg-route-payload="sheets"></pre></div>
			<?php $this->route_end('sheets'); ?>

			<?php $this->route_start('crm', 'CRM G-PLUS', $config['crm'], $state_options); ?>
			<section class="lfg-route-section"><?php echo $this->profile_selector('crm', (array) ($config['crm']['profile_ids'] ?? [])); ?><div class="lfg-route-section__heading"><div><h4><?php esc_html_e('Підключення до CRM', 'leadforms-go'); ?></h4><p><?php esc_html_e('Налаштуйте ідентифікатор форми та відповідність полів.', 'leadforms-go'); ?></p></div></div><div class="lfg-route-grid"><label><span><?php esc_html_e('ID рекламної форми (Advertising / Form ID)', 'leadforms-go'); ?></span><input type="text" data-lfg-route-input="crm.adv_id" value="<?php echo esc_attr((string) $config['crm']['adv_id']); ?>" placeholder="<?php esc_attr_e('Глобальне значення', 'leadforms-go'); ?>"></label></div></section>
			<section class="lfg-route-section"><div class="lfg-crm-mapping"><div class="lfg-sheet-columns__heading"><div><strong><?php esc_html_e('Відповідність полів CRM', 'leadforms-go'); ?></strong><small><?php esc_html_e('Вкажіть, у яке поле CRM передавати кожне значення форми.', 'leadforms-go'); ?></small></div><button type="button" class="button" data-lfg-add-crm-mapping><?php esc_html_e('Додати поле', 'leadforms-go'); ?></button></div><div data-lfg-crm-mapping-rows></div></div></section>
			<div class="lfg-route-preview"><strong><?php esc_html_e('Попередній перегляд даних', 'leadforms-go'); ?></strong><pre data-lfg-route-payload="crm"></pre></div>
			<?php $this->route_end('crm'); ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	public function test_route(): void
	{
		$this->guard();
		$form_id = absint($_POST['form_id'] ?? 0);
		$connector_key = sanitize_key($this->scalar($_POST['connector'] ?? ''));
		$form = $form_id > 0 ? Repositories::form($form_id) : null;
		if (! $form) wp_send_json_error(['message' => __('Спочатку збережіть форму.', 'leadforms-go')], 404);
		$schema = Form_Builder::sanitize_schema(json_decode((string) $form['form_schema'], true));
		$translations = json_decode((string) ($form['translations'] ?? ''), true);
		$locales = Form_Translations::locales(Form_Translations::sanitize(is_array($translations) ? $translations : []));
		$config = Route_Config::sanitize($this->json_post('routing_config'), $schema, $locales);
		$valid = Route_Config::validate($config, $schema);
		if (is_wp_error($valid)) wp_send_json_error(['message' => $valid->get_error_message()], 422);
		$connectors = Connectors::all();
		$connector = $connectors[$connector_key] ?? null;
		if (! $connector instanceof Contextual_Connector_Interface) wp_send_json_error(['message' => __('Маршрут недоступний.', 'leadforms-go')], 400);
		$destinations = Route_Config::destinations($config, $connector_key, true);
		$route = (array) ($destinations[0]['route'] ?? ($config[$connector_key] ?? []));
		$route_valid = $connector->validate_route(is_array($route) ? $route : []);
		if (is_wp_error($route_valid)) wp_send_json_error(['message' => $route_valid->get_error_message()], 422);
		$payload = Submission_Validator::sanitize_payload($this->json_post('payload'));
		if ($payload === []) wp_send_json_error(['message' => __('Тестові дані порожні.', 'leadforms-go')], 422);
		$locale = Form_Translations::normalize_locale($this->scalar($_POST['locale'] ?? '')) ?: (string) $form['default_locale'];
		$submission = Repositories::create_submission($form_id, $payload, home_url('/'), $locale, 'test_' . wp_generate_uuid4(), true);
		if ($submission['id'] <= 0) wp_send_json_error(['message' => __('Не вдалося створити тестову заявку.', 'leadforms-go')], 500);
		$config[$connector_key] = $route;
		$config[$connector_key]['state'] = 'enabled';
		$queue = new Delivery_Queue();
		$delivery_id = $queue->queue_test_submission((int) $submission['id'], $connector_key, $config);
		if ($delivery_id <= 0) wp_send_json_error(['message' => __('Не вдалося створити тестову доставку.', 'leadforms-go')], 500);
		$queue->process('test');
		wp_send_json_success($this->delivery_response($delivery_id, (int) $submission['id']));
	}

	public function route_status(): void
	{
		$this->guard();
		$delivery_id = absint($_POST['delivery_id'] ?? 0);
		$submission_id = absint($_POST['submission_id'] ?? 0);
		if (! Repositories::delivery_belongs_to_submission($delivery_id, $submission_id)) wp_send_json_error(['message' => __('Доставку не знайдено.', 'leadforms-go')], 404);
		wp_send_json_success($this->delivery_response($delivery_id, $submission_id));
	}

	public function sheets_list(): void
	{
		$this->guard();
		$id = $this->spreadsheet_id($this->scalar($_POST['spreadsheet_id'] ?? ''));
		$result = (new Google_Sheets_Service())->sheets($id);
		if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
		wp_send_json_success(['sheets' => $result]);
	}

	public function sheets_create(): void
	{
		$this->guard();
		$id = $this->spreadsheet_id($this->scalar($_POST['spreadsheet_id'] ?? ''));
		$result = (new Google_Sheets_Service())->create_sheet($id, $this->scalar($_POST['sheet_name'] ?? ''));
		if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
		wp_send_json_success(['sheet' => $result]);
	}

	public function sheets_headers(): void
	{
		$this->guard();
		$id = $this->spreadsheet_id($this->scalar($_POST['spreadsheet_id'] ?? ''));
		$headers = array_map('sanitize_text_field', array_slice((array) $this->json_post('headers'), 0, 50));
		$result = (new Google_Sheets_Service())->write_headers($id, sanitize_text_field($this->scalar($_POST['sheet_name'] ?? '')), $headers);
		if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 400);
		wp_send_json_success(['message' => __('Заголовки оновлено.', 'leadforms-go')]);
	}

	private function route_start(string $key, string $title, array $route, array $states): void
	{
		$descriptions = [
			'telegram' => __('Повідомлення, статуси та додаткові кнопки', 'leadforms-go'),
			'sheets' => __('Передача заявок у таблицю Google Sheets', 'leadforms-go'),
			'crm' => __('Передача лідів у CRM G-PLUS', 'leadforms-go'),
		];
		$icons = ['telegram' => 'format-chat', 'sheets' => 'media-spreadsheet', 'crm' => 'groups'];
		echo '<article class="lfg-route-card" data-lfg-route="' . esc_attr($key) . '"><header class="lfg-route-card__header"><button type="button" class="lfg-route-toggle" data-lfg-route-toggle aria-expanded="false"><span class="lfg-route-icon dashicons dashicons-' . esc_attr($icons[$key] ?? 'admin-generic') . '" aria-hidden="true"></span><span><strong>' . esc_html($title) . '</strong><small>' . esc_html($descriptions[$key] ?? '') . '</small></span><span class="lfg-route-chevron dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button><label class="lfg-route-state"><span>' . esc_html__('Режим', 'leadforms-go') . '</span><select data-lfg-route-input="' . esc_attr($key . '.state') . '" aria-label="' . esc_attr(sprintf(__('Стан маршруту %s', 'leadforms-go'), $title)) . '">';
		foreach ($states as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected($route['state'] ?? 'inherit', $value, false) . '>' . esc_html($label) . '</option>';
		echo '</select></label></header><div class="lfg-route-card__body" hidden>';
	}

	private function test_data(array $schema): void
	{
		$fields = [];
		foreach ($schema as $field) {
			$key = sanitize_key((string) ($field['key'] ?? ''));
			if ($key === '') continue;
			$type = sanitize_key((string) ($field['type'] ?? 'text'));
			$value = match ($type) {
				'email' => 'test@example.com',
				'tel' => '+38 (099) 111-22-33',
				'checkbox' => '1',
				'textarea' => __('Тестове повідомлення', 'leadforms-go'),
				default => __('Тест', 'leadforms-go'),
			};
			$fields[$key] = ['label' => sanitize_text_field((string) ($field['label'] ?? $key)), 'value' => $value];
		}
		$fields += [
			'utm_source' => ['label' => 'utm_source', 'value' => 'leadforms_go_test'],
			'utm_medium' => ['label' => 'utm_medium', 'value' => 'admin'],
			'utm_campaign' => ['label' => 'utm_campaign', 'value' => 'route_test'],
			'page_url' => ['label' => 'page_url', 'value' => home_url('/')],
		];
		?>
		<details class="lfg-test-data">
			<summary><?php esc_html_e('Тестові дані заявки', 'leadforms-go'); ?></summary>
			<p><?php esc_html_e('Значення можна змінити перед тестуванням маршруту. Вони зберігаються лише у тестовій заявці.', 'leadforms-go'); ?></p>
			<div class="lfg-test-data__grid">
				<?php foreach ($fields as $key => $field) : ?>
					<label><span><?php echo esc_html((string) $field['label']); ?></span><input type="text" data-lfg-test-value="<?php echo esc_attr((string) $key); ?>" value="<?php echo esc_attr((string) $field['value']); ?>"></label>
				<?php endforeach; ?>
			</div>
		</details>
		<?php
	}

	private function route_end(string $key, bool $show_test = true): void
	{
		if ($show_test) echo '<div class="lfg-route-test"><button type="button" class="button button-primary" data-lfg-test-route="' . esc_attr($key) . '">' . esc_html__('Надіслати тестову заявку', 'leadforms-go') . '</button><span data-lfg-route-result aria-live="polite"></span></div>';
		echo '</div></article>';
	}

	private function render_delivery_health(int $form_id, array $config): void
	{
		if ($form_id <= 0) return;
		global $wpdb;
		$tables = Database::tables();
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT SUBSTRING_INDEX(d.connector, '__', 1) AS connector, SUM(d.status IN ('sent','success')) AS successful, SUM(d.status = 'failed') AS failed, SUM(d.status = 'queued') AS queued, SUM(d.status = 'processing') AS processing, SUM(d.status = 'cancelled') AS cancelled, MAX(CASE WHEN d.status IN ('sent','success') THEN d.updated_at ELSE NULL END) AS last_success FROM {$tables['deliveries']} d INNER JOIN {$tables['submissions']} s ON s.id = d.submission_id WHERE s.form_id = %d AND s.is_test = 0 AND s.is_suspected_bot = 0 GROUP BY SUBSTRING_INDEX(d.connector, '__', 1)",
			$form_id
		), ARRAY_A) ?: [];
		$health = [];
		foreach ($rows as $row) $health[sanitize_key((string) ($row['connector'] ?? ''))] = $row;
		$labels = ['telegram' => 'Telegram', 'sheets' => 'Google Sheets', 'crm' => 'CRM G-PLUS'];
		?>
		<details class="lfg-route-health">
			<summary><?php esc_html_e('Стан доставок для цієї форми', 'leadforms-go'); ?></summary>
			<p class="description"><?php esc_html_e('Дані показують фактичні результати доставок, а не лише наявність налаштувань. Статус «Увімкнено» не гарантує доступність стороннього сервісу.', 'leadforms-go'); ?></p>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e('Інтеграція', 'leadforms-go'); ?></th><th><?php esc_html_e('Режим', 'leadforms-go'); ?></th><th><?php esc_html_e('Успішні доставки', 'leadforms-go'); ?></th><th><?php esc_html_e('Помилки', 'leadforms-go'); ?></th><th><?php esc_html_e('У черзі', 'leadforms-go'); ?></th><th><?php esc_html_e('Обробляється', 'leadforms-go'); ?></th><th><?php esc_html_e('Скасовано', 'leadforms-go'); ?></th><th><?php esc_html_e('Остання успішна', 'leadforms-go'); ?></th></tr></thead><tbody>
			<?php foreach ($labels as $key => $label) :
				$route_state = (string) ($config[$key]['state'] ?? 'inherit');
				$enabled = Route_Config::is_enabled($key, $config[$key] ?? []);
				$row = $health[$key] ?? [];
				$mode = ! $enabled ? __('Вимкнено', 'leadforms-go') : ($route_state === 'enabled' ? __('Увімкнено для форми', 'leadforms-go') : __('Успадковано глобальне', 'leadforms-go'));
				$last_success = (string) ($row['last_success'] ?? '');
				$last_success_date = $last_success !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $last_success, wp_timezone()) : false;
				?>
				<tr><td><?php echo esc_html($label); ?></td><td><?php echo esc_html($mode); ?></td><td><?php echo esc_html((string) (int) ($row['successful'] ?? 0)); ?></td><td><?php echo esc_html((string) (int) ($row['failed'] ?? 0)); ?></td><td><?php echo esc_html((string) (int) ($row['queued'] ?? 0)); ?></td><td><?php echo esc_html((string) (int) ($row['processing'] ?? 0)); ?></td><td><?php echo esc_html((string) (int) ($row['cancelled'] ?? 0)); ?></td><td><?php echo $last_success_date instanceof \DateTimeImmutable ? esc_html($last_success_date->format('Y-m-d H:i')) : esc_html__('Ще не було', 'leadforms-go'); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</details>
		<?php
	}

	private function profile_selector(string $connector, array $selected): string
	{
		$profiles = Connection_Profiles::all($connector);
		if ($profiles === []) return '<p class="lfg-route-profiles__empty"><a href="' . esc_url(admin_url('admin.php?page=leadforms-go-settings')) . '">' . esc_html__('Створіть профіль підключення в налаштуваннях, якщо потрібно надсилати заявки в кілька місць.', 'leadforms-go') . '</a></p>';
		$html = '<fieldset class="lfg-route-profiles"><legend>' . esc_html__('Додаткові профілі доставки', 'leadforms-go') . '</legend>';
		foreach ($profiles as $profile) {
			$id = (string) ($profile['id'] ?? '');
			$html .= '<label><input type="checkbox" data-lfg-profile="' . esc_attr($connector) . '" value="' . esc_attr($id) . '" ' . checked(in_array($id, $selected, true), true, false) . '> ' . esc_html((string) ($profile['name'] ?? $id)) . '</label>';
		}
		return $html . '</fieldset>';
	}

	private function delivery_response(int $delivery_id, int $submission_id): array
	{
		$delivery = Repositories::delivery($delivery_id) ?? [];
		return [
			'delivery_id' => $delivery_id,
			'submission_id' => $submission_id,
			'status' => sanitize_key((string) ($delivery['status'] ?? 'queued')),
			'attempts' => absint($delivery['attempts'] ?? 0),
			'http_code' => absint($delivery['http_code'] ?? 0),
			'error_message' => sanitize_text_field((string) ($delivery['error_message'] ?? '')),
			'external_reference' => sanitize_text_field((string) ($delivery['external_reference'] ?? '')),
		];
	}

	private function guard(): void
	{
		if (! current_user_can('manage_options')) wp_send_json_error(['message' => __('Недостатньо прав.', 'leadforms-go')], 403);
		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_send_json_error(['message' => __('Некоректний метод запиту.', 'leadforms-go')], 405);
		if (! check_ajax_referer('leadforms_go_routes', 'nonce', false)) wp_send_json_error(['message' => __('Некоректний запит.', 'leadforms-go')], 403);
	}

	private function json_post(string $key): array
	{
		$raw = isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
		if ($raw === '' || strlen($raw) > 131072) return [];
		$value = json_decode($raw, true);
		return is_array($value) ? $value : [];
	}

	private function scalar(mixed $value): string
	{
		return is_scalar($value) ? wp_unslash((string) $value) : '';
	}

	private function spreadsheet_id(string $value): string
	{
		if (preg_match('~/(?:spreadsheets/)?(?:u/\d+/)?d/([A-Za-z0-9_-]+)~', $value, $match)) $value = $match[1];
		return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $value);
	}
}
