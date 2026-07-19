CREATE TABLE IF NOT EXISTS workspace_marketplace_activation_bundle_state (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    bundle_key VARCHAR(80) NOT NULL,
    status ENUM('selected', 'dismissed', 'completed') NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketplace_activation_bundle_state (workspace_id, bundle_key),
    KEY idx_marketplace_activation_bundle_workspace (workspace_id),
    KEY idx_marketplace_activation_bundle_user (user_id),
    KEY idx_marketplace_activation_bundle_key (bundle_key),
    KEY idx_marketplace_activation_bundle_status (status),
    CONSTRAINT fk_marketplace_activation_bundle_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_activation_bundle_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
