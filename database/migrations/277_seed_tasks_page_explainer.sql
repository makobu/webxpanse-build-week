INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES (
    'tasks',
    'Tasks page guide',
    NULL,
    0
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
