-- Marketing live execution: controlled retry and backoff for failed live queue items.
-- Retries stay policy, approval, dispatch, idempotency, throttle, and emergency-stop gated.

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_retry_status ENUM('none','scheduled','retrying','exhausted','cancelled') NOT NULL DEFAULT 'none' AFTER live_dispatch_run_id,
    ADD COLUMN IF NOT EXISTS live_retry_count INT NOT NULL DEFAULT 0 AFTER live_retry_status,
    ADD COLUMN IF NOT EXISTS live_max_retries INT NOT NULL DEFAULT 0 AFTER live_retry_count,
    ADD COLUMN IF NOT EXISTS live_retry_backoff_seconds INT NOT NULL DEFAULT 900 AFTER live_max_retries,
    ADD COLUMN IF NOT EXISTS live_retry_after DATETIME NULL AFTER live_retry_backoff_seconds,
    ADD COLUMN IF NOT EXISTS live_retry_reason TEXT NULL AFTER live_retry_after,
    ADD COLUMN IF NOT EXISTS live_retry_json JSON NULL AFTER live_retry_reason,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_retry (workspace_id, execution_mode, live_retry_status, live_retry_after),
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_retry_dispatch (workspace_id, live_dispatch_status, live_retry_after);
