-- Marketing strategy phase three: brand voice, personas, SEO topics, and landing-page planning.

CREATE TABLE IF NOT EXISTS marketing_brand_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL DEFAULT 'Default Brand',
    voice VARCHAR(255) NULL,
    tone VARCHAR(255) NULL,
    banned_words TEXT NULL,
    value_props TEXT NULL,
    proof_points TEXT NULL,
    cta_defaults TEXT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_brand_profiles_workspace_default (workspace_id, is_default, updated_at),
    CONSTRAINT fk_marketing_brand_profiles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_brand_profiles_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_personas (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    segment VARCHAR(255) NULL,
    pains TEXT NULL,
    goals TEXT NULL,
    objections TEXT NULL,
    preferred_channels_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_personas_workspace_name (workspace_id, name),
    CONSTRAINT fk_marketing_personas_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_personas_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_seo_topics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    keyword VARCHAR(255) NOT NULL,
    intent ENUM('informational','commercial','transactional','navigational','other') NOT NULL DEFAULT 'informational',
    funnel_stage VARCHAR(80) NULL,
    priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    target_url VARCHAR(500) NULL,
    content_item_id INT NULL,
    status ENUM('idea','planned','in_progress','published','archived') NOT NULL DEFAULT 'idea',
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_seo_topics_workspace_status (workspace_id, status, priority),
    INDEX idx_marketing_seo_topics_workspace_keyword (workspace_id, keyword),
    CONSTRAINT fk_marketing_seo_topics_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_seo_topics_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_seo_topics_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_landing_pages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    headline VARCHAR(255) NULL,
    body_sections_json JSON NULL,
    form_id INT NULL,
    campaign_id INT NULL,
    status ENUM('draft','review','approved','archived') NOT NULL DEFAULT 'draft',
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_landing_pages_workspace_slug (workspace_id, slug),
    INDEX idx_marketing_landing_pages_workspace_status (workspace_id, status, updated_at),
    INDEX idx_marketing_landing_pages_workspace_campaign (workspace_id, campaign_id),
    CONSTRAINT fk_marketing_landing_pages_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_landing_pages_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_landing_pages_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_landing_pages_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_content_items ADD COLUMN brand_profile_id INT NULL AFTER campaign_brief_id;
ALTER TABLE marketing_content_items ADD COLUMN persona_id INT NULL AFTER brand_profile_id;
ALTER TABLE marketing_content_items ADD COLUMN seo_topic_id INT NULL AFTER persona_id;
ALTER TABLE marketing_content_items ADD COLUMN landing_page_id INT NULL AFTER seo_topic_id;
ALTER TABLE marketing_content_items ADD INDEX idx_marketing_content_workspace_strategy (workspace_id, brand_profile_id, persona_id, seo_topic_id, landing_page_id);
ALTER TABLE marketing_content_items ADD CONSTRAINT fk_marketing_content_brand_profile FOREIGN KEY (brand_profile_id) REFERENCES marketing_brand_profiles(id) ON DELETE SET NULL;
ALTER TABLE marketing_content_items ADD CONSTRAINT fk_marketing_content_persona FOREIGN KEY (persona_id) REFERENCES marketing_personas(id) ON DELETE SET NULL;
ALTER TABLE marketing_content_items ADD CONSTRAINT fk_marketing_content_seo_topic FOREIGN KEY (seo_topic_id) REFERENCES marketing_seo_topics(id) ON DELETE SET NULL;
ALTER TABLE marketing_content_items ADD CONSTRAINT fk_marketing_content_landing_page FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL;
