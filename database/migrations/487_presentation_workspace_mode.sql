-- Configurable presentation workspace mode.
-- Idempotent: creates presentation pitch session tables, seed tracking,
-- delivery audit, permissions, and the temporary presentation-owner role.

CREATE TABLE IF NOT EXISTS presentation_sessions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    session_uuid CHAR(36) NOT NULL,
    workspace_id INT NOT NULL,
    presenter_user_id INT NOT NULL,
    temporary_user_id INT NULL,
    pitch_title VARCHAR(180) NULL,
    prospect_name VARCHAR(180) NULL,
    prospect_company VARCHAR(255) NULL,
    encrypted_email TEXT NULL,
    encrypted_phone TEXT NULL,
    email_hash CHAR(64) NULL,
    phone_hash CHAR(64) NULL,
    audience_key VARCHAR(80) NULL,
    seed_pack_key VARCHAR(80) NOT NULL,
    feature_emphasis_json JSON NULL,
    delivery_mode VARCHAR(40) NOT NULL DEFAULT 'simulated_first',
    live_armed TINYINT(1) NOT NULL DEFAULT 0,
    live_armed_at DATETIME NULL,
    live_armed_by INT NULL,
    status ENUM('active','revoked','expired','archived') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_by INT NULL,
    archived_at DATETIME NULL,
    archived_by INT NULL,
    last_seen_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_presentation_sessions_uuid (session_uuid),
    KEY idx_presentation_sessions_workspace (workspace_id, status, expires_at),
    KEY idx_presentation_sessions_presenter (presenter_user_id, created_at),
    KEY idx_presentation_sessions_temp_user (temporary_user_id, status),
    CONSTRAINT fk_presentation_sessions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_sessions_presenter FOREIGN KEY (presenter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_sessions_temp_user FOREIGN KEY (temporary_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presentation_access_tokens (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    presentation_session_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('active','used','revoked','expired') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_presentation_access_token_hash (token_hash),
    KEY idx_presentation_tokens_session (presentation_session_id, status, expires_at),
    KEY idx_presentation_tokens_workspace (workspace_id, status, expires_at),
    CONSTRAINT fk_presentation_tokens_session FOREIGN KEY (presentation_session_id) REFERENCES presentation_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_tokens_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presentation_recipients (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    presentation_session_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    encrypted_label TEXT NULL,
    encrypted_email TEXT NULL,
    encrypted_phone TEXT NULL,
    email_hash CHAR(64) NULL,
    phone_hash CHAR(64) NULL,
    can_email TINYINT(1) NOT NULL DEFAULT 0,
    can_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    consent_source VARCHAR(120) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_presentation_recipients_session (presentation_session_id, can_email, can_whatsapp),
    KEY idx_presentation_recipients_email (workspace_id, email_hash),
    KEY idx_presentation_recipients_phone (workspace_id, phone_hash),
    CONSTRAINT fk_presentation_recipients_session FOREIGN KEY (presentation_session_id) REFERENCES presentation_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_recipients_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presentation_delivery_audit (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    presentation_session_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    actor_user_id INT NULL,
    recipient_id BIGINT NULL,
    channel ENUM('email','whatsapp') NOT NULL,
    delivery_kind VARCHAR(60) NOT NULL DEFAULT 'scenario',
    scenario_key VARCHAR(80) NULL,
    live_requested TINYINT(1) NOT NULL DEFAULT 0,
    live_sent TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('simulated','blocked','success','failed') NOT NULL DEFAULT 'simulated',
    provider_message_id VARCHAR(255) NULL,
    recipient_hash CHAR(64) NULL,
    payload_hash CHAR(64) NULL,
    subject VARCHAR(255) NULL,
    error_message TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_presentation_delivery_session (presentation_session_id, channel, created_at),
    KEY idx_presentation_delivery_workspace_day (workspace_id, channel, created_at),
    KEY idx_presentation_delivery_status (status, live_requested, created_at),
    CONSTRAINT fk_presentation_delivery_session FOREIGN KEY (presentation_session_id) REFERENCES presentation_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_delivery_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presentation_seed_runs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    presentation_session_id BIGINT NULL,
    seed_pack_key VARCHAR(80) NOT NULL,
    status ENUM('running','completed','failed','reset') NOT NULL DEFAULT 'running',
    reset_existing TINYINT(1) NOT NULL DEFAULT 0,
    entity_count INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_presentation_seed_runs_workspace (workspace_id, seed_pack_key, created_at),
    KEY idx_presentation_seed_runs_session (presentation_session_id, status),
    CONSTRAINT fk_presentation_seed_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_seed_runs_session FOREIGN KEY (presentation_session_id) REFERENCES presentation_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presentation_seed_entities (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    seed_run_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    table_name VARCHAR(80) NOT NULL,
    record_id BIGINT NOT NULL,
    record_key VARCHAR(160) NULL,
    cleanup_status ENUM('active','deleted','skipped') NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_presentation_seed_entity (seed_run_id, table_name, record_id),
    KEY idx_presentation_seed_entities_workspace (workspace_id, table_name, cleanup_status),
    CONSTRAINT fk_presentation_seed_entities_run FOREIGN KEY (seed_run_id) REFERENCES presentation_seed_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_seed_entities_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('presentation.workspace.create', 'Create Presentation Workspaces', 'Create fresh audience-specific workspaces for supervised pitches', TRUE),
('presentation.workspace.manage', 'Manage Presentation Workspaces', 'Manage presentation workspace sessions, seed packs, and temporary access', TRUE),
('presentation.workspace.archive', 'Archive Presentation Workspaces', 'Archive or expire supervised presentation workspaces', TRUE),
('presentation.live_send', 'Send Live Presentation Messages', 'Arm and send allowlisted live email or WhatsApp messages during a supervised presentation', TRUE),
('presentation.audit', 'View Presentation Audit', 'View presentation recipient, access, seed, and delivery audit history', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Presentation Owner', 'presentation_owner', 'Temporary owner-like access for supervised presentation workspaces without billing, export, invite, integration, tenant-delete, permanent-user, or platform-administration powers', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

SET @presentation_owner_role_id := (SELECT id FROM roles WHERE slug = 'presentation_owner' LIMIT 1);
SET @owner_role_id := (SELECT id FROM roles WHERE slug = 'owner' LIMIT 1);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT @presentation_owner_role_id, p.id, 1
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role_id = @owner_role_id
  AND rp.can_access = 1
  AND @presentation_owner_role_id IS NOT NULL
  AND p.permission_key NOT LIKE 'billing.%'
  AND p.permission_key NOT LIKE 'platform.%'
  AND p.permission_key NOT LIKE 'operator.%'
  AND p.permission_key NOT LIKE 'admin.%'
  AND p.permission_key NOT LIKE 'settings.api_keys%'
  AND p.permission_key NOT LIKE 'settings.whatsapp%'
  AND p.permission_key NOT LIKE 'settings.email%'
  AND p.permission_key NOT LIKE 'settings.company%'
  AND p.permission_key NOT LIKE 'settings.billing%'
  AND p.permission_key NOT LIKE 'workspace.invite%'
  AND p.permission_key NOT LIKE 'workspace.user%'
  AND p.permission_key NOT LIKE 'workspace.members%'
  AND p.permission_key NOT LIKE 'workspace.delete%'
  AND p.permission_key NOT LIKE 'exports.%'
  AND p.permission_key NOT LIKE 'integrations.%'
  AND p.permission_key NOT LIKE 'crm.custom_fields.manage'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT @presentation_owner_role_id, p.id, 1
FROM permissions p
WHERE @presentation_owner_role_id IS NOT NULL
  AND p.permission_key IN (
      'feature.automation_battery',
      'feature.automation_readiness',
      'tasks.read',
      'tasks.write',
      'contacts.view_all',
      'contacts.write',
      'conversations.view_all',
      'conversations.write',
      'events.read',
      'events.write',
      'notifications.view_all',
      'workspace.skills.view',
      'reports.nl_generate',
      'invoices.view',
      'deals.view_all',
      'deals.write'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'presentation.workspace.create',
    'presentation.workspace.manage',
    'presentation.workspace.archive',
    'presentation.live_send',
    'presentation.audit'
)
WHERE r.slug IN ('superadmin', 'admin', 'admin_ops', 'owner', 'demo_presenter')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
