INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Super Admin', 'superadmin', 'Top-level system access for seeded and bootstrap administrators', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'superadmin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
