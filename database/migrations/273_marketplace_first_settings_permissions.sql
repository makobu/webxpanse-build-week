-- Move workspace-owner setup permissions from Settings to Marketplace/Startup Journey.

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('workspace.skills.view', 'View Workspace Skills', 'View installed workspace skills and module catalog', FALSE),
('workspace.skills.manage', 'Manage Workspace Skills', 'Install, disable, and configure workspace skills', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'workspace.skills.view',
    'workspace.skills.manage',
    'billing.view',
    'billing.edit',
    'billing.manage',
    'feature.workspace_billing'
)
WHERE r.slug = 'owner'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'owner'
  AND p.permission_key LIKE 'settings.%';

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug <> 'superadmin'
  AND r.slug = 'owner'
  AND p.permission_key LIKE 'platform.%';
