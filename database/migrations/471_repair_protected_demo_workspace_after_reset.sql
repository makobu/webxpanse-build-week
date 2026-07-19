-- Repair protected demo workspace baseline after platform/menu reset.
-- This migration is intentionally idempotent and does not create visitor sessions.

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
    slug = 'protected-demo',
    status = 'active',
    plan_status = 'inactive',
    settings_json = JSON_SET(
        COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
        '$.protected_demo_workspace', TRUE,
        '$.demo_workspace', TRUE,
        '$.billing_disabled', TRUE,
        '$.invites_disabled', TRUE,
        '$.exports_disabled', TRUE,
        '$.real_integrations_disabled', TRUE
    ),
    updated_at = NOW()
WHERE slug = 'protected-demo'
   OR uuid = '00000000-0000-4000-8000-000000000461';

SET @protected_demo_workspace_id := (
    SELECT id
    FROM workspaces
    WHERE slug = 'protected-demo'
       OR uuid = '00000000-0000-4000-8000-000000000461'
    ORDER BY CASE WHEN slug = 'protected-demo' THEN 0 ELSE 1 END, id ASC
    LIMIT 1
);

INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
SELECT @protected_demo_workspace_id, 'protected-demo', 1
FROM DUAL
WHERE @protected_demo_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id),
    is_primary = VALUES(is_primary);

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('demo.workspace.access', 'Access Demo Workspace', 'Enter the protected shared demo workspace', FALSE),
('demo.channel.simulate', 'Simulate Demo Messages', 'Create simulated email and WhatsApp demo messages', FALSE),
('demo.realtime.read', 'Read Demo Realtime Events', 'Read private realtime events for an active demo session', FALSE),
('demo.workspace.operate', 'Operate Demo Workspace', 'Manage protected demo workspace sessions, seeding, and cleanup', TRUE),
('startup_journey.view', 'View Clarity Journey', 'View the Clarity Journey product story and setup context', FALSE)
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
JOIN permissions p ON p.permission_key IN (
    'demo.workspace.access',
    'demo.channel.simulate',
    'demo.realtime.read',
    'tasks.read',
    'workspace.skills.view',
    'startup_journey.view',
    'founder_loop.view',
    'feature.automation_battery'
)
WHERE r.slug = 'demo_viewer'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
