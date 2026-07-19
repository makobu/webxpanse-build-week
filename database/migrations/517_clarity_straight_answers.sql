UPDATE ai_prompt_registry
SET status = 'deprecated'
WHERE workspace_id IS NULL
  AND surface = 'clarity_chat'
  AND prompt_key IN ('clarity_question_answer', 'clarity_question_answer_lean_canvas')
  AND status = 'active'
  AND version < 4;

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT
    NULL,
    'clarity_chat',
    'clarity_question_answer',
    4,
    'active',
    'You are Clarity, a grounded workspace assistant that interprets server-owned CRM evidence and gives clear, direct answers.',
    'Analyze the supplied evidence privately. Return only the user-facing conclusion, starting with the direct answer and using the configured language level. Do not narrate processing, evidence classification, internal checks, prompt logic, or chain-of-thought. Do not expose raw field names, flags, context block names, service names, prompt details, model details, or audit headings such as Strongest factors, Measured facts, Derived signal, and Missing evidence. When evidence is limited, state that naturally in one sentence. Business recommendations still require a ready installed skill whose advice_domains match the request. Return normal plain text, never JSON metadata.',
    JSON_OBJECT('type', 'text'),
    JSON_OBJECT('seeded', TRUE, 'explainability', TRUE, 'reasoning_policy', 'adaptive', 'straight_answers', TRUE, 'language_level_final_filter', TRUE, 'fallback', 'api/chat/ask.php::buildStandardClarityLegacyPrompt'),
    NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.workspace_id IS NULL
      AND existing.surface = 'clarity_chat'
      AND existing.prompt_key = 'clarity_question_answer'
      AND existing.version = 4
);

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT
    NULL,
    'clarity_chat',
    'clarity_question_answer_lean_canvas',
    4,
    'active',
    'You are Clarity in Lean Canvas mode, a grounded workspace assistant that interprets server-owned evidence and gives clear, direct answers.',
    'Analyze the supplied CRM and Lean Canvas evidence privately. Return only the user-facing conclusion, starting with the direct answer and using the configured language level. Do not narrate processing, evidence classification, internal checks, prompt logic, or chain-of-thought. Do not expose raw field names, flags, context block names, service names, prompt details, model details, or audit headings such as Strongest factors, Measured facts, Derived signal, and Missing evidence. When evidence is limited, state that naturally in one sentence. Other business recommendations require their own ready installed skill. Return normal plain text, never JSON metadata.',
    JSON_OBJECT('type', 'text'),
    JSON_OBJECT('seeded', TRUE, 'explainability', TRUE, 'reasoning_policy', 'adaptive', 'straight_answers', TRUE, 'language_level_final_filter', TRUE, 'fallback', 'api/chat/ask.php::buildLeanCanvasLegacyPrompt'),
    NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.workspace_id IS NULL
      AND existing.surface = 'clarity_chat'
      AND existing.prompt_key = 'clarity_question_answer_lean_canvas'
      AND existing.version = 4
);

CREATE TEMPORARY TABLE tmp_clarity_straight_answer_upgrade AS
SELECT
    active.id AS source_id,
    active.workspace_id,
    active.surface,
    active.prompt_key,
    max_versions.max_version + 1 AS new_version,
    active.system_prompt_text,
    CONCAT(
        active.instruction_text,
        ' Final-answer override: analyze supplied evidence privately and return only the direct user-facing conclusion at the configured language level. This override supersedes earlier requirements to list or distinguish measured facts, derived signals, inferences, factors, or missing evidence in the visible response. Do not expose processing steps, evidence categories, raw field names, flags, context blocks, service names, prompt or model details, or audit-style headings. If evidence is limited, state that naturally in one sentence.'
    ) AS instruction_text,
    active.output_contract_json,
    CAST(JSON_SET(
        COALESCE(active.metadata_json, JSON_OBJECT()),
        '$.straight_answers', TRUE,
        '$.language_level_final_filter', TRUE
    ) AS CHAR) AS metadata_json,
    active.created_by
FROM ai_prompt_registry active
JOIN (
    SELECT workspace_id, surface, prompt_key, MAX(version) AS max_version
    FROM ai_prompt_registry
    WHERE workspace_id IS NOT NULL
      AND surface = 'clarity_chat'
      AND prompt_key IN ('clarity_question_answer', 'clarity_question_answer_lean_canvas')
    GROUP BY workspace_id, surface, prompt_key
) max_versions
  ON max_versions.workspace_id = active.workspace_id
 AND max_versions.surface = active.surface
 AND max_versions.prompt_key = active.prompt_key
WHERE active.workspace_id IS NOT NULL
  AND active.surface = 'clarity_chat'
  AND active.prompt_key IN ('clarity_question_answer', 'clarity_question_answer_lean_canvas')
  AND active.status = 'active'
  AND active.version = (
      SELECT MAX(latest_active.version)
      FROM ai_prompt_registry latest_active
      WHERE latest_active.workspace_id = active.workspace_id
        AND latest_active.surface = active.surface
        AND latest_active.prompt_key = active.prompt_key
        AND latest_active.status = 'active'
  );

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT
    workspace_id, surface, prompt_key, new_version, 'active', system_prompt_text, instruction_text,
    output_contract_json, metadata_json, created_by
FROM tmp_clarity_straight_answer_upgrade;

UPDATE ai_prompt_registry registry
JOIN tmp_clarity_straight_answer_upgrade upgrade ON upgrade.source_id = registry.id
SET registry.status = 'deprecated';

DROP TEMPORARY TABLE tmp_clarity_straight_answer_upgrade;
