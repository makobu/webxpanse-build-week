CREATE TABLE IF NOT EXISTS meeting_bot_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    provider ENUM('zoom') NOT NULL DEFAULT 'zoom',
    bot_display_name VARCHAR(255) DEFAULT NULL,
    join_policy ENUM('manual_invite_only', 'calendar_suggested', 'auto_join_eligible') NOT NULL DEFAULT 'manual_invite_only',
    recording_mode ENUM('provider_native', 'bot_requested') NOT NULL DEFAULT 'provider_native',
    transcript_required TINYINT(1) NOT NULL DEFAULT 1,
    auto_apply_mode ENUM('suggest_only', 'auto_safe', 'full_auto') NOT NULL DEFAULT 'auto_safe',
    consent_notice TEXT DEFAULT NULL,
    zoom_account_id VARCHAR(255) DEFAULT NULL,
    zoom_client_id VARCHAR(255) DEFAULT NULL,
    zoom_client_secret VARCHAR(255) DEFAULT NULL,
    webhook_secret VARCHAR(128) DEFAULT NULL,
    scheduling_secret VARCHAR(128) DEFAULT NULL,
    config_json JSON DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meeting_bot_config_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO meeting_bot_config (
    id, enabled, provider, bot_display_name, join_policy, recording_mode, transcript_required,
    auto_apply_mode, consent_notice, zoom_account_id, zoom_client_id, zoom_client_secret,
    webhook_secret, scheduling_secret, config_json, updated_by
)
VALUES (
    1, 0, 'zoom', NULL, 'manual_invite_only', 'provider_native', 1,
    'auto_safe', 'This meeting may be joined and transcribed by your workspace meeting assistant.',
    NULL, NULL, NULL,
    SHA2(CONCAT(UUID(), '-', RAND()), 256),
    SHA2(CONCAT(UUID(), '-', RAND(), '-schedule'), 256),
    JSON_OBJECT('schema_version', 1),
    NULL
)
ON DUPLICATE KEY UPDATE
    enabled = enabled;

CREATE TABLE IF NOT EXISTS meeting_bot_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider ENUM('zoom') NOT NULL DEFAULT 'zoom',
    external_meeting_id VARCHAR(255) DEFAULT NULL,
    external_event_id VARCHAR(255) DEFAULT NULL,
    event_id INT DEFAULT NULL,
    meeting_note_taker_run_id INT DEFAULT NULL,
    title VARCHAR(255) DEFAULT NULL,
    join_url TEXT DEFAULT NULL,
    bot_display_name_used VARCHAR(255) NOT NULL,
    join_request_source ENUM('manual', 'calendar', 'webhook', 'api') NOT NULL DEFAULT 'manual',
    join_policy_snapshot VARCHAR(64) NOT NULL DEFAULT 'manual_invite_only',
    status ENUM('scheduled', 'joining', 'joined', 'recording', 'transcript_ready', 'processed', 'partial', 'failed') NOT NULL DEFAULT 'scheduled',
    recording_status ENUM('not_requested', 'requested', 'recording', 'completed', 'blocked', 'failed') NOT NULL DEFAULT 'not_requested',
    transcript_status ENUM('pending', 'received', 'processed', 'partial', 'failed', 'not_required') NOT NULL DEFAULT 'pending',
    consent_status ENUM('pending', 'notified', 'granted', 'denied', 'not_required') NOT NULL DEFAULT 'pending',
    organizer_email VARCHAR(255) DEFAULT NULL,
    transcript_excerpt MEDIUMTEXT DEFAULT NULL,
    failure_reason TEXT DEFAULT NULL,
    note_taker_status VARCHAR(32) DEFAULT NULL,
    normalized_attendees_json JSON DEFAULT NULL,
    provider_payload_json JSON DEFAULT NULL,
    scheduled_for DATETIME DEFAULT NULL,
    joined_at DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    transcript_received_at DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_meeting_bot_runs_external_meeting (provider, external_meeting_id),
    INDEX idx_meeting_bot_runs_status (status, created_at),
    INDEX idx_meeting_bot_runs_event (event_id),
    INDEX idx_meeting_bot_runs_note_taker (meeting_note_taker_run_id),
    CONSTRAINT fk_meeting_bot_runs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_meeting_bot_run_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'meeting_note_taker_runs'
      AND COLUMN_NAME = 'meeting_bot_run_id'
);
SET @alter_meeting_note_taker_runs := IF(
    @has_meeting_bot_run_id = 0,
    'ALTER TABLE meeting_note_taker_runs ADD COLUMN meeting_bot_run_id INT DEFAULT NULL AFTER normalized_payload_json, ADD INDEX idx_meeting_note_taker_runs_meeting_bot (meeting_bot_run_id)',
    'SELECT 1'
);
PREPARE stmt_meeting_bot_alter FROM @alter_meeting_note_taker_runs;
EXECUTE stmt_meeting_bot_alter;
DEALLOCATE PREPARE stmt_meeting_bot_alter;

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('settings.meeting_bot', 'Meeting Bot Settings', 'Manage first-party meeting bot settings and provider configuration', TRUE),
    ('meeting_bot.view_runs', 'Meeting Bot Runs', 'View first-party meeting bot run logs', TRUE),
    ('meeting_bot.schedule', 'Meeting Bot Scheduling', 'Register meetings for first-party meeting bot attendance', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('settings.meeting_bot', 'meeting_bot.view_runs', 'meeting_bot.schedule')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
