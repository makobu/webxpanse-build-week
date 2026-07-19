-- Narrow product-story read access for protected demo viewers.
-- Keep destructive, billing, integration, invite, export, manage, and view-all
-- permissions out of this role.

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'tasks.read',
    'workspace.skills.view',
    'startup_journey.view',
    'founder_loop.view',
    'feature.automation_battery'
)
WHERE r.slug = 'demo_viewer'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
