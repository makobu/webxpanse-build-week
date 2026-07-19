CREATE TABLE IF NOT EXISTS workspace_skill_catalog_overrides (
    id INT PRIMARY KEY AUTO_INCREMENT,
    skill_key VARCHAR(80) NOT NULL,
    label VARCHAR(160) NULL,
    summary TEXT NULL,
    thumbnail_url VARCHAR(500) NULL,
    thumbnail_alt VARCHAR(255) NULL,
    banner_url VARCHAR(500) NULL,
    banner_alt VARCHAR(255) NULL,
    marketplace_profile_json JSON NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_skill_catalog_overrides_skill (skill_key),
    KEY idx_workspace_skill_catalog_overrides_updated_by (updated_by_user_id),
    CONSTRAINT fk_workspace_skill_catalog_overrides_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
