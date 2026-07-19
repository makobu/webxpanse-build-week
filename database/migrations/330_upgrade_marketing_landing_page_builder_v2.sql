-- Marketing Phase 38: landing page builder V2 planning fields.

ALTER TABLE marketing_landing_pages
    ADD COLUMN IF NOT EXISTS theme_settings_json JSON NULL AFTER gallery_media_json,
    ADD COLUMN IF NOT EXISTS cta_variants_json JSON NULL AFTER theme_settings_json,
    ADD COLUMN IF NOT EXISTS form_blocks_json JSON NULL AFTER cta_variants_json,
    ADD COLUMN IF NOT EXISTS seo_controls_json JSON NULL AFTER form_blocks_json,
    ADD COLUMN IF NOT EXISTS social_preview_json JSON NULL AFTER seo_controls_json,
    ADD COLUMN IF NOT EXISTS draft_version_id INT NULL AFTER social_preview_json,
    ADD COLUMN IF NOT EXISTS published_version_id INT NULL AFTER draft_version_id,
    ADD COLUMN IF NOT EXISTS builder_status VARCHAR(32) NOT NULL DEFAULT 'draft' AFTER published_version_id,
    ADD INDEX IF NOT EXISTS idx_marketing_landing_workspace_builder_status (workspace_id, builder_status);

CREATE TABLE IF NOT EXISTS marketing_landing_page_sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(180) NOT NULL,
    section_type ENUM('hero','problem','solution','proof','cta','faq','form','gallery','custom') NOT NULL DEFAULT 'custom',
    content_json JSON NULL,
    media_json JSON NULL,
    theme_json JSON NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_landing_sections_uuid (uuid),
    KEY idx_marketing_landing_sections_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_landing_sections_workspace_type (workspace_id, section_type),
    CONSTRAINT fk_marketing_landing_sections_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_landing_sections_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_landing_page_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    landing_page_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    version_type ENUM('draft','published') NOT NULL DEFAULT 'draft',
    version_number INT NOT NULL DEFAULT 1,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    snapshot_json JSON NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_landing_versions_uuid (uuid),
    UNIQUE KEY uniq_marketing_landing_versions_number (workspace_id, landing_page_id, version_type, version_number),
    KEY idx_marketing_landing_versions_workspace_page (workspace_id, landing_page_id, created_at),
    CONSTRAINT fk_marketing_landing_versions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_landing_versions_page FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_landing_versions_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
