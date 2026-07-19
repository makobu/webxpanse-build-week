-- Marketing distribution phase four: assets, channel variants, UTM links, and performance loop.

CREATE TABLE IF NOT EXISTS marketing_assets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    asset_type ENUM('image','video','document','audio','link','other') NOT NULL DEFAULT 'other',
    asset_url VARCHAR(1000) NULL,
    channel VARCHAR(80) NULL,
    usage_rights VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_assets_workspace_type (workspace_id, asset_type, updated_at),
    INDEX idx_marketing_assets_workspace_channel (workspace_id, channel, updated_at),
    CONSTRAINT fk_marketing_assets_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_assets_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_distribution_posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    content_item_id INT NOT NULL,
    asset_id INT NULL,
    channel ENUM('blog','linkedin','facebook','instagram','x','email','newsletter','whatsapp','sms','ads','youtube','website','other') NOT NULL DEFAULT 'other',
    planned_copy MEDIUMTEXT NULL,
    scheduled_at DATETIME NULL,
    status ENUM('draft','scheduled','exported','cancelled') NOT NULL DEFAULT 'draft',
    exported_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_distribution_workspace_status (workspace_id, status, scheduled_at),
    INDEX idx_marketing_distribution_workspace_content (workspace_id, content_item_id),
    INDEX idx_marketing_distribution_workspace_channel (workspace_id, channel, status),
    CONSTRAINT fk_marketing_distribution_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_distribution_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_distribution_asset FOREIGN KEY (asset_id) REFERENCES marketing_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_distribution_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_utm_links (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    campaign_id INT NULL,
    content_item_id INT NULL,
    url VARCHAR(1000) NOT NULL,
    utm_source VARCHAR(120) NULL,
    utm_medium VARCHAR(120) NULL,
    utm_campaign VARCHAR(160) NULL,
    utm_term VARCHAR(160) NULL,
    utm_content VARCHAR(160) NULL,
    generated_url VARCHAR(1400) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_utm_workspace_campaign (workspace_id, campaign_id, created_at),
    INDEX idx_marketing_utm_workspace_content (workspace_id, content_item_id, created_at),
    CONSTRAINT fk_marketing_utm_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_utm_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_utm_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_utm_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
