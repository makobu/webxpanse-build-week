-- Workspace-scoped contact scoring configuration and persisted score metadata.

CREATE TABLE IF NOT EXISTS workspace_scoring_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    default_score_weights JSON NOT NULL,
    auto_use_recommended_weights TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_scoring_config_workspace (workspace_id),
    CONSTRAINT fk_workspace_scoring_config_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_scoring_config (workspace_id, default_score_weights, auto_use_recommended_weights)
SELECT
    w.id,
    COALESCE(ec.default_score_weights, JSON_OBJECT('engagement', 0.4, 'ml', 0.4, 'ai', 0.2)),
    0
FROM workspaces w
LEFT JOIN enrichment_config ec ON ec.id = 1
ON DUPLICATE KEY UPDATE
    default_score_weights = VALUES(default_score_weights),
    updated_at = CURRENT_TIMESTAMP;

ALTER TABLE contacts ADD COLUMN score_recalculated_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE contacts ADD COLUMN score_metadata_json JSON NULL;
CREATE INDEX idx_contacts_score_recalculated_at ON contacts(score_recalculated_at);
