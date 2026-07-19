CREATE TABLE IF NOT EXISTS workspace_marketplace_activation_bundle_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    bundle_key VARCHAR(80) NOT NULL,
    surface ENUM('marketplace') NOT NULL DEFAULT 'marketplace',
    event_type ENUM('bundle_impression', 'cta_clicked', 'selected', 'dismissed', 'completed', 'module_installed') NOT NULL,
    bundle_status ENUM('suggested', 'selected', 'dismissed', 'completed') NULL,
    priority VARCHAR(20) NULL,
    included_skill_keys_json JSON NULL,
    recommended_skill_keys_json JSON NULL,
    progress_json JSON NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketplace_bundle_events_workspace (workspace_id),
    KEY idx_marketplace_bundle_events_user (user_id),
    KEY idx_marketplace_bundle_events_key (bundle_key),
    KEY idx_marketplace_bundle_events_surface_type (surface, event_type),
    KEY idx_marketplace_bundle_events_created_at (created_at),
    CONSTRAINT fk_marketplace_bundle_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketplace_bundle_events_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
