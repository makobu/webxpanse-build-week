-- Marketing Phase 92: operator export packs for manual launch handoff snapshots.

CREATE TABLE IF NOT EXISTS marketing_operator_export_packs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    campaign_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(190) NOT NULL,
    status ENUM('draft','ready','blocked','archived') NOT NULL DEFAULT 'draft',
    readiness_score INT NOT NULL DEFAULT 0,
    risk_snapshot_json JSON NULL,
    export_bundle_json JSON NULL,
    next_steps_json JSON NULL,
    integration_links_json JSON NULL,
    guardrails_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    prepared_by INT NULL,
    prepared_at DATETIME NULL,
    archived_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_operator_export_packs_uuid (uuid),
    KEY idx_marketing_operator_export_packs_workspace_status (workspace_id, status, readiness_score),
    KEY idx_marketing_operator_export_packs_workspace_campaign (workspace_id, campaign_id, prepared_at),
    KEY idx_marketing_operator_export_packs_workspace_owner (workspace_id, owner_user_id, status),
    CONSTRAINT fk_marketing_operator_export_packs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_operator_export_packs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_operator_export_packs_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_operator_export_packs_preparer FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_operator_export_packs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (page_key, label, video_url, is_active)
VALUES ('marketing_operator_export_packs', 'Operator Export Packs page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = CURRENT_TIMESTAMP;
