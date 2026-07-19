INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('ai.tasks.follow_up', 'AI Task Follow-up Domain', 'Allow AI to assign follow-up and check-in tasks to this role', TRUE),
('ai.tasks.pricing', 'AI Task Pricing Domain', 'Allow AI to assign pricing and package tasks to this role', TRUE),
('ai.tasks.segmentation', 'AI Task Segmentation Domain', 'Allow AI to assign segmentation and ICP tasks to this role', TRUE),
('ai.tasks.review', 'AI Task Review Domain', 'Allow AI to assign general review and exception tasks to this role', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('ai.tasks.follow_up', 'ai.tasks.pricing', 'ai.tasks.segmentation', 'ai.tasks.review')
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('ai.tasks.follow_up', 'ai.tasks.pricing', 'ai.tasks.review')
WHERE r.slug = 'sales'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN ('ai.tasks.segmentation', 'ai.tasks.review')
WHERE r.slug = 'marketing'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
