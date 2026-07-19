CREATE TABLE IF NOT EXISTS marketplace_page_explainers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    page_key VARCHAR(80) NOT NULL,
    label VARCHAR(160) NOT NULL,
    video_url VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketplace_page_explainers_key (page_key),
    KEY idx_marketplace_page_explainers_active (is_active),
    KEY idx_marketplace_page_explainers_updated_by (updated_by_user_id),
    CONSTRAINT fk_marketplace_page_explainers_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_page_explainers (
    page_key,
    label,
    video_url,
    is_active
) VALUES (
    'founder_operating_loop',
    'Founder Loop page guide',
    NULL,
    0
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    updated_at = NOW();
