<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/includes/class-form-translations.php';
require dirname(__DIR__) . '/includes/class-telegram-template.php';
require dirname(__DIR__) . '/includes/class-route-config.php';
require dirname(__DIR__) . '/includes/class-google-sheets-service.php';
require dirname(__DIR__) . '/includes/class-submission-security.php';
require dirname(__DIR__) . '/includes/class-submission-validator.php';
require dirname(__DIR__) . '/includes/class-result.php';
require dirname(__DIR__) . '/includes/class-lead-deduplicator.php';
require dirname(__DIR__) . '/includes/class-telegram-interactions.php';
require dirname(__DIR__) . '/includes/class-telegram-message-formatter.php';
require dirname(__DIR__) . '/includes/class-submission-presenter.php';

use LeadFormsGo\Google_Sheets_Service;
use LeadFormsGo\Route_Config;
use LeadFormsGo\Telegram_Template;
use LeadFormsGo\Submission_Security;
use LeadFormsGo\Submission_Validator;
use LeadFormsGo\Form_Translations;
use LeadFormsGo\Lead_Deduplicator;
use LeadFormsGo\Telegram_Interactions;
use LeadFormsGo\Telegram_Message_Formatter;
use LeadFormsGo\Repositories;
use LeadFormsGo\Submission_Presenter;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
	if (! $condition) $failures[] = $message;
};

$assert(Google_Sheets_Service::column_letter(1) === 'A', 'Column 1 must be A.');
$assert(Google_Sheets_Service::column_letter(27) === 'AA', 'Column 27 must be AA.');
$assert(Google_Sheets_Service::a1_range("Sales Q1's", 'A1:C1') === "'Sales Q1''s'!A1:C1", 'A1 sheet names must be quoted.');

$rendered = Telegram_Template::render('Hello {first_name}', 'MarkdownV2', ['first_name' => 'A_B']);
$assert($rendered === 'Hello A\\_B', 'Markdown variables must be escaped.');
$unknown = Telegram_Template::validate('{unknown}', 'plain', ['first_name']);
$assert(is_wp_error($unknown), 'Unknown Telegram variables must fail validation.');
$invalid_markdown = Telegram_Template::validate('[Broken link', 'MarkdownV2', []);
$assert(is_wp_error($invalid_markdown), 'Unbalanced MarkdownV2 must fail validation.');

$schema = [['key' => 'first_name'], ['key' => 'phone']];
$config = Route_Config::sanitize([
	'telegram' => ['state' => 'enabled', 'templates' => ['uk_UA' => '{first_name}']],
	'sheets' => ['columns' => [['header' => 'Name', 'type' => 'field', 'source' => 'first_name']]],
], $schema);
$assert($config['telegram']['state'] === 'enabled', 'Route state must be preserved.');
$assert(Route_Config::VERSION === 4, 'Route snapshots must use the optional Telegram status actions schema.');
$assert($config['telegram']['profile_ids'] === [], 'Routes without profiles must keep an empty profile list.');
$assert($config['sheets']['columns'][0]['source'] === 'first_name', 'Valid mapping must be preserved.');
$assert(Route_Config::resolve_value(['type' => 'field', 'source' => 'phone'], ['phone' => '+380']) === '+380', 'Mapping must resolve payload values.');
$dispatch_token = Submission_Security::dispatch_token(42);
$assert(Submission_Security::verify_dispatch_token(42, $dispatch_token), 'Dispatch token must verify for its submission.');
$assert(! Submission_Security::verify_dispatch_token(43, $dispatch_token), 'Dispatch token must not verify for another submission.');
$phone_form = ['editor_mode' => 'visual', 'form_schema' => json_encode([['key' => 'phone', 'type' => 'tel', 'required' => true]])];
$valid_polish_phone = Submission_Validator::validate($phone_form, ['phone' => '+48 501 234 567']);
$invalid_country_phone = Submission_Validator::validate($phone_form, ['phone' => '+49 151 23456789']);
$assert($valid_polish_phone['errors'] === [], 'An allowed international phone number must pass validation.');
$assert(isset($invalid_country_phone['errors']['phone']), 'A phone number from a disabled country must fail validation.');
$locales = Form_Translations::available_locales();
$assert(isset($locales['pl_PL'], $locales['de_DE']), 'Polish and German must be available in the form builder.');
$assert(Form_Translations::default_fields('pl_PL')['phone']['label'] === 'Numer telefonu', 'Polish field defaults must be translated.');
$assert(Form_Translations::default_fields('de_DE')['phone']['label'] === 'Telefonnummer', 'German field defaults must be translated.');
$polish_defaults = Form_Translations::resolve([], 'pl_PL', 'uk_UA');
$assert($polish_defaults['submit_label'] === 'Wyślij', 'Polish forms without stored translations must use Polish defaults.');
$choice_schema = [
	['key' => 'product', 'type' => 'select', 'label' => 'Продукт', 'options' => ['unihum', 'unipellet']],
	['key' => 'delivery', 'type' => 'radio', 'label' => 'Доставка', 'options' => ['pickup', 'courier']],
];
Repositories::$forms[7] = [
	'editor_mode' => 'visual',
	'form_schema' => wp_json_encode($choice_schema),
	'default_locale' => 'uk_UA',
	'translations' => wp_json_encode([
		'uk_UA' => ['submit_label' => 'Надіслати', 'messages' => [], 'fields' => [
			'product' => ['label' => 'Продукт', 'placeholder' => 'Оберіть продукт', 'options' => ['Унігум', 'Уніпелет']],
			'delivery' => ['label' => 'Доставка', 'placeholder' => '', 'options' => ['Самовивіз', 'Кур’єр']],
		]],
		'en_US' => ['submit_label' => 'Send', 'messages' => [], 'fields' => [
			'product' => ['label' => 'Product', 'placeholder' => 'Choose a product', 'options' => ['Unihum', 'Unipellet']],
			'delivery' => ['label' => 'Delivery', 'placeholder' => '', 'options' => ['Pickup', 'Courier']],
		]],
	]),
];
$presented_choices = Submission_Presenter::for_admin(['product' => 'unihum', 'delivery' => 'courier'], 7, 'en_US');
$assert(($presented_choices['Product'] ?? '') === 'Unihum', 'Select values must use the localized option label.');
$assert(($presented_choices['Delivery'] ?? '') === 'Courier', 'Radio values must use the localized option label.');
$fallback_choice = Submission_Presenter::for_admin(['product' => 'unipellet'], 7, 'fr_FR');
$assert(($fallback_choice['Продукт'] ?? '') === 'Уніпелет', 'Missing option translations must fall back to the primary locale.');
$unknown_choice = Submission_Presenter::for_admin(['product' => 'legacy-value'], 7, 'en_US');
$assert(($unknown_choice['Product'] ?? '') === 'legacy-value', 'Unknown option values must remain unchanged.');
$telegram_variables = Submission_Presenter::for_telegram_variables(['product' => 'unihum', 'page_url' => 'https://example.com'], 7, 'en_US');
$assert($telegram_variables['product'] === 'Unihum', 'Telegram template variables must use localized option labels.');
$assert($telegram_variables['page_url'] === 'https://example.com', 'Non-form Telegram variables must remain unchanged.');
$assert(Route_Config::resolve_value(['type' => 'field', 'source' => 'product'], ['product' => 'unihum']) === 'unihum', 'Connector mappings must keep raw option values.');
$snapshot = Route_Config::snapshot($config, 'telegram', ['form_name' => 'Original', 'submitted_at' => '2026-07-13 10:00:00']);
$snapshot_route = Route_Config::route_from_snapshot($snapshot, 'telegram');
$assert($snapshot_route['_context']['form_name'] === 'Original', 'Route snapshot context must be immutable.');
$contacts = Lead_Deduplicator::contact_values(['contact_email' => ' TEST@Example.COM ', 'phone' => '+38 (099) 111-22-33']);
$assert($contacts['email'] === 'test@example.com', 'Deduplication email must be normalized.');
$assert($contacts['phone'] === '380991112233', 'Deduplication phone must contain canonical digits only.');
$no_contacts = Lead_Deduplicator::contact_values(['message' => 'Call me tomorrow']);
$assert($no_contacts === ['phone' => '', 'email' => ''], 'Payloads without contact fields must remain unique.');
$interactive_config = Route_Config::sanitize(['telegram' => ['interactive' => true]], $schema);
$assert($interactive_config['telegram']['interactive'] === true, 'Interactive Telegram mode must survive route sanitization.');
$assert(Route_Config::sanitize([], $schema)['telegram']['interactive'] === false, 'Telegram status actions must be disabled for routes that do not define them.');
$assert(Route_Config::sanitize(['telegram' => ['interactive' => false]], $schema)['telegram']['interactive'] === false, 'Interactive Telegram mode must support an explicit opt-out.');
$buttons = Telegram_Interactions::action_buttons(42, '123456:test-token');
$assert(count($buttons) === 2 && count($buttons[0]) === 2 && count($buttons[1]) === 2, 'Telegram interaction must expose work, callback, rejection, and spam actions.');
$assert(strlen($buttons[0][0]['callback_data']) <= 64, 'Telegram callback data must fit the Bot API limit.');
$assert(($buttons[0][0]['style'] ?? '') === 'success' && ($buttons[1][0]['style'] ?? '') === 'primary' && ($buttons[1][1]['style'] ?? '') === 'danger', 'Telegram action buttons must expose semantic styles.');
$status_button = Telegram_Interactions::status_button(42, '123456:test-token');
$assert(count($status_button) === 1 && ($status_button[0][0]['text'] ?? '') === 'Змінити статус', 'Processed Telegram submissions must retain a status change action.');

$minimal_fields = [
	['key' => 'first_name', 'label' => 'Ім’я', 'type' => 'text', 'value' => 'Оксана', 'raw_value' => 'Оксана'],
	['key' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'value' => '+38 (050) 991-99-10', 'raw_value' => '+38 (050) 991-99-10'],
];
$minimal_message = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA']);
$assert(str_contains($minimal_message, '<b>Нова заявка</b>'), 'Minimal Telegram messages must use the compact HTML title.');
$assert(str_contains($minimal_message, 'Ім’я: <b>Оксана</b>') && str_contains($minimal_message, 'Номер телефону: <code>+38 (050) 991-99-10</code>'), 'Minimal Telegram messages must label and emphasize the name and phone.');
$assert(! str_contains($minimal_message, 'Деталі звернення') && ! str_contains($minimal_message, 'Джерело'), 'Empty Telegram sections must not be rendered.');

$full_fields = array_merge($minimal_fields, [
	['key' => 'last_name', 'label' => 'Прізвище', 'type' => 'text', 'value' => 'Рад', 'raw_value' => 'Рад'],
	['key' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'oksana@example.com', 'raw_value' => 'oksana@example.com'],
	['key' => 'service', 'label' => 'Послуга', 'type' => 'select', 'value' => 'Консультація флеболога', 'raw_value' => 'consultation'],
	['key' => 'city', 'label' => 'Місто', 'type' => 'text', 'value' => 'Київ', 'raw_value' => 'Київ'],
	['key' => 'comment', 'label' => 'Коментар', 'type' => 'textarea', 'value' => 'Після 16:00', 'raw_value' => 'Після 16:00'],
]);
$source_url = 'https://example.com/landing?utm_source=ig&utm_medium=paid&fbclid=long-click-id';
$full_message = Telegram_Message_Formatter::format($full_fields, [
	'locale' => 'uk_UA',
	'form_name' => 'Запис на консультацію',
	'source_url' => $source_url,
	'attribution' => ['utm_source' => 'ig', 'utm_medium' => 'paid', 'utm_campaign' => '120250174665870267', 'utm_term' => 'set-1', 'utm_content' => 'ad-1', 'utm_id' => 'campaign-1'],
]);
$assert(str_contains($full_message, 'Ім’я: <b>Оксана</b>') && str_contains($full_message, 'Прізвище: <b>Рад</b>') && ! str_contains($full_message, '<b>Оксана Рад</b>'), 'Full Telegram messages must render first and last names as separate fields.');
$assert(str_contains($full_message, 'Email: oksana@example.com'), 'Full Telegram messages must render email separately.');
$assert(str_contains($full_message, '<b>Деталі звернення</b>') && str_contains($full_message, 'Послуга: Консультація флеболога'), 'Full Telegram messages must render dynamic inquiry details.');
$assert(str_contains($full_message, '<blockquote>Після 16:00</blockquote>'), 'Comments must be rendered as a separate quote block.');
$assert(str_contains($full_message, 'Instagram · платна реклама') && str_contains($full_message, '<blockquote expandable>'), 'Advertising submissions must render a normalized source and expandable raw UTM data.');
$assert(str_contains($full_message, 'href="https://example.com/landing?utm_source=ig&amp;utm_medium=paid&amp;fbclid=long-click-id"'), 'The complete source URL must remain in the link href.');
$assert(! str_contains($full_message, 'fbclid:'), 'Click IDs must not be rendered as standalone fields.');

$without_utm = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'source_url' => 'https://example.com/direct']);
$assert(str_contains($without_utm, 'Прямий перехід') && ! str_contains($without_utm, 'Дані рекламної кампанії'), 'Direct submissions must omit the UTM block.');
$assert(! str_contains($without_utm, '<a href='), 'Direct submissions must not duplicate the source URL inside the message text.');
$escaped = Telegram_Message_Formatter::format([
	['key' => 'first_name', 'label' => 'Ім’я', 'type' => 'text', 'value' => '<Оксана & Co>', 'raw_value' => '<Оксана & Co>'],
	['key' => 'comment', 'label' => 'Коментар', 'type' => 'textarea', 'value' => '<script>alert(1)</script> & details', 'raw_value' => '<script>alert(1)</script> & details'],
], ['locale' => 'uk_UA']);
$assert(str_contains($escaped, '&lt;Оксана &amp; Co&gt;') && ! str_contains($escaped, '<script>'), 'Dynamic Telegram values must be escaped for HTML parse mode.');
$repeat = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'repeat_count' => 3]);
$assert(str_contains($repeat, '<i>Повторне звернення · 3 заявки</i>'), 'Repeat submissions must be identified below the title.');
$acknowledged = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'state' => 'acknowledged', 'actor' => '@manager']);
$assert(str_contains($acknowledged, '<b>Заявка в роботі</b>') && str_contains($acknowledged, '<b>Взяв у роботу:</b> @manager'), 'Acknowledged submissions must expose the Telegram user who took the lead.');
$follow_up = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'state' => 'follow_up', 'actor' => '@manager']);
$assert(str_contains($follow_up, '<b>Передзвонити пізніше</b>') && str_contains($follow_up, '<b>Запланував повторний дзвінок:</b> @manager'), 'Follow-up submissions must expose the user who scheduled the callback.');
$completed = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'state' => 'completed', 'actor' => '@manager']);
$assert(str_contains($completed, '<b>Заявку опрацьовано</b>') && str_contains($completed, '<b>Завершив роботу:</b> @manager'), 'Completed submissions must expose the user who completed the work.');
$reset = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'state' => 'new', 'actor' => '@manager']);
$assert(str_contains($reset, '<b>Скинув статус:</b> @manager'), 'Reset submissions must explain who cleared the status.');
$rejected = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'state' => 'rejected', 'actor' => '@manager']);
$assert(str_contains($rejected, '<b>Заявку відхилено</b>') && str_contains($rejected, '<b>Відхилив заявку:</b> @manager'), 'Rejected submissions must expose the user who rejected the lead.');
$spam = Telegram_Message_Formatter::format($minimal_fields, ['locale' => 'uk_UA', 'state' => 'spam', 'actor' => '@manager']);
$assert(str_contains($spam, '<b>Заявку позначено як спам</b>') && str_contains($spam, '<b>Позначив як спам:</b> @manager'), 'Spam submissions must expose the user who changed the state.');
$without_phone = Telegram_Message_Formatter::format([
	['key' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'lead@example.com', 'raw_value' => 'lead@example.com'],
	['key' => 'ip_address', 'label' => 'IP', 'type' => 'text', 'value' => '127.0.0.1', 'raw_value' => '127.0.0.1'],
	['key' => 'consent', 'label' => 'Згода на обробку даних', 'type' => 'checkbox', 'value' => 'Так', 'raw_value' => '1'],
	['key' => 'optional', 'label' => 'Optional', 'type' => 'text', 'value' => '-', 'raw_value' => '-'],
], ['locale' => 'uk_UA']);
$assert(str_contains($without_phone, 'Email: lead@example.com') && ! str_contains($without_phone, '<code>'), 'Submissions without phone numbers must still render valid contact details.');
$assert(! str_contains($without_phone, '127.0.0.1') && ! str_contains($without_phone, 'Optional') && ! str_contains($without_phone, 'Згода'), 'Technical, legal, and empty fields must not be rendered in Telegram.');
$long_comment = str_repeat('Дуже довгий коментар. ', 500);
$long_message = Telegram_Message_Formatter::format(array_merge($minimal_fields, [
	['key' => 'comment', 'label' => 'Коментар', 'type' => 'textarea', 'value' => $long_comment, 'raw_value' => $long_comment],
]), ['locale' => 'uk_UA', 'source_url' => $source_url]);
$long_message_length = function_exists('mb_strlen') ? mb_strlen($long_message, 'UTF-8') : strlen($long_message);
$assert($long_message_length <= 4096, 'Long Telegram messages must stay within the 4096-character limit.');
$assert(! str_contains($long_message, $long_comment), 'Long comments must be shortened before essential fields.');
$many_details = $minimal_fields;
for ($index = 1; $index <= 20; ++$index) {
	$many_details[] = ['key' => 'detail_' . $index, 'label' => 'Поле ' . $index, 'type' => 'text', 'value' => str_repeat('Значення ', 100), 'raw_value' => str_repeat('Значення ', 100)];
}
$fitted_details = Telegram_Message_Formatter::format($many_details, ['locale' => 'uk_UA', 'source_url' => $source_url]);
$fitted_details_length = function_exists('mb_strlen') ? mb_strlen($fitted_details, 'UTF-8') : strlen($fitted_details);
$assert($fitted_details_length <= 4096 && str_contains($fitted_details, 'Ім’я: <b>Оксана</b>') && str_contains($fitted_details, 'Номер телефону: <code>+38 (050) 991-99-10</code>'), 'Secondary fields must be removed before essential contact data when the Telegram limit is reached.');
$utility = Telegram_Interactions::utility_buttons('+380509919910', $source_url);
$assert(($utility[0][0]['copy_text']['text'] ?? '') === '+380509919910', 'The phone utility button must copy the normalized number.');
$assert(($utility[0][1]['url'] ?? '') === $source_url, 'The source utility button must preserve the complete URL.');

if ($failures !== []) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

fwrite(STDOUT, "LeadForms Go tests passed.\n");
