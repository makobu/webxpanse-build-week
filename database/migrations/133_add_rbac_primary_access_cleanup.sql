INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('ai.operations.manage', 'AI Operations', 'Manage AI diagnostics, incidents, recovery, runtime controls, and orchestration', TRUE),
('ai.prompt_control.manage', 'AI Prompt Control', 'Manage AI prompt registry and prompt diagnostics', TRUE),
('admin.audit_logs.view', 'View Audit Logs', 'View audit logs and security event history', TRUE),
('campaigns.manage', 'Manage Campaigns', 'Create and manage campaigns', TRUE),
('crm.custom_fields.manage', 'Manage Custom Fields', 'Create and edit custom fields', TRUE),
('crm.currencies.manage', 'Manage Currencies', 'Manage currencies and exchange data', TRUE),
('documents.manage_all', 'Manage All Documents', 'Manage any document and document categories', TRUE),
('notes.manage_all', 'Manage All Notes', 'Manage any user note across records', TRUE),
('targets.manage_all', 'Manage All Targets', 'Manage any user targets', TRUE),
('workflows.manage', 'Manage Workflows', 'Create and manage workflows and workflow analytics', TRUE),
('contacts.merge', 'Merge Contacts', 'Merge duplicate contacts', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'admin'
  AND p.permission_key IN (
    'ai.operations.manage',
    'ai.prompt_control.manage',
    'admin.audit_logs.view',
    'campaigns.manage',
    'crm.custom_fields.manage',
    'crm.currencies.manage',
    'documents.manage_all',
    'notes.manage_all',
    'targets.manage_all',
    'workflows.manage',
    'contacts.merge'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO user_roles (user_id, role_id, assigned_by)
SELECT u.id, r.id, NULL
FROM users u
JOIN roles r ON r.slug = u.role
LEFT JOIN user_roles ur ON ur.user_id = u.id
WHERE ur.user_id IS NULL
ON DUPLICATE KEY UPDATE
    role_id = user_roles.role_id;
