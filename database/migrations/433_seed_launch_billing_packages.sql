ALTER TABLE billing_plan_prices
    ADD COLUMN IF NOT EXISTS provider VARCHAR(50) NULL AFTER metadata_json,
    ADD COLUMN IF NOT EXISTS provider_plan_code VARCHAR(100) NULL AFTER provider,
    ADD COLUMN IF NOT EXISTS provider_plan_id VARCHAR(100) NULL AFTER provider_plan_code,
    ADD COLUMN IF NOT EXISTS provider_plan_status VARCHAR(50) NULL AFTER provider_plan_id,
    ADD COLUMN IF NOT EXISTS provider_plan_synced_at DATETIME NULL AFTER provider_plan_status,
    ADD KEY IF NOT EXISTS idx_billing_plan_prices_provider_plan (provider, provider_plan_code);

ALTER TABLE workspace_subscriptions
    ADD COLUMN IF NOT EXISTS provider_subscription_code VARCHAR(100) NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS provider_customer_code VARCHAR(100) NULL AFTER provider_subscription_code,
    ADD COLUMN IF NOT EXISTS provider_email_token VARCHAR(100) NULL AFTER provider_customer_code,
    ADD COLUMN IF NOT EXISTS provider_subscription_status VARCHAR(50) NULL AFTER provider_email_token,
    ADD COLUMN IF NOT EXISTS renewal_status VARCHAR(50) NOT NULL DEFAULT 'manual' AFTER provider_subscription_status,
    ADD COLUMN IF NOT EXISTS provider_metadata_json JSON NULL AFTER renewal_status,
    ADD KEY IF NOT EXISTS idx_workspace_subscriptions_provider_subscription (provider, provider_subscription_code);

ALTER TABLE billing_checkout_sessions
    ADD COLUMN IF NOT EXISTS provider_plan_code VARCHAR(100) NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS provider_subscription_code VARCHAR(100) NULL AFTER provider_plan_code,
    ADD COLUMN IF NOT EXISTS provider_customer_code VARCHAR(100) NULL AFTER provider_subscription_code,
    ADD KEY IF NOT EXISTS idx_billing_checkout_provider_subscription (provider, provider_subscription_code);

ALTER TABLE billing_transactions
    ADD COLUMN IF NOT EXISTS provider_subscription_code VARCHAR(100) NULL AFTER provider_reference,
    ADD COLUMN IF NOT EXISTS provider_customer_code VARCHAR(100) NULL AFTER provider_subscription_code,
    ADD KEY IF NOT EXISTS idx_billing_transactions_provider_subscription (provider, provider_subscription_code);

UPDATE billing_plans
SET is_active = 0,
    updated_at = NOW()
WHERE code = 'starter-subscription';

UPDATE billing_plan_prices bpp
JOIN billing_plans bp ON bp.id = bpp.plan_id
SET bpp.is_default = 0,
    bpp.is_active = 0,
    bpp.updated_at = NOW()
WHERE bp.code = 'starter-subscription';

INSERT INTO billing_plans (code, name, description, billing_type, is_active)
VALUES
    ('compass-free', 'Compass Free', 'Solo founder testing the system; removes adoption friction and captures top-of-funnel founder demand.', 'subscription', 1),
    ('solo-launch', 'Solo Launch', 'One-person founder who wants structure and AI help; deliberately affordable business operating system.', 'subscription', 1),
    ('founder-plus', 'Founder Plus', 'Founder plus one to two collaborators; good-better tier for serious users and core revenue driver.', 'subscription', 1),
    ('growth-studio', 'Growth Studio', 'Small team expanding beyond the founder; adds workflow depth, reporting, and stronger automation.', 'subscription', 1),
    ('scale-custom', 'Scale Custom', 'Multi-user teams, agencies, accelerators, and partner channels; custom annual contract.', 'subscription', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    billing_type = VALUES(billing_type),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO billing_plan_prices
    (plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens, metadata_json, provider, provider_plan_code, is_default, is_active)
SELECT bp.id, seed.price_code, 'KES', seed.interval_unit, 1, seed.amount, 0, seed.metadata_json, 'paystack', NULL, seed.is_default, 1
FROM billing_plans bp
JOIN (
    SELECT 'compass-free' AS plan_code, 'compass-free-monthly' AS price_code, 'monthly' AS interval_unit, 0.00 AS amount, 1 AS is_default,
           '{"launch_package":true,"launch_tier":"compass_free","ideal_customer":"Solo founder testing the system","rationale":"Removes adoption friction and captures top-of-funnel founder demand.","checkout_available":false,"auto_activated":true}' AS metadata_json
    UNION ALL
    SELECT 'compass-free', 'compass-free-annual', 'yearly', 0.00, 0,
           '{"launch_package":true,"launch_tier":"compass_free","ideal_customer":"Solo founder testing the system","rationale":"Removes adoption friction and captures top-of-funnel founder demand.","checkout_available":false,"auto_activated":true}'
    UNION ALL
    SELECT 'solo-launch', 'solo-launch-monthly', 'monthly', 1990.00, 0,
           '{"launch_package":true,"launch_tier":"solo_launch","ideal_customer":"One-person founder who wants structure and AI help","rationale":"Deliberately affordable versus imported tools; priced as a business operating system, not just a contact list.","checkout_available":true,"requires_provider_plan":true}'
    UNION ALL
    SELECT 'solo-launch', 'solo-launch-annual', 'yearly', 19900.00, 0,
           '{"launch_package":true,"launch_tier":"solo_launch","ideal_customer":"One-person founder who wants structure and AI help","rationale":"Deliberately affordable versus imported tools; priced as a business operating system, not just a contact list.","checkout_available":true,"requires_provider_plan":true}'
    UNION ALL
    SELECT 'founder-plus', 'founder-plus-monthly', 'monthly', 4990.00, 0,
           '{"launch_package":true,"launch_tier":"founder_plus","ideal_customer":"Founder plus one to two collaborators","rationale":"Best good-better tier for serious users; built to be the core revenue driver.","checkout_available":true,"requires_provider_plan":true}'
    UNION ALL
    SELECT 'founder-plus', 'founder-plus-annual', 'yearly', 49900.00, 0,
           '{"launch_package":true,"launch_tier":"founder_plus","ideal_customer":"Founder plus one to two collaborators","rationale":"Best good-better tier for serious users; built to be the core revenue driver.","checkout_available":true,"requires_provider_plan":true}'
    UNION ALL
    SELECT 'growth-studio', 'growth-studio-monthly', 'monthly', 11900.00, 0,
           '{"launch_package":true,"launch_tier":"growth_studio","ideal_customer":"Small team expanding beyond the founder","rationale":"Adds workflow depth, reporting, and stronger automation without enterprise complexity.","checkout_available":true,"requires_provider_plan":true}'
    UNION ALL
    SELECT 'growth-studio', 'growth-studio-annual', 'yearly', 119000.00, 0,
           '{"launch_package":true,"launch_tier":"growth_studio","ideal_customer":"Small team expanding beyond the founder","rationale":"Adds workflow depth, reporting, and stronger automation without enterprise complexity.","checkout_available":true,"requires_provider_plan":true}'
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

INSERT INTO workspace_subscriptions
    (workspace_id, billing_plan_price_id, provider, subscription_status, current_period_start, current_period_end, next_billing_at, created_by, renewal_status, provider_metadata_json)
SELECT w.id, bpp.id, 'internal', 'active', NOW(), NULL, NULL, w.created_by, 'free',
       '{"source":"433_seed_launch_billing_packages","auto_activated":true}'
FROM workspaces w
JOIN billing_plan_prices bpp ON bpp.price_code = 'compass-free-monthly'
LEFT JOIN workspace_subscriptions ws ON ws.workspace_id = w.id
WHERE ws.id IS NULL
  AND w.status <> 'archived';

UPDATE workspaces w
JOIN workspace_subscriptions ws ON ws.workspace_id = w.id
JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
SET w.plan_status = 'active',
    w.updated_at = NOW()
WHERE bpp.price_code = 'compass-free-monthly'
  AND w.plan_status = 'inactive';
