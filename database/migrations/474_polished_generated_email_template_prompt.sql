-- Keep learned email template generation copy-focused and apply presentation deterministically at render time.

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'smart_templates' AS surface,
        'email_pack_generation' AS prompt_key,
        5 AS version,
        'active' AS status,
        'You generate learned CRM email template copy from supplied profile context and real email learning evidence. The application owns the final email presentation shell, so generate only copy and simple body fragments.' AS system_prompt_text,
        'Return strict JSON with an "emails" array containing exactly 5 templates for these template_key values: lead_intro, demo_or_discovery_invite, proposal_follow_up, re_engagement, post_win_onboarding. Each item must include template_key, name, category, subject, body_html, body_text, variables, description, and match_metadata_json. Use the EMAIL LEARNING EVIDENCE block as the primary voice and structure guide. body_html must be body-fragment only: paragraphs, short lists, strong emphasis, and normal anchor tags are allowed; do not include <!DOCTYPE>, html, head, body, meta, style, table, wrapper, card, layout shell, images, scripts, external assets, brand chrome, or no-layout-breaking CSS. Preserve placeholders such as {first_name}, {company}, {sender_name}, and {meeting_link}. Keep writing concise, specific, professional, under 170 words, with one clear CTA. Do not invent product features, customer names, prices, guarantees, URLs, or unsupported claims. body_text must be a faithful plain-text version. Do not wrap JSON in markdown.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('emails'),
            'emails_required_keys', JSON_ARRAY('template_key', 'name', 'category', 'subject', 'body_html', 'body_text', 'variables', 'description', 'match_metadata_json'),
            'quality_rules', JSON_ARRAY('learned_evidence_required', 'workspace_specific', 'body_fragment_only', 'no_layout_shell', 'one_clear_cta', 'under_170_words', 'no_generic_library_copy', 'email_safe_html')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'email', 'quality_version', 5, 'requires_learning_evidence', TRUE, 'presentation_applied_at_render', TRUE) AS metadata_json,
        NULL AS created_by
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.workspace_id IS NULL
      AND existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
