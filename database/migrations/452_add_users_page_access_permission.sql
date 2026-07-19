-- Restrict the Users page to superadmins and owner accounts with explicit access.

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('admin.users.access', 'Access Users Page', 'Open the Users page and view workspace user administration screens', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'admin.users.access'
WHERE r.slug IN ('superadmin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
