-- Marketing Phase 52: draft-side campaign AI copilot runs.

CREATE TABLE IF NOT EXISTS marketing_campaign_copilot_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    campaign_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    run_type ENUM('campaign_plan','missing_assets','launch_checklist','content_sequence','readiness_summary','operator_handoff','blocked_explanation','custom') NOT NULL DEFAULT 'readiness_summary',
    status ENUM('completed','failed','draft') NOT NULL DEFAULT 'completed',
    prompt_context_json JSON NULL,
    context_used_json JSON NULL,
    result_json JSON NULL,
    provider_metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_campaign_copilot_runs_uuid (uuid),
    KEY idx_marketing_campaign_copilot_runs_workspace_campaign (workspace_id, campaign_id, created_at),
    KEY idx_marketing_campaign_copilot_runs_workspace_type (workspace_id, run_type, status, created_at),
    CONSTRAINT fk_marketing_campaign_copilot_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_campaign_copilot_runs_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_campaign_copilot_runs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
