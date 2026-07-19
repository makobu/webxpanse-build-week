CREATE TABLE IF NOT EXISTS readiness_capture_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    connector_key VARCHAR(64) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    capture_secret VARCHAR(128) NOT NULL,
    source_label VARCHAR(50) NOT NULL DEFAULT 'web_assessment',
    origin_notes TEXT NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_readiness_capture_connector_key (connector_key),
    KEY idx_readiness_capture_enabled (is_enabled),
    KEY idx_readiness_capture_created_by_user (created_by_user_id),
    KEY idx_readiness_capture_updated_by_user (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
