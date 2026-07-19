# Deployment Configuration And Secrets Checklist

Use this checklist before deploying a new environment or rotating production credentials. Do not commit filled-in secrets, service-account JSON, generated logs, database dumps, or one-off diagnostic archives.

## Environment Files

- Keep live `.env` files outside Git. The root `.env` and `landing page/.env` files are intentionally ignored.
- Treat every `.env.*` file as sensitive unless it is an intentional sample or test fixture; only `.env.testing`, `.env.example`, `.env.template`, and `env.production.template` should be tracked.
- Keep `env.production.template` synchronized with all supported runtime keys whenever new integrations are added.
- Keep `APP_ENV=production` and `APP_DEBUG=false` for public deployments.
- Keep `DEBUG=false` and `ENABLE_PUBLIC_DIAGNOSTICS=false` on public deployments.
- Set `PROCESS_QUEUE_SECRET` before enabling queue or cron endpoints, and pass it from protected scheduler configuration rather than from URLs committed to scripts.
- Remove or disable temporary diagnostic routes before launch, or keep them gated to local requests plus an explicit diagnostics flag.
- Use unique, high-entropy values for `APP_SECRET`, `SESSION_SECRET`, `PROCESS_QUEUE_SECRET`, webhook verification tokens, OAuth client secrets, SMTP passwords, and billing/webhook signing secrets.
- Prefer server-side environment variables or protected host configuration over editable web UI settings for production secrets.

## Database And Mail

- Use a database user with only the privileges the CRM needs; do not deploy with a root/admin database user.
- Use a separate restricted database user for the landing-page billing hub.
- Rotate database and SMTP credentials after any suspected exposure, including exposure in Git history or deployment logs.
- Confirm SMTP settings use TLS or SSL and that assistant/digest mail accounts are intentionally scoped.
- Confirm IMAP and assistant IMAP accounts are read-scoped where the provider supports it.
- Keep test database credentials isolated from live databases.

## Third-Party Integrations

- Configure WhatsApp, Meta, Google/Gmail, Firebase, Paystack, Hunter, Clearbit, People Data Labs, and AI provider keys only in the deployment environment.
- Store Firebase service-account JSON outside the web root where possible. If a path is used, restrict filesystem permissions to the web-server user.
- Validate webhook signing or verification secrets for WhatsApp, Paystack, meeting notes, meeting bots, and workflow triggers.

## Filesystem And Runtime

- Ensure `logs/`, `cache/`, `exports/`, and runtime upload directories are writable by the application user but not committed.
- Keep service-account JSON and credential files outside the web root where possible; if they must live under `config/`, confirm Apache denies direct access and the files are ignored by Git.
- Keep uploaded executable extensions blocked. The root and upload `.htaccess` files should remain deployed on Apache hosts.
- Keep ignored archive files such as `.zip`, `.tar`, `.sql`, `.dump`, `.bak`, and generated logs out of public web roots. If a public web root must contain them temporarily, confirm `.htaccess` denies direct download first.
- Disable directory listing on every public web root.
- Avoid world-writable permissions; prefer owner/group write for the web-server user over broad `777` access.
- Do not deploy generated screenshots, browser session files, PHPUnit logs, zip archives, database dumps, or local cache folders.

## Release Gate

- Run `composer validate --strict --no-check-publish`.
- Run `composer audit`.
- Run `composer run lint:php`.
- Run `composer run runtime:prepare`.
- Run `composer run backup:check -- --json` before migrations.
- Run `composer run migrate`.
- Run `composer run templates:validate -- --json`.
- Run `composer run security:audit -- --json`.
- Run `composer run integrations:check -- --json`.
- Run `composer run demo:quarantine -- --json`.
- Run `composer run automation:detectors -- --dry-run --json`.
- Run `composer run preflight:production -- --strict`.
- Run the focused smoke suite used for tenant/workspace isolation.
- Follow `docs/live-upload-checklist.md` for the final upload file list, runtime permissions, migration order, and post-upload smoke test.
- Verify `.env*` files are denied by the web server in each public document root.
- Verify root diagnostics such as `verify_setup.php` and `public/test.php` return 404 remotely unless diagnostics are intentionally enabled.
- Verify API CORS policy is appropriate for the production domain before launch; the current Apache example emits a wildcard CORS header for PHP endpoints.
- Verify no production secrets appear in docs, templates, logs, generated reports, or tracked files.

## Urgent Follow-Up

- Git history previously contained production-looking secrets. Rotate those credentials before relying on current-tree cleanup.
- Plan a coordinated history rewrite after rotation and a short branch freeze, then invalidate old clones and deployment artifacts.
- Rotate any credential that has appeared in local logs, deployment notes, screenshots, zip archives, or shared support bundles.
