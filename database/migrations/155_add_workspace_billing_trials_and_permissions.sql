ALTER TABLE workspace_billing_settings
    MODIFY COLUMN workspace_status ENUM('current', 'trialing', 'payment_due', 'grace', 'locked') NOT NULL DEFAULT 'current';

ALTER TABLE workspace_billing_settings
    ADD COLUMN trial_starts_at DATETIME NULL AFTER last_paid_at;

ALTER TABLE workspace_billing_settings
    ADD COLUMN trial_ends_at DATETIME NULL AFTER trial_starts_at;

ALTER TABLE workspace_billing_settings
    ADD COLUMN trial_granted_by INT NULL AFTER trial_ends_at;

ALTER TABLE workspace_billing_settings
    ADD COLUMN trial_notes TEXT NULL AFTER trial_granted_by;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('billing.edit', 'Edit Workspace Billing', 'Edit recurring plans and workspace billing charges', TRUE),
('billing.trials.manage', 'Manage Billing Trials', 'Grant and extend workspace billing trials', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'billing.edit',
    'billing.trials.manage'
)
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
