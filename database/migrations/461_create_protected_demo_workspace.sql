-- Protected shared demo workspace with per-visitor private overlays.

INSERT INTO workspaces (uuid, name, slug, status, plan_status, settings_json, created_by)
SELECT
    '00000000-0000-4000-8000-000000000461',
    'Protected Demo Workspace',
    'protected-demo',
    'active',
    'inactive',
    JSON_OBJECT(
        'protected_demo_workspace', TRUE,
        'demo_workspace', TRUE,
        'billing_disabled', TRUE,
        'invites_disabled', TRUE,
        'exports_disabled', TRUE,
        'real_integrations_disabled', TRUE
    ),
    NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM workspaces WHERE slug = 'protected-demo' OR uuid = '00000000-0000-4000-8000-000000000461'
);

UPDATE workspaces
SET
    name = 'Protected Demo Workspace',
    status = 'active',
    plan_status = 'inactive',
    settings_json = JSON_SET(
        COALESCE(settings_json, JSON_OBJECT()),
        '$.protected_demo_workspace', TRUE,
        '$.demo_workspace', TRUE,
        '$.billing_disabled', TRUE,
        '$.invites_disabled', TRUE,
        '$.exports_disabled', TRUE,
        '$.real_integrations_disabled', TRUE
    )
WHERE slug = 'protected-demo'
   OR uuid = '00000000-0000-4000-8000-000000000461';

INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
SELECT id, 'protected-demo', 1
FROM workspaces
WHERE slug = 'protected-demo'
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id),
    is_primary = VALUES(is_primary);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_demo_guest TINYINT(1) NOT NULL DEFAULT 0 AFTER role,
    ADD COLUMN IF NOT EXISTS demo_expires_at DATETIME NULL AFTER is_demo_guest,
    ADD COLUMN IF NOT EXISTS demo_session_uuid CHAR(36) NULL AFTER demo_expires_at;

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_demo_guest (is_demo_guest, demo_expires_at),
    ADD INDEX IF NOT EXISTS idx_users_demo_session_uuid (demo_session_uuid);

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('demo.workspace.access', 'Access Demo Workspace', 'Enter the protected shared demo workspace', FALSE),
('demo.channel.simulate', 'Simulate Demo Messages', 'Create simulated email and WhatsApp demo messages', FALSE),
('demo.realtime.read', 'Read Demo Realtime Events', 'Read private realtime events for an active demo session', FALSE),
('demo.workspace.operate', 'Operate Demo Workspace', 'Manage protected demo workspace sessions, seeding, and cleanup', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Demo Viewer', 'demo_viewer', 'Temporary visitor access to the protected shared demo workspace', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('demo.workspace.access', 'demo.channel.simulate', 'demo.realtime.read')
WHERE r.slug = 'demo_viewer'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

CREATE TABLE IF NOT EXISTS demo_visitor_sessions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    session_uuid CHAR(36) NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    guest_user_id INT NULL,
    encrypted_name TEXT NULL,
    encrypted_email TEXT NULL,
    encrypted_phone TEXT NULL,
    email_hash CHAR(64) NULL,
    phone_hash CHAR(64) NULL,
    consent_contact TINYINT(1) NOT NULL DEFAULT 0,
    consent_privacy TINYINT(1) NOT NULL DEFAULT 0,
    access_source ENUM('logged_in', 'guest') NOT NULL DEFAULT 'guest',
    delivery_mode ENUM('simulated_first') NOT NULL DEFAULT 'simulated_first',
    status ENUM('active', 'ended', 'expired', 'revoked', 'cleanup_failed', 'anonymized') NOT NULL DEFAULT 'active',
    risk_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    message_count INT NOT NULL DEFAULT 0,
    last_seen_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    purge_after DATETIME NOT NULL,
    consent_retention_until DATETIME NULL,
    ended_at DATETIME NULL,
    revoked_at DATETIME NULL,
    cleanup_status ENUM('not_started', 'pending', 'succeeded', 'failed') NOT NULL DEFAULT 'not_started',
    cleanup_summary_json JSON NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demo_visitor_sessions_uuid (session_uuid),
    KEY idx_demo_sessions_workspace_status (workspace_id, status, expires_at),
    KEY idx_demo_sessions_user_status (user_id, status),
    KEY idx_demo_sessions_guest_user_status (guest_user_id, status),
    KEY idx_demo_sessions_email_hash (email_hash, created_at),
    KEY idx_demo_sessions_phone_hash (phone_hash, created_at),
    KEY idx_demo_sessions_ip_hash (ip_hash, created_at),
    CONSTRAINT fk_demo_sessions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_demo_sessions_guest_user FOREIGN KEY (guest_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_session_entities (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    demo_session_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    table_name VARCHAR(80) NOT NULL,
    record_id BIGINT NOT NULL,
    visibility ENUM('public_seed', 'session_private', 'operator_only') NOT NULL DEFAULT 'session_private',
    cleanup_status ENUM('active', 'purged', 'failed', 'retained') NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demo_session_entity (demo_session_id, table_name, record_id),
    KEY idx_demo_entities_workspace_table (workspace_id, table_name, record_id),
    KEY idx_demo_entities_cleanup (cleanup_status, created_at),
    CONSTRAINT fk_demo_entities_session FOREIGN KEY (demo_session_id) REFERENCES demo_visitor_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_entities_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_realtime_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    demo_session_id BIGINT NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT NULL,
    payload_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_demo_realtime_session_id (demo_session_id, id),
    KEY idx_demo_realtime_workspace_created (workspace_id, created_at),
    KEY idx_demo_realtime_type (event_type, created_at),
    CONSTRAINT fk_demo_realtime_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_realtime_session FOREIGN KEY (demo_session_id) REFERENCES demo_visitor_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE communications
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE conversation_threads
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE emails
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE email_queue
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE whatsapp_messages
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE whatsapp_queue
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE activities
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS demo_visibility ENUM('public_seed', 'session_private', 'operator_only') NULL AFTER workspace_id,
    ADD COLUMN IF NOT EXISTS demo_session_id BIGINT NULL AFTER demo_visibility;

ALTER TABLE contacts
    ADD INDEX IF NOT EXISTS idx_contacts_demo_scope (workspace_id, demo_visibility, demo_session_id);

ALTER TABLE communications
    ADD INDEX IF NOT EXISTS idx_communications_demo_scope (workspace_id, demo_visibility, demo_session_id, created_at),
    ADD INDEX IF NOT EXISTS idx_communications_demo_thread (workspace_id, demo_session_id, thread_key);

ALTER TABLE conversation_threads
    ADD INDEX IF NOT EXISTS idx_conversation_threads_demo_scope (workspace_id, demo_visibility, demo_session_id, last_message_at);

ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notifications_demo_scope (workspace_id, demo_visibility, demo_session_id, user_id, created_at);

ALTER TABLE emails
    ADD INDEX IF NOT EXISTS idx_emails_demo_scope (workspace_id, demo_visibility, demo_session_id, created_at);

ALTER TABLE email_queue
    ADD INDEX IF NOT EXISTS idx_email_queue_demo_scope (workspace_id, demo_visibility, demo_session_id, status);

ALTER TABLE whatsapp_messages
    ADD INDEX IF NOT EXISTS idx_whatsapp_messages_demo_scope (workspace_id, demo_visibility, demo_session_id, created_at);

ALTER TABLE whatsapp_queue
    ADD INDEX IF NOT EXISTS idx_whatsapp_queue_demo_scope (workspace_id, demo_visibility, demo_session_id, status);

ALTER TABLE activities
    ADD INDEX IF NOT EXISTS idx_activities_demo_scope (workspace_id, demo_visibility, demo_session_id, created_at);

ALTER TABLE tasks
    ADD INDEX IF NOT EXISTS idx_tasks_demo_scope (workspace_id, demo_visibility, demo_session_id, created_at);

ALTER TABLE deals
    ADD INDEX IF NOT EXISTS idx_deals_demo_scope (workspace_id, demo_visibility, demo_session_id, created_at);
