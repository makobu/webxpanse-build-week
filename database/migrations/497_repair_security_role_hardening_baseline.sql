-- Repair security and role hardening baseline after new production-readiness permissions.
-- Keeps Super Admin canonical, sensitive permission metadata current, and demo/presentation roles scoped.

INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Super Admin', 'superadmin', 'Top-level system access for seeded and bootstrap administrators', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

UPDATE roles
SET is_system = TRUE,
    is_active = TRUE,
    updated_at = NOW()
WHERE slug = 'superadmin';

UPDATE permissions
SET is_sensitive = TRUE
WHERE permission_key IN (
        'admin.users.access',
        'admin.users.manage',
        'admin.users.edit',
        'admin.users.delete',
        'admin.roles.manage',
        'admin.audit_logs.view',
        'billing.trials.manage',
        'commercial_automation.approvals',
        'ai.operations.manage',
        'ai.prompt_control.manage',
        'platform.settings.manage',
        'platform.system.reset',
        'platform.users.view',
        'workspace.skills.manage'
    )
   OR permission_key LIKE 'admin.%'
   OR permission_key LIKE 'operator.%'
   OR permission_key LIKE 'platform.%'
   OR permission_key LIKE 'settings.api_keys%'
   OR permission_key LIKE 'settings.billing%'
   OR permission_key LIKE 'settings.email%'
   OR permission_key LIKE 'settings.whatsapp%'
   OR permission_key LIKE 'workspace.delete%'
   OR permission_key LIKE 'exports.%'
   OR permission_key LIKE 'integrations.%';

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'superadmin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug <> 'superadmin'
  AND (
       p.permission_key LIKE 'platform.%'
    OR p.permission_key LIKE 'operator.%'
    OR p.permission_key IN ('platform.settings.manage', 'platform.system.reset', 'platform.users.view')
  );

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug IN ('demo_owner', 'presentation_owner', 'demo_presenter')
  AND (
       p.permission_key LIKE 'admin.%'
    OR p.permission_key LIKE 'billing.%'
    OR p.permission_key LIKE 'exports.%'
    OR p.permission_key LIKE 'integrations.%'
    OR p.permission_key LIKE 'operator.%'
    OR p.permission_key LIKE 'platform.%'
    OR p.permission_key LIKE 'settings.%'
    OR p.permission_key LIKE 'workspace.delete%'
    OR p.permission_key LIKE 'workspace.invite%'
    OR p.permission_key LIKE 'workspace.members%'
    OR p.permission_key LIKE 'workspace.user%'
    OR p.permission_key = 'crm.custom_fields.manage'
  );
