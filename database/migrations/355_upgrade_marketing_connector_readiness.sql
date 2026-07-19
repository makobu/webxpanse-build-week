-- Marketing Phase 66: connector readiness setup surface.
-- Readiness is dry-run and diagnostic only. No secrets or external API calls are stored here.

ALTER TABLE marketing_channel_connectors
    ADD COLUMN IF NOT EXISTS setup_status ENUM('not_started','in_progress','ready','blocked') NOT NULL DEFAULT 'not_started' AFTER execution_mode,
    ADD COLUMN IF NOT EXISTS readiness_score INT NOT NULL DEFAULT 0 AFTER setup_status,
    ADD COLUMN IF NOT EXISTS setup_checklist_json JSON NULL AFTER readiness_score,
    ADD COLUMN IF NOT EXISTS setup_notes TEXT NULL AFTER setup_checklist_json,
    ADD COLUMN IF NOT EXISTS last_readiness_review_at DATETIME NULL AFTER last_tested_at,
    ADD INDEX IF NOT EXISTS idx_marketing_channel_connectors_workspace_setup (workspace_id, setup_status, readiness_score);

CREATE TABLE IF NOT EXISTS marketing_channel_connector_readiness_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    connector_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    status ENUM('ready','needs_attention','blocked') NOT NULL DEFAULT 'needs_attention',
    readiness_score INT NOT NULL DEFAULT 0,
    required_checks_json JSON NULL,
    missing_checks_json JSON NULL,
    recommendation_json JSON NULL,
    diagnostics_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_connector_readiness_reviews_uuid (uuid),
    KEY idx_marketing_connector_reviews_workspace_connector (workspace_id, connector_id, created_at),
    KEY idx_marketing_connector_reviews_workspace_status (workspace_id, status, readiness_score),
    CONSTRAINT fk_marketing_connector_reviews_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_connector_reviews_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_connector_reviews_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
