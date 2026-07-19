-- Restore workspace owners to a full workspace administration profile.
-- Owners keep owner-facing Settings access via the allowlist, so global
-- Settings permissions stay limited to invoicing.

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT owner_role.id, p.id, 1
FROM roles owner_role
JOIN roles admin_role ON admin_role.slug = 'admin'
JOIN role_permissions admin_rp ON admin_rp.role_id = admin_role.id AND admin_rp.can_access = 1
JOIN permissions p ON p.id = admin_rp.permission_id
WHERE owner_role.slug = 'owner'
  AND p.permission_key NOT LIKE 'platform.%'
  AND p.permission_key NOT LIKE 'operator.%'
  AND p.permission_key NOT IN ('admin.audit_logs.view', 'billing.trials.manage')
  AND (
      p.permission_key NOT LIKE 'settings.%'
      OR p.permission_key = 'settings.invoicing'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'workspace.skills.view',
    'workspace.skills.manage',
    'feature.automation_readiness_card',
    'settings.invoicing'
)
WHERE r.slug = 'owner'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'owner'
  AND (
      p.permission_key LIKE 'platform.%'
      OR p.permission_key LIKE 'operator.%'
      OR p.permission_key IN ('admin.audit_logs.view', 'billing.trials.manage')
      OR (
          p.permission_key LIKE 'settings.%'
          AND p.permission_key <> 'settings.invoicing'
      )
  );
