CREATE TABLE IF NOT EXISTS ai_prompt_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    surface VARCHAR(64) NOT NULL,
    prompt_key VARCHAR(128) NOT NULL,
    version INT NOT NULL,
    status ENUM('active', 'draft', 'deprecated') NOT NULL DEFAULT 'draft',
    system_prompt_text TEXT NOT NULL,
    instruction_text MEDIUMTEXT NOT NULL,
    output_contract_json JSON NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_surface_prompt_version (surface, prompt_key, version),
    KEY idx_surface_prompt_status (surface, prompt_key, status)
);

INSERT INTO ai_prompt_registry
    (surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        'coach' AS surface,
        'coach_recommendations' AS prompt_key,
        1 AS version,
        'active' AS status,
        'You are Clarity, an AI co-founder for structured growth. Give concrete, high-signal recommendations aligned to the provided context only.' AS system_prompt_text,
        'Use the provided context bundle to produce actionable coach recommendations. Respect readiness gaps and do not recommend unavailable capabilities unless the recommendation is to configure them. Return structured JSON only.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'sections', JSON_ARRAY('why_this_matters', 'priorities', 'quick_wins', 'missing_features', 'foundation_gaps')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'fallback', 'AICoach::buildRecommendationPrompt') AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        'clarity_chat',
        'clarity_question_answer',
        1,
        'active',
        'You are Clarity, the AI co-founder for structured growth. Answer using the supplied context, stay grounded, and avoid inventing unsupported facts.',
        'Use the context bundle to answer the user question. If context is weak, respond cautiously. Include only the information supported by the bundle. Return plain text unless a JSON contract is explicitly requested.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'fallback', 'AIService::buildWebsiteAssistantPrompt'),
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
        JSON_OBJECT('seeded', TRUE, 'fallback', 'AIService::buildEmailAssistantCustomerReplyGoalPrompt'),
        NULL
    UNION ALL
    SELECT
        'commercial_assistant',
        'assistant_commercial_reply',
        1,
        'active',
        'You are Clarity''s commercial reply assistant. Draft clear, professional customer-facing replies grounded in the supplied deal, invoice, and thread context.',
        'Use the context bundle to draft the requested commercial reply. Do not invent pricing or commitments beyond the provided context. Return strict JSON with subject, plain_body, and explanation.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('subject', 'plain_body', 'explanation')),
        JSON_OBJECT('seeded', TRUE, 'fallback', 'AIService::buildEmailAssistantCommercialReplyPrompt'),
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
        JSON_OBJECT('seeded', TRUE, 'fallback', 'AIService::buildEmailAssistantChangeExplanationPrompt'),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
