-- Marketing Phase 93: live execution emergency stop.
-- Adds a workspace-scoped global pause that blocks all live execution without deleting connector or queue configuration.

ALTER TABLE marketing_live_execution_policies
    ADD COLUMN IF NOT EXISTS emergency_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER daily_live_cap,
    ADD COLUMN IF NOT EXISTS emergency_paused_at DATETIME NULL AFTER emergency_paused,
    ADD COLUMN IF NOT EXISTS emergency_paused_by INT NULL AFTER emergency_paused_at,
    ADD COLUMN IF NOT EXISTS emergency_pause_reason TEXT NULL AFTER emergency_paused_by,
    ADD COLUMN IF NOT EXISTS emergency_resumed_at DATETIME NULL AFTER emergency_pause_reason,
    ADD COLUMN IF NOT EXISTS emergency_resumed_by INT NULL AFTER emergency_resumed_at,
    ADD INDEX IF NOT EXISTS idx_marketing_live_execution_policies_emergency (workspace_id, emergency_paused, emergency_paused_at);
