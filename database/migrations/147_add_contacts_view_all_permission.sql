INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES ('contacts.view_all', 'View All Contacts', 'Allow switching the Contacts view filter to All Contacts', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'contacts.view_all'
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
