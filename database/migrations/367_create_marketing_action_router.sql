-- Marketing Phase 55: CRM action router for cross-system marketing recommendations.

CREATE TABLE IF NOT EXISTS marketing_action_router_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    action_key VARCHAR(160) NOT NULL,
    action_type ENUM('crm_setup','campaign','audience','content','landing_page','tracking','handoff','task','form','email_template','deal','contact','company','decision','custom') NOT NULL DEFAULT 'custom',
    action_status ENUM('suggested','accepted','resolved','dismissed','archived') NOT NULL DEFAULT 'suggested',
    priority ENUM('high','normal','medium','low') NOT NULL DEFAULT 'normal',
    label VARCHAR(190) NOT NULL,
    reason TEXT NULL,
    recommended_action TEXT NULL,
    source VARCHAR(120) NULL,
    source_record_type VARCHAR(80) NULL,
    source_record_id INT NULL,
    target_system VARCHAR(80) NOT NULL DEFAULT 'marketing',
    target_href VARCHAR(255) NOT NULL,
    target_record_type VARCHAR(80) NULL,
    target_record_id INT NULL,
    contact_id INT NULL,
    company_id INT NULL,
    deal_id INT NULL,
    campaign_id INT NULL,
    form_id INT NULL,
    task_id INT NULL,
    email_template_id INT NULL,
    content_item_id INT NULL,
    metadata_json JSON NULL,
    status_note TEXT NULL,
    created_by INT NULL,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_action_router_uuid (uuid),
    UNIQUE KEY uniq_marketing_action_router_open_key (workspace_id, action_key, source_record_type, source_record_id),
    KEY idx_marketing_action_router_workspace_status (workspace_id, action_status, priority, updated_at),
    KEY idx_marketing_action_router_workspace_type (workspace_id, action_type, action_status),
    KEY idx_marketing_action_router_workspace_campaign (workspace_id, campaign_id, action_status),
    KEY idx_marketing_action_router_workspace_target (workspace_id, target_system, target_record_type, target_record_id),
    CONSTRAINT fk_marketing_action_router_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_action_router_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_action_router_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES (
    'marketing_action_router',
    'Marketing Action Router page guide',
    NULL,
    0
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
