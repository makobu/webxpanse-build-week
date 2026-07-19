-- Premium Calendar & Meetings foundation.

CREATE TABLE IF NOT EXISTS meeting_booking_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    owner_user_id INT DEFAULT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL DEFAULT 'Book a meeting',
    description TEXT DEFAULT NULL,
    public_enabled TINYINT(1) NOT NULL DEFAULT 1,
    timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
    default_duration_minutes INT NOT NULL DEFAULT 30,
    allowed_durations_json JSON DEFAULT NULL,
    buffer_before_minutes INT NOT NULL DEFAULT 15,
    buffer_after_minutes INT NOT NULL DEFAULT 15,
    min_notice_hours INT NOT NULL DEFAULT 24,
    max_advance_days INT NOT NULL DEFAULT 60,
    allowed_meeting_formats_json JSON DEFAULT NULL,
    approval_mode ENUM('manual', 'auto_confirm_internal') NOT NULL DEFAULT 'manual',
    status ENUM('active', 'paused', 'archived') NOT NULL DEFAULT 'active',
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_meeting_booking_profile_slug (workspace_id, slug),
    KEY idx_meeting_booking_profiles_workspace_status (workspace_id, status, public_enabled),
    KEY idx_meeting_booking_profiles_owner (workspace_id, owner_user_id),
    CONSTRAINT fk_meeting_booking_profiles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_booking_profiles_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_booking_profiles_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_booking_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_availability_windows (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_id INT NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_meeting_availability_profile_day (profile_id, day_of_week, is_enabled),
    CONSTRAINT fk_meeting_availability_profile FOREIGN KEY (profile_id) REFERENCES meeting_booking_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_blocked_times (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    profile_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    is_all_day TINYINT(1) NOT NULL DEFAULT 0,
    reason VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_meeting_blocked_workspace_range (workspace_id, start_time, end_time),
    KEY idx_meeting_blocked_profile_range (profile_id, start_time, end_time),
    CONSTRAINT fk_meeting_blocked_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_blocked_profile FOREIGN KEY (profile_id) REFERENCES meeting_booking_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_blocked_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_blocked_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_booking_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    profile_id INT NOT NULL,
    event_id INT DEFAULT NULL,
    requester_name VARCHAR(255) NOT NULL,
    requester_email VARCHAR(255) NOT NULL,
    requester_phone VARCHAR(80) DEFAULT NULL,
    requester_organization VARCHAR(255) DEFAULT NULL,
    requester_role VARCHAR(255) DEFAULT NULL,
    meeting_format ENUM('phone_call', 'zoom', 'google_meet', 'in_person') NOT NULL DEFAULT 'google_meet',
    inquiry_type VARCHAR(120) NOT NULL DEFAULT 'General meeting',
    inquiry_description TEXT DEFAULT NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
    scheduled_start DATETIME NOT NULL,
    scheduled_end DATETIME NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 30,
    status ENUM('pending', 'confirmed', 'declined', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
    approval_note TEXT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    declined_by INT DEFAULT NULL,
    declined_at DATETIME DEFAULT NULL,
    cancelled_by INT DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    dedupe_hash CHAR(64) DEFAULT NULL,
    public_token CHAR(64) NOT NULL,
    request_ip_hash CHAR(64) DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_meeting_booking_public_token (public_token),
    UNIQUE KEY uniq_meeting_booking_dedupe (workspace_id, dedupe_hash),
    KEY idx_meeting_booking_workspace_status (workspace_id, status, scheduled_start),
    KEY idx_meeting_booking_profile_range (profile_id, scheduled_start, scheduled_end),
    KEY idx_meeting_booking_requester (workspace_id, requester_email, created_at),
    CONSTRAINT fk_meeting_booking_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_booking_profile FOREIGN KEY (profile_id) REFERENCES meeting_booking_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_booking_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_booking_declined_by FOREIGN KEY (declined_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_meeting_booking_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meeting_calendar_busy_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    integration_id INT DEFAULT NULL,
    source_label VARCHAR(255) DEFAULT NULL,
    busy_start DATETIME NOT NULL,
    busy_end DATETIME NOT NULL,
    fetched_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    status ENUM('ok', 'error') NOT NULL DEFAULT 'ok',
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meeting_busy_workspace_range (workspace_id, busy_start, busy_end, expires_at),
    KEY idx_meeting_busy_integration (integration_id, busy_start, busy_end),
    CONSTRAINT fk_meeting_busy_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_sync_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    integration_id INT DEFAULT NULL,
    provider VARCHAR(40) DEFAULT NULL,
    direction ENUM('import', 'export', 'two_way', 'health') NOT NULL DEFAULT 'health',
    operation VARCHAR(80) NOT NULL DEFAULT 'sync',
    status ENUM('success', 'warning', 'failed') NOT NULL DEFAULT 'success',
    event_id INT DEFAULT NULL,
    external_event_id VARCHAR(255) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_calendar_sync_audit_workspace_created (workspace_id, created_at),
    KEY idx_calendar_sync_audit_integration_created (integration_id, created_at),
    KEY idx_calendar_sync_audit_event (workspace_id, event_id),
    CONSTRAINT fk_calendar_sync_audit_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    KEY idx_calendar_sync_audit_external (workspace_id, provider, external_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events ADD COLUMN custom_fields JSON NULL AFTER recurrence_count;
ALTER TABLE events ADD COLUMN calendar_provider VARCHAR(40) DEFAULT NULL AFTER custom_fields;
ALTER TABLE events ADD COLUMN external_calendar_id VARCHAR(255) DEFAULT NULL AFTER calendar_provider;
ALTER TABLE events ADD COLUMN external_event_id VARCHAR(255) DEFAULT NULL AFTER external_calendar_id;
ALTER TABLE events ADD COLUMN external_event_etag VARCHAR(255) DEFAULT NULL AFTER external_event_id;
ALTER TABLE events ADD COLUMN sync_origin ENUM('crm', 'provider', 'booking', 'ical') DEFAULT 'crm' AFTER external_event_etag;
ALTER TABLE events ADD COLUMN last_synced_at DATETIME DEFAULT NULL AFTER sync_origin;
ALTER TABLE events ADD INDEX idx_events_sync_identity (workspace_id, calendar_provider, external_calendar_id, external_event_id);
ALTER TABLE events ADD INDEX idx_events_sync_origin_updated (workspace_id, sync_origin, updated_at);

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('meeting_bookings.view', 'View Meeting Bookings', 'View meeting booking requests and scheduling operations', TRUE),
    ('meeting_bookings.manage', 'Manage Meeting Bookings', 'Approve, reschedule, cancel, and complete meeting booking requests', TRUE),
    ('meeting_availability.manage', 'Manage Meeting Availability', 'Configure public booking profiles, availability windows, and blocked times', TRUE),
    ('calendar_sync.manage', 'Manage Calendar Sync', 'Manage calendar sync direction, health, and audit controls', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'meeting_bookings.view',
    'meeting_bookings.manage',
    'meeting_availability.manage',
    'calendar_sync.manage'
)
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
