-- Production data contract for the CRM Design Studio.
-- Documents are validated against a code-owned schema before persistence.

ALTER TABLE marketing_landing_pages
    ADD COLUMN design_schema_version VARCHAR(32) NOT NULL DEFAULT 'crm.design/v1' AFTER builder_status,
    ADD COLUMN design_document_json JSON NULL AFTER design_schema_version,
    ADD COLUMN design_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER design_document_json,
    ADD COLUMN design_validation_json JSON NULL AFTER design_revision,
    ADD COLUMN design_updated_at DATETIME NULL AFTER design_validation_json,
    ADD INDEX idx_marketing_landing_design_revision (workspace_id, id, design_revision);

CREATE TABLE IF NOT EXISTS marketing_design_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id INT NULL,
    template_key VARCHAR(120) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description VARCHAR(500) NULL,
    use_case VARCHAR(80) NOT NULL,
    industry VARCHAR(80) NOT NULL DEFAULT 'general',
    schema_version VARCHAR(32) NOT NULL DEFAULT 'crm.design/v1',
    template_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    status ENUM('draft', 'review', 'approved', 'archived') NOT NULL DEFAULT 'draft',
    document_json JSON NOT NULL,
    preview_media_file_id BIGINT UNSIGNED NULL,
    usage_count INT UNSIGNED NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_marketing_design_template (workspace_id, template_key, template_version),
    KEY idx_marketing_design_template_catalog (status, use_case, industry),
    KEY idx_marketing_design_template_workspace (workspace_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_design_template_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    landing_page_id BIGINT UNSIGNED NULL,
    template_key VARCHAR(120) NOT NULL,
    template_version VARCHAR(32) NOT NULL,
    event_type ENUM('previewed', 'applied', 'published') NOT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_marketing_design_template_event (workspace_id, template_key, event_type, created_at),
    KEY idx_marketing_design_template_page (workspace_id, landing_page_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
