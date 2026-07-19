CREATE TABLE IF NOT EXISTS workspace_marketplace_setup_journey_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    skill_key VARCHAR(80) NOT NULL,
    step_key VARCHAR(120) NULL,
    label VARCHAR(255) NULL,
    surface ENUM('marketplace') NOT NULL DEFAULT 'marketplace',
    event_type ENUM('journey_impression', 'setup_opened', 'install_completed', 'step_completed', 'step_skipped', 'step_reset') NOT NULL,
    step_status ENUM('pending', 'completed', 'skipped') NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'workspace_marketplace_page',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketplace_setup_events_workspace (workspace_id),
    KEY idx_marketplace_setup_events_user (user_id),
    KEY idx_marketplace_setup_events_skill (skill_key),
    KEY idx_marketplace_setup_events_step (step_key),
    KEY idx_marketplace_setup_events_type (event_type),
    KEY idx_marketplace_setup_events_created_at (created_at),
    CONSTRAINT fk_marketplace_setup_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_setup_events_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
