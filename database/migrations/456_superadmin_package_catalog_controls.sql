-- Superadmin package catalog controls: dynamic feature catalog and per-price payment allowlists.

CREATE TABLE IF NOT EXISTS billing_package_features (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feature_key VARCHAR(120) NOT NULL,
    label VARCHAR(191) NOT NULL,
    description TEXT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'feature',
    value_type ENUM('boolean', 'integer', 'decimal', 'text') NOT NULL DEFAULT 'boolean',
    default_value_json JSON NULL,
    is_core TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_package_features_key (feature_key),
    KEY idx_billing_package_features_active (is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_plan_feature_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    feature_id INT UNSIGNED NOT NULL,
    value_json JSON NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_plan_feature_values_plan_feature (plan_id, feature_id),
    KEY idx_billing_plan_feature_values_feature (feature_id),
    CONSTRAINT fk_billing_plan_feature_values_plan
        FOREIGN KEY (plan_id) REFERENCES billing_plans(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_billing_plan_feature_values_feature
        FOREIGN KEY (feature_id) REFERENCES billing_package_features(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_plan_price_payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_plan_price_id INT NOT NULL,
    payment_mode VARCHAR(40) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_plan_price_payment_methods_mode (billing_plan_price_id, payment_mode),
    KEY idx_billing_plan_price_payment_methods_enabled (payment_mode, is_enabled),
    CONSTRAINT fk_billing_plan_price_payment_methods_price
        FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO billing_package_features
    (feature_key, label, description, category, value_type, default_value_json, is_core, is_active, display_order)
VALUES
    ('seat_limit', 'Seat limit', 'Maximum active members and pending invites allowed on the package. Zero means unlimited.', 'limits', 'integer', JSON_OBJECT('value', 1), 1, 1, 10),
    ('can_top_up', 'AI Credit top-ups', 'Allows the workspace to buy additional AI Credit packs.', 'credits', 'boolean', JSON_OBJECT('value', false), 1, 1, 20),
    ('business_intelligence', 'Business Intelligence', 'Unlocks the Business Intelligence plugin gate.', 'plugins', 'boolean', JSON_OBJECT('value', false), 1, 1, 30),
    ('personal_api_key', 'Personal API key', 'Unlocks personal API key access.', 'plugins', 'boolean', JSON_OBJECT('value', false), 1, 1, 40),
    ('credit_expiry_days', 'Credit expiry days', 'Number of days before included or purchased AI Credits expire.', 'credits', 'integer', JSON_OBJECT('value', 180), 1, 1, 50)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    category = VALUES(category),
    value_type = VALUES(value_type),
    default_value_json = VALUES(default_value_json),
    is_core = VALUES(is_core),
    display_order = VALUES(display_order),
    updated_at = NOW();

INSERT INTO billing_plan_feature_values
    (plan_id, feature_id, value_json, is_enabled)
SELECT bp.id, bpf.id, JSON_OBJECT('value', seed.feature_value), seed.is_enabled
FROM billing_plans bp
JOIN (
    SELECT 'compass-free' AS plan_code, 'seat_limit' AS feature_key, 1 AS feature_value, 1 AS is_enabled
    UNION ALL SELECT 'compass-free', 'can_top_up', 0, 0
    UNION ALL SELECT 'compass-free', 'business_intelligence', 0, 0
    UNION ALL SELECT 'compass-free', 'personal_api_key', 0, 0
    UNION ALL SELECT 'compass-free', 'credit_expiry_days', 180, 1
    UNION ALL SELECT 'solo-launch', 'seat_limit', 1, 1
    UNION ALL SELECT 'solo-launch', 'can_top_up', 1, 1
    UNION ALL SELECT 'solo-launch', 'business_intelligence', 0, 0
    UNION ALL SELECT 'solo-launch', 'personal_api_key', 0, 0
    UNION ALL SELECT 'solo-launch', 'credit_expiry_days', 180, 1
    UNION ALL SELECT 'founder-plus', 'seat_limit', 3, 1
    UNION ALL SELECT 'founder-plus', 'can_top_up', 1, 1
    UNION ALL SELECT 'founder-plus', 'business_intelligence', 1, 1
    UNION ALL SELECT 'founder-plus', 'personal_api_key', 0, 0
    UNION ALL SELECT 'founder-plus', 'credit_expiry_days', 180, 1
    UNION ALL SELECT 'growth-studio', 'seat_limit', 15, 1
    UNION ALL SELECT 'growth-studio', 'can_top_up', 1, 1
    UNION ALL SELECT 'growth-studio', 'business_intelligence', 1, 1
    UNION ALL SELECT 'growth-studio', 'personal_api_key', 1, 1
    UNION ALL SELECT 'growth-studio', 'credit_expiry_days', 180, 1
    UNION ALL SELECT 'scale-custom', 'seat_limit', 0, 1
    UNION ALL SELECT 'scale-custom', 'can_top_up', 1, 1
    UNION ALL SELECT 'scale-custom', 'business_intelligence', 1, 1
    UNION ALL SELECT 'scale-custom', 'personal_api_key', 1, 1
    UNION ALL SELECT 'scale-custom', 'credit_expiry_days', 180, 1
) seed ON seed.plan_code = bp.code
JOIN billing_package_features bpf ON bpf.feature_key = seed.feature_key
WHERE bp.billing_type = 'subscription'
ON DUPLICATE KEY UPDATE
    value_json = VALUES(value_json),
    is_enabled = VALUES(is_enabled),
    updated_at = NOW();
