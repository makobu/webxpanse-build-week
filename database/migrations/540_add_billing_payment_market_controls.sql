-- Allow Super Admin to restrict globally enabled billing methods by country or region.

CREATE TABLE IF NOT EXISTS billing_payment_market_rules (
    id INT NOT NULL AUTO_INCREMENT,
    scope_type ENUM('country', 'region') NOT NULL,
    scope_code VARCHAR(32) NOT NULL,
    scope_name VARCHAR(120) NOT NULL,
    payment_card_enabled TINYINT(1) NOT NULL DEFAULT 1,
    payment_mpesa_enabled TINYINT(1) NOT NULL DEFAULT 1,
    payment_bank_transfer_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_payment_market_rule (scope_type, scope_code),
    KEY idx_billing_payment_market_rules_active (is_active, scope_type),
    CONSTRAINT fk_billing_payment_market_rules_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_market_assignments (
    workspace_id INT NOT NULL,
    country_code CHAR(2) NULL,
    region_code VARCHAR(32) NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id),
    KEY idx_workspace_billing_market_country (country_code),
    KEY idx_workspace_billing_market_region (region_code),
    CONSTRAINT fk_workspace_billing_market_assignment_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_billing_market_assignment_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
