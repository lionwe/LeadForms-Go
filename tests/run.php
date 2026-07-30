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

use LeadFormsGo\Google_Sheets_Service;
use LeadFormsGo\Route_Config;
use LeadFormsGo\Telegram_Template;
use LeadFormsGo\Submission_Security;
use LeadFormsGo\Submission_Validator;
use LeadFormsGo\Form_Translations;
use LeadFormsGo\Lead_Deduplicator;
use LeadFormsGo\Telegram_Interactions;

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
$assert(Route_Config::VERSION === 3, 'Route snapshots must use the interactive Telegram schema.');
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
$buttons = Telegram_Interactions::action_buttons(42, '123456:test-token');
$assert(count($buttons) === 1 && count($buttons[0]) === 2, 'Telegram interaction must expose exactly two primary actions.');
$assert(strlen($buttons[0][0]['callback_data']) <= 64, 'Telegram callback data must fit the Bot API limit.');

if ($failures !== []) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

fwrite(STDOUT, "LeadForms Go tests passed.\n");
