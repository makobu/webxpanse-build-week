-- Marketing Phase 56: auditable AI context evidence snapshots.

CREATE TABLE IF NOT EXISTS marketing_ai_context_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    surface ENUM('content_draft','assistant','content_tool','creative_tool','campaign_copilot','strategy_gap','campaign_planner','performance_analysis','integration_readiness','custom') NOT NULL DEFAULT 'custom',
    prompt_key VARCHAR(120) NOT NULL,
    context_hash CHAR(64) NOT NULL,
    context_score DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    workspace_brain_score INT NOT NULL DEFAULT 0,
    missing_context_json JSON NULL,
    recommended_inputs_json JSON NULL,
    linked_records_json JSON NULL,
    context_bundle_json JSON NULL,
    provider_json JSON NULL,
    metadata_json JSON NULL,
    assistant_run_id INT NULL,
    content_tool_run_id INT NULL,
    creative_run_id INT NULL,
    campaign_copilot_run_id INT NULL,
    content_item_id INT NULL,
    campaign_id INT NULL,
    campaign_brief_id INT NULL,
    landing_page_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_ai_context_snapshot_uuid (uuid),
    KEY idx_marketing_ai_context_workspace_surface (workspace_id, surface, created_at),
    KEY idx_marketing_ai_context_workspace_prompt (workspace_id, prompt_key, created_at),
    KEY idx_marketing_ai_context_workspace_hash (workspace_id, context_hash),
    KEY idx_marketing_ai_context_workspace_content (workspace_id, content_item_id, created_at),
    KEY idx_marketing_ai_context_workspace_campaign (workspace_id, campaign_id, campaign_brief_id, created_at),
    CONSTRAINT fk_marketing_ai_context_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_ai_context_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES (
    'marketing_ai_context_evidence',
    'Marketing AI Context Evidence guide',
    NULL,
    0
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
