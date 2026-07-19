# CRM Cron Jobs and Background Workers Setup

This document lists all cron jobs and background workers required for full CRM functionality. Configure these during initial setup.

## Organization Intelligence daily snapshots

Capture one daily baseline per active workspace after the application day closes. The command is idempotent for each workspace and date and uses the application/database timezone.

```cron
15 0 * * * cd /path/to/crm && php cli/capture_organization_intelligence_snapshots.php --all >> /var/log/crm/organization_intelligence_snapshots.log 2>&1
15 * * * * cd /path/to/crm && php cli/monitor_organization_intelligence.php >> /var/log/crm/organization_intelligence_monitor.log 2>&1
```

For one workspace, run `php cli/capture_organization_intelligence_snapshots.php --workspace=123`. Diagnostics warn after 36 hours and mark trends stale after 48 hours.

---

## Overview

| Type | Purpose |
|------|---------|
| **Cron jobs** | Run periodically (e.g. every 5 minutes, hourly) |
| **Supervisor workers** | Long-running processes that stay active |
| **Optional** | Only needed if you use specific features |

---

## 1. Cron Jobs

Add these to your system crontab. Replace `/path/to/crm` with your actual CRM installation path (e.g. `/var/www/crm` or `C:\xampp\htdocs\crm`).

### Required

#### Fetch Incoming Emails
Fetches new emails from IMAP/POP3 into the inbox.

| Setting | Value |
|---------|-------|
| **Script** | `cli/fetch_incoming_emails.php` |
| **Schedule** | Every 5 minutes (or match `IMAP_FETCH_INTERVAL` in Settings) |
| **Required** | Yes, if IMAP is enabled |

```bash
# Every 5 minutes
*/5 * * * * cd /path/to/crm && php cli/fetch_incoming_emails.php >> /var/log/crm/fetch_emails.log 2>&1
```

**Windows (Task Scheduler):** Create a task that runs every 5 minutes:
```
C:\xampp\php\php.exe C:\xampp\htdocs\crm\cli\fetch_incoming_emails.php
```

**Note:** When Email Assistant is enabled, this script also processes admin-to-system emails (questions, create task, etc.). Configure in Settings > Email Assistant.

---

#### Daily Digest (Email Assistant)
Sends morning to-do and AI recommendations to admins. Requires `EMAIL_DIGEST_ENABLED=true` in Settings > Email Assistant.

| Setting | Value |
|---------|-------|
| **Script** | `cli/daily_digest_worker.php` |
| **Schedule** | Daily at configured time (e.g. 7 AM) |
| **Required** | No, only if Email Assistant digest is enabled |

```bash
# Daily at 7 AM (adjust hour to match EMAIL_DIGEST_TIME in settings)
0 7 * * * cd /path/to/crm && php cli/daily_digest_worker.php >> /var/log/crm/daily_digest.log 2>&1
```

**Windows (Task Scheduler):** Run daily at the configured digest time (e.g. 7:00 AM).

---

#### Guardian Detector (AI Strategic Alerts)
Runs Guardian checks: conversion collapse, high-value leads ignored, pipeline issues, stale deals. Creates alerts when triggers fire.

| Setting | Value |
|---------|-------|
| **Script** | `cli/guardian_detector.php` |
| **Schedule** | Every 6–12 hours |
| **Required** | Yes, for AI Guidance (Guardian Mode) |

```bash
# Every 6 hours
0 */6 * * * cd /path/to/crm && php cli/guardian_detector.php >> /var/log/crm/guardian.log 2>&1
```

**Windows (Task Scheduler):** Run at 00:00, 06:00, 12:00, 18:00 daily.

---

#### Workflow Scheduled Triggers
Fires time-based workflow triggers (daily_at_time, no_activity_for_days, contact_birthday, task_overdue, deal_closing_soon, etc.).

| Setting | Value |
|---------|-------|
| **Script** | `cli/workflow_scheduled_triggers.php` |
| **Schedule** | Every minute |
| **Required** | Yes, for scheduled workflow triggers |

```bash
# Every minute
* * * * * cd /path/to/crm && php cli/workflow_scheduled_triggers.php >> /var/log/crm/workflow_triggers.log 2>&1
```

---

### Optional

#### ML Model Retraining (Lead Scoring)
Retrains ML models for lead scoring. Only needed if you use ML-based lead scoring.

| Setting | Value |
|---------|-------|
| **Script** | `cli/ml_model_retrain.php` |
| **Schedule** | Weekly (e.g. Sunday 2 AM) |
| **Required** | No, only if ML scoring is used |

```bash
# Weekly on Sunday at 2 AM
0 2 * * 0 cd /path/to/crm && php cli/ml_model_retrain.php --all --days=90 --auto-promote >> /var/log/crm/ml_retrain.log 2>&1
```

---

#### Database Backup
Backs up the database and uploads. Recommended for production.

| Setting | Value |
|---------|-------|
| **Script** | `scripts/backup.sh` |
| **Schedule** | Daily (e.g. 2 AM) |
| **Required** | Recommended for production |

```bash
# Daily at 2 AM - uses DB_* from .env, keeps 7 daily backups
0 2 * * * cd /path/to/crm && chmod +x scripts/backup.sh && ./scripts/backup.sh >> /var/log/crm/backup.log 2>&1
```

The backup script (`scripts/backup.sh`):
- Reads DB_HOST, DB_NAME, DB_USER, DB_PASS from `.env`
- Creates `backups/db_YYYYMMDD_HHMMSS.sql.gz` and `backups/uploads_YYYYMMDD_HHMMSS.tar.gz`
- Retention: `--retention-days 7` (default), `--retention-weekly 4` (optional)
- Set `BACKUP_DIR` to change output directory (default: `./backups`)

**Windows:** Use `scripts/backup.bat` with Task Scheduler. Set DB_* environment variables or use .env.

---

## 2. Background Workers (Supervisor)

These run continuously. Use **Supervisor** (Linux) or **systemd** to keep them running. On Windows, use **NSSM** or run in separate terminal windows for development.

### Supervisor Configuration

Create `/etc/supervisor/conf.d/crm-workers.conf`:

```ini
[program:crm-email-worker]
command=php /path/to/crm/cli/email_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/email-worker.log

[program:crm-scheduled-email-worker]
command=php /path/to/crm/cli/scheduled_email_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/scheduled-email-worker.log

[program:crm-scheduled-report-worker]
command=php /path/to/crm/cli/scheduled_report_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/scheduled-report-worker.log

[program:crm-target-reminder-worker]
command=php /path/to/crm/cli/target_reminder_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/target-reminder-worker.log

[program:crm-workflow-scheduler]
command=php /path/to/crm/cli/workflow_scheduler.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/workflow-scheduler.log

[program:crm-workflow-queue-processor]
command=php /path/to/crm/cli/workflow_queue_processor.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/workflow-queue-processor.log

[program:crm-ai-autoresponder-worker]
command=php /path/to/crm/cli/ai_autoresponder_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/ai-autoresponder-worker.log
```

### Optional Workers (add if you use these features)

```ini
[program:crm-whatsapp-worker]
command=php /path/to/crm/cli/whatsapp_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/whatsapp-worker.log

[program:crm-sms-worker]
command=php /path/to/crm/cli/sms_worker.php
directory=/path/to/crm
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/crm/sms-worker.log
```

### Start Workers

```bash
sudo mkdir -p /var/log/crm
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

---

## 3. Worker Reference

| Worker | Purpose | Required |
|--------|---------|----------|
| `email_worker.php` | Processes email queue (outbound emails) | Yes |
| `scheduled_email_worker.php` | Sends scheduled emails | Yes (if using scheduled emails) |
| `scheduled_report_worker.php` | Runs scheduled reports and emails them | Yes (if using scheduled reports) |
| `target_reminder_worker.php` | Sends target/reminder notifications | Yes (if using targets) |
| `workflow_scheduler.php` | Executes scheduled workflow actions (e.g. wait_for_days) | Yes (if using workflows) |
| `workflow_queue_processor.php` | Processes async workflow execution queue | Yes (if using workflows) |
| `ai_autoresponder_worker.php` | Processes inbound AI auto-responder queue | If using AI auto-responder |
| `whatsapp_worker.php` | Processes WhatsApp message queue | If using WhatsApp |
| `sms_worker.php` | Processes SMS queue | If using SMS |

---

## 4. One-Time / Startup Tasks

### Workflow Trigger Service
Run once after deployment (or when workflows are added) to register workflow triggers:

```bash
php cli/workflow_trigger_service.php
```

Consider adding to a post-deploy script.

### Workflow Workers (when using workflows)
Two workers are required for workflows to function:

1. **workflow_queue_processor.php** – Processes the async execution queue (workflows run in background, non-blocking). Runs continuously via Supervisor.
2. **workflow_scheduler.php** – Executes delayed actions (e.g. `wait_for_days`). Runs continuously via Supervisor.

Optional: Set `WORKFLOW_QUEUE_INTERVAL` in `.env` (default 30) to change how often the queue processor checks for pending items (seconds).

---

## 5. Quick Reference: Full Crontab Example

```bash
# CRM Cron Jobs
# Edit: crontab -e
# Path: /var/www/crm (adjust as needed)

# Fetch incoming emails every 5 minutes
*/5 * * * * cd /var/www/crm && php cli/fetch_incoming_emails.php >> /var/log/crm/fetch_emails.log 2>&1

# Guardian detector every 6 hours
0 */6 * * * cd /var/www/crm && php cli/guardian_detector.php >> /var/log/crm/guardian.log 2>&1

# Alert checker (system health: DB, queue, disk, memory) every 15 minutes
*/15 * * * * cd /var/www/crm && php cli/alert_checker.php >> /var/log/crm/alert_checker.log 2>&1

# ML retrain weekly (optional)
0 2 * * 0 cd /var/www/crm && php cli/ml_model_retrain.php --all --days=90 --auto-promote >> /var/log/crm/ml_retrain.log 2>&1

# Daily backup (optional)
0 2 * * * /usr/local/bin/crm-backup.sh
```

---

## 6. Windows Setup (XAMPP / Development)

For local development on Windows:

1. **Cron:** Use Windows Task Scheduler or a tool like [Cron for Windows](https://github.com/nicholasruggeri/cron-windows).

2. **Workers:** Run in separate Command Prompt windows, or use [NSSM](https://nssm.cc/) to run them as Windows services:

   ```cmd
   nssm install CRM-EmailWorker "C:\xampp\php\php.exe" "C:\xampp\htdocs\crm\cli\email_worker.php"
   nssm set CRM-EmailWorker AppDirectory "C:\xampp\htdocs\crm"
   nssm start CRM-EmailWorker
   ```

3. **Manual run (testing):**
   ```cmd
   cd C:\xampp\htdocs\crm
   php cli/fetch_incoming_emails.php
   php cli/guardian_detector.php
   php cli/workflow_trigger_service.php
   php cli/workflow_queue_processor.php
   php cli/workflow_scheduler.php
   ```

---

## 7. Verification

After setup, verify:

1. **Cron:** Check logs in `/var/log/crm/` (or your log path).
2. **Workers:** `sudo supervisorctl status` should show all workers as `RUNNING`.
3. **Email fetch:** Enable IMAP in Settings, wait 5+ minutes, then check Inbox.
4. **Guardian:** Run `php cli/guardian_detector.php` manually; check Alerts page for any triggered alerts.
