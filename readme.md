# LeadForms Go

LeadForms Go stores WordPress form submissions locally and delivers them to enabled Telegram, Google Sheets, and G-PLUS CRM connectors.

## Requirements

- WordPress 6.6 or newer
- PHP 8.2 or newer with OpenSSL

## Google Sheets

Create a Google Cloud service account and enable the Google Sheets API. Under LeadForms Go → Settings, upload its JSON key, copy the displayed service-account email to the spreadsheet sharing dialog with Editor access, and paste the full spreadsheet URL. The plugin validates and encrypts the JSON before storing it and never creates a public credential file.

Existing installations may continue using `LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH`; the constant takes precedence over credentials managed in the admin screen.

## Shortcodes

- `[leadforms_go_form id="1"]`
- `[leadforms_go_form id="1" locale="en_US"]`

For visual forms, one shared field structure can contain translations for multiple locales. The shortcode locale has the highest priority, followed by the current Polylang locale and the WordPress locale. Missing translations fall back to the form's primary locale.

## Delivery queue

Submissions are stored locally before connector jobs are added to the WP-Cron queue. Temporary network and server failures are retried with exponential backoff. The History screen provides delivery filters, attempt timelines, and manual retry controls.

For production sites with low traffic or `DISABLE_WP_CRON` enabled, configure a real system cron request to WordPress cron. The dashboard reports stalled or unscheduled queue work.

If the WP-Cron loopback request fails, an overdue delivery can be processed by a server-side fallback at the end of a later WordPress request. The fallback processes one delivery per request and uses the same queue lock and atomic claim as WP-Cron. A real system cron remains recommended for sites without regular traffic.

## Per-form integrations

Each form has an Integrations tab for Telegram, Google Sheets, and CRM routes. A route can inherit the global connection, override its destination, or be disabled for that form. New delivery jobs store a versioned route snapshot, so retries keep the original template and mapping.

Telegram supports localized plain text, HTML, and MarkdownV2 templates, topics, and up to five inline buttons per locale. Google Sheets supports sheet discovery, sheet creation, visual column mapping, raw append, and exact email or phone update-or-append. Route tests create diagnostic submissions that are excluded from dashboard statistics.

## Security

Public submissions use a per-form nonce, signed render context, honeypot, atomic IP/global rate limits, and a unique request ID. Google Sheets stores visitor input as raw values so formulas are not evaluated.

Telegram and CRM tokens are encrypted before storage. For stronger operational isolation, define credentials in `wp-config.php`; values configured through constants override and remove stored copies:

```php
define('LEADFORMS_GO_TELEGRAM_TOKEN', '...');
define('LEADFORMS_GO_TELEGRAM_CHAT_ID', '...');
define('LEADFORMS_GO_CRM_TOKEN', '...');
define('LEADFORMS_GO_CRM_PARTNER_ID', '...');
define('LEADFORMS_GO_CRM_ADV_ID', '...');
```

Submission retention and browser attribution retention are configured under LeadForms Go → Settings. WordPress personal-data export and erase tools include LeadForms Go submissions.

## Release

Run `npm run release`. The distributable plugin and ZIP archive are written to `build/` without development files or credentials.

## Roadmap

The prioritized product roadmap and current implementation status are documented in [ROADMAP.md](ROADMAP.md).
