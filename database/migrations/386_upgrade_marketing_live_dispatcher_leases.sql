-- Marketing Phase 95: live queue dispatcher lease safety.
-- Allows the shared live worker lease table to track dispatcher runs without
-- overloading the email-worker run foreign key.

ALTER TABLE marketing_live_worker_leases
    ADD COLUMN IF NOT EXISTS dispatch_run_id INT NULL AFTER run_id,
    ADD INDEX IF NOT EXISTS idx_marketing_live_worker_lease_dispatch_run (dispatch_run_id);
