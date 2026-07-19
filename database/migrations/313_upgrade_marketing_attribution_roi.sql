-- Marketing Phase 19: attribution and ROI reporting upgrade.

ALTER TABLE marketing_analytics_snapshots
    ADD COLUMN IF NOT EXISTS campaign_roi_json JSON NULL AFTER campaign_performance_json,
    ADD COLUMN IF NOT EXISTS content_influence_json JSON NULL AFTER campaign_roi_json,
    ADD COLUMN IF NOT EXISTS landing_page_funnel_json JSON NULL AFTER landing_page_conversion_json,
    ADD COLUMN IF NOT EXISTS form_conversion_json JSON NULL AFTER form_submissions_json,
    ADD COLUMN IF NOT EXISTS utm_performance_json JSON NULL AFTER utm_usage_json,
    ADD COLUMN IF NOT EXISTS revenue_attribution_json JSON NULL AFTER attributed_revenue_json,
    ADD INDEX IF NOT EXISTS idx_marketing_analytics_workspace_created (workspace_id, created_at);
