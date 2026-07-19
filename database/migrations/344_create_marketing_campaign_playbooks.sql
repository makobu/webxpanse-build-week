-- Marketing Phase 54: campaign playbooks.

CREATE TABLE IF NOT EXISTS marketing_campaign_playbooks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    campaign_goal VARCHAR(255) NULL,
    target_audience TEXT NULL,
    audience_segment_id INT NULL,
    persona_id INT NULL,
    offer_context_item_id INT NULL,
    landing_page_id INT NULL,
    success_metrics_json JSON NULL,
    channel_plan_json JSON NULL,
    checklist_json JSON NULL,
    readiness_score INT NOT NULL DEFAULT 0,
    missing_requirements_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_campaign_playbooks_uuid (uuid),
    KEY idx_marketing_playbooks_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_playbooks_workspace_owner (workspace_id, owner_user_id, status),
    KEY idx_marketing_playbooks_workspace_segment (workspace_id, audience_segment_id),
    CONSTRAINT fk_marketing_playbooks_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_playbooks_segment FOREIGN KEY (audience_segment_id) REFERENCES marketing_audience_segments(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_playbooks_persona FOREIGN KEY (persona_id) REFERENCES marketing_personas(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_playbooks_offer FOREIGN KEY (offer_context_item_id) REFERENCES marketing_context_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_playbooks_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_playbooks_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_playbooks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_campaign_playbook_stages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    playbook_id INT NOT NULL,
    stage_order INT NOT NULL DEFAULT 1,
    stage_type ENUM('strategy','content','conversion','distribution','sales_handoff','reporting','other') NOT NULL DEFAULT 'strategy',
    title VARCHAR(180) NOT NULL,
    instructions TEXT NULL,
    required_record_type ENUM('brief','content','landing_page','email_run','channel_export','handoff_rule','asset','task','none') NOT NULL DEFAULT 'none',
    checklist_json JSON NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketing_playbook_stages_workspace_playbook (workspace_id, playbook_id, stage_order),
    KEY idx_marketing_playbook_stages_workspace_type (workspace_id, stage_type),
    CONSTRAINT fk_marketing_playbook_stages_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_playbook_stages_playbook FOREIGN KEY (playbook_id) REFERENCES marketing_campaign_playbooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_campaign_briefs
    ADD COLUMN IF NOT EXISTS campaign_playbook_id INT NULL AFTER campaign_id,
    ADD INDEX IF NOT EXISTS idx_marketing_briefs_workspace_playbook (workspace_id, campaign_playbook_id);

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_playbooks', 'Campaign Playbooks page guide', NULL, 0),
    ('marketing_playbook_edit', 'Campaign Playbook Editor page guide', NULL, 0),
    ('marketing_playbook_view', 'Campaign Playbook Detail page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
