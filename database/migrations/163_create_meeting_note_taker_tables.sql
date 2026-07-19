CREATE TABLE IF NOT EXISTS meeting_note_taker_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    auto_apply_mode ENUM('suggest_only', 'auto_safe', 'full_auto') NOT NULL DEFAULT 'full_auto',
    contact_updates_additive_only TINYINT(1) NOT NULL DEFAULT 1,
    task_auto_create_enabled TINYINT(1) NOT NULL DEFAULT 1,
    deal_stage_auto_move_enabled TINYINT(1) NOT NULL DEFAULT 1,
    deal_stage_min_confidence DECIMAL(4,2) NOT NULL DEFAULT 0.90,
    contact_update_min_confidence DECIMAL(4,2) NOT NULL DEFAULT 0.75,
    ingest_secret VARCHAR(128) DEFAULT NULL,
    config_json JSON DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meeting_note_taker_config_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO meeting_note_taker_config (
    id, enabled, auto_apply_mode, contact_updates_additive_only, task_auto_create_enabled,
    deal_stage_auto_move_enabled, deal_stage_min_confidence, contact_update_min_confidence,
    ingest_secret, config_json, updated_by
)
VALUES (
    1, 0, 'full_auto', 1, 1, 1, 0.90, 0.75,
    SHA2(CONCAT(UUID(), '-', RAND()), 256),
    JSON_OBJECT(
        'allowed_contact_fields', JSON_ARRAY('job_title', 'location', 'company_website', 'linkedin_url', 'twitter_url', 'timezone'),
        'max_context_entries', 10,
        'schema_version', 1
    ),
    NULL
)
ON DUPLICATE KEY UPDATE
    enabled = enabled;

CREATE TABLE IF NOT EXISTS meeting_note_taker_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL DEFAULT 'generic',
    external_meeting_id VARCHAR(255) DEFAULT NULL,
    title VARCHAR(255) DEFAULT NULL,
    organizer_email VARCHAR(255) DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    transcript_text MEDIUMTEXT DEFAULT NULL,
    summary_text MEDIUMTEXT DEFAULT NULL,
    normalized_payload_json JSON DEFAULT NULL,
    matched_contact_id INT DEFAULT NULL,
    matched_deal_id INT DEFAULT NULL,
    entity_match_status ENUM('matched', 'ambiguous', 'unmatched') NOT NULL DEFAULT 'unmatched',
    extraction_status ENUM('pending', 'completed', 'fallback', 'failed') NOT NULL DEFAULT 'pending',
    apply_status ENUM('applied', 'partial', 'skipped', 'blocked', 'failed') NOT NULL DEFAULT 'skipped',
    confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    extracted_actions_json JSON DEFAULT NULL,
    applied_actions_json JSON DEFAULT NULL,
    skipped_actions_json JSON DEFAULT NULL,
    reasons_json JSON DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_meeting_note_taker_runs_provider (provider),
    INDEX idx_meeting_note_taker_runs_contact (matched_contact_id),
    INDEX idx_meeting_note_taker_runs_deal (matched_deal_id),
    INDEX idx_meeting_note_taker_runs_created_at (created_at),
    CONSTRAINT fk_meeting_note_taker_runs_contact FOREIGN KEY (matched_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_note_taker_runs_deal FOREIGN KEY (matched_deal_id) REFERENCES deals(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_note_taker_runs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('settings.meeting_note_taker', 'Meeting Note Taker Settings', 'Manage meeting note taker settings and integrations', TRUE),
    ('meeting_notes.view_runs', 'Meeting Note Taker Runs', 'View meeting note taker run logs', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('settings.meeting_note_taker', 'meeting_notes.view_runs')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
