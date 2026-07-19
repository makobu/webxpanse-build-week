-- Marketing Phase 59: launch control room.

CREATE TABLE IF NOT EXISTS marketing_launch_control_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    launch_readiness_review_id INT NULL,
    campaign_id INT NULL,
    campaign_brief_id INT NULL,
    landing_page_id INT NULL,
    content_item_id INT NULL,
    distribution_post_id INT NULL,
    email_run_id INT NULL,
    audience_activation_id INT NULL,
    launch_name VARCHAR(180) NOT NULL,
    launch_date DATE NULL,
    status ENUM('planning','blocked','ready','launched_manual','paused','completed','archived') NOT NULL DEFAULT 'planning',
    readiness_score INT NOT NULL DEFAULT 0,
    checklist_json JSON NULL,
    blocker_json JSON NULL,
    warning_json JSON NULL,
    manual_launch_log_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    prepared_by INT NULL,
    prepared_at DATETIME NULL,
    launched_by INT NULL,
    launched_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_launch_control_uuid (uuid),
    KEY idx_marketing_launch_control_workspace_status (workspace_id, status, launch_date),
    KEY idx_marketing_launch_control_workspace_review (workspace_id, launch_readiness_review_id),
    KEY idx_marketing_launch_control_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_launch_control_workspace_brief (workspace_id, campaign_brief_id),
    KEY idx_marketing_launch_control_workspace_owner (workspace_id, owner_user_id, status),
    CONSTRAINT fk_marketing_launch_control_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_launch_control_review FOREIGN KEY (launch_readiness_review_id) REFERENCES marketing_launch_readiness_reviews(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_brief FOREIGN KEY (campaign_brief_id) REFERENCES marketing_campaign_briefs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_email_run FOREIGN KEY (email_run_id) REFERENCES marketing_email_campaign_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_activation FOREIGN KEY (audience_activation_id) REFERENCES marketing_audience_activations(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_preparer FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_launcher FOREIGN KEY (launched_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_control_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_campaign_briefs
    ADD COLUMN IF NOT EXISTS launch_control_status ENUM('planning','blocked','ready','launched_manual','paused','completed','archived') NULL AFTER launch_readiness_json,
    ADD COLUMN IF NOT EXISTS launch_control_json JSON NULL AFTER launch_control_status,
    ADD INDEX IF NOT EXISTS idx_marketing_briefs_workspace_launch_control (workspace_id, launch_control_status);

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_launch_control', 'Marketing Launch Control Room page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
