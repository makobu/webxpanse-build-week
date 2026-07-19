-- Keep canonical superadmin aligned with every seeded permission after all current migrations.

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'superadmin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
