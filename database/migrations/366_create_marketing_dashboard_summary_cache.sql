-- Marketing Phase 54: short-lived command-center summary cache for page speed.

CREATE TABLE IF NOT EXISTS marketing_dashboard_summary_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    cache_key VARCHAR(190) NOT NULL,
    payload_json JSON NOT NULL,
    query_count INT NOT NULL DEFAULT 0,
    build_ms DECIMAL(10,2) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    cache_metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_dashboard_cache_key (workspace_id, cache_key),
    KEY idx_marketing_dashboard_cache_workspace_expiry (workspace_id, expires_at),
    CONSTRAINT fk_marketing_dashboard_cache_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
