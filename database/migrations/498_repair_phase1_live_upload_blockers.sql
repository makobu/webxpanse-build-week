-- Repair repeatable Phase 1 live-upload blockers found by production readiness gates.
-- This migration removes proven default-workspace demo contamination, disables broken
-- local calendar integrations, turns credentialless risky payment methods off by
-- default, and normalizes template variable declarations.

DELETE FROM notifications
WHERE workspace_id = 1
  AND (
       LOWER(CONCAT_WS(' ', title, message, link, ai_insight, ai_action)) LIKE '%codex verification presentation workspace%'
    OR LOWER(CONCAT_WS(' ', title, message, link, ai_insight, ai_action)) LIKE '%codex verification prospect%'
    OR LOWER(CONCAT_WS(' ', title, message, link, ai_insight, ai_action)) LIKE '%presentation_workspace%'
  );

UPDATE calendar_integrations
SET sync_enabled = 0,
    availability_enabled = 0,
    reconnect_required = 1,
    scope_status = 'unknown',
    availability_last_error = 'Disabled by migration 498: enabled calendar integration had no access token during live-upload readiness.',
    updated_at = NOW()
WHERE sync_enabled = 1
  AND (access_token IS NULL OR TRIM(access_token) = '');

UPDATE workspace_billing_settings
SET payment_card_enabled = CASE
        WHEN COALESCE(NULLIF(paystack_secret_key, ''), NULL) IS NULL THEN 0
        ELSE payment_card_enabled
    END,
    payment_mpesa_enabled = CASE
        WHEN COALESCE(NULLIF(mpesa_consumer_key, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_consumer_secret, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_shortcode, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_passkey, ''), NULL) IS NULL THEN 0
        ELSE payment_mpesa_enabled
    END,
    mpesa_enabled = CASE
        WHEN COALESCE(NULLIF(mpesa_consumer_key, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_consumer_secret, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_shortcode, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_passkey, ''), NULL) IS NULL THEN 0
        ELSE mpesa_enabled
    END,
    paystack_mode = CASE
        WHEN COALESCE(NULLIF(paystack_secret_key, ''), NULL) IS NULL THEN 'test'
        ELSE paystack_mode
    END,
    mpesa_environment = CASE
        WHEN COALESCE(NULLIF(mpesa_consumer_key, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_consumer_secret, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_shortcode, ''), NULL) IS NULL
          OR COALESCE(NULLIF(mpesa_passkey, ''), NULL) IS NULL THEN 'sandbox'
        ELSE mpesa_environment
    END,
    updated_at = NOW()
WHERE id = 1;

UPDATE email_templates
SET variables = JSON_ARRAY('first_name')
WHERE id = 17
  AND slug = 'default-webxpanse-owner-welcome';

UPDATE email_templates
SET variables = JSON_ARRAY('workspace_name', 'issue_summary', 'recommended_action', 'operator_action_url')
WHERE template_key = 'support_escalation'
  AND (
       slug LIKE 'platform-%'
    OR slug LIKE 'library-%'
  );
