CREATE TABLE IF NOT EXISTS ai_coach_recommendation_controls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    created_by INT NULL,
    control_scope ENUM('feedback_signature', 'source_type', 'source_section') NOT NULL,
    control_value VARCHAR(160) NOT NULL,
    control_type ENUM('boosted', 'muted', 'reset_learning') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    reason VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_coach_rec_control (workspace_id, control_scope, control_value, control_type),
    KEY idx_ai_coach_rec_controls_workspace_enabled (workspace_id, enabled),
    KEY idx_ai_coach_rec_controls_scope_value (control_scope, control_value),
    KEY idx_ai_coach_rec_controls_type (control_type),
    KEY idx_ai_coach_rec_controls_created_by (created_by),
    CONSTRAINT fk_ai_coach_rec_controls_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_ai_coach_rec_controls_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
