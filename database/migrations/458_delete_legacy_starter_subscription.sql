-- Remove the legacy Starter Subscription package now that Compass Free is the canonical free subscription.

UPDATE workspace_subscriptions ws
JOIN billing_plan_prices starter_price ON starter_price.id = ws.billing_plan_price_id
JOIN billing_plans starter_plan ON starter_plan.id = starter_price.plan_id
JOIN billing_plan_prices compass_price ON compass_price.price_code = 'compass-free-monthly'
SET ws.billing_plan_price_id = compass_price.id,
    ws.updated_at = NOW()
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

UPDATE workspace_subscriptions ws
JOIN billing_plan_prices starter_price ON starter_price.id = ws.scheduled_billing_plan_price_id
JOIN billing_plans starter_plan ON starter_plan.id = starter_price.plan_id
SET ws.scheduled_billing_plan_price_id = NULL,
    ws.scheduled_change_type = NULL,
    ws.scheduled_change_at = NULL,
    ws.scheduled_change_metadata_json = NULL,
    ws.updated_at = NOW()
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

UPDATE workspace_subscription_cycles wsc
JOIN billing_plan_prices starter_price ON starter_price.id = wsc.billing_plan_price_id
JOIN billing_plans starter_plan ON starter_plan.id = starter_price.plan_id
JOIN billing_plan_prices compass_price ON compass_price.price_code = 'compass-free-monthly'
SET wsc.billing_plan_price_id = compass_price.id,
    wsc.updated_at = NOW()
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

UPDATE billing_checkout_sessions bcs
JOIN billing_plan_prices starter_price ON starter_price.id = bcs.billing_plan_price_id
JOIN billing_plans starter_plan ON starter_plan.id = starter_price.plan_id
JOIN billing_plan_prices compass_price ON compass_price.price_code = 'compass-free-monthly'
SET bcs.billing_plan_price_id = compass_price.id,
    bcs.updated_at = NOW()
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

DELETE bpm
FROM billing_plan_price_payment_methods bpm
JOIN billing_plan_prices starter_price ON starter_price.id = bpm.billing_plan_price_id
JOIN billing_plans starter_plan ON starter_plan.id = starter_price.plan_id
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

DELETE bpfv
FROM billing_plan_feature_values bpfv
JOIN billing_plans starter_plan ON starter_plan.id = bpfv.plan_id
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

DELETE starter_price
FROM billing_plan_prices starter_price
JOIN billing_plans starter_plan ON starter_plan.id = starter_price.plan_id
WHERE starter_plan.code = 'starter-subscription'
  AND starter_plan.billing_type = 'subscription';

DELETE FROM billing_plans
WHERE code = 'starter-subscription'
  AND billing_type = 'subscription';
