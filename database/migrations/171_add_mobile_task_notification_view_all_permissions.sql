INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('tasks.view_all', 'View All Tasks', 'Allow viewing all tasks in mobile and scoped task surfaces', FALSE),
    ('notifications.view_all', 'View All Notifications', 'Allow viewing all in-app notifications in mobile notification feeds', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('tasks.view_all', 'notifications.view_all')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
