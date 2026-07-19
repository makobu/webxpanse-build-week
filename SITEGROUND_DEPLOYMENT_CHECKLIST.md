# SiteGround Deployment Checklist

Use this for deploying the CRM on SiteGround shared hosting with cron-based background processing.

## 1. Server Paths

- Domain: `https://crm.makdennis.dev`
- Project root: `/home/customer/www/crm.makdennis.dev/public_html`

Adjust both if your live domain or folder is different.

## 2. Upload Files

Upload the full application to:

`/home/customer/www/crm.makdennis.dev/public_html`

Required updated files from this change set:

- `api/process_task_completion.php`
- `cli/task_completion_worker.php`
- `cli/process_workflow_scheduler.php`
- `cli/process_workflow_queue.php`
- `cli/process_scheduled_emails.php`
- `cli/process_target_reminders.php`
- `cli/process_campaign_scheduler.php`
- `cli/process_campaign_queue.php`
- `cli/process_scheduled_reports.php`
- `modules/Tasks.php`
- `public/task_view.php`
- `env.production.template`

## 3. Install Dependencies

Run in SSH:

```bash
cd /home/customer/www/crm.makdennis.dev/public_html
composer install --no-dev --optimize-autoloader
```

## 4. Configure `.env`

Confirm these values exist on live:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://webxpanse.com
DB_HOST=localhost
DB_NAME=...
DB_USER=...
DB_PASS=...
DB_CHARSET=utf8mb4
PROCESS_QUEUE_SECRET=YOUR_LONG_RANDOM_SECRET
IMAP_FETCH_INTERVAL=5
```

Use a long random value for `PROCESS_QUEUE_SECRET`.

## 5. Run Migrations

```bash
cd /home/customer/www/crm.makdennis.dev/public_html
php database/migrations/migrate.php
```

## 6. Set Permissions

```bash
chmod 755 -R /home/customer/www/crm.makdennis.dev/public_html
chmod 775 -R /home/customer/www/crm.makdennis.dev/public_html/uploads
chmod 775 -R /home/customer/www/crm.makdennis.dev/public_html/cache
chmod 600 /home/customer/www/crm.makdennis.dev/public_html/.env
```

## 7. Add SiteGround Cron Jobs

In Site Tools:

- `Devs` -> `Cron Jobs`
- Create each job as `Custom`

SiteGround shared hosting only offers coarse schedules such as:

- every 30 minutes
- hourly
- twice daily
- daily
- weekly
- yearly

Because of that, this CRM will run with background latency on SiteGround. Anything that previously assumed 1 to 15 minute processing will now complete in up to 30 or 60 minutes depending on the job.

### Every 30 minutes

Create these jobs with the `Every half hour` option:

```bash
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/workflow_scheduled_triggers.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_workflow_scheduler.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_workflow_queue.php 100 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_pending_emails.php 100 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_scheduled_emails.php 200 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_target_reminders.php 100 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_campaign_scheduler.php 200 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_campaign_queue.php 150 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_scheduled_reports.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/fetch_incoming_emails.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/process_ai_autoresponder_queue.php 50 >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/calendar_sync_worker.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/alert_checker.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/ai_incident_check.php >/dev/null 2>&1
```

Create these URL cron jobs with the same `Every half hour` option:

```text
https://crm.makdennis.dev/api/process_task_completion.php?token=YOUR_LONG_RANDOM_SECRET
https://crm.makdennis.dev/api/process_whatsapp_queue.php?token=YOUR_LONG_RANDOM_SECRET
```

### Hourly

Create this job with the `Every hour` option:

```bash
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/ai_runtime_control_cleanup.php >/dev/null 2>&1
```

### Twice Daily

Create this job with the `Twice per day` option:

```bash
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/guardian_detector.php >/dev/null 2>&1
```

### Daily

Create these jobs with the `Daily` option:

```bash
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/ai_confidence_calibration.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/ai_outcome_reconciliation.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/daily_digest_worker.php >/dev/null 2>&1
cd /home/customer/www/crm.makdennis.dev/public_html && php cli/deal_automation_inactivity_sweep.php >/dev/null 2>&1
```

Recommended daily times:

- `02:00` deal inactivity sweep
- `02:30` AI confidence calibration
- `03:00` AI outcome reconciliation
- `07:00` daily digest

### Operational Note

With SiteGround-only cron granularity:

- workflow triggers may run up to 30 minutes late
- incoming email processing may lag by up to 30 minutes
- AI auto-responses may lag by up to 30 minutes
- task auto-completion scans may lag by up to 30 minutes
- WhatsApp queue sending via cron may lag by up to 30 minutes

If you need near-real-time automation, you need a VPS or a platform that supports minutely cron.

## 8. Test Endpoints

Open in browser after deploy:

```text
https://crm.makdennis.dev/api/process_task_completion.php?token=YOUR_LONG_RANDOM_SECRET
https://crm.makdennis.dev/api/process_whatsapp_queue.php?token=YOUR_LONG_RANDOM_SECRET
```

Expected result: JSON response with `success: true`

## 9. Test App Flows

- Log in successfully
- Open task detail page
- Click `Mark Completed`
- Complete all checklist subtasks and confirm parent task auto-completes
- Send a test email
- Verify inbound email fetch works
- Verify WhatsApp queue processing works

## 10. Final Checks

- `.env` is not publicly accessible
- `vendor/` exists on live
- DB migrations completed
- Cron jobs saved in SiteGround
- SSL is active
- No PHP fatal errors in SiteGround logs

## 11. Rollback

If deploy fails:

- restore previous changed files
- restore previous `.env` if edited incorrectly
- disable newly added cron jobs
- re-run a known good release
