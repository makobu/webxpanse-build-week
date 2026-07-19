-- Marketing Phase 88: live email worker throttle and emergency pause controls.

ALTER TABLE marketing_live_worker_schedules
    ADD COLUMN IF NOT EXISTS max_handoffs_per_run INT NOT NULL DEFAULT 25 AFTER max_stale_minutes,
    ADD COLUMN IF NOT EXISTS min_seconds_between_sends INT NOT NULL DEFAULT 0 AFTER max_handoffs_per_run,
    ADD COLUMN IF NOT EXISTS hourly_send_cap INT NOT NULL DEFAULT 0 AFTER min_seconds_between_sends,
    ADD COLUMN IF NOT EXISTS daily_send_cap INT NOT NULL DEFAULT 0 AFTER hourly_send_cap,
    ADD COLUMN IF NOT EXISTS emergency_paused_at DATETIME NULL AFTER daily_send_cap,
    ADD COLUMN IF NOT EXISTS emergency_paused_by INT NULL AFTER emergency_paused_at,
    ADD COLUMN IF NOT EXISTS emergency_pause_reason VARCHAR(255) NULL AFTER emergency_paused_by,
    ADD INDEX IF NOT EXISTS idx_marketing_live_worker_schedule_pause (workspace_id, emergency_paused_at),
    ADD CONSTRAINT fk_marketing_live_worker_schedule_paused_by FOREIGN KEY (emergency_paused_by) REFERENCES users(id) ON DELETE SET NULL;
