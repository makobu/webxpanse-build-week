CREATE TABLE IF NOT EXISTS workspace_marketplace_setup_journey_steps (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    skill_key VARCHAR(80) NOT NULL,
    step_key VARCHAR(120) NOT NULL,
    label VARCHAR(255) NOT NULL,
    status ENUM('pending', 'completed', 'skipped') NOT NULL DEFAULT 'pending',
    source VARCHAR(40) NOT NULL DEFAULT 'catalog',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketplace_setup_step (workspace_id, skill_key, step_key),
    KEY idx_marketplace_setup_workspace (workspace_id),
    KEY idx_marketplace_setup_user (user_id),
    KEY idx_marketplace_setup_skill (skill_key),
    KEY idx_marketplace_setup_status (status),
    CONSTRAINT fk_marketplace_setup_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_setup_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
