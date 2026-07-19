# Deployment Readiness Checklist - 2026-05-20

This note captures the overnight configuration and secrets audit. It intentionally does not include secret values.

## Current Urgent Concerns

- The local root `.env` is configured as production but still has a truthy legacy `DEBUG` flag. Set `DEBUG=false` anywhere public traffic can reach the app.
- The local root `.env` uses the default database administrator user with an empty password. Production should use a dedicated least-privilege CRM database user.
- The landing-page billing hub `.env` also uses the database administrator user with an empty password. Production should use a separate least-privilege billing database user.
- Firebase service-account credentials are expected at `config/firebase-service-account.json`. The file is ignored and the root `.htaccess` blocks `config/`, but the safer production posture is to store the JSON outside the document root and point `FCM_SERVICE_ACCOUNT_PATH` there.
- `public/.htaccess` currently emits `Access-Control-Allow-Origin: *` for PHP files. Before public launch, restrict CORS to the intended production origins or remove it if browser cross-origin API access is not required.
- Existing deployment documentation notes that production-looking secrets existed in Git history. Rotate any credentials that may have been committed, logged, zipped, screenshotted, or shared before relying on current ignore rules.

## Verified Hygiene

- Root `.env`, `landing page/.env`, logs, archives, cache, exports, marketplace uploads, Playwright output, and Firebase service-account JSON are ignored by Git.
- Root `.htaccess` denies direct access to `.env` files and sensitive directories including `config/`, `vendor/`, `core/`, `database/`, `modules/`, `services/`, and `.git`.
- `landing page/.htaccess` denies `.env`, logs, zips, SQL, and backup files.
- `uploads/.htaccess` disables PHP/script execution and directory indexes for uploaded files.
- `env.production.template` now includes additional runtime keys for timezone, OAuth client secrets, Paystack, enrichment providers, assistant Q&A, and WhatsApp verbose debug.
- `.gitignore` now also ignores common private-key, certificate, keystore, and SSH private-key filenames.

## Deployment Gate

- Confirm all production env flags: `APP_ENV=production`, `APP_DEBUG=false`, `DEBUG=false`, and `ENABLE_PUBLIC_DIAGNOSTICS=false`.
- Use dedicated least-privilege database users for the CRM and landing billing hub; do not deploy with root/admin database credentials.
- Rotate `APP_SECRET`, `APP_KEY`, `SESSION_SECRET`, `PROCESS_QUEUE_SECRET`, SMTP, IMAP, OAuth, WhatsApp, Meta, Paystack, Firebase, AI, and enrichment provider credentials before launch.
- Move service-account JSON outside the public document root where hosting allows it, and restrict filesystem permissions to the web-server user.
- Restrict or remove wildcard CORS headers before exposing production APIs.
- Verify diagnostic routes such as `verify_setup.php`, `public/test.php`, `public/debug_2fa.php`, and API test endpoints are disabled, removed, or reachable only by authorized local/admin contexts.
- Confirm generated logs, screenshots, zips, SQL dumps, local caches, and browser session folders are not included in deployment bundles.
- Run `composer validate --strict --no-check-publish`, `composer audit`, `composer run lint:php`, `composer run runtime:prepare`, `composer run backup:check -- --json`, `composer run migrate`, `composer run templates:validate -- --json`, `composer run security:audit -- --json`, `composer run integrations:check -- --json`, `composer run demo:quarantine -- --json`, `composer run automation:detectors -- --dry-run --json`, `composer run preflight:production -- --strict`, and the focused tenant/workspace isolation tests before release.
- Use `docs/live-upload-checklist.md` as the current upload runbook for file inclusion/exclusion, live `.env`, runtime permissions, migration order, and post-upload smoke tests.
