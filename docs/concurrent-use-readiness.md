# Concurrent-use readiness

The CRM uses four explicit concurrency controls:

1. Every base table has an InnoDB primary key, and application database sessions run in strict SQL mode.
2. Shared editable records use `lock_version` and reject stale writes with HTTP 409 or a reload message.
3. Email jobs use transaction-protected claims, claim tokens, and expiring leases. Core workers publish heartbeats in `queue_worker_heartbeats`.
4. Read-only dashboard, list, search, inbox, contact, and invoice-preview paths release the PHP session write lock before expensive work.

## Verification

Restore a recent backup into an isolated database and run:

```powershell
php database/checks/concurrency_readiness.php --database=crm_concurrency_test --exercise-queues --exercise-locks
php scripts/verify_session_concurrency.php
php scripts/run_concurrency_load.php --url=http://localhost/crm/api/health.php --levels=10,25,50 --p95-ms=1000
```

Acceptance requires zero failed schema/claim/locking assertions, early-release session work under 1.5 seconds and under 60 percent of the serialized baseline, zero HTTP errors, and p95 at or below one second at each load level.

The readiness script refuses write exercises against the configured database unless `--allow-live` is explicitly supplied.

## Worker supervision

Run at least the email, workflow, and WhatsApp workers under the host's process supervisor or cron. Alert when a required worker has no `running` heartbeat or its `heartbeat_at` is more than five minutes old. Email jobs abandoned after `EMAIL_QUEUE_LEASE_SECONDS` are reclaimable by another worker.

Use a stable, unique `QUEUE_WORKER_ID` for each supervised process. Never run two processes with the same ID.

## Production runtime

- Load [php-concurrency.ini](../config/deployment/php-concurrency.ini), then restart the PHP runtime. Keep OPcache timestamp validation enabled only when deployments do not restart PHP.
- Merge [mariadb-concurrency.cnf](../config/deployment/mariadb-concurrency.cnf) after confirming host RAM, then restart MariaDB in a maintenance window.
- For one application node, file sessions are supported because read-only paths release their lock early.
- Before using two or more application nodes, set `APP_INSTANCE_COUNT`, use `SESSION_DRIVER=redis` or `memcached`, and choose the same shared backend for `CACHE_DRIVER`.
- Run `SystemReadinessService` after deployment. Missing primary keys, non-InnoDB tables, disabled strict mode, or missing queue leases are critical. OPcache, worker heartbeat, and buffer-pool sizing are reported as operational warnings.
