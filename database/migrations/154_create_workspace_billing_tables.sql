CREATE TABLE IF NOT EXISTS workspace_billing_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    paystack_mode ENUM('test', 'live') NOT NULL DEFAULT 'test',
    paystack_public_key VARCHAR(255) NULL,
    paystack_secret_key VARCHAR(255) NULL,
    paystack_callback_url VARCHAR(500) NULL,
    paystack_webhook_url VARCHAR(500) NULL,
    mobile_return_url VARCHAR(500) NULL,
    default_currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    email_reminders_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    in_app_prompts_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    auto_lock_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    grace_days INT NOT NULL DEFAULT 3,
    reminder_days_before_json JSON NULL,
    workspace_status ENUM('current', 'payment_due', 'grace', 'locked') NOT NULL DEFAULT 'current',
    last_status_changed_at DATETIME NULL,
    last_locked_at DATETIME NULL,
    last_paid_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_workspace_billing_status (workspace_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_billing_settings (
    id, enabled, paystack_mode, default_currency, email_reminders_enabled,
    in_app_prompts_enabled, auto_lock_enabled, grace_days, reminder_days_before_json, workspace_status
) VALUES (
    1, 0, 'test', 'KES', 1, 1, 1, 3, JSON_ARRAY(7, 3, 1), 'current'
)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS workspace_billing_contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    name VARCHAR(255) NULL,
    email VARCHAR(255) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_billing_contact_email (email),
    CONSTRAINT fk_workspace_billing_contacts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    interval_unit ENUM('weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
    interval_count INT NOT NULL DEFAULT 1,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    grace_days INT NOT NULL DEFAULT 3,
    next_charge_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    INDEX idx_workspace_billing_plans_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_billing_plans (
    id, name, description, is_active, interval_unit, interval_count, amount, currency, grace_days, next_charge_date
) VALUES (
    1, 'Default Workspace Plan', 'Default recurring workspace billing plan', 0, 'monthly', 1, 0, 'KES', 3, CURDATE()
)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS workspace_billing_cycles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_id INT NOT NULL,
    cycle_key VARCHAR(100) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('open', 'settled', 'cancelled') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_billing_cycle_key (cycle_key),
    INDEX idx_workspace_billing_cycles_plan (plan_id),
    INDEX idx_workspace_billing_cycles_due_date (due_date),
    CONSTRAINT fk_workspace_billing_cycles_plan FOREIGN KEY (plan_id) REFERENCES workspace_billing_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_charges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_id INT NULL,
    cycle_id INT NULL,
    charge_type ENUM('recurring', 'one_time', 'adjustment') NOT NULL DEFAULT 'one_time',
    status ENUM('draft', 'open', 'pending', 'paid', 'waived', 'cancelled') NOT NULL DEFAULT 'open',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    due_date DATE NOT NULL,
    billing_period_start DATE NULL,
    billing_period_end DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    updated_by INT NULL,
    settled_at DATETIME NULL,
    waived_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    INDEX idx_workspace_billing_charges_status (status),
    INDEX idx_workspace_billing_charges_due_date (due_date),
    INDEX idx_workspace_billing_charges_cycle (cycle_id),
    CONSTRAINT fk_workspace_billing_charges_plan FOREIGN KEY (plan_id) REFERENCES workspace_billing_plans(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_billing_charges_cycle FOREIGN KEY (cycle_id) REFERENCES workspace_billing_cycles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_payment_intents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL DEFAULT 'paystack',
    reference VARCHAR(100) NOT NULL,
    status ENUM('pending', 'processing', 'paid', 'failed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    customer_email VARCHAR(255) NULL,
    authorization_url VARCHAR(500) NULL,
    access_code VARCHAR(255) NULL,
    provider_transaction_id VARCHAR(100) NULL,
    channel VARCHAR(50) NOT NULL DEFAULT 'web',
    callback_url VARCHAR(500) NULL,
    provider_response_json JSON NULL,
    paid_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT NULL,
    UNIQUE KEY uniq_workspace_billing_payment_reference (reference),
    INDEX idx_workspace_billing_payment_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_payment_intent_items (
    payment_intent_id INT NOT NULL,
    charge_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_intent_id, charge_id),
    CONSTRAINT fk_workspace_billing_intent_items_intent FOREIGN KEY (payment_intent_id) REFERENCES workspace_billing_payment_intents(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_billing_intent_items_charge FOREIGN KEY (charge_id) REFERENCES workspace_billing_charges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_status_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    previous_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    reason VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workspace_billing_status_history_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_billing_webhook_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL DEFAULT 'paystack',
    event_name VARCHAR(120) NULL,
    signature VARCHAR(255) NULL,
    payload_json JSON NULL,
    verification_status ENUM('accepted', 'rejected', 'ignored') NOT NULL DEFAULT 'accepted',
    message VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workspace_billing_webhook_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('feature.workspace_billing', 'Workspace Billing Feature', 'Access workspace billing flows', FALSE),
('billing.view', 'View Workspace Billing', 'View workspace billing details and payment prompts', FALSE),
('billing.manage', 'Manage Workspace Billing', 'Manage workspace billing settings, charges, and payments', TRUE),
('settings.billing', 'Billing Settings', 'Manage workspace billing configuration', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'feature.workspace_billing',
    'billing.view',
    'billing.manage',
    'settings.billing'
)
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'feature.workspace_billing',
    'billing.view'
)
WHERE r.slug IN ('sales', 'marketing', 'viewer')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
