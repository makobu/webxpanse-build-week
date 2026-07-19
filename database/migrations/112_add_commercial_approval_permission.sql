INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('commercial_automation.approvals', 'Commercial Automation Approvals', 'View and resolve commercial automation approvals', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'commercial_automation.approvals'
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
