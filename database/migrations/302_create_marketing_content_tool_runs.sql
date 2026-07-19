-- Marketing Phase 7: AI content creation tool run ledger.

CREATE TABLE IF NOT EXISTS marketing_content_tool_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    content_item_id INT NOT NULL,
    distribution_post_id INT NULL,
    action VARCHAR(64) NOT NULL,
    status ENUM('completed', 'failed') NOT NULL DEFAULT 'completed',
    input_json JSON NULL,
    result_json JSON NULL,
    provider_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_content_tool_runs_uuid (uuid),
    KEY idx_marketing_tool_runs_workspace_content (workspace_id, content_item_id, created_at),
    KEY idx_marketing_tool_runs_workspace_action (workspace_id, action, created_at),
    KEY idx_marketing_tool_runs_distribution (workspace_id, distribution_post_id),
    CONSTRAINT fk_marketing_tool_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_tool_runs_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_tool_runs_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_tool_runs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
