CREATE TABLE IF NOT EXISTS ai_autonomy_domain_controls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_key VARCHAR(128) NOT NULL,
    domain_key VARCHAR(64) NOT NULL,
    autonomy_mode VARCHAR(32) NOT NULL DEFAULT 'suggest_only',
    demonstration_capture_enabled TINYINT(1) NOT NULL DEFAULT 1,
    policy_learning_enabled TINYINT(1) NOT NULL DEFAULT 1,
    review_ui_enabled TINYINT(1) NOT NULL DEFAULT 1,
    fast_promotion_enabled TINYINT(1) NOT NULL DEFAULT 1,
    promotion_status VARCHAR(32) NOT NULL DEFAULT 'suggest_only',
    min_precision_to_promote DECIMAL(6,4) NOT NULL DEFAULT 0.9000,
    max_reversal_rate_to_promote DECIMAL(6,4) NOT NULL DEFAULT 0.0800,
    max_edit_rate_to_promote DECIMAL(6,4) NOT NULL DEFAULT 0.1200,
    metadata_json JSON NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_autonomy_domain_controls (tenant_key, domain_key),
    INDEX idx_ai_autonomy_domain_controls_domain (domain_key, promotion_status),
    CONSTRAINT fk_ai_autonomy_domain_controls_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_autonomy_domain_controls
    (tenant_key, domain_key, autonomy_mode, demonstration_capture_enabled, policy_learning_enabled, review_ui_enabled, fast_promotion_enabled, promotion_status, metadata_json)
VALUES
    ('global:default', 'commercial_mvp', 'suggest_only', 1, 1, 1, 1, 'suggest_only', JSON_OBJECT('seeded', TRUE)),
    ('global:default', 'customer_thread', 'suggest_only', 1, 1, 1, 1, 'suggest_only', JSON_OBJECT('seeded', TRUE)),
    ('global:default', 'deal_followthrough', 'suggest_only', 1, 1, 1, 1, 'suggest_only', JSON_OBJECT('seeded', TRUE)),
    ('global:default', 'task_followthrough', 'suggest_only', 1, 1, 1, 1, 'suggest_only', JSON_OBJECT('seeded', TRUE))
ON DUPLICATE KEY UPDATE
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('ai.learning_review', 'AI Learning Review', 'Review AI learning memory, demonstrations, and autonomy controls', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'ai.learning_review'
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
