-- Marketing Phase 92: final live-run controls.
-- Adds idempotency and explicit operator confirmation evidence for live execution.

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_idempotency_key CHAR(64) NULL AFTER live_approved_at,
    ADD COLUMN IF NOT EXISTS live_rerun_policy ENUM('block_after_success','allow_failed_retry','allow_manual_rerun') NOT NULL DEFAULT 'block_after_success' AFTER live_idempotency_key,
    ADD COLUMN IF NOT EXISTS live_last_confirmed_at DATETIME NULL AFTER live_rerun_policy,
    ADD COLUMN IF NOT EXISTS live_last_confirmed_by INT NULL AFTER live_last_confirmed_at,
    ADD COLUMN IF NOT EXISTS live_last_confirmation_text VARCHAR(40) NULL AFTER live_last_confirmed_by,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_idempotency (workspace_id, live_idempotency_key),
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_confirmed (workspace_id, live_last_confirmed_at);

ALTER TABLE marketing_execution_attempts
    ADD COLUMN IF NOT EXISTS idempotency_key CHAR(64) NULL AFTER attempt_mode,
    ADD COLUMN IF NOT EXISTS live_confirmation_text VARCHAR(40) NULL AFTER idempotency_key,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_attempts_idempotency (workspace_id, idempotency_key, status);
