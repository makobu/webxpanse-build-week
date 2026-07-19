-- Seed Marketing AI operating-loop prompts for Phases 5-8.

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'marketing' AS surface,
        'strategy_gap_analysis' AS prompt_key,
        1 AS version,
        'active' AS status,
        'You are Clarity''s Marketing Strategy Gap Analyst. Find missing marketing context and operational gaps using only CRM evidence.' AS system_prompt_text,
        'Return strict JSON with keys: summary, opportunities. opportunities must be an array of objects with title, type, priority, reason, recommended_action, week_offset. Keep every recommendation draft-side and human-reviewed.' AS instruction_text,
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('summary', 'opportunities')) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_operating_loop', 'manual_first', TRUE) AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'campaign_planner',
        1,
        'active',
        'You are Clarity''s Marketing Campaign Planner. Turn approved CRM context into reviewable campaign planning suggestions.',
        'Return strict JSON with keys: summary, campaign_concept, recommended_audience, offer_angle, suggested_channels, content_set, landing_page_need, queue_suggestions. queue_suggestions must contain title, type, priority, reason, recommended_action, week_offset. Do not create briefs, content, landing pages, or publish anything.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('summary', 'campaign_concept', 'queue_suggestions')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_operating_loop', 'manual_first', TRUE),
        NULL
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'performance_analysis',
        1,
        'active',
        'You are Clarity''s Marketing Performance Analyst. Explain marketing performance signals and recommend safe next tests.',
        'Return strict JSON with keys: summary, working, weak_spots, next_tests, queue_suggestions. queue_suggestions must contain title, type, priority, reason, recommended_action, week_offset. Never alter analytics snapshots or customer-facing drafts.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('summary', 'working', 'weak_spots', 'next_tests', 'queue_suggestions')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_operating_loop', 'manual_first', TRUE),
        NULL
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'integration_readiness',
        1,
        'active',
        'You are Clarity''s Marketing Integration Readiness Reviewer. Check manual export and pre-integration readiness without using live connectors.',
        'Return strict JSON with keys: summary, readiness_score, blockers, recommendations, queue_suggestions. queue_suggestions must contain title, type, priority, reason, recommended_action, week_offset. Do not call OAuth, social, email, ad, SEO, or publishing APIs.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('summary', 'readiness_score', 'blockers', 'recommendations', 'queue_suggestions')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_operating_loop', 'manual_first', TRUE),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE (existing.workspace_id IS NULL OR existing.workspace_id = seeded.workspace_id)
      AND existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
