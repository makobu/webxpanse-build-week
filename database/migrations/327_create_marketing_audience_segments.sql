-- Marketing Phase 35: audience segmentation foundation.

CREATE TABLE IF NOT EXISTS marketing_audience_segments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    source_scope ENUM('contacts','companies','deals','mixed') NOT NULL DEFAULT 'contacts',
    rule_logic ENUM('all','any') NOT NULL DEFAULT 'all',
    preview_count INT NOT NULL DEFAULT 0,
    last_previewed_at DATETIME NULL,
    last_snapshot_at DATETIME NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_audience_segments_uuid (uuid),
    KEY idx_marketing_segments_workspace_status (workspace_id, status, updated_at),
    KEY idx_marketing_segments_workspace_name (workspace_id, name),
    KEY idx_marketing_segments_workspace_preview (workspace_id, last_previewed_at),
    CONSTRAINT fk_marketing_segments_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_segments_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_segment_rules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    segment_id INT NOT NULL,
    source_type ENUM('contact','company','deal','form_submission','tag','activity') NOT NULL DEFAULT 'contact',
    field_key VARCHAR(80) NOT NULL,
    operator ENUM('equals','not_equals','contains','not_contains','in','not_in','greater_than','less_than','between','exists','not_exists','after','before') NOT NULL DEFAULT 'equals',
    value_text VARCHAR(500) NULL,
    value_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_marketing_segment_rules_workspace_segment (workspace_id, segment_id, sort_order),
    KEY idx_marketing_segment_rules_workspace_source (workspace_id, source_type, field_key),
    CONSTRAINT fk_marketing_segment_rules_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_segment_rules_segment FOREIGN KEY (segment_id) REFERENCES marketing_audience_segments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_segment_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    segment_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(180) NOT NULL,
    contact_count INT NOT NULL DEFAULT 0,
    contact_ids_json JSON NULL,
    sample_contacts_json JSON NULL,
    criteria_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_segment_snapshots_uuid (uuid),
    KEY idx_marketing_segment_snapshots_workspace_segment (workspace_id, segment_id, created_at),
    KEY idx_marketing_segment_snapshots_workspace_created (workspace_id, created_at),
    CONSTRAINT fk_marketing_segment_snapshots_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_segment_snapshots_segment FOREIGN KEY (segment_id) REFERENCES marketing_audience_segments(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_segment_snapshots_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_campaign_briefs
    ADD COLUMN IF NOT EXISTS audience_segment_id INT NULL AFTER audience,
    ADD INDEX IF NOT EXISTS idx_marketing_briefs_workspace_segment (workspace_id, audience_segment_id);

ALTER TABLE marketing_email_campaign_runs
    ADD COLUMN IF NOT EXISTS audience_segment_id INT NULL AFTER campaign_id,
    ADD COLUMN IF NOT EXISTS segment_snapshot_id INT NULL AFTER audience_segment_id,
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_workspace_segment (workspace_id, audience_segment_id),
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_workspace_snapshot (workspace_id, segment_snapshot_id);

ALTER TABLE marketing_channel_export_bundles
    ADD COLUMN IF NOT EXISTS audience_segment_id INT NULL AFTER media_kit_id,
    ADD COLUMN IF NOT EXISTS segment_snapshot_id INT NULL AFTER audience_segment_id,
    ADD INDEX IF NOT EXISTS idx_marketing_channel_export_workspace_segment (workspace_id, audience_segment_id),
    ADD INDEX IF NOT EXISTS idx_marketing_channel_export_workspace_snapshot (workspace_id, segment_snapshot_id);

ALTER TABLE marketing_landing_pages
    ADD COLUMN IF NOT EXISTS audience_segment_id INT NULL AFTER campaign_id,
    ADD INDEX IF NOT EXISTS idx_marketing_landing_pages_workspace_segment (workspace_id, audience_segment_id);

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('marketing_segments', 'Audience Segments page guide', NULL, 0),
    ('marketing_segment_edit', 'Audience Segment Editor page guide', NULL, 0),
    ('marketing_segment_view', 'Audience Segment Detail page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
