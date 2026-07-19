# Configuration And Secrets Audit

Date: 2026-05-21

## Scope

- Reviewed root and landing-page environment files by key name only; no secret values are documented here.
- Reviewed tracked environment templates, deployment docs, Apache access rules, ignored runtime artifacts, database and security config defaults, mail/integration settings, debug diagnostics, generated logs, archives, screenshots, and service-account file handling.
- Checked tracked status for live environment files and known credential-style config files.

## Changes Made

- Tightened the root Apache deny rules so `.log`, `.zip`, `.sql`, `.dump`, backup, archive, certificate, and key files are blocked when this repository root is web-served.
- Expanded root `.gitignore` coverage for common generated screenshot PNG names left by UI/browser testing.
- Left live `.env`, `landing page/.env`, and `config/firebase-service-account.json` untracked and ignored.

## Current Findings

- Live root `.env` and `landing page/.env` are present locally and ignored. They contain production-sensitive categories such as database, SMTP/IMAP, OAuth, WhatsApp/Meta, Firebase, AI provider, queue, Redis, and billing settings. Keep them out of Git and rotate any value that has been shared through logs, docs, screenshots, archives, or old commits.
- `env.production.template` is tracked and uses placeholders for secret values. It contains more keys than the local root `.env`, including optional OAuth, Paystack, logging, diagnostics, and enrichment settings that should be reviewed before deployment.
- `.env.testing` is tracked and uses local test database defaults. Do not reuse those credentials for public environments.
- `landing page/.env.example` is tracked and uses placeholder secret fields; the real landing-page `.env` is ignored.
- `config/firebase-service-account.json` exists locally but is ignored and not tracked. Prefer placing service-account JSON outside the web root and referencing it with `FCM_SERVICE_ACCOUNT_PATH`.
- The repo root contains ignored generated logs, zip archives, and root-level screenshot PNG artifacts. These should not be deployed; the root `.htaccess` now denies the riskiest downloadable file types if they are accidentally present.
- `public/.htaccess` still declares wildcard CORS for PHP files. Confirm this is intentional for production; otherwise restrict it to the CRM origin and approved app origins.
- Public diagnostic endpoints such as `public/test.php` and `public/debug_2fa.php` are gated by local host or diagnostics flags. Keep `ENABLE_PUBLIC_DIAGNOSTICS=false`, `APP_DEBUG=false`, and `DEBUG=false` on public hosts.
- Historical audit notes still report production-looking secrets in Git history. Current-tree hygiene does not mitigate old clones, deployment bundles, or Git history exposure.

## Deployment Readiness Checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, `DEBUG=false`, and `ENABLE_PUBLIC_DIAGNOSTICS=false`.
- Use unique high-entropy `APP_SECRET`, `APP_KEY`, `SESSION_SECRET`, `PROCESS_QUEUE_SECRET`, webhook verification tokens, OAuth client secrets, SMTP/IMAP passwords, billing keys, and AI/API keys.
- Use restricted database users for the CRM and landing billing hub; do not deploy with root/admin DB credentials.
- Confirm SMTP and IMAP use TLS/SSL and intentionally scoped accounts.
- Store Firebase service-account JSON outside public web roots or deny it at the web server and restrict filesystem permissions to the web-server user.
- Confirm `logs/`, `cache/`, `exports/`, and upload/runtime directories are writable by the app user but excluded from deployment artifacts.
- Remove generated screenshots, local logs, browser session folders, zip archives, database dumps, and temporary debug files from deployment bundles.
- Verify `.env*`, service-account JSON, key/certificate files, logs, dumps, and archives return 403 or 404 on the live host.
- Review and restrict production CORS headers for API/PHP endpoints.
- Run `composer validate --strict --no-check-publish`, `composer audit`, `composer lint:php`, `php database/migrations/migrate.php`, and the focused workspace/tenant smoke suite before launch.

## Urgent Security Concerns

- Rotate any credential that appeared in Git history, deployment notes, logs, screenshots, archives, or support bundles before treating the environment as clean.
- Plan the documented history rewrite only after rotation and a short branch freeze.
- Do not deploy from a working copy that contains local `.env` files, generated logs, archives, root screenshots, or service-account JSON unless the hosting layout and deny rules have been verified.
