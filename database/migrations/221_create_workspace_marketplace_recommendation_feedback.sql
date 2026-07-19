CREATE TABLE IF NOT EXISTS workspace_marketplace_recommendation_feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    skill_key VARCHAR(80) NOT NULL,
    feedback_type ENUM('dismissed', 'snoozed') NOT NULL,
    reason_code VARCHAR(120) NULL,
    snoozed_until DATETIME NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketplace_feedback_workspace (workspace_id),
    KEY idx_marketplace_feedback_skill (skill_key),
    KEY idx_marketplace_feedback_type (feedback_type),
    KEY idx_marketplace_feedback_snoozed_until (snoozed_until),
    CONSTRAINT fk_marketplace_feedback_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_feedback_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
