-- Marketing Phase 98: live connector credential freshness enforcement.
-- Expired or rotation-overdue credentials must not pass live preflight.

ALTER TABLE marketing_live_connector_secrets
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL AFTER last_verification_status,
    ADD COLUMN IF NOT EXISTS rotation_due_at DATETIME NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS rotation_interval_days INT NULL AFTER rotation_due_at,
    ADD COLUMN IF NOT EXISTS freshness_status ENUM('fresh','rotation_due','expired','revoked','unknown') NOT NULL DEFAULT 'fresh' AFTER rotation_interval_days,
    ADD INDEX IF NOT EXISTS idx_marketing_live_connector_secrets_freshness (workspace_id, connector_id, freshness_status, expires_at, rotation_due_at);
