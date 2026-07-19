# Live Upload Checklist

This is an internal infrastructure runbook for WebXpanse platform maintainers. It documents a manual PHP/MySQL upload path and is not a customer deployment or product-distribution guide.

Do not upload to a live server until every required gate below is either green or explicitly accepted by the Super Admin as a launch risk.

## 1. Release Source

- Work from a clean `codex/*` release branch, not `master`.
- Confirm the release commit is committed and tagged or recorded in the deployment note.
- Run `git status --short --branch` and confirm there are no uncommitted source changes.
- Do not include generated screenshots, Playwright output, logs, SQL dumps, archives, `.env`, service-account JSON, key files, `vendor/` from a dev install, or `node_modules/`.

## 2. Upload File List

Upload these source/runtime-control paths:

- `.htaccess`
- `index.php`
- `composer.json`
- `composer.lock`
- `api/`
- `cli/`
- `config/` without private service-account or credential JSON files
- `core/`
- `database/`
- `modules/`
- `public/`
- `scripts/`
- `services/`
- `uploads/.htaccess`
- `env.production.template` as a reference only, not as live `.env`

Upload `vendor/` only when the host cannot run `composer install --no-dev --optimize-autoloader`. If `vendor/` is uploaded, build it from the release commit on a trusted machine and exclude dev dependencies.

Optional on a protected shell host:

- `docs/` for operator runbooks
- `tests/` only for staging verification, not normal production web hosting

Never upload these:

- `.git/`, `.github/`, `.idea/`, `.vscode/`, `.claude/`
- `.env`, `.env.*`, filled production templates, or secret notes
- `node_modules/`
- `.phpunit.cache/`, `playwright-report/`, `test-results/`, `.playwright-mcp/`
- `cache/`, `logs/`, `tmp/`, `backups/`, `exports/`, `output/`, `screenshots/`
- `*.sql`, `*.sql.gz`, `*.dump`, `*.zip`, `*.tar`, `*.tar.gz`, `*.rar`
- `config/*service-account*.json`, `config/*credentials*.json`, `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.crt`, `*.cer`, `*.jks`
- local uploaded customer files unless this is an intentional one-time migration

Live `uploads/` contents should usually be created or preserved on the live server, not overwritten from local development. For a first upload, create the directories with `composer run runtime:prepare` and keep `uploads/.htaccess`.

## 3. Live `.env` Requirements

Create live `.env` on the server from `env.production.template`. Do not commit or upload a filled `.env`.

Required production values:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `DEBUG=false`
- `APP_URL=https://your-live-domain`
- `APP_SECRET`, `APP_KEY`, `SESSION_SECRET`, `PROCESS_QUEUE_SECRET`
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`
- `LOG_DIR`, `LOG_LEVEL`, `ENABLE_PUBLIC_DIAGNOSTICS=false`
- `BACKUP_DIR`, `BACKUP_MAX_AGE_HOURS`, `UPLOADS_BACKUP_MAX_AGE_HOURS`, `RETENTION_DAYS`, `RETENTION_WEEKLY`
- `BACKUP_OFFSITE_PATH` or another offsite/host-level backup setting
- `BACKUP_RESTORE_DRILL_AT` after a successful restore drill

Required when enabled:

- SMTP sender keys: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_ENCRYPTION`, `SMTP_FROM_EMAIL`
- WhatsApp/Meta keys when WhatsApp is enabled
- Google/Gmail/calendar OAuth keys when those integrations are enabled
- Paystack/M-Pesa keys when live payments are enabled
- AI provider keys when AI features are enabled
- Firebase service-account settings for mobile push

Production must not use local root database credentials with an empty password. Use a dedicated least-privilege database user.

## 4. Runtime Permissions

After upload and dependency install:

```bash
composer run runtime:prepare
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 775 cache logs tmp uploads
find cache logs tmp uploads -type d -exec chmod 775 {} \;
chmod 600 .env
```

Apache hosts must deploy the root `.htaccess`, `public/.htaccess`, and `uploads/.htaccess`.

Confirm:

- `public/` is the only intended web entry surface.
- Direct HTTP access to `.env`, `config/`, `vendor/`, `core/`, `database/`, `modules/`, `services/`, `tests/`, `scripts/`, `cli/`, and `docs/` is denied when the repository root is served.
- PHP execution is disabled under `uploads/`.
- Backups live outside `public/` and preferably outside the deployed app tree.

## 5. Migration And Gate Order

Use this order on the live server:

```bash
composer install --no-dev --optimize-autoloader
composer run runtime:prepare
composer run backup:check -- --json
composer run migrate
composer run templates:validate -- --json
composer run security:audit -- --json
composer run integrations:check -- --json
composer run demo:quarantine -- --json
composer run backup:check -- --json
composer run automation:detectors -- --dry-run --json
composer run preflight:production -- --strict
```

Rules:

- Take a fresh database and uploads backup before `composer run migrate`.
- Run migrations before strict preflight so schema-backed readiness checks use the latest tables.
- Strict preflight must pass before opening traffic.
- Do not run cleanup, demo quarantine deletion, provider reconnects, or customer-facing sends from the checklist unless a separate approved task calls for it.

## 6. Post-Upload Smoke Test

Run these after upload, migration, and strict preflight:

```bash
curl -fsS https://your-live-domain/api/health.php
curl -I https://your-live-domain/login.php
curl -I https://your-live-domain/.env
curl -I https://your-live-domain/scripts/create_admin_user.php
curl -I https://your-live-domain/uploads/.htaccess
```

Expected:

- `/api/health.php` returns HTTP 200 and database `ok`.
- `/login.php` returns HTTP 200 or a normal redirect to login.
- `.env`, `scripts/create_admin_user.php`, and direct upload control files return 403 or 404.

Browser smoke:

- Log in as Super Admin.
- Open Dashboard.
- Open System Health and confirm no critical checks.
- Open Contacts, Inbox, Tasks, Settings, and Workspace Skills.
- Confirm no PHP warnings, blank pages, or console-blocking JavaScript errors.
- Confirm runtime directories remain writable after the web server writes a cache/log file.

Optional Playwright smoke on staging or a protected live window:

```bash
npx playwright test tests/smoke/auth.smoke.spec.js tests/smoke/business-continuity.smoke.spec.js tests/smoke/contacts.smoke.spec.js tests/smoke/inbox.smoke.spec.js tests/smoke/tasks.smoke.spec.js tests/smoke/settings.smoke.spec.js --project=chromium --workers=1 --timeout=60000
```

## 7. Rollback And Recovery

- Keep the pre-upload database dump and uploads archive until the next successful backup cycle.
- Keep the previous release bundle or commit hash available.
- If migration fails, stop traffic and restore from the fresh backup before retrying.
- If strict preflight fails after upload, keep the site closed or in maintenance mode until critical findings are repaired.
- Record the final release commit, migration result, backup artifact names, preflight output, and smoke-test result in the deployment note.
