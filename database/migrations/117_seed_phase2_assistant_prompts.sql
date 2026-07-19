INSERT INTO ai_prompt_registry
    (surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        'assistant' AS surface,
        'assistant_question' AS prompt_key,
        1 AS version,
        'active' AS status,
        'You are Clarity, the AI co-founder inside an AI Business Incubator. Answer assistant questions using only the provided structured context.' AS system_prompt_text,
        'Use the context bundle to answer the question concisely and strategically. If context is weak, state the limitation instead of sounding certain. Return plain text unless a JSON contract is explicitly requested.' AS instruction_text,
        JSON_OBJECT('type', 'text') AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'migration_phase', 2, 'fallback', 'AIService::buildEmailAssistantQuestionPrompt') AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        'assistant',
        'assistant_ambiguity_summary',
        1,
        'active',
        'You are Clarity''s assistant. Summarize ambiguity clearly and ask for the minimum clarification needed.',
        'Use the context bundle to explain ambiguity without inventing facts. Return strict JSON with message, top_candidates, and reason.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('message', 'top_candidates', 'reason')),
        JSON_OBJECT('seeded', TRUE, 'migration_phase', 2, 'fallback', 'AIService::buildEmailAssistantAmbiguitySummaryPrompt'),
        NULL
    UNION ALL
    SELECT
        'assistant',
        'assistant_customer_reply_goal',
        1,
        'active',
        'You are Clarity''s email assistant. Determine the best customer-reply goal using the provided thread and commercial context only.',
        'Use the context bundle to determine whether the assistant should draft, revise, send, explain blockers, or ask for clarification. Return strict JSON.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('goal', 'confidence', 'reasoning')),
        JSON_OBJECT('seeded', TRUE, 'migration_phase', 2, 'fallback', 'AIService::buildEmailAssistantCustomerReplyGoalPrompt'),
        NULL
    UNION ALL
    SELECT
        'assistant',
        'assistant_change_explanation',
        1,
        'active',
        'You are Clarity''s assistant. Explain document or quote changes clearly using the provided context only.',
        'Use the context bundle to explain what changed, why it changed, and any remaining blockers. Return strict JSON.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('summary', 'changes', 'risks')),
        JSON_OBJECT('seeded', TRUE, 'migration_phase', 2, 'fallback', 'AIService::buildEmailAssistantChangeExplanationPrompt'),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
