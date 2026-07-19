-- Marketing live execution: guided setup wizard state.
-- Tracks a sanitized, workspace-scoped checklist from disabled live execution to
-- a safe controlled live run.

CREATE TABLE IF NOT EXISTS marketing_live_setup_steps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    step_key VARCHAR(80) NOT NULL,
    label VARCHAR(190) NOT NULL,
    status ENUM('missing','ready','blocked','dismissed') NOT NULL DEFAULT 'missing',
    evidence_json JSON NULL,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_setup_steps_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_setup_steps_workspace_key (workspace_id, step_key),
    KEY idx_marketing_live_setup_steps_workspace_status (workspace_id, status, updated_at),
    CONSTRAINT fk_marketing_live_setup_steps_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_setup_steps_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_setup_steps_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
