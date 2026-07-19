INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('workflows', 'Workflows page guide', NULL, 0),
    ('calendar', 'Calendar page guide', NULL, 0),
    ('campaigns', 'Campaign Automation page guide', NULL, 0),
    ('reports', 'Reports page guide', NULL, 0),
    ('docs', 'Feature Documentation page guide', NULL, 0),
    ('users', 'Users page guide', NULL, 0),
    ('roles', 'Access Profiles page guide', NULL, 0),
    ('ai_control_center', 'AI Control Center page guide', NULL, 0),
    ('hr_analytics', 'Organization Intelligence page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
