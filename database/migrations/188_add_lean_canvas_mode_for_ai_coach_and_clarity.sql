ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_problem TEXT NULL AFTER draft_reading_level;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_customer_segments TEXT NULL AFTER lean_problem;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_unique_value_proposition TEXT NULL AFTER lean_customer_segments;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_solution TEXT NULL AFTER lean_unique_value_proposition;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_channels TEXT NULL AFTER lean_solution;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_revenue_streams TEXT NULL AFTER lean_channels;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_cost_structure TEXT NULL AFTER lean_revenue_streams;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_key_metrics TEXT NULL AFTER lean_cost_structure;

ALTER TABLE user_strategy_profiles
    ADD COLUMN lean_unfair_advantage TEXT NULL AFTER lean_key_metrics;

INSERT INTO ai_prompt_registry
    (surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        'coach' AS surface,
        'coach_recommendations_lean_canvas' AS prompt_key,
        1 AS version,
        'active' AS status,
        'You are Clarity, an AI co-founder operating in Lean Canvas mode. Use the user''s Lean Canvas as the primary frame for recommendations and stay grounded in the provided context.' AS system_prompt_text,
        'Produce actionable coach recommendations that prioritize missing Lean Canvas blocks, contradictions, validation experiments, channel ideas, metric definitions, and CRM execution tied to the current canvas. Respect readiness gaps. Return structured JSON only, keeping the same coach response sections.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'sections', JSON_ARRAY('why_this_matters', 'priorities', 'quick_wins', 'missing_features', 'foundation_gaps')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'fallback', 'AICoach::buildLeanCanvasRecommendationPrompt') AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        'clarity_chat',
        'clarity_question_answer_lean_canvas',
        1,
        'active',
        'You are Clarity, the AI co-founder in Lean Canvas mode. Answer through the current Lean Canvas, ask targeted follow-up questions for missing blocks, and stay grounded in the supplied context.',
        'Use the context bundle to answer the user question using Lean Canvas framing. If the canvas is incomplete, ask targeted follow-up questions for the most important missing blocks. If the user appears to be editing a canvas block, propose a confirmation-friendly update instead of auto-saving. Return plain text unless a JSON contract is explicitly requested.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'fallback', 'api/chat/ask.php::buildLeanCanvasLegacyPrompt'),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
