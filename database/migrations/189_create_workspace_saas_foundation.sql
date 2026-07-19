CREATE TABLE IF NOT EXISTS workspaces (
    id INT PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    status ENUM('active', 'trialing', 'past_due', 'suspended', 'archived') NOT NULL DEFAULT 'active',
    plan_status ENUM('inactive', 'trialing', 'active', 'past_due', 'cancelled') NOT NULL DEFAULT 'inactive',
    trial_starts_at DATETIME NULL,
    trial_ends_at DATETIME NULL,
    suspended_at DATETIME NULL,
    settings_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspaces_uuid (uuid),
    UNIQUE KEY uniq_workspaces_slug (slug),
    KEY idx_workspaces_status (status),
    KEY idx_workspaces_plan_status (plan_status),
    CONSTRAINT fk_workspaces_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_memberships (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    role_slug VARCHAR(64) NOT NULL DEFAULT 'viewer',
    membership_status ENUM('active', 'invited', 'suspended', 'left') NOT NULL DEFAULT 'active',
    is_owner BOOLEAN NOT NULL DEFAULT FALSE,
    joined_at DATETIME NULL,
    invited_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_membership (workspace_id, user_id),
    KEY idx_workspace_memberships_user (user_id),
    KEY idx_workspace_memberships_status (membership_status),
    CONSTRAINT fk_workspace_memberships_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_memberships_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_memberships_invited_by FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_invites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    role_slug VARCHAR(64) NOT NULL DEFAULT 'viewer',
    token_hash CHAR(64) NOT NULL,
    invite_status ENUM('pending', 'accepted', 'revoked', 'expired') NOT NULL DEFAULT 'pending',
    invited_by INT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_invites_token_hash (token_hash),
    KEY idx_workspace_invites_lookup (workspace_id, email, invite_status),
    CONSTRAINT fk_workspace_invites_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_invites_invited_by FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_slugs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    slug VARCHAR(120) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_slugs_slug (slug),
    KEY idx_workspace_slugs_workspace (workspace_id),
    CONSTRAINT fk_workspace_slugs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    billing_type ENUM('subscription', 'token_pack') NOT NULL DEFAULT 'subscription',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_plans_code (code),
    KEY idx_billing_plans_type (billing_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_plan_prices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    plan_id INT NOT NULL,
    price_code VARCHAR(64) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    interval_unit ENUM('one_time', 'weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
    interval_count INT NOT NULL DEFAULT 1,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    included_tokens BIGINT NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_plan_prices_code (price_code),
    KEY idx_billing_plan_prices_plan (plan_id, is_active),
    CONSTRAINT fk_billing_plan_prices_plan FOREIGN KEY (plan_id) REFERENCES billing_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    billing_plan_price_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'paystack',
    provider_reference VARCHAR(100) NULL,
    subscription_status ENUM('trialing', 'active', 'past_due', 'cancelled', 'expired') NOT NULL DEFAULT 'trialing',
    current_period_start DATETIME NULL,
    current_period_end DATETIME NULL,
    cancel_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    trial_starts_at DATETIME NULL,
    trial_ends_at DATETIME NULL,
    next_billing_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_workspace_subscriptions_workspace (workspace_id, subscription_status),
    KEY idx_workspace_subscriptions_provider_reference (provider, provider_reference),
    CONSTRAINT fk_workspace_subscriptions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_subscriptions_price FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_workspace_subscriptions_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS token_pack_prices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    billing_plan_price_id INT NOT NULL,
    token_quantity BIGINT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_token_pack_price (billing_plan_price_id),
    KEY idx_token_pack_prices_active (is_active, sort_order),
    CONSTRAINT fk_token_pack_prices_price FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_wallets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    token_balance BIGINT NOT NULL DEFAULT 0,
    reserved_tokens BIGINT NOT NULL DEFAULT 0,
    lifetime_credited_tokens BIGINT NOT NULL DEFAULT 0,
    lifetime_debited_tokens BIGINT NOT NULL DEFAULT 0,
    last_activity_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_wallets_workspace (workspace_id),
    CONSTRAINT fk_workspace_wallets_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_wallet_ledger (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    wallet_id INT NOT NULL,
    user_id INT NULL,
    entry_type ENUM('credit', 'debit', 'reserve', 'release', 'adjustment') NOT NULL,
    reference_type VARCHAR(64) NOT NULL,
    reference_id VARCHAR(100) NOT NULL,
    external_reference VARCHAR(100) NULL,
    token_delta BIGINT NOT NULL DEFAULT 0,
    balance_after BIGINT NULL,
    reserved_after BIGINT NULL,
    status ENUM('pending', 'posted', 'voided') NOT NULL DEFAULT 'posted',
    description VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_wallet_ledger_reference (workspace_id, reference_type, reference_id, entry_type),
    KEY idx_workspace_wallet_ledger_wallet (wallet_id, created_at),
    KEY idx_workspace_wallet_ledger_external (external_reference),
    CONSTRAINT fk_workspace_wallet_ledger_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_wallet_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES workspace_wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_wallet_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_checkout_sessions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'paystack',
    checkout_type ENUM('subscription', 'token_pack', 'mixed') NOT NULL DEFAULT 'subscription',
    provider_reference VARCHAR(100) NOT NULL,
    status ENUM('pending', 'processing', 'paid', 'failed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    billing_plan_price_id INT NULL,
    token_pack_price_id INT NULL,
    subscription_id INT NULL,
    authorization_url VARCHAR(500) NULL,
    callback_url VARCHAR(500) NULL,
    metadata_json JSON NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_billing_checkout_provider_reference (provider, provider_reference),
    KEY idx_billing_checkout_workspace (workspace_id, status),
    CONSTRAINT fk_billing_checkout_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_checkout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_checkout_price FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_checkout_token_pack FOREIGN KEY (token_pack_price_id) REFERENCES token_pack_prices(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_checkout_subscription FOREIGN KEY (subscription_id) REFERENCES workspace_subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_transactions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    checkout_session_id BIGINT NULL,
    subscription_id INT NULL,
    wallet_ledger_id BIGINT NULL,
    provider VARCHAR(50) NOT NULL DEFAULT 'paystack',
    provider_reference VARCHAR(100) NOT NULL,
    transaction_type ENUM('subscription_charge', 'token_pack_purchase', 'refund', 'manual_adjustment') NOT NULL,
    transaction_status ENUM('pending', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_billing_transactions_workspace (workspace_id, transaction_status, created_at),
    KEY idx_billing_transactions_reference (provider, provider_reference),
    CONSTRAINT fk_billing_transactions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_transactions_checkout FOREIGN KEY (checkout_session_id) REFERENCES billing_checkout_sessions(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_transactions_subscription FOREIGN KEY (subscription_id) REFERENCES workspace_subscriptions(id) ON DELETE SET NULL,
    CONSTRAINT fk_billing_transactions_ledger FOREIGN KEY (wallet_ledger_id) REFERENCES workspace_wallet_ledger(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_provider_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    provider VARCHAR(50) NOT NULL DEFAULT 'paystack',
    workspace_id INT NULL,
    event_name VARCHAR(120) NOT NULL,
    event_reference VARCHAR(120) NULL,
    signature VARCHAR(255) NULL,
    payload_json JSON NULL,
    processing_status ENUM('pending', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'pending',
    processing_message VARCHAR(255) NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_billing_provider_events_lookup (provider, event_name, created_at),
    KEY idx_billing_provider_events_workspace (workspace_id),
    CONSTRAINT fk_billing_provider_events_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_ai_usage (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    provider VARCHAR(50) NOT NULL,
    model VARCHAR(120) NULL,
    feature_key VARCHAR(120) NOT NULL,
    request_id VARCHAR(100) NOT NULL,
    input_tokens INT NOT NULL DEFAULT 0,
    output_tokens INT NOT NULL DEFAULT 0,
    billable_tokens INT NOT NULL DEFAULT 0,
    provider_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
    ledger_entry_id BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_ai_usage_request (workspace_id, request_id),
    KEY idx_workspace_ai_usage_workspace_created (workspace_id, created_at),
    KEY idx_workspace_ai_usage_feature (feature_key, created_at),
    CONSTRAINT fk_workspace_ai_usage_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_ai_usage_ledger FOREIGN KEY (ledger_entry_id) REFERENCES workspace_wallet_ledger(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mobile_auth_challenges ADD COLUMN workspace_id INT NULL AFTER user_id;
ALTER TABLE mobile_auth_challenges ADD KEY idx_mobile_auth_challenges_workspace (workspace_id);

ALTER TABLE mobile_auth_tokens ADD COLUMN workspace_id INT NULL AFTER user_id;
ALTER TABLE mobile_auth_tokens ADD KEY idx_mobile_auth_tokens_workspace (workspace_id);

ALTER TABLE contacts ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE contacts ADD KEY idx_contacts_workspace (workspace_id);

ALTER TABLE companies ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE companies ADD KEY idx_companies_workspace (workspace_id);

ALTER TABLE deals ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE deals ADD KEY idx_deals_workspace (workspace_id);

ALTER TABLE tasks ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE tasks ADD KEY idx_tasks_workspace (workspace_id);

ALTER TABLE activities ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE activities ADD KEY idx_activities_workspace (workspace_id);

ALTER TABLE notes ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE notes ADD KEY idx_notes_workspace (workspace_id);

ALTER TABLE documents ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE documents ADD KEY idx_documents_workspace (workspace_id);

ALTER TABLE communications ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE communications ADD KEY idx_communications_workspace (workspace_id);

ALTER TABLE conversation_threads ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE conversation_threads ADD KEY idx_conversation_threads_workspace (workspace_id);

ALTER TABLE workflows ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE workflows ADD KEY idx_workflows_workspace (workspace_id);

ALTER TABLE workflow_queue ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE workflow_queue ADD KEY idx_workflow_queue_workspace (workspace_id);

ALTER TABLE forms ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE forms ADD KEY idx_forms_workspace (workspace_id);

ALTER TABLE form_submissions ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE form_submissions ADD KEY idx_form_submissions_workspace (workspace_id);

ALTER TABLE campaigns ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE campaigns ADD KEY idx_campaigns_workspace (workspace_id);

ALTER TABLE reports ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE reports ADD KEY idx_reports_workspace (workspace_id);

ALTER TABLE invoices ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE invoices ADD KEY idx_invoices_workspace (workspace_id);

ALTER TABLE notifications ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE notifications ADD KEY idx_notifications_workspace (workspace_id);

ALTER TABLE tags ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE tags ADD KEY idx_tags_workspace (workspace_id);

INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_by)
SELECT
    1,
    '00000000-0000-4000-8000-000000000001',
    'Default Workspace',
    'default',
    'active',
    'inactive',
    MIN(id)
FROM users
HAVING COUNT(*) >= 0
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    slug = VALUES(slug);

INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
SELECT 1, 'default', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM workspace_slugs WHERE workspace_id = 1 AND slug = 'default'
);

INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
SELECT 1, 'KES', 0, 0, 0, 0, NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM workspace_wallets WHERE workspace_id = 1
);

INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
SELECT
    1,
    u.id,
    CASE
        WHEN u.role = 'admin' THEN 'owner'
        WHEN u.role = 'sales' THEN 'sales'
        WHEN u.role = 'marketing' THEN 'marketing'
        ELSE 'viewer'
    END,
    'active',
    CASE WHEN u.role = 'admin' THEN 1 ELSE 0 END,
    NOW(),
    NULL
FROM users u
LEFT JOIN workspace_memberships wm ON wm.workspace_id = 1 AND wm.user_id = u.id
WHERE wm.id IS NULL;

UPDATE mobile_auth_challenges SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE mobile_auth_tokens SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE contacts SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE companies SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE deals SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE tasks SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE activities SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE notes SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE documents SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE communications SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE conversation_threads SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE workflows SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE invoices SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE notifications SET workspace_id = 1 WHERE workspace_id IS NULL;
UPDATE tags SET workspace_id = 1 WHERE workspace_id IS NULL;

INSERT INTO billing_plans (id, code, name, description, billing_type, is_active)
VALUES
    (1, 'starter-subscription', 'Starter Subscription', 'Default recurring workspace subscription', 'subscription', 1),
    (2, 'starter-token-pack', 'Starter Token Pack', 'Default prepaid AI token top-up pack', 'token_pack', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    billing_type = VALUES(billing_type),
    is_active = VALUES(is_active);

INSERT INTO billing_plan_prices (id, plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens, is_default, is_active)
VALUES
    (1, 1, 'starter-subscription-monthly', 'KES', 'monthly', 1, 0, 0, 1, 1),
    (2, 2, 'starter-token-pack-100k', 'KES', 'one_time', 1, 0, 100000, 1, 1)
ON DUPLICATE KEY UPDATE
    currency = VALUES(currency),
    interval_unit = VALUES(interval_unit),
    interval_count = VALUES(interval_count),
    amount = VALUES(amount),
    included_tokens = VALUES(included_tokens),
    is_default = VALUES(is_default),
    is_active = VALUES(is_active);

INSERT INTO token_pack_prices (billing_plan_price_id, token_quantity, sort_order, is_active)
SELECT 2, 100000, 1, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM token_pack_prices WHERE billing_plan_price_id = 2
);
