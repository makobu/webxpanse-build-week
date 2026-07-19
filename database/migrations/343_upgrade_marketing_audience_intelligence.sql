-- Marketing Phase 53: CRM audience intelligence.

ALTER TABLE marketing_audience_segments
    ADD COLUMN IF NOT EXISTS crm_preview_json JSON NULL AFTER preview_count,
    ADD COLUMN IF NOT EXISTS fit_score INT NOT NULL DEFAULT 0 AFTER crm_preview_json,
    ADD COLUMN IF NOT EXISTS fit_score_json JSON NULL AFTER fit_score,
    ADD COLUMN IF NOT EXISTS stale_reason VARCHAR(255) NULL AFTER fit_score_json,
    ADD COLUMN IF NOT EXISTS last_intelligence_at DATETIME NULL AFTER stale_reason,
    ADD INDEX IF NOT EXISTS idx_marketing_segments_workspace_fit (workspace_id, fit_score, last_intelligence_at);

CREATE TABLE IF NOT EXISTS marketing_audience_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    recommendation_type ENUM('lead_source','lifecycle_stage','engagement','form_activity','deal_stage','handoff_outcome','tag') NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    rule_suggestions_json JSON NULL,
    fit_score INT NOT NULL DEFAULT 0,
    status ENUM('suggested','accepted','dismissed','archived') NOT NULL DEFAULT 'suggested',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_audience_recommendation_uuid (uuid),
    UNIQUE KEY uniq_marketing_audience_recommendation_title (workspace_id, recommendation_type, title),
    KEY idx_marketing_audience_recommendations_workspace_status (workspace_id, status, fit_score),
    KEY idx_marketing_audience_recommendations_workspace_type (workspace_id, recommendation_type, created_at),
    CONSTRAINT fk_marketing_audience_recommendations_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_audience_recommendations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
