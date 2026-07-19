-- Track Super Admin notification delivery for urgent or overdue automation review escalations.

ALTER TABLE automation_catalog_runs
    ADD COLUMN IF NOT EXISTS escalation_notified_at DATETIME NULL AFTER escalation_note,
    ADD COLUMN IF NOT EXISTS escalation_notification_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER escalation_notified_at,
    ADD COLUMN IF NOT EXISTS escalation_last_notification_reason VARCHAR(80) NULL AFTER escalation_notification_count,
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_escalation_notify (escalation_status, escalation_priority, escalation_due_at, escalation_notified_at);
