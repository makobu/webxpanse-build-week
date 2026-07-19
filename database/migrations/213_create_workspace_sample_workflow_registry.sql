-- Migration 213: Registry for guided post-onboarding sample workflow records

CREATE TABLE IF NOT EXISTS workspace_sample_workflow_registry (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    sample_run_id VARCHAR(64) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    record_id INT NOT NULL,
    record_key VARCHAR(64) NOT NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sample_workflow_workspace_key (workspace_id, record_key),
    UNIQUE KEY uniq_sample_workflow_workspace_record (workspace_id, table_name, record_id),
    INDEX idx_sample_workflow_workspace (workspace_id),
    INDEX idx_sample_workflow_run (sample_run_id),
    CONSTRAINT fk_sample_workflow_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_sample_workflow_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
