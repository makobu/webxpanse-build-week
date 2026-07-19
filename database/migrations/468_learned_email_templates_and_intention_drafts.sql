-- Learned email templates and intention-first AI drafting.
-- Generic seeded templates are archived from normal user surfaces and learned packs use a reviewable candidate lifecycle.

ALTER TABLE smart_template_sets
    MODIFY COLUMN status ENUM('candidate','active','archived','rejected') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS generation_mode VARCHAR(32) NOT NULL DEFAULT 'manual' AFTER status,
    ADD COLUMN IF NOT EXISTS learning_state VARCHAR(32) NULL AFTER generation_mode,
    ADD COLUMN IF NOT EXISTS learning_hash CHAR(64) NULL AFTER context_hash,
    ADD COLUMN IF NOT EXISTS metrics_snapshot_json JSON NULL AFTER context_snapshot_json,
    ADD COLUMN IF NOT EXISTS generated_reason VARCHAR(80) NULL AFTER metrics_snapshot_json,
    ADD COLUMN IF NOT EXISTS refresh_due_at DATETIME NULL AFTER generated_reason,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER refresh_due_at,
    ADD COLUMN IF NOT EXISTS reviewed_by INT NULL AFTER reviewed_at,
    ADD COLUMN IF NOT EXISTS activated_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN IF NOT EXISTS rejected_at DATETIME NULL AFTER activated_at,
    ADD INDEX IF NOT EXISTS idx_smart_template_sets_learning_hash (learning_hash),
    ADD INDEX IF NOT EXISTS idx_smart_template_sets_refresh_due (refresh_due_at);

ALTER TABLE emails
    ADD COLUMN IF NOT EXISTS source_template_id INT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS source_template_slug VARCHAR(255) NULL AFTER source_template_id,
    ADD COLUMN IF NOT EXISTS draft_source VARCHAR(80) NULL AFTER source_template_slug,
    ADD COLUMN IF NOT EXISTS draft_intention TEXT NULL AFTER draft_source,
    ADD COLUMN IF NOT EXISTS ai_assistant_run_id INT NULL AFTER draft_intention,
    ADD INDEX IF NOT EXISTS idx_emails_source_template (source_template_id),
    ADD INDEX IF NOT EXISTS idx_emails_draft_source (draft_source);

CREATE TABLE IF NOT EXISTS email_template_learning_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    contact_id INT NULL,
    email_id INT NULL,
    communication_id INT NULL,
    assistant_run_id INT NULL,
    source_template_id INT NULL,
    smart_template_set_id INT NULL,
    sample_kind VARCHAR(40) NOT NULL DEFAULT 'sent_email',
    source VARCHAR(80) NOT NULL DEFAULT 'unknown',
    intent_key VARCHAR(120) NULL,
    draft_mode VARCHAR(80) NULL,
    draft_intention TEXT NULL,
    subject VARCHAR(500) NULL,
    body_hash CHAR(64) NULL,
    body_excerpt TEXT NULL,
    outcome_label VARCHAR(80) NOT NULL DEFAULT 'generated',
    outcome_score DECIMAL(5,4) NULL,
    generated_at DATETIME NULL,
    sent_at DATETIME NULL,
    opened_at DATETIME NULL,
    clicked_at DATETIME NULL,
    replied_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_template_learning_email (email_id),
    INDEX idx_email_template_learning_workspace_user (workspace_id, user_id, sample_kind),
    INDEX idx_email_template_learning_contact (contact_id),
    INDEX idx_email_template_learning_source (source, outcome_label),
    INDEX idx_email_template_learning_template (source_template_id),
    INDEX idx_email_template_learning_generated (generated_at),
    INDEX idx_email_template_learning_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archive generic global library templates. Platform/workspace operational templates are left alone.
UPDATE email_templates
SET is_active = 0
WHERE COALESCE(is_library, 0) = 1
  AND workspace_id IS NULL
  AND slug LIKE 'library-%';

-- Archive the original generic defaults that were later scoped to workspace 1.
UPDATE email_templates
SET is_active = 0
WHERE slug IN ('welcome', 'follow_up', 'thank_you')
  AND created_by IS NULL
  AND COALESCE(is_library, 0) = 0
  AND COALESCE(is_ai_generated, 0) = 0;

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'smart_templates' AS surface,
        'email_pack_generation' AS prompt_key,
        4 AS version,
        'active' AS status,
        'You generate learned CRM email template packs only from supplied profile context and real email learning evidence. The pack must reflect observed sender voice, accepted drafts, and engagement signals; do not produce generic library copy.' AS system_prompt_text,
        'Return strict JSON with an "emails" array containing exactly 5 templates for these template_key values: lead_intro, demo_or_discovery_invite, proposal_follow_up, re_engagement, post_win_onboarding. Each item must include template_key, name, category, subject, body_html, body_text, variables, description, and match_metadata_json. Use the EMAIL LEARNING EVIDENCE block as the primary voice and structure guide. Preserve placeholders such as {first_name}, {company}, {sender_name}, and {meeting_link}. Keep writing concise, specific, professional, under 170 words, with one clear CTA. Do not invent product features, customer names, prices, guarantees, URLs, or unsupported claims. HTML must be simple email-safe markup. Do not wrap JSON in markdown.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('emails'),
            'emails_required_keys', JSON_ARRAY('template_key', 'name', 'category', 'subject', 'body_html', 'body_text', 'variables', 'description', 'match_metadata_json'),
            'quality_rules', JSON_ARRAY('learned_evidence_required', 'workspace_specific', 'one_clear_cta', 'under_170_words', 'no_generic_library_copy', 'email_safe_html')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'email', 'quality_version', 4, 'requires_learning_evidence', TRUE) AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'smart_templates',
        'workflow_pack_generation',
        4,
        'active',
        'You generate practical workflow template packs only when they are tied to an approved learned email template pack. Keep workflow logic conservative and review-friendly.',
        'Return strict JSON with a "workflows" array containing exactly 5 templates for these template_key values: new_lead_nurture, demo_follow_up, proposal_stall_recovery, silent_lead_reengagement, closed_won_onboarding. Each item must include template_key, name, description, category, trigger_config, conditions, actions, variables, and recipe_metadata_json. Every send_email action must include template_query with intent_key, preferred_template_key, purpose, tone, lifecycle_stage, audience, and required_variables. Supported actions: send_email, wait_for_days, create_task, change_stage, and send_in_app_notification. Do not wrap JSON in markdown.',
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('workflows'),
            'workflows_required_keys', JSON_ARRAY('template_key', 'name', 'description', 'category', 'trigger_config', 'conditions', 'actions', 'variables', 'recipe_metadata_json'),
            'quality_rules', JSON_ARRAY('linked_to_learned_email_templates', 'realistic_cadence', 'supported_actions_only')
        ),
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'workflow', 'quality_version', 4, 'requires_learning_evidence', TRUE),
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
