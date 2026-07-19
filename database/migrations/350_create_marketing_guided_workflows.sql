-- Marketing Phase 60: guided workflow operating layer.

CREATE TABLE IF NOT EXISTS marketing_guided_workflows (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    workflow_key VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    status ENUM('not_started','in_progress','blocked','ready','completed','dismissed','archived') NOT NULL DEFAULT 'not_started',
    current_step_key VARCHAR(80) NULL,
    progress_score INT NOT NULL DEFAULT 0,
    step_state_json JSON NULL,
    recommendation_json JSON NULL,
    missing_requirements_json JSON NULL,
    quick_links_json JSON NULL,
    owner_user_id INT NULL,
    refreshed_by INT NULL,
    refreshed_at DATETIME NULL,
    completed_by INT NULL,
    completed_at DATETIME NULL,
    dismissed_by INT NULL,
    dismissed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_guided_workflow_uuid (uuid),
    UNIQUE KEY uniq_marketing_guided_workflow_workspace_key (workspace_id, workflow_key),
    KEY idx_marketing_guided_workflows_workspace_status (workspace_id, status, progress_score),
    KEY idx_marketing_guided_workflows_workspace_owner (workspace_id, owner_user_id, status),
    CONSTRAINT fk_marketing_guided_workflow_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_guided_workflow_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_guided_workflow_refresher FOREIGN KEY (refreshed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_guided_workflow_completer FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_guided_workflow_dismisser FOREIGN KEY (dismissed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_guided_workflow_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_guided_workflow_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    workflow_id INT NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    event_note TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_marketing_guided_workflow_events_workflow (workspace_id, workflow_id, created_at),
    KEY idx_marketing_guided_workflow_events_type (workspace_id, event_type, created_at),
    CONSTRAINT fk_marketing_guided_workflow_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_guided_workflow_events_workflow FOREIGN KEY (workflow_id) REFERENCES marketing_guided_workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_guided_workflow_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_guided_workflows', 'Marketing Guided Workflows page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
