-- Add escalation metadata for open automation detector findings that need Super Admin follow-through.

ALTER TABLE automation_catalog_runs
    ADD COLUMN IF NOT EXISTS escalation_status ENUM('none','escalated','acknowledged') NOT NULL DEFAULT 'none' AFTER review_status,
    ADD COLUMN IF NOT EXISTS escalation_priority ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER escalation_status,
    ADD COLUMN IF NOT EXISTS escalation_reason VARCHAR(255) NULL AFTER escalation_priority,
    ADD COLUMN IF NOT EXISTS escalated_at DATETIME NULL AFTER escalation_reason,
    ADD COLUMN IF NOT EXISTS escalation_due_at DATETIME NULL AFTER escalated_at,
    ADD COLUMN IF NOT EXISTS escalation_acknowledged_at DATETIME NULL AFTER escalation_due_at,
    ADD COLUMN IF NOT EXISTS escalation_acknowledged_by_user_id INT NULL AFTER escalation_acknowledged_at,
    ADD COLUMN IF NOT EXISTS escalation_note TEXT NULL AFTER escalation_acknowledged_by_user_id,
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_escalation (escalation_status, escalation_priority, escalation_due_at),
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_escalation_ack (escalation_acknowledged_by_user_id, escalation_acknowledged_at);
