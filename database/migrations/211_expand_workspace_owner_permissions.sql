-- Give workspace owners full workspace administration without platform-wide powers.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','owner','sales','marketing','viewer') DEFAULT 'viewer';

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'admin.users.manage',
    'admin.users.edit',
    'admin.users.delete',
    'admin.roles.manage',
    'org.departments.manage',
    'pages.onboarding.view',
    'pages.pricing.view',
    'settings.general',
    'settings.email',
    'settings.email_assistant',
    'settings.whatsapp',
    'settings.ai',
    'settings.calendar',
    'settings.sms',
    'settings.ai_autoresponder',
    'settings.company',
    'settings.invoicing',
    'settings.billing',
    'settings.deal_automation',
    'settings.meeting_bot',
    'settings.meeting_note_taker',
    'settings.workflow_automation',
    'settings.commercial_automation',
    'billing.view',
    'billing.edit',
    'billing.manage',
    'feature.workspace_billing',
    'feature.invoices',
    'feature.automation_readiness',
    'feature.automation_battery',
    'feature.automation_readiness_card',
    'meeting_bot.view_runs',
    'meeting_bot.schedule',
    'meeting_notes.view_runs',
    'commercial_automation.approvals',
    'contacts.view_all',
    'conversations.view_all',
    'analytics.view_all',
    'tasks.view_all',
    'notifications.view_all',
    'events.view_all',
    'tasks.read',
    'tasks.write',
    'tasks.reassign',
    'tasks.ai_assignable'
)
WHERE r.slug = 'owner'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.slug = 'owner'
  AND (
    p.permission_key IN (
        'admin.audit_logs.view',
        'billing.trials.manage',
        'platform.settings.manage',
        'platform.system.reset',
        'platform.users.view'
    )
    OR p.permission_key LIKE 'platform.%'
    OR p.permission_key LIKE 'operator.%'
  );

UPDATE workspace_memberships
SET role_slug = 'owner',
    is_owner = 1,
    updated_at = NOW()
WHERE membership_status = 'active'
  AND is_owner = 1;

INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
SELECT wm.workspace_id, wm.user_id, r.id, COALESCE(wm.invited_by, wm.user_id)
FROM workspace_memberships wm
JOIN roles r ON r.slug = 'owner'
WHERE wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
ON DUPLICATE KEY UPDATE
    role_id = VALUES(role_id),
    assigned_by = VALUES(assigned_by),
    updated_at = NOW();

UPDATE user_roles ur
JOIN roles cr ON cr.id = ur.role_id
JOIN roles owner_role ON owner_role.slug = 'owner'
JOIN users u ON u.id = ur.user_id
LEFT JOIN roles super_role ON super_role.slug = 'superadmin'
LEFT JOIN user_roles super_ur
    ON super_ur.user_id = ur.user_id
   AND super_ur.role_id = super_role.id
SET ur.role_id = owner_role.id,
    ur.updated_at = NOW(),
    u.role = 'owner'
WHERE cr.slug = 'admin'
  AND EXISTS (
      SELECT 1
      FROM workspace_memberships wm
      WHERE wm.user_id = ur.user_id
        AND wm.membership_status = 'active'
        AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
  )
  AND super_ur.user_id IS NULL;
