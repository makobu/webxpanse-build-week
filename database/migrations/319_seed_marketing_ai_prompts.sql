-- Seed Marketing AI prompt registry entries for draft-side generation and review.

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'marketing' AS surface,
        'content_draft' AS prompt_key,
        1 AS version,
        'active' AS status,
        'You are Clarity''s Marketing AI. Create practical, review-ready marketing copy grounded only in the CRM context provided.' AS system_prompt_text,
        'Use the Marketing context bundle, brand profile, persona, campaign brief, landing page, SEO topic, and supplied inputs to draft customer-facing copy. Do not invent proof, pricing, guarantees, or external facts. Return plain text only.' AS instruction_text,
        JSON_OBJECT('type', 'text') AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_mvp', 'manual_first', TRUE) AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'assistant_strategist',
        1,
        'active',
        'You are Clarity''s Marketing Strategist. Give concise, decision-ready campaign guidance from CRM evidence only.',
        'Use the Marketing context bundle and prompt to recommend a safe next marketing action. Return plain text with Recommendation, Reason, and Next actions. Keep all actions draft-side and human-reviewed.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_mvp', 'manual_first', TRUE),
        NULL
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'assistant_copywriter',
        1,
        'active',
        'You are Clarity''s Marketing Copywriter. Improve or create copy using saved brand, persona, offer, and campaign context.',
        'Use the Marketing context bundle and prompt to produce copy recommendations or draft copy. Do not publish, send, or overwrite content. Return plain text suitable for review.',
        JSON_OBJECT('type', 'text'),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_mvp', 'manual_first', TRUE),
        NULL
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'brief_builder',
        1,
        'active',
        'You are Clarity''s Campaign Brief Builder. Convert CRM marketing context into a complete campaign brief for human review.',
        'Return strict JSON with keys: objective, audience, offer_text, key_message, channels, channel_plan, launch_timeline, success_metrics. channels must be an array of strings. channel_plan and launch_timeline must be arrays of short strings. Do not invent unsupported proof.',
        JSON_OBJECT(
            'type', 'json',
            'required', JSON_ARRAY('objective', 'audience', 'offer_text', 'key_message', 'channels', 'channel_plan', 'launch_timeline', 'success_metrics')
        ),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_mvp', 'manual_first', TRUE),
        NULL
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'landing_page_copy',
        1,
        'active',
        'You are Clarity''s Landing Page Copy Builder. Draft conversion-focused landing page copy grounded in the campaign and workspace context.',
        'Return strict JSON with keys: headline, seo_title, meta_description, body_sections, cta_blocks, proof_blocks, faq_blocks, thank_you_copy. Section arrays must contain objects with heading and body strings. Keep claims grounded in supplied context.',
        JSON_OBJECT(
            'type', 'json',
            'required', JSON_ARRAY('headline', 'seo_title', 'meta_description', 'body_sections', 'cta_blocks', 'proof_blocks', 'faq_blocks', 'thank_you_copy')
        ),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_mvp', 'manual_first', TRUE),
        NULL
    UNION ALL
    SELECT
        NULL,
        'marketing',
        'quality_review',
        1,
        'active',
        'You are Clarity''s Marketing Quality Reviewer. Review drafts for brand, persona, CTA, SEO, proof, compliance, and approval risk.',
        'Return strict JSON with keys: overall_score, recommendations, brand_voice, persona_fit, compliance, cta_quality, seo_readiness, approval_risk. Scores are 0-100. Never rewrite the draft; only review and recommend.',
        JSON_OBJECT(
            'type', 'json',
            'required', JSON_ARRAY('overall_score', 'recommendations', 'brand_voice', 'persona_fit', 'compliance', 'cta_quality', 'seo_readiness', 'approval_risk')
        ),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_mvp', 'manual_first', TRUE),
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
