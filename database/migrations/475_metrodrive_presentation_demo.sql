-- MetroDrive Academy protected presentation demo.
-- Idempotent: creates the supervised pitch workspace, temporary owner role,
-- presentation-session controls, and live delivery audit tables.

INSERT INTO workspaces (uuid, name, slug, status, plan_status, settings_json, created_by)
SELECT
    '00000000-0000-4000-8000-000000000475',
    'MetroDrive Academy',
    'metrodrive-demo',
    'active',
    'inactive',
    JSON_OBJECT(
        'protected_demo_workspace', TRUE,
        'demo_workspace', TRUE,
        'presentation_demo_workspace', TRUE,
        'billing_disabled', TRUE,
        'invites_disabled', TRUE,
        'exports_disabled', TRUE,
        'real_integrations_disabled', TRUE
    ),
    NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM workspaces WHERE slug = 'metrodrive-demo' OR uuid = '00000000-0000-4000-8000-000000000475'
);

UPDATE workspaces
SET
    name = 'MetroDrive Academy',
    slug = 'metrodrive-demo',
    status = 'active',
    plan_status = 'inactive',
    settings_json = JSON_SET(
        COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
        '$.protected_demo_workspace', TRUE,
        '$.demo_workspace', TRUE,
        '$.presentation_demo_workspace', TRUE,
        '$.billing_disabled', TRUE,
        '$.invites_disabled', TRUE,
        '$.exports_disabled', TRUE,
        '$.real_integrations_disabled', TRUE
    ),
    updated_at = NOW()
WHERE slug = 'metrodrive-demo'
   OR uuid = '00000000-0000-4000-8000-000000000475';

SET @metrodrive_demo_workspace_id := (
    SELECT id
    FROM workspaces
    WHERE slug = 'metrodrive-demo'
       OR uuid = '00000000-0000-4000-8000-000000000475'
    ORDER BY CASE WHEN slug = 'metrodrive-demo' THEN 0 ELSE 1 END, id ASC
    LIMIT 1
);

INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
SELECT @metrodrive_demo_workspace_id, 'metrodrive-demo', 1
FROM DUAL
WHERE @metrodrive_demo_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id),
    is_primary = VALUES(is_primary);

ALTER TABLE demo_visitor_sessions
    ADD COLUMN IF NOT EXISTS presentation_pitch TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_mode,
    ADD COLUMN IF NOT EXISTS demo_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER presentation_pitch,
    ADD COLUMN IF NOT EXISTS presenter_created TINYINT(1) NOT NULL DEFAULT 0 AFTER demo_owner,
    ADD COLUMN IF NOT EXISTS magic_link TINYINT(1) NOT NULL DEFAULT 0 AFTER presenter_created,
    ADD COLUMN IF NOT EXISTS live_presentation TINYINT(1) NOT NULL DEFAULT 0 AFTER magic_link,
    ADD COLUMN IF NOT EXISTS live_armed_at DATETIME NULL AFTER live_presentation,
    ADD COLUMN IF NOT EXISTS live_armed_by INT NULL AFTER live_armed_at,
    ADD COLUMN IF NOT EXISTS presentation_company VARCHAR(255) NULL AFTER live_armed_by,
    ADD COLUMN IF NOT EXISTS presentation_mode VARCHAR(40) NULL AFTER presentation_company;

ALTER TABLE demo_visitor_sessions
    ADD KEY IF NOT EXISTS idx_demo_sessions_presentation (workspace_id, presentation_pitch, status, expires_at),
    ADD KEY IF NOT EXISTS idx_demo_sessions_live_armed (workspace_id, live_presentation, live_armed_at);

CREATE TABLE IF NOT EXISTS demo_presentation_access_tokens (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    demo_session_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('active','used','revoked','expired') NOT NULL DEFAULT 'active',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demo_presentation_token_hash (token_hash),
    KEY idx_demo_presentation_tokens_session (demo_session_id, status, expires_at),
    KEY idx_demo_presentation_tokens_workspace (workspace_id, status, expires_at),
    CONSTRAINT fk_demo_presentation_tokens_session FOREIGN KEY (demo_session_id) REFERENCES demo_visitor_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_presentation_tokens_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_presentation_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_presentation_recipients (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    demo_session_id BIGINT NOT NULL,
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
    KEY idx_demo_presentation_recipients_session (demo_session_id, can_email, can_whatsapp),
    KEY idx_demo_presentation_recipients_email (workspace_id, email_hash),
    KEY idx_demo_presentation_recipients_phone (workspace_id, phone_hash),
    CONSTRAINT fk_demo_presentation_recipients_session FOREIGN KEY (demo_session_id) REFERENCES demo_visitor_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_presentation_recipients_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_presentation_delivery_audit (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    demo_session_id BIGINT NOT NULL,
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
    KEY idx_demo_delivery_session (demo_session_id, channel, created_at),
    KEY idx_demo_delivery_workspace_day (workspace_id, channel, created_at),
    KEY idx_demo_delivery_status (status, live_requested, created_at),
    CONSTRAINT fk_demo_delivery_session FOREIGN KEY (demo_session_id) REFERENCES demo_visitor_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_delivery_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('demo.presentation.create', 'Create Presentation Demo', 'Create supervised MetroDrive presentation demo sessions', TRUE),
('demo.presentation.live_send', 'Send Live Presentation Messages', 'Arm and send allowlisted live email or WhatsApp messages during a supervised presentation', TRUE),
('demo.presentation.revoke', 'Revoke Presentation Demo', 'Revoke or expire supervised presentation demo sessions', TRUE),
('demo.presentation.audit', 'View Presentation Demo Audit', 'View presentation recipient and delivery audit state', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Demo Owner', 'demo_owner', 'Temporary owner-like access for supervised protected presentation demos', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

SET @demo_owner_role_id := (SELECT id FROM roles WHERE slug = 'demo_owner' LIMIT 1);
SET @owner_role_id := (SELECT id FROM roles WHERE slug = 'owner' LIMIT 1);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT @demo_owner_role_id, p.id, 1
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role_id = @owner_role_id
  AND rp.can_access = 1
  AND @demo_owner_role_id IS NOT NULL
  AND p.permission_key NOT LIKE 'billing.%'
  AND p.permission_key NOT LIKE 'platform.%'
  AND p.permission_key NOT LIKE 'operator.%'
  AND p.permission_key NOT LIKE 'admin.users.%'
  AND p.permission_key NOT LIKE 'admin.audit%'
  AND p.permission_key NOT LIKE 'settings.api_keys%'
  AND p.permission_key NOT LIKE 'settings.whatsapp%'
  AND p.permission_key NOT LIKE 'settings.email%'
  AND p.permission_key NOT LIKE 'settings.company%'
  AND p.permission_key NOT LIKE 'workspace.invite%'
  AND p.permission_key NOT LIKE 'workspace.user%'
  AND p.permission_key NOT LIKE 'workspace.members%'
  AND p.permission_key NOT LIKE 'exports.%'
  AND p.permission_key NOT LIKE 'crm.custom_fields.manage'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT @demo_owner_role_id, p.id, 1
FROM permissions p
WHERE @demo_owner_role_id IS NOT NULL
  AND p.permission_key IN (
      'demo.workspace.access',
      'demo.channel.simulate',
      'demo.realtime.read',
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
      'invoices.view'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'demo.presentation.create',
    'demo.presentation.live_send',
    'demo.presentation.revoke',
    'demo.presentation.audit'
)
WHERE r.slug IN ('superadmin', 'admin', 'admin_ops', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
