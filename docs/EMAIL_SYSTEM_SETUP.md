# Email System Setup and Verification

This document covers Phase 1 of the Email CRM debug plan: environment, database, and configuration.

## 1. Environment variables

### 1.1 Loading .env

- **Web:** `public/email_compose.php`, `api/process_emails.php`, and other entry points load `.env` manually from `__DIR__ . '/../.env'` (or similar), so the path is CWD-independent.
- **CLI:** `cli/process_pending_emails.php` and `cli/fetch_incoming_emails.php` use `__DIR__ . '/../.env'`, so they work regardless of current working directory (e.g. when run from cron with `cd /path/to/crm`).

### 1.2 Required variables

| Purpose | Variables | Notes |
|--------|-----------|--------|
| **Database** | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Optional: `DB_CHARSET` (default utf8mb4) |
| **Outbound (SMTP)** | `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` | Optional: `SMTP_ENCRYPTION` (e.g. ssl, tls) |
| **Assistant SMTP** | `EMAIL_ASSISTANT_SMTP_HOST`, `EMAIL_ASSISTANT_SMTP_PORT`, `EMAIL_ASSISTANT_SMTP_USER`, `EMAIL_ASSISTANT_SMTP_PASS`, `EMAIL_ASSISTANT_FROM_EMAIL`, `EMAIL_ASSISTANT_FROM_NAME` | Only if using Email Assistant |
| **Inbound (IMAP)** | `IMAP_ENABLED=true`, `IMAP_HOST`, `IMAP_PORT`, `IMAP_USER`, `IMAP_PASS` | Optional: `IMAP_PROTOCOL`, `IMAP_ENCRYPTION`, `IMAP_FOLDER` (default INBOX) |
| **Assistant IMAP** | `EMAIL_ASSISTANT_IMAP_ENABLED`, `EMAIL_ASSISTANT_IMAP_HOST`, `EMAIL_ASSISTANT_IMAP_PORT`, `EMAIL_ASSISTANT_IMAP_USER`, `EMAIL_ASSISTANT_IMAP_PASS` | Only if using Email Assistant inbox |
| **Tracking** | `APP_URL` | Must be correct for open/click tracking links |

### 1.3 Optional variables

- `IMAP_AUTO_CREATE_CONTACTS` — create contact when inbound email has no match (default false).
- `IMAP_FETCH_INTERVAL` — minutes between fetches (documentation; cron must be set accordingly).
- `AUTO_ENRICH_ON_EMAIL` — run enrichment on inbound email (default false).
- AI/Guardian and other feature-specific vars as per project docs.

---

## 2. Database connectivity and migrations

### 2.1 Test DB connection

From project root:

```bash
php -r "
require_once 'vendor/autoload.php';
\$env = __DIR__ . '/.env';
if (file_exists(\$env)) {
    foreach (file(\$env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as \$line) {
        if (strpos(trim(\$line), '#') === 0 || strpos(\$line, '=') === false) continue;
        list(\$k, \$v) = explode('=', \$line, 2);
        \$_ENV[trim(\$k)] = trim(\$v);
    }
}
require_once 'config/constants.php';
\$db = require 'config/database.php';
\$pdo = new PDO(
    'mysql:host=' . \$db['host'] . ';dbname=' . \$db['name'] . ';charset=' . (\$db['charset'] ?? 'utf8mb4'),
    \$db['user'],
    \$db['pass']
);
echo 'DB connection OK';
"
```

### 2.2 Migrations (all tables)

To ensure the database has **all** required tables, run the migration runner from the project root:

```bash
php database/migrations/migrate.php
```

Or use the all-in-one script (migrations + inbox column verification + table check):

```bash
php scripts/ensure_database.php
```

The runner executes every `*.sql` file in `database/migrations/` in natural order (001, 002, … 096). It creates the database if missing, creates a `migrations` table to track executed files, and skips already-applied migrations. Key email-related migrations include:

- **006_create_email_tables.sql** — `emails`, `email_tracking`, `email_queue`
- **008_create_communications_table.sql** — `communications` (base, includes `uuid CHAR(36) UNIQUE NOT NULL`)
- **047_add_inbox_management_fields.sql** — adds `email_id`, `from_email`, `to_email`, `message_id`, `in_reply_to` to `communications`
- **087_add_pending_to_communications_status.sql** — extends `communications.status` enum
- **093_create_ai_autoresponder_logs.sql** — AI auto-responder logs
- **094_create_ai_autoresponder_queue.sql** — AI auto-responder queue

### 2.3 UUID format

The `communications.uuid` column is `CHAR(36)` (e.g. `550e8400-e29b-41d4-a716-446655440000`). All code that inserts into `communications` uses the shared `uuid_v4()` helper (from `includes/helpers.php`) or a 36-character UUID. Do not use raw `bin2hex(random_bytes(16))` (32 chars) for this column.

---

## 3. PHP extensions and dependencies

| Requirement | Purpose |
|-------------|---------|
| **php-imap** | `EmailFetcher::connect()` uses `imap_open()` for inbound email. Enable in `php.ini` (e.g. `extension=imap`). |
| **Composer / vendor** | `vendor/autoload.php` must be present. PHPMailer is optional; `SMTPClient` can use custom SMTP or `mail()`. |

Check IMAP:

```bash
php -m | grep -i imap
```

---

## 4. Auth and permissions

- **Session:** All web entry points (compose, emails list, inbox, conversation, process_emails API) must call `Session::start()` before `Auth::check()`.
- **process_emails.php:** Only users with admin role may POST. Used for "Send Now" and "Send All Pending" from `public/emails.php`. Returns 401 if not logged in, 403 if not admin.
- **fetch_emails.php:** Admin-only; used for manual "Fetch Emails" from the inbox.

Ensure the test user has the admin role when testing queue processing and manual fetch.

---

## 5. Outbound email (Phase 2) – test steps and SMTP

### 5.1 Step-by-step test: immediate send

1. Log in as a user with access to Contacts and Emails.
2. Go to **Emails** → **Compose Email**.
3. Select a contact, enter **To**, **Subject**, and **Body** (or use a template).
4. Click **Send** (do not set a schedule).
5. You should be redirected to the Emails list. The new email should show status **Sent**.
6. In the database, check:
   - `emails`: one row with `status = 'sent'` and the correct `to_email`, `subject`.
   - `communications`: one row with `channel = 'email'`, `direction = 'outbound'`, and the same `contact_id` and `email_id` (from `emails.id`).

### 5.2 Step-by-step test: scheduled send and process

1. Compose an email and set **Schedule** to a future time; send.
2. In the Emails list, the email should show **Pending**.
3. Either:
   - Run **Send All Pending** (admin only), or
   - Run from CLI: `php cli/process_pending_emails.php 10`, or
   - Run the email worker: `php cli/email_worker.php` (and wait for it to process the queue).
4. After processing, the email should show **Sent** and a row should exist in `communications` as above.

### 5.3 SMTP and mail() fallback

- **SMTPClient** (used by `EmailService::processEmail`) tries in order:
  1. PHPMailer over SMTP (if available),
  2. Custom socket SMTP,
  3. For the **default** profile only: PHP `mail()` if SMTP was configured but both above failed.
- **Assistant profile** does not use `mail()`; it throws on failure.
- Ensure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, and optionally `SMTP_ENCRYPTION` are correct. For port 465 use `SMTP_ENCRYPTION=ssl`.

### 5.4 "Send Now" / "Send All Pending" does nothing

- Check browser **Network** tab: POST to `api/process_emails.php` should return 200 and JSON `success: true` or an error message. If 401/403, the user is not logged in or not admin.
- Check PHP **error_log** for the line logged by `process_emails.php` (method, user_id, email_id, limit).
- If the email is **pending** but has no row in `email_queue`, the API still processes it via the "orphan" path; after processing, the email should move to **sent**.

---

## 6. Inbound email (Phase 3) – IMAP fetch and inbox

### 6.1 Inbound test steps

1. Ensure **IMAP** is enabled and configured (see §1.2). Create a contact whose **email** matches the sender address you will use.
2. Send an email **to** the CRM IMAP inbox (e.g. `IMAP_USER` / `SMTP_FROM_EMAIL`) from that contact’s address.
3. **Manual fetch:** Log in as admin → **Inbox** → **Fetch Emails**. Check that a new row appears in the inbox (and in `communications` with `channel = 'email'`, `direction = 'inbound'`).
4. **CLI fetch:** From project root run `php cli/fetch_incoming_emails.php`. You should see "Processed email: ..." and new rows in `communications`. Check `email_fetch_log` for `last_uid` and `last_fetch_at`.

### 6.2 "No contact found" handling

- Inbound emails are matched to contacts by **sender email** (`EmailFetcher::matchToContact`).
- If there is **no matching contact** and `IMAP_AUTO_CREATE_CONTACTS` is not `true`, the email is **skipped** (CLI logs "Skipping email from … (no contact found, auto-create disabled)"); the API increments `skipped` and does not insert into `communications`.
- To have all senders appear in the inbox, either:
  - Create a contact with that email in the CRM, or
  - Set `IMAP_AUTO_CREATE_CONTACTS=true` in `.env` so the fetcher creates a contact (and then inserts the communication).

### 6.3 Cron / Task Scheduler for fetch

- **Recommended:** Run `cli/fetch_incoming_emails.php` every 5 minutes (or match `IMAP_FETCH_INTERVAL`).
- **Linux (crontab):**  
  `*/5 * * * * cd /path/to/crm && php cli/fetch_incoming_emails.php >> /var/log/crm/fetch_emails.log 2>&1`
- **Windows (Task Scheduler):** Create a task that runs every 5 minutes with:
  - Program: `C:\xampp\php\php.exe` (or your PHP path)
  - Arguments: `C:\xampp\htdocs\crm\cli\fetch_incoming_emails.php`
  - Start in: `C:\xampp\htdocs\crm`
- Confirm the task runs (e.g. check `email_fetch_log.last_fetch_at` or a log file).

---

## 7. Background workers (Phase 4) – outbound queue and scheduled

### 7.1 email_worker.php

- **Role:** Long-running process that pops from `email_queue`, calls `EmailService::processEmail()` for each job. Also processes **orphan** pending emails (status `pending` with no `email_queue` row) so none stay stuck.
- **Run:** From project root: `php cli/email_worker.php`. Use Supervisor (Linux) or NSSM / a dedicated terminal (Windows). See [CRON_SETUP.md](CRON_SETUP.md) for Supervisor config.
- **Note:** Do not run both a continuous `email_worker` and a frequent cron that runs `process_pending_emails.php` for the same queue without coordinating (e.g. use one or the other, or ensure cron only runs when the worker is stopped).

### 7.2 scheduled_email_worker.php

- **Role:** Sends emails whose `email_queue.scheduled_at` has passed. Run as a separate long-running process; see [CRON_SETUP.md](CRON_SETUP.md).

### 7.3 process_pending_emails.php (one-shot)

- **Role:** `php cli/process_pending_emails.php [limit]` processes up to `limit` pending emails (from queue and orphans) then exits. Use for cron (e.g. every minute) if you are **not** running `email_worker`.

---

## 8. AI auto-responder (Phase 5)

- **Enqueue:** When an inbound email is added to `communications` (`EmailFetcher::addToCommunications`), it enqueues an item in `ai_autoresponder_queue` via `AIAutoResponderQueueService::enqueueInboundCommunication`. Enqueue failures are logged; the communication is still stored.
- **Worker:** Run `php cli/ai_autoresponder_worker.php` as a long-running process. It polls the queue, builds context, calls the AI service for reply text, and sends via `AIAutoResponderDispatcher` (email → `EmailService::sendImmediate`, WhatsApp/SMS → respective services).
- **Config:** Ensure `ai_autoresponder_config` exists and is enabled if you expect auto-replies. Policy and thresholds control whether a reply is sent or saved as draft. See [CRON_SETUP.md](CRON_SETUP.md) for Supervisor config and any AI API keys required.

---

## 9. Inbox and conversation (Phase 6)

- **Inbox:** [inbox.php](../public/inbox.php) and [api/inbox.php](../api/inbox.php) (GET `list=1`) use `UnifiedInbox::getAll()`. Both outbound (from `syncToCommunications`) and inbound (from EmailFetcher) email rows should appear with `channel = 'email'`.
- **Conversation:** [conversation.php](../public/conversation.php) loads by communication ID, resolves the thread via `ConversationThreads::getByCommunication` / `getCommunications`, and shows the reply form. Email replies use `EmailService::sendImmediate` and appear in the thread after sync to `communications`.
- If a reply "does nothing", check: form submit, CSRF (if applicable), contact has valid email, and PHP/error_log for exceptions.

---

## 10. Tracking, templates, signatures (Phase 7)

- **Open/click tracking:** `EmailService::injectTracking` adds a pixel and link rewrites using `APP_URL`. Ensure `APP_URL` is correct and that `api/track/email/open.php` and `api/track/email/click.php` are reachable (no auth). They update `email_tracking` and optionally `emails.opened_at` / `clicked_at`.
- **Templates/signatures:** Use **Compose** or **Conversation** reply with a template or signature selected; confirm subject/body and that the email sends. No code changes required for full function beyond existing behaviour.
- **Proposal automation:** When subject/body contain "proposal", "quote", etc., `syncToCommunications` calls `DealAutomationOrchestrator::runForProposalSent`. Errors are logged only; the email still sends.

---

## 11. E2E tests and runbook (Phase 8)

### 11.1 E2E scenarios (manual)

1. **Outbound only:** Login → Compose → immediate send → check Emails list and `communications`. Optional: schedule send → run worker or "Send All Pending" → confirm sent.
2. **Inbound only:** Send email to IMAP inbox → run fetch (manual or cron) → check Inbox and `communications`. Optional: run AI worker → check outbound reply in `emails` and `communications`.
3. **Round-trip:** Send from CRM to a contact that has an IMAP mailbox → fetch brings reply into Inbox → open conversation → reply from conversation → confirm new outbound and thread updated.
4. **Send Now / Send All Pending:** Create a pending email → from Emails list click "Send Now" or "Send All Pending" (admin) → confirm status **sent** and row in `communications`.

### 11.2 Health checks

- DB: run `php scripts/verify_email_setup.php`.
- IMAP: run `php cli/fetch_incoming_emails.php` and check for connection success or clear error.
- Queues: `SELECT COUNT(*) FROM email_queue WHERE status = 'pending'`; `SELECT COUNT(*) FROM ai_autoresponder_queue WHERE status = 'pending'` (or equivalent).

### 11.3 Runbook (quick reference)

| Symptom | Check |
|--------|--------|
| Email not sending | Compose vs queue; SMTP credentials and port; `emails.error_message`; PHP error_log; worker running if using queue. |
| Inbox not updating | Cron running `fetch_incoming_emails.php`; IMAP credentials; contact match or `IMAP_AUTO_CREATE_CONTACTS`; `email_fetch_log.last_fetch_at`. |
| Send Now does nothing | Browser Network tab (401/403/404/500); user is admin; error_log from `process_emails.php`. |
| Reply from conversation does nothing | Form submit; contact email present; PHP error_log; SMTP as above. |
