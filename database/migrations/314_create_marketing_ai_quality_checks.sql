-- Marketing Phase 20: AI quality and brand-safety recommendations.

CREATE TABLE IF NOT EXISTS marketing_ai_quality_checks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    content_item_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    overall_score INT NOT NULL DEFAULT 0,
    brand_voice_json JSON NULL,
    persona_fit_json JSON NULL,
    compliance_json JSON NULL,
    cta_quality_json JSON NULL,
    seo_readiness_json JSON NULL,
    approval_risk_json JSON NULL,
    recommendations_json JSON NULL,
    metadata_json JSON NULL,
    status ENUM('completed','failed','archived') NOT NULL DEFAULT 'completed',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_quality_uuid (uuid),
    KEY idx_marketing_quality_workspace_content (workspace_id, content_item_id, created_at),
    KEY idx_marketing_quality_workspace_score (workspace_id, overall_score, created_at),
    KEY idx_marketing_quality_workspace_status (workspace_id, status, created_at),
    CONSTRAINT fk_marketing_quality_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_quality_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_quality_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
