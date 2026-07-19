-- Workspace-private negotiated package offers.

ALTER TABLE billing_plan_prices
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER plan_id,
    ADD KEY IF NOT EXISTS idx_billing_plan_prices_workspace (workspace_id, is_active),
    ADD CONSTRAINT fk_billing_plan_prices_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS billing_plan_price_feature_values (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    billing_plan_price_id INT NOT NULL,
    feature_id INT UNSIGNED NOT NULL,
    value_json JSON NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_plan_price_feature_values_price_feature (billing_plan_price_id, feature_id),
    KEY idx_billing_plan_price_feature_values_feature (feature_id),
    CONSTRAINT fk_billing_plan_price_feature_values_price
        FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_billing_plan_price_feature_values_feature
        FOREIGN KEY (feature_id) REFERENCES billing_package_features(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_negotiated_package_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    base_billing_plan_price_id INT NULL,
    negotiated_billing_plan_price_id INT NOT NULL,
    status ENUM('draft', 'offered', 'accepted', 'active', 'expired', 'archived') NOT NULL DEFAULT 'draft',
    agreement_reference VARCHAR(120) NULL,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    accepted_at DATETIME NULL,
    activated_at DATETIME NULL,
    archived_at DATETIME NULL,
    notes TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_workspace_negotiated_offers_workspace_status (workspace_id, status),
    KEY idx_workspace_negotiated_offers_price (negotiated_billing_plan_price_id),
    KEY idx_workspace_negotiated_offers_base_price (base_billing_plan_price_id),
    KEY idx_workspace_negotiated_offers_expiry (expires_at, status),
    CONSTRAINT fk_workspace_negotiated_offers_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workspace_negotiated_offers_base_price
        FOREIGN KEY (base_billing_plan_price_id) REFERENCES billing_plan_prices(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_workspace_negotiated_offers_price
        FOREIGN KEY (negotiated_billing_plan_price_id) REFERENCES billing_plan_prices(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workspace_negotiated_offers_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_workspace_negotiated_offers_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
