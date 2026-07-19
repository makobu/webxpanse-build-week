INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES ('feature.dashboard_filter', 'Dashboard Filter', 'Access the dashboard user-scope filter to view other users'' dashboards', FALSE)
ON DUPLICATE KEY UPDATE
    label       = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'feature.dashboard_filter'
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = 1;
