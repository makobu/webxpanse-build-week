-- Marketing Phase 68: campaign launch checklist records.

CREATE TABLE IF NOT EXISTS marketing_campaign_launch_checklists (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(190) NOT NULL,
    status ENUM('draft','ready','blocked','launched','archived') NOT NULL DEFAULT 'draft',
    readiness_score INT NOT NULL DEFAULT 0,
    campaign_id INT NULL,
    campaign_brief_id INT NULL,
    audience_segment_id INT NULL,
    landing_page_id INT NULL,
    content_item_id INT NULL,
    distribution_post_id INT NULL,
    email_run_id INT NULL,
    channel_export_bundle_id INT NULL,
    connector_id INT NULL,
    launch_date DATE NULL,
    required_checks_json JSON NULL,
    missing_items_json JSON NULL,
    warning_json JSON NULL,
    next_actions_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_launch_checklists_uuid (uuid),
    KEY idx_marketing_launch_checklists_workspace_status (workspace_id, status, readiness_score),
    KEY idx_marketing_launch_checklists_workspace_launch (workspace_id, launch_date),
    KEY idx_marketing_launch_checklists_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_launch_checklists_workspace_owner (workspace_id, owner_user_id),
    CONSTRAINT fk_marketing_launch_checklists_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_launch_checklists_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_brief FOREIGN KEY (campaign_brief_id) REFERENCES marketing_campaign_briefs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_segment FOREIGN KEY (audience_segment_id) REFERENCES marketing_audience_segments(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_email FOREIGN KEY (email_run_id) REFERENCES marketing_email_campaign_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_bundle FOREIGN KEY (channel_export_bundle_id) REFERENCES marketing_channel_export_bundles(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_checklists_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_campaign_launch_checklist_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    checklist_id INT NOT NULL,
    check_key VARCHAR(120) NOT NULL,
    label VARCHAR(190) NOT NULL,
    status ENUM('pending','complete','warning','blocked','waived') NOT NULL DEFAULT 'pending',
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    source_record_type VARCHAR(80) NULL,
    source_record_id INT NULL,
    recommendation TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_launch_checklist_item_key (checklist_id, check_key),
    KEY idx_marketing_launch_checklist_items_workspace_status (workspace_id, status, is_required),
    CONSTRAINT fk_marketing_launch_items_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_launch_items_checklist FOREIGN KEY (checklist_id) REFERENCES marketing_campaign_launch_checklists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (page_key, label, video_url, is_active)
VALUES ('marketing_launch_checklists', 'Campaign Launch Checklists page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = CURRENT_TIMESTAMP;
