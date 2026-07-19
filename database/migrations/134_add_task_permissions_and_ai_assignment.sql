INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('tasks.read', 'Read Tasks', 'View task pages and task details', FALSE),
('tasks.write', 'Write Tasks', 'Create, edit, and complete tasks', FALSE),
('tasks.reassign', 'Reassign Tasks', 'Change task assignees', TRUE),
('tasks.ai_assignable', 'Eligible For AI Task Assignment', 'Allow AI and autonomy features to assign tasks to this role', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'admin'
  AND p.permission_key IN ('tasks.read', 'tasks.write', 'tasks.reassign', 'tasks.ai_assignable')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'owner'
  AND p.permission_key IN ('tasks.read', 'tasks.write', 'tasks.reassign', 'tasks.ai_assignable')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'sales'
  AND p.permission_key IN ('tasks.read', 'tasks.write', 'tasks.reassign', 'tasks.ai_assignable')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'marketing'
  AND p.permission_key IN ('tasks.read', 'tasks.write')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'viewer'
  AND p.permission_key IN ('tasks.read')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
