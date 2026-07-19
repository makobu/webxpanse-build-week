INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('feature.automation_battery', 'Automation Battery', 'View the automation battery widget on dashboard', TRUE),
    ('feature.automation_readiness_card', 'Automation Readiness Card', 'View the automation readiness card on dashboard', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('feature.automation_battery', 'feature.automation_readiness_card')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
