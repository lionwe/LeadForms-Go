# LeadForms Go

LeadForms Go stores WordPress form submissions locally and delivers them to enabled Telegram, Google Sheets, and G-PLUS CRM connectors.

## Requirements

- WordPress 6.6 or newer
- PHP 8.2 or newer with OpenSSL

## Google Sheets

Create a Google Cloud service account, enable the Google Sheets API, and share the target spreadsheet with the service-account email. Store the downloaded JSON key outside the public WordPress directory and add this to `wp-config.php`:

```php
define('LEADFORMS_GO_GOOGLE_CREDENTIALS_PATH', dirname(ABSPATH) . '/private/google-service-account.json');
```

Do not commit the JSON key. Configure the spreadsheet ID, sheet name, and field order under LeadForms Go → Settings.

## Shortcodes

- `[leadforms_go_form id="1"]`

## Delivery queue

Submissions are stored locally before connector jobs are added to the WP-Cron queue. Temporary network and server failures are retried with exponential backoff. The History screen provides delivery filters, attempt timelines, and manual retry controls.

For production sites with low traffic or `DISABLE_WP_CRON` enabled, configure a real system cron request to WordPress cron. The dashboard reports stalled or unscheduled queue work.

## Release

Run `npm run release`. The distributable plugin and ZIP archive are written to `build/` without development files or credentials.

## Roadmap

The prioritized product roadmap and current implementation status are documented in [ROADMAP.md](ROADMAP.md).
