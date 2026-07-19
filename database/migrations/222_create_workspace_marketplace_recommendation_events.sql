CREATE TABLE IF NOT EXISTS workspace_marketplace_recommendation_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    skill_key VARCHAR(80) NOT NULL,
    surface ENUM('marketplace', 'clarity_chat', 'coach') NOT NULL,
    event_type ENUM('impression', 'cta_clicked', 'dismissed', 'snoozed', 'task_created', 'installed') NOT NULL,
    recommendation_score INT NULL,
    priority VARCHAR(20) NULL,
    reason_codes_json JSON NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketplace_rec_events_workspace (workspace_id),
    KEY idx_marketplace_rec_events_user (user_id),
    KEY idx_marketplace_rec_events_skill (skill_key),
    KEY idx_marketplace_rec_events_surface_type (surface, event_type),
    KEY idx_marketplace_rec_events_created_at (created_at),
    CONSTRAINT fk_marketplace_rec_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_rec_events_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
