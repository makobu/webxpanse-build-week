-- Marketing Phase 57: campaign launch readiness reviews.

CREATE TABLE IF NOT EXISTS marketing_launch_readiness_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    template_key VARCHAR(120) NOT NULL,
    campaign_type ENUM('launch','nurture','event','product','content','retention','other') NOT NULL DEFAULT 'launch',
    required_checks_json JSON NULL,
    recommended_checks_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_launch_templates_uuid (uuid),
    UNIQUE KEY uniq_marketing_launch_templates_workspace_key (workspace_id, template_key),
    KEY idx_marketing_launch_templates_workspace_type (workspace_id, campaign_type),
    CONSTRAINT fk_marketing_launch_templates_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_launch_templates_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_launch_readiness_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    campaign_id INT NULL,
    campaign_brief_id INT NULL,
    landing_page_id INT NULL,
    content_item_id INT NULL,
    distribution_post_id INT NULL,
    email_run_id INT NULL,
    template_id INT NULL,
    launch_name VARCHAR(180) NOT NULL,
    launch_date DATE NULL,
    status ENUM('draft','ready','blocked','approved','archived') NOT NULL DEFAULT 'draft',
    readiness_score INT NOT NULL DEFAULT 0,
    blocking_reasons_json JSON NULL,
    warnings_json JSON NULL,
    checklist_json JSON NULL,
    result_json JSON NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_launch_reviews_uuid (uuid),
    KEY idx_marketing_launch_reviews_workspace_status (workspace_id, status, launch_date),
    KEY idx_marketing_launch_reviews_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_launch_reviews_workspace_brief (workspace_id, campaign_brief_id),
    KEY idx_marketing_launch_reviews_workspace_content (workspace_id, content_item_id),
    KEY idx_marketing_launch_reviews_workspace_landing (workspace_id, landing_page_id),
    CONSTRAINT fk_marketing_launch_reviews_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_launch_reviews_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_brief FOREIGN KEY (campaign_brief_id) REFERENCES marketing_campaign_briefs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_email_run FOREIGN KEY (email_run_id) REFERENCES marketing_email_campaign_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_template FOREIGN KEY (template_id) REFERENCES marketing_launch_readiness_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_launch_reviews_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_campaign_briefs
    ADD COLUMN IF NOT EXISTS launch_readiness_score INT NOT NULL DEFAULT 0 AFTER readiness_score,
    ADD COLUMN IF NOT EXISTS launch_blocked_reason TEXT NULL AFTER launch_readiness_score,
    ADD COLUMN IF NOT EXISTS launch_readiness_json JSON NULL AFTER launch_blocked_reason,
    ADD INDEX IF NOT EXISTS idx_marketing_briefs_workspace_launch_readiness (workspace_id, launch_readiness_score);

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_launch_readiness', 'Marketing Launch Readiness page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
