-- Normalize the legacy admin_ops role into the canonical superadmin role.

UPDATE roles
SET slug = 'superadmin',
    name = 'Super Admin',
    description = 'Top-level system access for seeded and bootstrap administrators',
    is_system = TRUE,
    is_active = TRUE
WHERE slug = 'admin_ops'
  AND NOT EXISTS (
      SELECT 1
      FROM (
          SELECT id
          FROM roles
          WHERE slug = 'superadmin'
      ) AS canonical_superadmin
  );

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Super Admin', 'superadmin', 'Top-level system access for seeded and bootstrap administrators', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

UPDATE users
SET role = 'admin'
WHERE role = 'admin_ops';

UPDATE user_roles ur
JOIN roles legacy ON legacy.id = ur.role_id AND legacy.slug = 'admin_ops'
JOIN roles canonical ON canonical.slug = 'superadmin'
SET ur.role_id = canonical.id,
    ur.updated_at = NOW();

DELETE rp
FROM role_permissions rp
JOIN roles legacy ON legacy.id = rp.role_id
JOIN roles canonical ON canonical.slug = 'superadmin'
WHERE legacy.slug = 'admin_ops'
  AND legacy.id <> canonical.id;

DELETE r
FROM roles r
JOIN (
    SELECT id
    FROM roles
    WHERE slug = 'superadmin'
) canonical ON 1 = 1
WHERE r.slug = 'admin_ops'
  AND r.id <> canonical.id;

UPDATE roles
SET name = 'Super Admin',
    description = 'Top-level system access for seeded and bootstrap administrators',
    is_system = TRUE,
    is_active = TRUE
WHERE slug = 'superadmin';

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'superadmin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
