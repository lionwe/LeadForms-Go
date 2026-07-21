<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/includes/class-telegram-template.php';
require dirname(__DIR__) . '/includes/class-route-config.php';
require dirname(__DIR__) . '/includes/class-google-sheets-service.php';
require dirname(__DIR__) . '/includes/class-submission-security.php';

use LeadFormsGo\Google_Sheets_Service;
use LeadFormsGo\Route_Config;
use LeadFormsGo\Telegram_Template;
use LeadFormsGo\Submission_Security;

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
$assert(Route_Config::VERSION === 2, 'Route snapshots must use the multiple-destination schema.');
$assert($config['telegram']['profile_ids'] === [], 'Routes without profiles must keep an empty profile list.');
$assert($config['sheets']['columns'][0]['source'] === 'first_name', 'Valid mapping must be preserved.');
$assert(Route_Config::resolve_value(['type' => 'field', 'source' => 'phone'], ['phone' => '+380']) === '+380', 'Mapping must resolve payload values.');
$dispatch_token = Submission_Security::dispatch_token(42);
$assert(Submission_Security::verify_dispatch_token(42, $dispatch_token), 'Dispatch token must verify for its submission.');
$assert(! Submission_Security::verify_dispatch_token(43, $dispatch_token), 'Dispatch token must not verify for another submission.');
$snapshot = Route_Config::snapshot($config, 'telegram', ['form_name' => 'Original', 'submitted_at' => '2026-07-13 10:00:00']);
$snapshot_route = Route_Config::route_from_snapshot($snapshot, 'telegram');
$assert($snapshot_route['_context']['form_name'] === 'Original', 'Route snapshot context must be immutable.');

if ($failures !== []) {
	fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
	exit(1);
}

fwrite(STDOUT, "LeadForms Go tests passed.\n");
