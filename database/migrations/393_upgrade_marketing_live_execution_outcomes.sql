-- Marketing live execution: reconciled downstream outcomes for live queue items.
-- Initial adapter success is separated from later delivery/publication/webhook evidence.

ALTER TABLE marketing_execution_queue
    ADD COLUMN IF NOT EXISTS live_outcome_status ENUM('not_started','queued','processing','delivered','partially_delivered','failed','blocked','cancelled','unknown') NOT NULL DEFAULT 'not_started' AFTER live_retry_json,
    ADD COLUMN IF NOT EXISTS live_outcome_checked_at DATETIME NULL AFTER live_outcome_status,
    ADD COLUMN IF NOT EXISTS live_outcome_json JSON NULL AFTER live_outcome_checked_at,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_live_outcome (workspace_id, execution_mode, live_outcome_status, live_outcome_checked_at);
