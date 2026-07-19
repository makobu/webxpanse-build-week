-- Marketing Phase 36: manual email campaign execution upgrade.

ALTER TABLE marketing_email_campaign_runs
    ADD COLUMN IF NOT EXISTS preheader_text VARCHAR(255) NULL AFTER preview_text,
    ADD COLUMN IF NOT EXISTS suppression_notes TEXT NULL AFTER segment_snapshot_json,
    ADD COLUMN IF NOT EXISTS unsubscribe_text TEXT NULL AFTER suppression_notes,
    ADD COLUMN IF NOT EXISTS cta_url VARCHAR(1400) NULL AFTER unsubscribe_text,
    ADD COLUMN IF NOT EXISTS cta_label VARCHAR(160) NULL AFTER cta_url,
    ADD COLUMN IF NOT EXISTS approval_status ENUM('not_requested','pending','approved','rejected') NOT NULL DEFAULT 'not_requested' AFTER send_checklist_json,
    ADD COLUMN IF NOT EXISTS readiness_score INT NOT NULL DEFAULT 0 AFTER approval_status,
    ADD COLUMN IF NOT EXISTS readiness_warnings_json JSON NULL AFTER readiness_score,
    ADD COLUMN IF NOT EXISTS csv_export_json JSON NULL AFTER export_bundle_json,
    ADD COLUMN IF NOT EXISTS csv_exported_at DATETIME NULL AFTER csv_export_json,
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_workspace_approval (workspace_id, approval_status, status),
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_workspace_readiness (workspace_id, readiness_score, status),
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_workspace_csv_exported (workspace_id, csv_exported_at);
