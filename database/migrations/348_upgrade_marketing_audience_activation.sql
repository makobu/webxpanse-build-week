-- Marketing Phase 58: audience activation layer.

CREATE TABLE IF NOT EXISTS marketing_audience_activations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    audience_segment_id INT NOT NULL,
    activation_name VARCHAR(180) NOT NULL,
    activation_type ENUM('campaign','brief','landing_page','content','distribution','email_run','launch','other') NOT NULL DEFAULT 'campaign',
    status ENUM('planned','active','paused','completed','archived') NOT NULL DEFAULT 'planned',
    campaign_id INT NULL,
    campaign_brief_id INT NULL,
    landing_page_id INT NULL,
    content_item_id INT NULL,
    distribution_post_id INT NULL,
    email_run_id INT NULL,
    fit_score INT NOT NULL DEFAULT 0,
    coverage_status ENUM('unknown','ready','warning','blocked') NOT NULL DEFAULT 'unknown',
    coverage_warnings_json JSON NULL,
    usage_summary_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_audience_activations_uuid (uuid),
    KEY idx_marketing_audience_activations_workspace_segment (workspace_id, audience_segment_id, status),
    KEY idx_marketing_audience_activations_workspace_status (workspace_id, status, coverage_status, updated_at),
    KEY idx_marketing_audience_activations_workspace_campaign (workspace_id, campaign_id),
    KEY idx_marketing_audience_activations_workspace_brief (workspace_id, campaign_brief_id),
    KEY idx_marketing_audience_activations_workspace_content (workspace_id, content_item_id),
    KEY idx_marketing_audience_activations_workspace_landing (workspace_id, landing_page_id),
    CONSTRAINT fk_marketing_audience_activations_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_audience_activations_segment FOREIGN KEY (audience_segment_id) REFERENCES marketing_audience_segments(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_audience_activations_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_brief FOREIGN KEY (campaign_brief_id) REFERENCES marketing_campaign_briefs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_email_run FOREIGN KEY (email_run_id) REFERENCES marketing_email_campaign_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_audience_activations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_persona_segment_map (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    persona_id INT NOT NULL,
    audience_segment_id INT NOT NULL,
    match_score INT NOT NULL DEFAULT 0,
    match_reason TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_persona_segment_workspace (workspace_id, persona_id, audience_segment_id),
    KEY idx_marketing_persona_segment_workspace_persona (workspace_id, persona_id, match_score),
    KEY idx_marketing_persona_segment_workspace_segment (workspace_id, audience_segment_id, match_score),
    CONSTRAINT fk_marketing_persona_segment_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_persona_segment_persona FOREIGN KEY (persona_id) REFERENCES marketing_personas(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_persona_segment_segment FOREIGN KEY (audience_segment_id) REFERENCES marketing_audience_segments(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_persona_segment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_audience_segments
    ADD COLUMN IF NOT EXISTS activation_score INT NOT NULL DEFAULT 0 AFTER fit_score_json,
    ADD COLUMN IF NOT EXISTS activation_warnings_json JSON NULL AFTER activation_score,
    ADD COLUMN IF NOT EXISTS last_activation_at DATETIME NULL AFTER activation_warnings_json,
    ADD INDEX IF NOT EXISTS idx_marketing_segments_workspace_activation (workspace_id, activation_score, last_activation_at);

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_audience_activation', 'Audience Activation page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
