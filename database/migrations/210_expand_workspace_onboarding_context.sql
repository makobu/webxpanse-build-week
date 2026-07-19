ALTER TABLE workspace_onboarding_state
    ADD COLUMN readiness_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER current_step,
    ADD COLUMN technical_level ENUM('guide_me', 'work_with_me', 'run_quietly') NULL AFTER automation_launch_mode,
    ADD COLUMN relationship_style VARCHAR(80) NULL AFTER technical_level,
    ADD COLUMN tone_json JSON NULL AFTER relationship_style,
    ADD COLUMN optional_setup_json JSON NULL AFTER skipped_optional_json,
    ADD COLUMN launch_summary_json JSON NULL AFTER recommended_workflows_json,
    ADD COLUMN starter_kit_json JSON NULL AFTER launch_summary_json;

CREATE TABLE IF NOT EXISTS workspace_assistant_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    assistant_type ENUM('email', 'whatsapp') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    settings_json LONGTEXT NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_assistant_configs_type (workspace_id, assistant_type),
    KEY idx_workspace_assistant_configs_workspace (workspace_id),
    CONSTRAINT fk_workspace_assistant_configs_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_assistant_configs_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_assistant_configs_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
