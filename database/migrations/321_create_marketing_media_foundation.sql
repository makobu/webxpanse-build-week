-- Marketing Phase 29: media library foundation for images, video, documents, and rich asset metadata.

CREATE TABLE IF NOT EXISTS marketing_media_files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    media_type ENUM('image','video','document','audio','logo','thumbnail','banner','other') NOT NULL DEFAULT 'other',
    source_type ENUM('upload','url','reference') NOT NULL DEFAULT 'url',
    file_path VARCHAR(1000) NULL,
    source_url VARCHAR(1000) NULL,
    mime_type VARCHAR(160) NULL,
    file_size BIGINT NULL,
    width INT NULL,
    height INT NULL,
    duration_seconds DECIMAL(10,2) NULL,
    thumbnail_path VARCHAR(1000) NULL,
    thumbnail_url VARCHAR(1000) NULL,
    alt_text TEXT NULL,
    caption TEXT NULL,
    transcript MEDIUMTEXT NULL,
    usage_rights VARCHAR(255) NULL,
    tags_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_media_uuid (uuid),
    KEY idx_marketing_media_workspace_type (workspace_id, media_type, updated_at),
    KEY idx_marketing_media_workspace_source (workspace_id, source_type, updated_at),
    KEY idx_marketing_media_workspace_owner (workspace_id, owner_user_id, updated_at),
    CONSTRAINT fk_marketing_media_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_media_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_media_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_assets
    ADD COLUMN IF NOT EXISTS media_file_id INT NULL AFTER uuid,
    ADD INDEX IF NOT EXISTS idx_marketing_assets_workspace_media (workspace_id, media_file_id);
