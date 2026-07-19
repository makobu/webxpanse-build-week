-- Add detector run idempotency metadata so scheduled scans refresh repeated evidence instead of flooding review queues.

ALTER TABLE automation_catalog_runs
    ADD COLUMN IF NOT EXISTS run_fingerprint CHAR(64) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS duplicate_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER run_fingerprint,
    ADD COLUMN IF NOT EXISTS first_seen_at DATETIME NULL AFTER duplicate_count,
    ADD COLUMN IF NOT EXISTS last_seen_at DATETIME NULL AFTER first_seen_at,
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_fingerprint (automation_id, workspace_id, review_status, run_fingerprint),
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_last_seen (last_seen_at);
