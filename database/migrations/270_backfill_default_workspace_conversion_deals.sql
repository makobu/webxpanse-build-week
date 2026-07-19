INSERT INTO deals (
    workspace_id,
    title,
    description,
    contact_id,
    assigned_to,
    created_by,
    stage,
    value,
    probability,
    expected_close_date,
    currency,
    lead_source,
    custom_fields,
    created_at,
    updated_at
)
SELECT
    src.default_workspace_id,
    CONCAT(src.owner_workspace_name, ' conversion'),
    CONCAT('Default workspace conversion opportunity for workspace #', src.owner_workspace_id, '.'),
    src.contact_id,
    src.assigned_to,
    src.created_by,
    src.target_stage,
    src.value_amount,
    CASE src.target_stage
        WHEN 'qualification' THEN 40
        WHEN 'proposal' THEN 60
        WHEN 'negotiation' THEN 75
        WHEN 'closed_won' THEN 100
        WHEN 'closed_lost' THEN 0
        ELSE 20
    END,
    CASE WHEN src.trial_ends_at IS NOT NULL THEN DATE(src.trial_ends_at) ELSE NULL END,
    src.currency,
    'other',
    JSON_OBJECT(
        'default_workspace_pipeline', true,
        'owner_workspace_id', src.owner_workspace_id,
        'owner_user_id', src.owner_user_id,
        'owner_workspace_plan_status', src.owner_workspace_plan_status,
        'trial_ends_at', src.trial_ends_at,
        'conversion_signal', src.conversion_signal,
        'last_pipeline_sync_at', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ')
    ),
    NOW(),
    NOW()
FROM (
    SELECT
        map.default_workspace_id,
        map.owner_workspace_id,
        map.owner_user_id,
        map.contact_id,
        COALESCE(NULLIF(w.name, ''), CONCAT('Workspace #', map.owner_workspace_id)) AS owner_workspace_name,
        w.status AS owner_workspace_status,
        w.plan_status AS owner_workspace_plan_status,
        COALESCE(w.trial_ends_at, ws.trial_ends_at) AS trial_ends_at,
        c.assigned_to,
        COALESCE(c.assigned_to, map.owner_user_id) AS created_by,
        COALESCE(bpp.amount, checkout.amount, 0) AS value_amount,
        UPPER(LEFT(COALESCE(bpp.currency, checkout.currency, 'KES'), 3)) AS currency,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM workspace_subscriptions ws_paid
                JOIN billing_transactions bt
                  ON bt.subscription_id = ws_paid.id
                 AND bt.workspace_id = ws_paid.workspace_id
                WHERE ws_paid.workspace_id = map.owner_workspace_id
                  AND ws_paid.subscription_status = 'active'
                  AND ws_paid.current_period_end IS NOT NULL
                  AND ws_paid.current_period_end >= NOW()
                  AND bt.transaction_type = 'subscription_charge'
                  AND bt.transaction_status = 'succeeded'
                LIMIT 1
            ) THEN 'closed_won'
            WHEN w.status IN ('suspended', 'archived', 'deleted', 'inactive', 'locked')
              OR ws.subscription_status IN ('expired', 'cancelled')
              OR (
                    COALESCE(w.trial_ends_at, ws.trial_ends_at) IS NOT NULL
                AND COALESCE(w.trial_ends_at, ws.trial_ends_at) < NOW()
                AND COALESCE(ws.subscription_status, w.plan_status) IN ('trialing', 'inactive')
              ) THEN 'closed_lost'
            WHEN w.plan_status = 'past_due'
              OR ws.subscription_status = 'past_due'
              OR checkout.status IN ('pending', 'processing', 'failed', 'cancelled')
              OR EXISTS (
                    SELECT 1
                    FROM billing_transactions bt_failed
                    WHERE bt_failed.workspace_id = map.owner_workspace_id
                      AND bt_failed.transaction_type = 'subscription_charge'
                      AND bt_failed.transaction_status = 'failed'
                    LIMIT 1
              ) THEN 'negotiation'
            WHEN (w.plan_status = 'trialing' OR ws.subscription_status = 'trialing')
              AND COALESCE(w.trial_ends_at, ws.trial_ends_at) IS NOT NULL
              AND COALESCE(w.trial_ends_at, ws.trial_ends_at) <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                THEN 'proposal'
            WHEN w.plan_status = 'trialing' OR ws.subscription_status = 'trialing'
                THEN 'qualification'
            ELSE 'prospecting'
        END AS target_stage,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM workspace_subscriptions ws_paid
                JOIN billing_transactions bt
                  ON bt.subscription_id = ws_paid.id
                 AND bt.workspace_id = ws_paid.workspace_id
                WHERE ws_paid.workspace_id = map.owner_workspace_id
                  AND ws_paid.subscription_status = 'active'
                  AND ws_paid.current_period_end IS NOT NULL
                  AND ws_paid.current_period_end >= NOW()
                  AND bt.transaction_type = 'subscription_charge'
                  AND bt.transaction_status = 'succeeded'
                LIMIT 1
            ) THEN 'paid_subscription'
            WHEN w.status IN ('suspended', 'archived', 'deleted', 'inactive', 'locked')
                THEN 'workspace_inactive'
            WHEN ws.subscription_status IN ('expired', 'cancelled')
              OR (
                    COALESCE(w.trial_ends_at, ws.trial_ends_at) IS NOT NULL
                AND COALESCE(w.trial_ends_at, ws.trial_ends_at) < NOW()
                AND COALESCE(ws.subscription_status, w.plan_status) IN ('trialing', 'inactive')
              ) THEN 'trial_expired'
            WHEN w.plan_status = 'past_due'
              OR ws.subscription_status = 'past_due'
              OR checkout.status IN ('failed', 'cancelled')
              OR EXISTS (
                    SELECT 1
                    FROM billing_transactions bt_failed
                    WHERE bt_failed.workspace_id = map.owner_workspace_id
                      AND bt_failed.transaction_type = 'subscription_charge'
                      AND bt_failed.transaction_status = 'failed'
                    LIMIT 1
              ) THEN 'payment_failed'
            WHEN checkout.status IN ('pending', 'processing') THEN 'payment_started'
            WHEN (w.plan_status = 'trialing' OR ws.subscription_status = 'trialing')
              AND COALESCE(w.trial_ends_at, ws.trial_ends_at) IS NOT NULL
              AND COALESCE(w.trial_ends_at, ws.trial_ends_at) <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                THEN 'trial_ending_soon'
            WHEN w.plan_status = 'trialing' OR ws.subscription_status = 'trialing'
                THEN 'trial_active'
            ELSE 'signed_in'
        END AS conversion_signal
    FROM default_workspace_owner_contacts map
    JOIN contacts c
      ON c.id = map.contact_id
     AND c.workspace_id = map.default_workspace_id
    JOIN workspaces w
      ON w.id = map.owner_workspace_id
    LEFT JOIN workspace_subscriptions ws
      ON ws.id = (
            SELECT ws_pick.id
            FROM workspace_subscriptions ws_pick
            WHERE ws_pick.workspace_id = map.owner_workspace_id
            ORDER BY FIELD(ws_pick.subscription_status, 'active', 'trialing', 'past_due', 'expired', 'cancelled'), ws_pick.id DESC
            LIMIT 1
        )
    LEFT JOIN billing_plan_prices bpp
      ON bpp.id = ws.billing_plan_price_id
    LEFT JOIN billing_checkout_sessions checkout
      ON checkout.id = (
            SELECT checkout_pick.id
            FROM billing_checkout_sessions checkout_pick
            WHERE checkout_pick.workspace_id = map.owner_workspace_id
              AND checkout_pick.billing_plan_price_id IS NOT NULL
            ORDER BY checkout_pick.id DESC
            LIMIT 1
        )
    WHERE map.relationship_status = 'active'
      AND map.conversion_deal_id IS NULL
      AND NOT EXISTS (
            SELECT 1
            FROM deals existing_deal
            WHERE existing_deal.workspace_id = map.default_workspace_id
              AND JSON_UNQUOTE(JSON_EXTRACT(existing_deal.custom_fields, '$.default_workspace_pipeline')) IN ('true', '1')
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(existing_deal.custom_fields, '$.owner_workspace_id')) AS UNSIGNED) = map.owner_workspace_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(existing_deal.custom_fields, '$.owner_user_id')) AS UNSIGNED) = map.owner_user_id
            LIMIT 1
        )
) src;

UPDATE default_workspace_owner_contacts map
JOIN deals d
  ON d.workspace_id = map.default_workspace_id
 AND JSON_UNQUOTE(JSON_EXTRACT(d.custom_fields, '$.default_workspace_pipeline')) IN ('true', '1')
 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(d.custom_fields, '$.owner_workspace_id')) AS UNSIGNED) = map.owner_workspace_id
 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(d.custom_fields, '$.owner_user_id')) AS UNSIGNED) = map.owner_user_id
SET map.conversion_deal_id = d.id
WHERE map.conversion_deal_id IS NULL;
