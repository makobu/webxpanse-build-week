CREATE TABLE IF NOT EXISTS smart_template_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    context_hash CHAR(64) NOT NULL,
    context_snapshot_json JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_smart_template_sets_user_status (user_id, status),
    INDEX idx_smart_template_sets_context_hash (context_hash),
    CONSTRAINT fk_smart_template_sets_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_templates
    ADD COLUMN smart_template_set_id INT NULL;

ALTER TABLE email_templates
    ADD COLUMN is_ai_generated BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE email_templates
    ADD COLUMN template_key VARCHAR(100) NULL;

CREATE INDEX idx_email_templates_smart_template_set
    ON email_templates (smart_template_set_id);

CREATE INDEX idx_email_templates_created_by_library
    ON email_templates (created_by, is_library);

CREATE INDEX idx_email_templates_template_key
    ON email_templates (template_key);

ALTER TABLE email_templates
    ADD CONSTRAINT fk_email_templates_smart_template_set
        FOREIGN KEY (smart_template_set_id) REFERENCES smart_template_sets(id) ON DELETE SET NULL;

ALTER TABLE workflow_templates
    ADD COLUMN created_by INT NULL;

ALTER TABLE workflow_templates
    ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE workflow_templates
    ADD COLUMN smart_template_set_id INT NULL;

ALTER TABLE workflow_templates
    ADD COLUMN is_ai_generated BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE workflow_templates
    ADD COLUMN template_key VARCHAR(100) NULL;

CREATE INDEX idx_workflow_templates_created_by_active
    ON workflow_templates (created_by, is_active);

CREATE INDEX idx_workflow_templates_smart_template_set
    ON workflow_templates (smart_template_set_id);

CREATE INDEX idx_workflow_templates_template_key
    ON workflow_templates (template_key);

ALTER TABLE workflow_templates
    ADD CONSTRAINT fk_workflow_templates_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE workflow_templates
    ADD CONSTRAINT fk_workflow_templates_smart_template_set
        FOREIGN KEY (smart_template_set_id) REFERENCES smart_template_sets(id) ON DELETE SET NULL;

INSERT INTO ai_prompt_registry
    (surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        'smart_templates' AS surface,
        'email_pack_generation' AS prompt_key,
        1 AS version,
        'active' AS status,
        'You generate high-quality personal email template packs for a CRM user. Use only the provided company, strategy, and idea-validation context. Keep the templates practical, specific, and ready for editing.' AS system_prompt_text,
        'Return strict JSON with an "emails" array containing exactly 5 templates for these template_key values: lead_intro, demo_or_discovery_invite, proposal_follow_up, re_engagement, post_win_onboarding. Each item must include template_key, name, category, subject, body_html, body_text, variables, and description. Preserve placeholders like {first_name}, {company}, {sender_name}, and {meeting_link} where useful. Do not wrap the JSON in markdown.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('emails'),
            'emails_required_keys', JSON_ARRAY('template_key', 'name', 'category', 'subject', 'body_html', 'body_text', 'variables', 'description')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'email') AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        'smart_templates',
        'workflow_pack_generation',
        1,
        'active',
        'You generate workflow template packs that coordinate with a matching generated email template pack. Use only supported workflow trigger and action types, and keep the workflow logic realistic and easy to customize.' AS system_prompt_text,
        'Return strict JSON with a "workflows" array containing exactly 5 templates for these template_key values: new_lead_nurture, demo_follow_up, proposal_stall_recovery, silent_lead_reengagement, closed_won_onboarding. Each item must include template_key, name, description, category, trigger_config, conditions, actions, and variables. Every send_email action must reference the matching generated email by using email_template_key, not inline body text only. Supported action types include send_email, wait_for_days, create_task, change_stage, and send_in_app_notification. Do not wrap the JSON in markdown.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('workflows'),
            'workflows_required_keys', JSON_ARRAY('template_key', 'name', 'description', 'category', 'trigger_config', 'conditions', 'actions', 'variables')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'workflow') AS metadata_json,
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
