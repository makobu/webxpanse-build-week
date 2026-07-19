-- First-class access controls for onboarding and pricing surfaces.

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('pages.onboarding.view', 'View Onboarding Launchpad', 'Access the launchpad onboarding and implementation workspace', FALSE),
('pages.pricing.view', 'View Pricing Page', 'Access the internal pricing and package explanation page', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('pages.onboarding.view', 'pages.pricing.view')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
