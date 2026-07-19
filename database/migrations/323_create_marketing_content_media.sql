-- Marketing Phase 31: content-level media attachments and readiness metadata.

CREATE TABLE IF NOT EXISTS marketing_content_media (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    content_item_id INT NOT NULL,
    media_file_id INT NOT NULL,
    role ENUM('featured','inline','thumbnail','banner','attachment','reference','video_source','proof') NOT NULL DEFAULT 'attachment',
    channel VARCHAR(80) NULL,
    crop_guidance VARCHAR(255) NULL,
    placement_notes TEXT NULL,
    caption TEXT NULL,
    alt_text_override TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_content_media_uuid (uuid),
    KEY idx_marketing_content_media_workspace_content (workspace_id, content_item_id, sort_order),
    KEY idx_marketing_content_media_workspace_media (workspace_id, media_file_id),
    KEY idx_marketing_content_media_workspace_role (workspace_id, role, updated_at),
    CONSTRAINT fk_marketing_content_media_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_media_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_media_media FOREIGN KEY (media_file_id) REFERENCES marketing_media_files(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_media_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
