INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES
    ('lead_scoring', 'Lead Scoring page guide', NULL, 0),
    ('enrichment_dashboard', 'Enrichment Dashboard page guide', NULL, 0),
    ('predictive_analytics', 'Predictive Analytics page guide', NULL, 0),
    ('workflow_analytics', 'Workflow Analytics page guide', NULL, 0),
    ('attribution_reports', 'Attribution Reports page guide', NULL, 0),
    ('draft_reviews', 'Draft Reviews page guide', NULL, 0),
    ('email_assistant_runs', 'Email Assistant Runs page guide', NULL, 0),
    ('commercial_approvals', 'Commercial Approvals page guide', NULL, 0)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
