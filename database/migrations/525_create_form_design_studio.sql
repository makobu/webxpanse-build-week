-- Production data contract for the CRM Form Studio.
-- Existing forms remain published and new Studio forms explicitly start as drafts.
-- Plain ALTER statements keep this import compatible with live phpMyAdmin users
-- that cannot use MariaDB-only IF NOT EXISTS syntax. The project migration
-- runners tolerate duplicate column/index errors on rerun.

ALTER TABLE forms ADD COLUMN design_schema_version VARCHAR(32) NOT NULL DEFAULT 'crm.form/v1' AFTER settings;
ALTER TABLE forms ADD COLUMN design_document_json JSON NULL AFTER design_schema_version;
ALTER TABLE forms ADD COLUMN design_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER design_document_json;
ALTER TABLE forms ADD COLUMN design_validation_json JSON NULL AFTER design_revision;
ALTER TABLE forms ADD COLUMN design_status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published' AFTER design_validation_json;
ALTER TABLE forms ADD COLUMN published_document_json JSON NULL AFTER design_status;
ALTER TABLE forms ADD COLUMN published_revision INT UNSIGNED NULL AFTER published_document_json;
ALTER TABLE forms ADD COLUMN preview_token VARCHAR(64) NULL AFTER published_revision;
ALTER TABLE forms ADD COLUMN published_at DATETIME NULL AFTER preview_token;
ALTER TABLE forms ADD COLUMN design_updated_at DATETIME NULL AFTER published_at;
ALTER TABLE forms ADD INDEX idx_forms_design_revision (workspace_id, id, design_revision);
ALTER TABLE forms ADD INDEX idx_forms_design_status (workspace_id, design_status, updated_at);
ALTER TABLE forms ADD UNIQUE INDEX uq_forms_preview_token (preview_token);

CREATE TABLE IF NOT EXISTS form_design_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    form_id INT NOT NULL,
    revision INT UNSIGNED NOT NULL,
    version_type ENUM('draft', 'published', 'restored') NOT NULL DEFAULT 'draft',
    document_json JSON NOT NULL,
    validation_json JSON NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_form_design_versions (workspace_id, form_id, created_at),
    KEY idx_form_design_version_revision (workspace_id, form_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_design_template_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    form_id INT NULL,
    template_key VARCHAR(120) NOT NULL,
    template_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    event_type ENUM('previewed', 'applied', 'published') NOT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_form_design_template_event (workspace_id, template_key, event_type, created_at),
    KEY idx_form_design_template_form (workspace_id, form_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
