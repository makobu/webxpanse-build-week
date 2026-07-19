ALTER TABLE workspace_subscriptions
    ADD COLUMN IF NOT EXISTS scheduled_billing_plan_price_id INT NULL AFTER billing_plan_price_id,
    ADD COLUMN IF NOT EXISTS scheduled_change_type VARCHAR(32) NULL AFTER scheduled_billing_plan_price_id,
    ADD COLUMN IF NOT EXISTS scheduled_change_at DATETIME NULL AFTER scheduled_change_type,
    ADD COLUMN IF NOT EXISTS scheduled_change_metadata_json JSON NULL AFTER scheduled_change_at,
    ADD KEY IF NOT EXISTS idx_workspace_subscriptions_scheduled_price (scheduled_billing_plan_price_id, scheduled_change_at);

CREATE TABLE IF NOT EXISTS workspace_credit_lots (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    wallet_id INT NOT NULL,
    user_id INT NULL,
    source_type VARCHAR(64) NOT NULL,
    source_id VARCHAR(120) NOT NULL,
    source_ledger_id BIGINT NULL,
    granted_credits BIGINT NOT NULL DEFAULT 0,
    remaining_credits BIGINT NOT NULL DEFAULT 0,
    reserved_credits BIGINT NOT NULL DEFAULT 0,
    granted_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    status ENUM('active', 'depleted', 'expired', 'voided') NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_credit_lot_source (workspace_id, source_type, source_id),
    KEY idx_workspace_credit_lots_available (workspace_id, status, expires_at),
    KEY idx_workspace_credit_lots_wallet (wallet_id, status, expires_at),
    KEY idx_workspace_credit_lots_source_ledger (source_ledger_id),
    CONSTRAINT fk_workspace_credit_lots_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_credit_lots_wallet FOREIGN KEY (wallet_id) REFERENCES workspace_wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_credit_lots_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_credit_lots_source_ledger FOREIGN KEY (source_ledger_id) REFERENCES workspace_wallet_ledger(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_credit_lot_ledger (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    lot_id BIGINT NOT NULL,
    wallet_ledger_id BIGINT NULL,
    entry_type ENUM('credit', 'reserve', 'release', 'debit', 'expire', 'adjustment') NOT NULL,
    reference_type VARCHAR(64) NOT NULL,
    reference_id VARCHAR(120) NOT NULL,
    credit_delta BIGINT NOT NULL DEFAULT 0,
    remaining_after BIGINT NOT NULL DEFAULT 0,
    reserved_after BIGINT NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_workspace_credit_lot_ledger_lot (lot_id, created_at),
    KEY idx_workspace_credit_lot_ledger_wallet (wallet_ledger_id),
    KEY idx_workspace_credit_lot_ledger_reference (workspace_id, reference_type, reference_id),
    CONSTRAINT fk_workspace_credit_lot_ledger_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_credit_lot_ledger_lot FOREIGN KEY (lot_id) REFERENCES workspace_credit_lots(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_credit_lot_ledger_wallet FOREIGN KEY (wallet_ledger_id) REFERENCES workspace_wallet_ledger(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_subscription_cycles (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    subscription_id INT NOT NULL,
    billing_plan_price_id INT NOT NULL,
    period_key VARCHAR(120) NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NULL,
    included_credits BIGINT NOT NULL DEFAULT 0,
    credit_lot_id BIGINT NULL,
    billing_transaction_id BIGINT NULL,
    status ENUM('active', 'closed', 'failed', 'voided') NOT NULL DEFAULT 'active',
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_subscription_cycle_period (workspace_id, subscription_id, period_key),
    KEY idx_workspace_subscription_cycles_workspace (workspace_id, period_start),
    KEY idx_workspace_subscription_cycles_subscription (subscription_id, period_start),
    CONSTRAINT fk_workspace_subscription_cycles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_subscription_cycles_subscription FOREIGN KEY (subscription_id) REFERENCES workspace_subscriptions(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_subscription_cycles_price FOREIGN KEY (billing_plan_price_id) REFERENCES billing_plan_prices(id) ON DELETE RESTRICT,
    CONSTRAINT fk_workspace_subscription_cycles_lot FOREIGN KEY (credit_lot_id) REFERENCES workspace_credit_lots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO billing_plans (code, name, description, billing_type, is_active)
VALUES
    ('compass-free', 'Compass Free', 'One-seat free workspace for guided onboarding and core system exploration.', 'subscription', 1),
    ('solo-launch', 'Solo Launch', 'One-person founder plan with full access, top-ups, and recurring AI Credits.', 'subscription', 1),
    ('founder-plus', 'Founder Plus', 'Founder plus collaborators with Business Intelligence access and larger AI Credit allowance.', 'subscription', 1),
    ('growth-studio', 'Growth Studio', 'Small-team operating plan with Business Intelligence, personal API key access, and deeper usage.', 'subscription', 1),
    ('scale-custom', 'Scale Custom', 'Custom rollout plan for unlimited seats, larger credits, and sales-led billing.', 'subscription', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    billing_type = VALUES(billing_type),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO billing_plan_prices
    (plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens, metadata_json, provider, provider_plan_code, is_default, is_active)
SELECT bp.id, seed.price_code, 'KES', seed.interval_unit, 1, seed.amount, seed.included_credits,
       JSON_OBJECT(
           'launch_package', TRUE,
           'launch_tier', seed.launch_tier,
           'checkout_available', seed.checkout_available,
           'requires_provider_plan', seed.requires_provider_plan,
           'entitlements', JSON_OBJECT(
               'seat_limit', seed.seat_limit,
               'included_credits', seed.included_credits,
               'can_top_up', seed.can_top_up,
               'business_intelligence_enabled', seed.business_intelligence_enabled,
               'personal_api_key_enabled', seed.personal_api_key_enabled,
               'credit_expiry_days', 180,
               'is_custom', seed.is_custom,
               'maturity_tier', seed.maturity_tier,
               'display_name', seed.display_name,
               'public_summary', seed.public_summary
           )
       ),
       'paystack', NULL, seed.is_default, 1
FROM billing_plans bp
JOIN (
    SELECT 'compass-free' AS plan_code, 'compass-free-monthly' AS price_code, 'monthly' AS interval_unit, 0.00 AS amount, 50000 AS included_credits, 1 AS is_default,
           'compass_free' AS launch_tier, FALSE AS checkout_available, FALSE AS requires_provider_plan, 1 AS seat_limit, FALSE AS can_top_up,
           FALSE AS business_intelligence_enabled, FALSE AS personal_api_key_enabled, FALSE AS is_custom, 'free' AS maturity_tier,
           'Compass Free' AS display_name, 'Core workspace access with 50,000 one-time onboarding AI Credits.' AS public_summary
    UNION ALL
    SELECT 'compass-free', 'compass-free-annual', 'yearly', 0.00, 50000, 0,
           'compass_free', FALSE, FALSE, 1, FALSE, FALSE, FALSE, FALSE, 'free',
           'Compass Free', 'Core workspace access with 50,000 one-time onboarding AI Credits.'
    UNION ALL
    SELECT 'solo-launch', 'solo-launch-monthly', 'monthly', 2500.00, 1000000, 0,
           'solo_launch', TRUE, TRUE, 1, TRUE, FALSE, FALSE, FALSE, 'solo',
           'Solo Launch', 'One founder, full access, top-ups, and 1,000,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'solo-launch', 'solo-launch-annual', 'yearly', 25000.00, 1000000, 0,
           'solo_launch', TRUE, TRUE, 1, TRUE, FALSE, FALSE, FALSE, 'solo',
           'Solo Launch', 'One founder, full access, top-ups, and 1,000,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'founder-plus', 'founder-plus-monthly', 'monthly', 6500.00, 3500000, 0,
           'founder_plus', TRUE, TRUE, 3, TRUE, TRUE, FALSE, FALSE, 'founder',
           'Founder Plus', 'Three seats, Business Intelligence access, and 3,500,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'founder-plus', 'founder-plus-annual', 'yearly', 65000.00, 3500000, 0,
           'founder_plus', TRUE, TRUE, 3, TRUE, TRUE, FALSE, FALSE, 'founder',
           'Founder Plus', 'Three seats, Business Intelligence access, and 3,500,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'growth-studio', 'growth-studio-monthly', 'monthly', 15000.00, 6000000, 0,
           'growth_studio', TRUE, TRUE, 15, TRUE, TRUE, TRUE, FALSE, 'growth',
           'Growth Studio', 'Fifteen seats, Business Intelligence, personal API key access, and 6,000,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'growth-studio', 'growth-studio-annual', 'yearly', 150000.00, 6000000, 0,
           'growth_studio', TRUE, TRUE, 15, TRUE, TRUE, TRUE, FALSE, 'growth',
           'Growth Studio', 'Fifteen seats, Business Intelligence, personal API key access, and 6,000,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'scale-custom', 'scale-custom-monthly', 'monthly', 39000.00, 20000000, 0,
           'scale_custom', TRUE, FALSE, 0, TRUE, TRUE, TRUE, TRUE, 'scale',
           'Scale Custom', 'Unlimited seats by agreement, all plugins, and 20,000,000 AI Credits per billing cycle.'
    UNION ALL
    SELECT 'scale-custom', 'scale-custom-annual', 'yearly', 390000.00, 20000000, 0,
           'scale_custom', TRUE, FALSE, 0, TRUE, TRUE, TRUE, TRUE, 'scale',
           'Scale Custom', 'Unlimited seats by agreement, all plugins, and 20,000,000 AI Credits per billing cycle.'
) seed ON seed.plan_code = bp.code
ON DUPLICATE KEY UPDATE
    plan_id = VALUES(plan_id),
    currency = VALUES(currency),
    interval_unit = VALUES(interval_unit),
    interval_count = VALUES(interval_count),
    amount = VALUES(amount),
    included_tokens = VALUES(included_tokens),
    metadata_json = VALUES(metadata_json),
    provider = COALESCE(billing_plan_prices.provider, VALUES(provider)),
    provider_plan_code = billing_plan_prices.provider_plan_code,
    is_default = VALUES(is_default),
    is_active = VALUES(is_active),
    updated_at = NOW();

UPDATE billing_plan_prices bpp
JOIN billing_plans bp ON bp.id = bpp.plan_id
SET bpp.is_active = 0,
    bpp.updated_at = NOW()
WHERE bp.code = 'starter-token-pack'
   OR bpp.price_code = 'starter-token-pack-100k';

UPDATE billing_plans
SET is_active = 0,
    updated_at = NOW()
WHERE code = 'starter-token-pack';

INSERT INTO billing_plans (code, name, description, billing_type, is_active)
VALUES
    ('starter-ai-credits', 'Starter AI Credits', 'One-time AI Credit top-up pack.', 'token_pack', 1),
    ('power-ai-credits', 'Power AI Credits', 'One-time AI Credit top-up pack.', 'token_pack', 1),
    ('next-level-ai-credits', 'Next Level AI Credits', 'One-time AI Credit top-up pack.', 'token_pack', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    billing_type = VALUES(billing_type),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO billing_plan_prices
    (plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens, metadata_json, provider, provider_plan_code, is_default, is_active)
SELECT bp.id, seed.price_code, 'KES', 'one_time', 1, seed.amount, seed.credits,
       JSON_OBJECT(
           'credit_pack', TRUE,
           'display_name', seed.display_name,
           'credit_expiry_days', 180,
           'price_per_credit', seed.amount / seed.credits
       ),
       'paystack', NULL, 0, 1
FROM billing_plans bp
JOIN (
    SELECT 'starter-ai-credits' AS plan_code, 'starter-ai-credits-100k' AS price_code, 150.00 AS amount, 100000 AS credits, 'Starter AI Credits' AS display_name
    UNION ALL
    SELECT 'power-ai-credits', 'power-ai-credits-1m', 1100.00, 1000000, 'Power AI Credits'
    UNION ALL
    SELECT 'next-level-ai-credits', 'next-level-ai-credits-5m', 5000.00, 5000000, 'Next Level AI Credits'
) seed ON seed.plan_code = bp.code
ON DUPLICATE KEY UPDATE
    plan_id = VALUES(plan_id),
    currency = VALUES(currency),
    interval_unit = VALUES(interval_unit),
    interval_count = VALUES(interval_count),
    amount = VALUES(amount),
    included_tokens = VALUES(included_tokens),
    metadata_json = VALUES(metadata_json),
    provider = COALESCE(billing_plan_prices.provider, VALUES(provider)),
    provider_plan_code = billing_plan_prices.provider_plan_code,
    is_default = VALUES(is_default),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO token_pack_prices (billing_plan_price_id, token_quantity, sort_order, is_active)
SELECT bpp.id, seed.credits, seed.sort_order, 1
FROM billing_plan_prices bpp
JOIN (
    SELECT 'starter-ai-credits-100k' AS price_code, 100000 AS credits, 10 AS sort_order
    UNION ALL
    SELECT 'power-ai-credits-1m', 1000000, 20
    UNION ALL
    SELECT 'next-level-ai-credits-5m', 5000000, 30
) seed ON seed.price_code = bpp.price_code
ON DUPLICATE KEY UPDATE
    token_quantity = VALUES(token_quantity),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active),
    updated_at = NOW();
