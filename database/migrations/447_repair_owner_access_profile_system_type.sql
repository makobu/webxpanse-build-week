-- Repair drift where the built-in Owner access profile is shown as Custom.
UPDATE roles
SET is_system = 1,
    is_active = 1,
    updated_at = NOW()
WHERE slug = 'owner';
