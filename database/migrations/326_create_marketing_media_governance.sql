-- Marketing Phase 34: media governance, QA, and audit trail.

ALTER TABLE marketing_media_files
    ADD COLUMN IF NOT EXISTS approval_status ENUM('pending','approved','rejected','blocked','archived') NOT NULL DEFAULT 'pending' AFTER usage_rights,
    ADD COLUMN IF NOT EXISTS reviewer_user_id INT NULL AFTER approval_status,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewer_user_id,
    ADD COLUMN IF NOT EXISTS expiry_date DATE NULL AFTER reviewed_at,
    ADD COLUMN IF NOT EXISTS license_status ENUM('unknown','owned','licensed','expired','restricted') NOT NULL DEFAULT 'unknown' AFTER expiry_date,
    ADD COLUMN IF NOT EXISTS accessibility_status ENUM('unknown','ready','needs_alt_text','needs_caption','blocked') NOT NULL DEFAULT 'unknown' AFTER license_status,
    ADD COLUMN IF NOT EXISTS brand_safety_notes TEXT NULL AFTER accessibility_status,
    ADD COLUMN IF NOT EXISTS blocked_reason TEXT NULL AFTER brand_safety_notes,
    ADD INDEX IF NOT EXISTS idx_marketing_media_workspace_approval (workspace_id, approval_status, updated_at),
    ADD INDEX IF NOT EXISTS idx_marketing_media_workspace_expiry (workspace_id, expiry_date),
    ADD INDEX IF NOT EXISTS idx_marketing_media_workspace_license (workspace_id, license_status, updated_at);

CREATE TABLE IF NOT EXISTS marketing_media_audit_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    media_file_id INT NULL,
    uuid CHAR(36) NOT NULL,
    event_type ENUM('upload','edit','approve','reject','block','archive','attach','detach','export_use','cleanup_preview','cleanup_archive') NOT NULL,
    context_type VARCHAR(80) NULL,
    context_id INT NULL,
    message VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_media_audit_uuid (uuid),
    KEY idx_marketing_media_audit_workspace_media (workspace_id, media_file_id, created_at),
    KEY idx_marketing_media_audit_workspace_type (workspace_id, event_type, created_at),
    CONSTRAINT fk_marketing_media_audit_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_media_audit_media FOREIGN KEY (media_file_id) REFERENCES marketing_media_files(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_media_audit_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
