-- Separate voice-enabled prices. Existing Growth/Scale prices remain unchanged.
INSERT INTO billing_plan_prices
    (plan_id, workspace_id, price_code, currency, interval_unit, interval_count, amount, included_tokens,
     metadata_json, provider, provider_plan_code, is_default, is_active)
SELECT bp.id, NULL, seed.price_code, 'KES', seed.interval_unit, 1, seed.amount, seed.included_tokens,
       JSON_OBJECT(
           'checkout_available', false,
           'sales_led', true,
           'voice_beta', true,
           'provider_usage_paid_by_client', true,
           'display_name', seed.display_name,
           'public_display_name', seed.display_name,
           'public_summary', seed.public_summary,
           'public_display_copy', seed.public_summary
       ),
       NULL, NULL, 0, 1
FROM billing_plans bp
JOIN (
    SELECT 'growth-studio' AS plan_code, 'growth-studio-voice-monthly' AS price_code, 'monthly' AS interval_unit,
           27500.00 AS amount, 6000000 AS included_tokens, 'Growth Studio Voice' AS display_name,
           'Growth Studio plus 15 voice agents, 10 concurrent calls, transcription, and Customer Voice review. Provider usage is paid directly by the client.' AS public_summary
    UNION ALL
    SELECT 'growth-studio', 'growth-studio-voice-annual', 'yearly', 275000.00, 6000000, 'Growth Studio Voice',
           'Annual Growth Studio Voice with 15 voice agents, 10 concurrent calls, transcription, and Customer Voice review. Provider usage is paid directly by the client.'
    UNION ALL
    SELECT 'scale-custom', 'scale-voice-monthly', 'monthly', 74000.00, 20000000, 'Scale Voice',
           'Scale Custom plus up to 40 concurrent voice calls, negotiated agents, AI transcription, routing, and support. Provider usage is paid directly by the client.'
    UNION ALL
    SELECT 'scale-custom', 'scale-voice-annual', 'yearly', 740000.00, 20000000, 'Scale Voice',
           'Annual Scale Voice with up to 40 concurrent calls, negotiated agents, AI transcription, routing, and support. Provider usage is paid directly by the client.'
) seed ON seed.plan_code = bp.code
ON DUPLICATE KEY UPDATE
    plan_id = VALUES(plan_id), amount = VALUES(amount), included_tokens = VALUES(included_tokens),
    metadata_json = VALUES(metadata_json), is_default = 0, is_active = VALUES(is_active), updated_at = NOW();

INSERT INTO billing_plan_price_feature_values (billing_plan_price_id, feature_id, value_json, is_enabled)
SELECT bpp.id, feature.id, JSON_OBJECT('value', seed.feature_value), seed.is_enabled
FROM billing_plan_prices bpp
JOIN (
    SELECT 'growth-studio-voice-monthly' AS price_code, 'voice_call_center' AS feature_key, 1 AS feature_value, 1 AS is_enabled
    UNION ALL SELECT 'growth-studio-voice-monthly', 'voice_concurrent_calls', 10, 1
    UNION ALL SELECT 'growth-studio-voice-monthly', 'voice_agent_limit', 15, 1
    UNION ALL SELECT 'growth-studio-voice-monthly', 'voice_recording_enabled', 1, 1
    UNION ALL SELECT 'growth-studio-voice-monthly', 'voice_transcription_enabled', 1, 1
    UNION ALL SELECT 'growth-studio-voice-monthly', 'voice_customer_voice_enabled', 1, 1
    UNION ALL SELECT 'growth-studio-voice-annual', 'voice_call_center', 1, 1
    UNION ALL SELECT 'growth-studio-voice-annual', 'voice_concurrent_calls', 10, 1
    UNION ALL SELECT 'growth-studio-voice-annual', 'voice_agent_limit', 15, 1
    UNION ALL SELECT 'growth-studio-voice-annual', 'voice_recording_enabled', 1, 1
    UNION ALL SELECT 'growth-studio-voice-annual', 'voice_transcription_enabled', 1, 1
    UNION ALL SELECT 'growth-studio-voice-annual', 'voice_customer_voice_enabled', 1, 1
    UNION ALL SELECT 'scale-voice-monthly', 'voice_call_center', 1, 1
    UNION ALL SELECT 'scale-voice-monthly', 'voice_concurrent_calls', 40, 1
    UNION ALL SELECT 'scale-voice-monthly', 'voice_agent_limit', 40, 1
    UNION ALL SELECT 'scale-voice-monthly', 'voice_recording_enabled', 1, 1
    UNION ALL SELECT 'scale-voice-monthly', 'voice_transcription_enabled', 1, 1
    UNION ALL SELECT 'scale-voice-monthly', 'voice_customer_voice_enabled', 1, 1
    UNION ALL SELECT 'scale-voice-annual', 'voice_call_center', 1, 1
    UNION ALL SELECT 'scale-voice-annual', 'voice_concurrent_calls', 40, 1
    UNION ALL SELECT 'scale-voice-annual', 'voice_agent_limit', 40, 1
    UNION ALL SELECT 'scale-voice-annual', 'voice_recording_enabled', 1, 1
    UNION ALL SELECT 'scale-voice-annual', 'voice_transcription_enabled', 1, 1
    UNION ALL SELECT 'scale-voice-annual', 'voice_customer_voice_enabled', 1, 1
) seed ON seed.price_code = bpp.price_code
JOIN billing_package_features feature ON feature.feature_key = seed.feature_key
ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), is_enabled = VALUES(is_enabled), updated_at = NOW();
