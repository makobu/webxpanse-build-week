# Email Assistant plugin operations

Email Assistant configuration is workspace-scoped and managed only through Marketplace > Email Assistant. Legacy `EMAIL_ASSISTANT_*` and `EMAIL_DIGEST_*` environment values are retained for rollback but are ignored by runtime services after import.

## One-time legacy import

```powershell
php cli/import_legacy_email_assistant.php
```

The importer installs the plugin after validating its Email dependency, encrypts secrets in `workspace_assistant_configs`, and never overwrites an existing workspace configuration.

## Windows/XAMPP workers

```powershell
powershell -ExecutionPolicy Bypass -File scripts/install_email_assistant_workers.ps1 -Action Install
powershell -ExecutionPolicy Bypass -File scripts/install_email_assistant_workers.ps1 -Action Status
```

Both workers run every five minutes. Their latest heartbeat is shown on the plugin Tests tab and stored in `automation_job_health`.
Use `-Action Disable` while mailbox credentials are invalid or intentionally offline, then `-Action Enable` after a successful live test.

## Linux cron

```cron
*/5 * * * * cd /path/to/crm && php cli/daily_digest_worker.php
*/5 * * * * cd /path/to/crm && php cli/fetch_incoming_emails.php
```

Test digests are recorded in `email_digest_log` with `is_test = 1` and never suppress the scheduled daily digest.
