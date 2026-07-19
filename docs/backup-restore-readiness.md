# Backup And Restore Readiness

Use this runbook before live upload and after any hosting move. The readiness checker is read-only; it verifies evidence but does not create backups or restore data.

## Live Expectations

- Database backups run at least daily and the latest restorable dump is less than 24 hours old.
- Uploaded files are archived when the `uploads` directory contains customer or generated assets.
- Backups are stored outside `public/` and preferably outside the deployed application tree.
- At least one offsite or host-level backup destination is configured.
- `mysqldump` and `mysql` are available on the live server, either in `PATH` or through `MYSQLDUMP_BIN` and `MYSQL_BIN`.
- A restore drill is recorded in `BACKUP_RESTORE_DRILL_AT` at least once every 90 days.

## Backup Command

Run a manual backup before uploading or migrating the live server:

```bash
composer run backup:check -- --json
./scripts/backup.sh
composer run backup:check -- --json
```

On Windows/XAMPP:

```bat
scripts\backup.bat
```

## Restore Drill Outline

1. Create a fresh database dump and uploads archive.
2. Copy the dump to a safe staging database, never over production first.
3. Import with the configured `mysql` client.
4. Extract the uploads archive into a temporary staging uploads directory.
5. Run `composer run preflight:production -- --json` against the restored staging environment.
6. Record the successful drill date in `BACKUP_RESTORE_DRILL_AT`.

## Live Incident Expectations

- Recovery owner knows where backups are stored and has hosting/database access.
- Database restore and uploads restore are tested separately.
- DNS, env secrets, payment webhooks, cron jobs, and queue workers are checked after restore.
- If a backup is stale, empty, missing, public, or not restorable, stop live upload until the finding is resolved.
