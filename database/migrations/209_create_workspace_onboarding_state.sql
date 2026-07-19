CREATE TABLE IF NOT EXISTS workspace_onboarding_state (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    required_steps_json JSON NULL,
    completed_steps_json JSON NULL,
    skipped_optional_json JSON NULL,
    communication_channel ENUM('email', 'whatsapp', 'both') NULL,
    automation_launch_mode ENUM('learning_on_the_go', 'manual_review', 'full_auto') NULL,
    ai_autoresponder_mode ENUM('off', 'draft_only', 'hybrid', 'full_auto') NULL,
    ai_best_practices_enabled TINYINT(1) NOT NULL DEFAULT 0,
    commercial_layer_enabled TINYINT(1) NULL,
    deal_automation_enabled TINYINT(1) NULL,
    recommended_workflows_json JSON NULL,
    voice_transcript MEDIUMTEXT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_onboarding_state_workspace (workspace_id),
    KEY idx_workspace_onboarding_state_status (status),
    KEY idx_workspace_onboarding_state_current_step (current_step),
    CONSTRAINT fk_workspace_onboarding_state_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_onboarding_state (
    workspace_id,
    status,
    current_step,
    required_steps_json,
    completed_steps_json,
    skipped_optional_json,
    automation_launch_mode,
    ai_autoresponder_mode,
    deal_automation_enabled,
    completed_at
)
SELECT
    w.id,
    'completed',
    6,
    JSON_ARRAY('channel', 'context', 'automation_launch', 'autoresponder', 'commercial', 'workflows'),
    JSON_ARRAY('channel', 'context', 'automation_launch', 'autoresponder', 'commercial', 'workflows'),
    JSON_ARRAY(),
    'learning_on_the_go',
    'draft_only',
    1,
    NOW()
FROM workspaces w
WHERE NOT EXISTS (
    SELECT 1
    FROM workspace_onboarding_state s
    WHERE s.workspace_id = w.id
);
