-- Keep workspace-owner Settings access limited to owner-facing workspace surfaces.

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('settings.invoicing', 'Invoicing Settings', 'Manage invoicing settings tab', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'owner'
  AND p.permission_key LIKE 'settings.%'
  AND p.permission_key <> 'settings.invoicing';

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'settings.invoicing'
WHERE r.slug = 'owner'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
