-- Marketing Phase 62: campaign operating system workspace rollups.

CREATE TABLE IF NOT EXISTS marketing_campaign_workspaces (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    campaign_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    workspace_name VARCHAR(180) NOT NULL,
    status ENUM('draft','active','blocked','ready','launched','completed','archived') NOT NULL DEFAULT 'draft',
    health_score INT NOT NULL DEFAULT 0,
    readiness_json JSON NULL,
    linked_summary_json JSON NULL,
    missing_requirements_json JSON NULL,
    warning_json JSON NULL,
    next_actions_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    refreshed_by INT NULL,
    refreshed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_campaign_workspace_uuid (uuid),
    UNIQUE KEY uniq_marketing_campaign_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_campaign_workspace_status (workspace_id, status, health_score),
    KEY idx_marketing_campaign_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_campaign_workspace_owner (workspace_id, owner_user_id, status),
    KEY idx_marketing_campaign_workspace_refreshed (workspace_id, refreshed_at),
    CONSTRAINT fk_marketing_campaign_workspace_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_campaign_workspace_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_campaign_workspace_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_campaign_workspace_refreshed_by FOREIGN KEY (refreshed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_campaign_workspace_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_campaign_workspace', 'Marketing Campaign Workspace page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
