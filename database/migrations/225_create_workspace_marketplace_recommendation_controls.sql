CREATE TABLE IF NOT EXISTS workspace_marketplace_recommendation_controls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    skill_key VARCHAR(80) NOT NULL,
    control_type ENUM('pinned', 'muted', 'surface_disabled') NOT NULL,
    surface ENUM('all', 'marketplace', 'clarity_chat', 'coach') NOT NULL DEFAULT 'all',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    reason_code VARCHAR(80) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketplace_rec_control (workspace_id, skill_key, control_type, surface),
    KEY idx_marketplace_rec_controls_workspace (workspace_id),
    KEY idx_marketplace_rec_controls_user (user_id),
    KEY idx_marketplace_rec_controls_skill (skill_key),
    KEY idx_marketplace_rec_controls_type_surface (control_type, surface),
    KEY idx_marketplace_rec_controls_enabled (enabled),
    CONSTRAINT fk_marketplace_rec_controls_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_rec_controls_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
