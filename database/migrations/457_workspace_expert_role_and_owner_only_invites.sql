-- Workspace-scoped Expert role for invite-only tenant access.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','owner','accountant','expert','sales','marketing','viewer') DEFAULT 'viewer';

INSERT INTO roles (name, slug, description, is_system, is_active)
VALUES
    ('Expert', 'expert', 'Workspace-scoped specialist access for invited experts and task contributors', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT expert_role.id, rp.permission_id, rp.can_access
FROM roles expert_role
JOIN roles viewer_role ON viewer_role.slug = 'viewer'
JOIN role_permissions rp ON rp.role_id = viewer_role.id
WHERE expert_role.slug = 'expert'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('tasks.read', 'tasks.write')
WHERE r.slug = 'expert'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'expert'
  AND (
      p.permission_key LIKE 'admin.%'
      OR p.permission_key LIKE 'operator.%'
      OR p.permission_key LIKE 'platform.%'
      OR p.permission_key LIKE '%.view_all'
      OR p.permission_key IN (
          'org.departments.manage',
          'settings.billing',
          'billing.edit',
          'billing.manage',
          'billing.trials.manage',
          'tasks.reassign',
          'tasks.ai_assignable'
      )
  );
