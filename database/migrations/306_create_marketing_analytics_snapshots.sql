-- Marketing Phase 11: analytics snapshots for reporting.

CREATE TABLE IF NOT EXISTS marketing_analytics_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    period_type ENUM('weekly','monthly') NOT NULL DEFAULT 'weekly',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    content_velocity_json JSON NULL,
    review_bottlenecks_json JSON NULL,
    campaign_performance_json JSON NULL,
    landing_page_conversion_json JSON NULL,
    form_submissions_json JSON NULL,
    utm_usage_json JSON NULL,
    attributed_revenue_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_analytics_uuid (uuid),
    KEY idx_marketing_analytics_workspace_period (workspace_id, period_type, period_start, period_end),
    CONSTRAINT fk_marketing_analytics_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_analytics_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
