INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'settings.integrations',
    'settings.api_keys',
    'settings.webhooks',
    'drafts.manage',
    'reports.nl_generate'
)
WHERE r.slug = 'superadmin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
