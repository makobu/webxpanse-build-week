-- Marketing Phase 50: operator decision center for launch and readiness choices.

CREATE TABLE IF NOT EXISTS marketing_decision_center_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    decision_type ENUM('launch_readiness','campaign_blocker','content_approval','media_readiness','budget_risk','channel_readiness','ai_recommendation','integration_risk','custom') NOT NULL DEFAULT 'custom',
    decision_status ENUM('open','accepted','rejected','deferred','archived') NOT NULL DEFAULT 'open',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    source_type ENUM('campaign','content','landing_page','media','distribution','report','ai','readiness','custom') NOT NULL DEFAULT 'custom',
    source_id INT NULL,
    campaign_id INT NULL,
    content_item_id INT NULL,
    landing_page_id INT NULL,
    media_file_id INT NULL,
    distribution_post_id INT NULL,
    recommended_action TEXT NULL,
    rationale TEXT NULL,
    expected_impact TEXT NULL,
    decision_note TEXT NULL,
    due_at DATETIME NULL,
    decided_at DATETIME NULL,
    decided_by INT NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    evidence_json JSON NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_decision_center_uuid (uuid),
    KEY idx_marketing_decision_center_workspace_status (workspace_id, decision_status, priority, due_at),
    KEY idx_marketing_decision_center_workspace_owner (workspace_id, owner_user_id, decision_status),
    KEY idx_marketing_decision_center_workspace_campaign (workspace_id, campaign_id, decision_status),
    KEY idx_marketing_decision_center_workspace_source (workspace_id, source_type, source_id),
    CONSTRAINT fk_marketing_decision_center_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_decision_center_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_media FOREIGN KEY (media_file_id) REFERENCES marketing_media_files(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_decision_center_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES (
    'marketing_decisions',
    'Marketing Decision Center page guide',
    NULL,
    0
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
