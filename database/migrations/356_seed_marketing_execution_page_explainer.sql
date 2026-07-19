-- Marketing Phase 67: execution control center page guide seed.

INSERT INTO marketplace_page_explainers (page_key, label, video_url, is_active)
VALUES ('marketing_execution', 'Marketing Execution Control Center page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = CURRENT_TIMESTAMP;
