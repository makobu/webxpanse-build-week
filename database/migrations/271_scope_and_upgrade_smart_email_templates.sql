-- Scope smart template packs to the active workspace and upgrade email-generation quality guidance.

ALTER TABLE smart_template_sets
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

UPDATE smart_template_sets sts
SET sts.workspace_id = COALESCE(
    (
        SELECT et.workspace_id
        FROM email_templates et
        WHERE et.smart_template_set_id = sts.id
          AND et.workspace_id IS NOT NULL
          AND et.workspace_id > 0
        ORDER BY et.id ASC
        LIMIT 1
    ),
    (
        SELECT wm.workspace_id
        FROM workspace_memberships wm
        WHERE wm.user_id = sts.user_id
          AND wm.membership_status = 'active'
        ORDER BY wm.is_owner DESC, wm.id ASC
        LIMIT 1
    ),
    1
)
WHERE sts.workspace_id IS NULL OR sts.workspace_id <= 0;

ALTER TABLE smart_template_sets
    MODIFY COLUMN workspace_id INT NOT NULL,
    ADD INDEX IF NOT EXISTS idx_smart_template_sets_workspace_user_status (workspace_id, user_id, status);

UPDATE email_templates et
JOIN smart_template_sets sts ON sts.id = et.smart_template_set_id
SET et.workspace_id = sts.workspace_id
WHERE et.is_ai_generated = 1
  AND (et.workspace_id IS NULL OR et.workspace_id <= 0);

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'smart_templates' AS surface,
        'email_pack_generation' AS prompt_key,
        2 AS version,
        'active' AS status,
        'You generate polished, workspace-specific CRM email template packs. Use only the supplied company, strategy, and idea-validation context. The writing must feel specific, credible, concise, and ready for a real sales or onboarding motion.' AS system_prompt_text,
        'Return strict JSON with an "emails" array containing exactly 5 templates for these template_key values: lead_intro, demo_or_discovery_invite, proposal_follow_up, re_engagement, post_win_onboarding. Each item must include template_key, name, category, subject, body_html, body_text, variables, and description. Quality bar: write in a plain professional voice; open with context the recipient can recognize; include one concrete reason for outreach; make the value proposition specific to the supplied context; include one clear CTA; avoid filler phrases such as "just checking in", "touching base", "hope all is well", "game changer", and "unlock potential"; keep each email under 170 words; keep subjects under 70 characters; preserve placeholders like {first_name}, {company}, {sender_name}, and {meeting_link} where useful; do not invent product features, prices, guarantees, customer names, or URLs. HTML must be simple email-safe markup with paragraphs and optional small callout blocks only, no external assets. body_text must be a faithful plain-text version. Do not wrap the JSON in markdown.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('emails'),
            'emails_required_keys', JSON_ARRAY('template_key', 'name', 'category', 'subject', 'body_html', 'body_text', 'variables', 'description'),
            'quality_rules', JSON_ARRAY('workspace_specific', 'one_clear_cta', 'under_170_words', 'no_invented_claims', 'email_safe_html')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'email', 'quality_version', 2) AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'smart_templates',
        'workflow_pack_generation',
        2,
        'active',
        'You generate practical, workspace-specific CRM workflow template packs that coordinate with matching generated email templates. Use only the supplied context and supported workflow capabilities.',
        'Return strict JSON with a "workflows" array containing exactly 5 templates for these template_key values: new_lead_nurture, demo_follow_up, proposal_stall_recovery, silent_lead_reengagement, closed_won_onboarding. Each item must include template_key, name, description, category, trigger_config, conditions, actions, and variables. Every send_email action must reference the matching generated email by using email_template_key, not inline body text only. Keep workflows realistic for a small CRM team: one clear trigger, restrained waits, useful task titles, and no invented systems or unsupported actions. Supported action types include send_email, wait_for_days, create_task, change_stage, and send_in_app_notification. Do not wrap the JSON in markdown.',
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('workflows'),
            'workflows_required_keys', JSON_ARRAY('template_key', 'name', 'description', 'category', 'trigger_config', 'conditions', 'actions', 'variables'),
            'quality_rules', JSON_ARRAY('workspace_specific', 'realistic_cadence', 'linked_email_templates', 'supported_actions_only')
        ),
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'workflow', 'quality_version', 2),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.workspace_id IS NULL
      AND existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
