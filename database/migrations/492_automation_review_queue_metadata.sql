-- Add Super Admin review metadata to automation detector run evidence.

ALTER TABLE automation_catalog_runs
    ADD COLUMN IF NOT EXISTS review_note TEXT NULL AFTER review_status,
    ADD COLUMN IF NOT EXISTS reviewed_by_user_id INT NULL AFTER review_note,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewed_by_user_id,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_reviewed_by (reviewed_by_user_id, reviewed_at),
    ADD KEY IF NOT EXISTS idx_automation_catalog_runs_updated (updated_at);
