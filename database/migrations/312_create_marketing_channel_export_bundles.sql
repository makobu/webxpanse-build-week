-- Marketing Phase 18: channel-specific manual export bundles.

CREATE TABLE IF NOT EXISTS marketing_channel_export_bundles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    distribution_post_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    channel VARCHAR(80) NOT NULL,
    bundle_type ENUM('social','whatsapp','sms','ad','newsletter','custom') NOT NULL DEFAULT 'custom',
    status ENUM('draft','ready','exported','archived') NOT NULL DEFAULT 'draft',
    required_fields_json JSON NULL,
    asset_readiness_json JSON NULL,
    export_payload_json JSON NULL,
    metadata_json JSON NULL,
    exported_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_channel_export_uuid (uuid),
    KEY idx_marketing_channel_export_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_channel_export_workspace_post (workspace_id, distribution_post_id),
    KEY idx_marketing_channel_export_workspace_channel (workspace_id, channel, status),
    CONSTRAINT fk_marketing_channel_export_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_channel_export_distribution FOREIGN KEY (distribution_post_id) REFERENCES marketing_distribution_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_channel_export_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
