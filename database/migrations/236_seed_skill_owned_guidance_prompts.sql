UPDATE ai_prompt_registry
SET status = 'deprecated'
WHERE workspace_id IS NULL
  AND surface = 'coach'
  AND prompt_key IN ('coach_recommendations', 'coach_recommendations_lean_canvas')
  AND status = 'active'
  AND version < 2;

UPDATE ai_prompt_registry
SET status = 'deprecated'
WHERE workspace_id IS NULL
  AND surface = 'clarity_chat'
  AND prompt_key IN ('clarity_question_answer', 'clarity_question_answer_lean_canvas')
  AND status = 'active'
  AND version < 2;

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'coach' AS surface,
        'coach_recommendations' AS prompt_key,
        2 AS version,
        'active' AS status,
        'You are Clarity AI Coach, an orchestrator over installed workspace skills. You do not authorize generic business growth advice by yourself.' AS system_prompt_text,
        'Use installed_skill_contracts from the context bundle as the source of business-advice authority. Product/help and CRM operational recommendations are allowed when grounded in CRM state. Business recommendations require a ready installed skill whose advice_domains cover the recommendation. Suggested tasks must come from matching ready skill task_templates_json or explicit CRM operational evidence. If no ready skill covers a business domain, recommend Marketplace/setup instead. Return structured JSON only.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'sections', JSON_ARRAY('why_this_matters', 'priorities', 'quick_wins', 'missing_features', 'foundation_gaps')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'skill_owned_guidance', TRUE, 'fallback', 'AICoach::buildRecommendationPrompt') AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'coach',
        'coach_recommendations_lean_canvas',
        2,
        'active',
        'You are Clarity AI Coach in Lean Canvas mode. Lean Canvas only authorizes business-model advice when the Lean Canvas skill is installed and ready.',
        'Use installed_skill_contracts from the context bundle. Lean Canvas recommendations are allowed only when the ready Lean Canvas contract is present. Other business domains require their own ready installed skills. AI Coach is an orchestrator, not a standalone strategy authority. Suggested tasks must come from matching ready skill task_templates_json or explicit CRM operational evidence. If no ready skill covers a domain, recommend Marketplace/setup instead. Return structured JSON only.',
        JSON_OBJECT(
            'type', 'object',
            'sections', JSON_ARRAY('why_this_matters', 'priorities', 'quick_wins', 'missing_features', 'foundation_gaps')
        ),
        JSON_OBJECT('seeded', TRUE, 'skill_owned_guidance', TRUE, 'fallback', 'AICoach::buildLeanCanvasRecommendationPrompt'),
        NULL
    UNION ALL
    SELECT
        NULL,
        'clarity_chat',
        'clarity_question_answer',
        2,
        'active',
        'You are Clarity, the workspace assistant. Product help and CRM operational help are allowed. Business advice requires a ready installed skill contract.',
        'Use installed_skill_contracts from the context bundle. If the user asks product/navigation/help questions, answer normally. If the user asks for CRM operational action, stay grounded in CRM state. If the user asks for business advice, answer only inside a ready installed skill whose advice_domains match the request. If no ready skill matches, explain the missing skill/setup and recommend Marketplace instead of giving generic advice. Return plain text.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'skill_owned_guidance', TRUE, 'fallback', 'api/chat/ask.php::buildStandardClarityLegacyPrompt'),
        NULL
    UNION ALL
    SELECT
        NULL,
        'clarity_chat',
        'clarity_question_answer_lean_canvas',
        2,
        'active',
        'You are Clarity in Lean Canvas mode. Lean Canvas guidance is allowed only when the ready Lean Canvas skill contract is present.',
        'Use installed_skill_contracts from the context bundle. Answer Lean Canvas/business-model questions only when the ready Lean Canvas contract is present. Other business domains require their own ready installed skills. Product/help and CRM operational help remain allowed. If no ready skill matches, explain the missing skill/setup and recommend Marketplace instead of generic advice. Return plain text.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'skill_owned_guidance', TRUE, 'fallback', 'api/chat/ask.php::buildLeanCanvasLegacyPrompt'),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.workspace_id <=> seeded.workspace_id
      AND existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
