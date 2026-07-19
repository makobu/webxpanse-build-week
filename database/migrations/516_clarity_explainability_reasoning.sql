UPDATE ai_prompt_registry
SET status = 'deprecated'
WHERE workspace_id IS NULL
  AND surface = 'clarity_chat'
  AND prompt_key IN ('clarity_question_answer', 'clarity_question_answer_lean_canvas')
  AND status = 'active'
  AND version < 3;

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'clarity_chat' AS surface,
        'clarity_question_answer' AS prompt_key,
        3 AS version,
        'active' AS status,
        'You are Clarity, a grounded workspace assistant that can interpret server-owned CRM evidence and explain how derived scores and signals were produced.' AS system_prompt_text,
        'Answer product and CRM questions from the supplied evidence. An explanation request is interpretation, not new strategic advice: answer the causal question first, name the strongest factors and their supplied values, distinguish measured facts from derived signals and inferences, and state missing or stale evidence. Never invent a score factor and never reveal hidden chain-of-thought; provide a concise auditable rationale. Business recommendations still require a ready installed skill whose advice_domains match the request. Return normal plain text, never JSON metadata.' AS instruction_text,
        JSON_OBJECT('type', 'text') AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'explainability', TRUE, 'reasoning_policy', 'adaptive', 'fallback', 'api/chat/ask.php::buildStandardClarityLegacyPrompt') AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'clarity_chat',
        'clarity_question_answer_lean_canvas',
        3,
        'active',
        'You are Clarity in Lean Canvas mode, a grounded workspace assistant that can interpret server-owned CRM evidence and explain derived scores and signals.',
        'Use the ready Lean Canvas contract for business-model advice. Explanation requests about supplied CRM facts, calculations, scores, and signals are interpretation rather than new advice: lead with the answer, cite the strongest supplied factors and values, separate measured facts from derived signals and inferences, and state evidence gaps. Never invent evidence or reveal hidden chain-of-thought; provide a concise auditable rationale. Other business recommendations require their own ready installed skill. Return normal plain text, never JSON metadata.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'explainability', TRUE, 'reasoning_policy', 'adaptive', 'fallback', 'api/chat/ask.php::buildLeanCanvasLegacyPrompt'),
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

CREATE TEMPORARY TABLE tmp_clarity_prompt_explainability_upgrade AS
SELECT
    active.id AS source_id,
    active.workspace_id,
    active.surface,
    active.prompt_key,
    max_versions.max_version + 1 AS new_version,
    active.system_prompt_text,
    CONCAT(
        active.instruction_text,
        ' Explanation requests about supplied CRM facts, calculations, scores, or signals are interpretation rather than new business advice. Answer the causal question first, cite the strongest supplied factors and values, distinguish measured facts from derived signals and inferences, and state missing or stale evidence. Never invent evidence or reveal hidden chain-of-thought; provide a concise auditable rationale. Return normal prose for text contracts and never emit schema metadata.'
    ) AS instruction_text,
    active.output_contract_json,
    JSON_SET(COALESCE(active.metadata_json, JSON_OBJECT()), '$.explainability', TRUE, '$.reasoning_policy', 'adaptive') AS metadata_json,
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
FROM tmp_clarity_prompt_explainability_upgrade;

UPDATE ai_prompt_registry registry
JOIN tmp_clarity_prompt_explainability_upgrade upgrade ON upgrade.source_id = registry.id
SET registry.status = 'deprecated';

DROP TEMPORARY TABLE tmp_clarity_prompt_explainability_upgrade;
