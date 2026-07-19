-- Migration 272: Workflow and email template intelligence metadata
-- Adds flexible metadata for template matching and richer workflow recipes.

ALTER TABLE email_templates
    ADD COLUMN match_metadata_json JSON NULL AFTER template_key;

ALTER TABLE workflow_templates
    ADD COLUMN recipe_metadata_json JSON NULL AFTER template_key;

ALTER TABLE email_templates
    ADD INDEX idx_email_templates_workspace_active_library (workspace_id, is_active, is_library);

ALTER TABLE email_templates
    ADD INDEX idx_email_templates_purpose_category_active (purpose, category, is_active);

ALTER TABLE workflow_templates
    ADD INDEX idx_workflow_templates_category_active_public (category, is_active, is_public);

-- Prompt registry v3: require matcher metadata in generated smart template packs.
INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT
        NULL AS workspace_id,
        'smart_templates' AS surface,
        'email_pack_generation' AS prompt_key,
        3 AS version,
        'active' AS status,
        'You generate polished, workspace-specific CRM email template packs with structured matching metadata. Use only the supplied company, strategy, and idea-validation context.' AS system_prompt_text,
        'Return strict JSON with an "emails" array containing exactly 5 templates for these template_key values: lead_intro, demo_or_discovery_invite, proposal_follow_up, re_engagement, post_win_onboarding. Each item must include template_key, name, category, subject, body_html, body_text, variables, description, and match_metadata_json. match_metadata_json must include purposes, workflow_intents, lifecycle_stages, audiences, tones, and required_variables. Keep writing concise, specific, professional, under 170 words, with one clear CTA. Preserve placeholders like {first_name}, {company}, {sender_name}, and {meeting_link}. Do not wrap JSON in markdown.' AS instruction_text,
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('emails'),
            'emails_required_keys', JSON_ARRAY('template_key', 'name', 'category', 'subject', 'body_html', 'body_text', 'variables', 'description', 'match_metadata_json')
        ) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'email', 'quality_version', 3) AS metadata_json,
        NULL AS created_by
    UNION ALL
    SELECT
        NULL,
        'smart_templates',
        'workflow_pack_generation',
        3,
        'active',
        'You generate practical CRM workflow template packs that can auto-select the best email template by intent instead of hard-coding one template ID.',
        'Return strict JSON with a "workflows" array containing exactly 5 templates for these template_key values: new_lead_nurture, demo_follow_up, proposal_stall_recovery, silent_lead_reengagement, closed_won_onboarding. Each item must include template_key, name, description, category, trigger_config, conditions, actions, variables, and recipe_metadata_json. Every send_email action must include template_query with intent_key, preferred_template_key, purpose, tone, lifecycle_stage, audience, and required_variables. Supported actions: send_email, wait_for_days, create_task, change_stage, send_in_app_notification. Do not wrap JSON in markdown.',
        JSON_OBJECT(
            'type', 'object',
            'required', JSON_ARRAY('workflows'),
            'workflows_required_keys', JSON_ARRAY('template_key', 'name', 'description', 'category', 'trigger_config', 'conditions', 'actions', 'variables', 'recipe_metadata_json')
        ),
        JSON_OBJECT('seeded', TRUE, 'feature', 'smart_templates', 'pack', 'workflow', 'quality_version', 3),
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

-- Upgrade existing library email templates with matcher metadata where possible.
UPDATE email_templates
SET match_metadata_json = JSON_OBJECT(
    'purposes', JSON_ARRAY('follow_up', 'executive_follow_up', 'proposal_follow_up'),
    'workflow_intents', JSON_ARRAY('proposal_stall_recovery', 'deal_stage_follow_up'),
    'lifecycle_stages', JSON_ARRAY('proposal', 'decision'),
    'audiences', JSON_ARRAY('prospect', 'decision_maker'),
    'tones', JSON_ARRAY('executive', 'consultative'),
    'required_variables', JSON_ARRAY('first_name', 'company_name', 'sender_name')
)
WHERE slug = 'library-executive-value-follow-up';

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version, match_metadata_json
)
SELECT
    'Payment Reminder - Helpful Nudge',
    'library-payment-reminder-helpful-nudge',
    'Payment reminder for {company}',
    '<html><body><p>Hi {first_name},</p><p>This is a quick reminder that payment for {company} is still pending.</p><p>If anything needs adjusting on the invoice or timing, reply here and we will help resolve it.</p><p>Best,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nThis is a quick reminder that payment for {company} is still pending.\n\nIf anything needs adjusting on the invoice or timing, reply here and we will help resolve it.\n\nBest,\n{sender_name}',
    'billing',
    JSON_ARRAY('first_name', 'company', 'sender_name'),
    TRUE,
    TRUE,
    'Helpful payment reminder for overdue or pending invoices.',
    JSON_ARRAY('billing', 'payment', 'reminder'),
    'general',
    'payment_reminder',
    TRUE,
    'CRM Team',
    '1.0',
    JSON_OBJECT('purposes', JSON_ARRAY('payment_reminder', 'invoice_reminder'), 'workflow_intents', JSON_ARRAY('invoice_payment_reminder'), 'lifecycle_stages', JSON_ARRAY('invoice_due', 'overdue'), 'audiences', JSON_ARRAY('customer'), 'tones', JSON_ARRAY('helpful', 'direct'), 'required_variables', JSON_ARRAY('first_name', 'company', 'sender_name'), 'disqualifiers', JSON_ARRAY('sales'))
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE slug = 'library-payment-reminder-helpful-nudge');

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version, match_metadata_json
)
SELECT
    'Support Follow-up - Resolution Check',
    'library-support-resolution-check',
    'Checking that this is resolved',
    '<html><body><p>Hi {first_name},</p><p>I wanted to confirm the support item is now resolved: {issue_summary}</p><p>If anything still looks off, reply here and we will reopen it with the right context.</p><p>Best,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nI wanted to confirm the support item is now resolved: {issue_summary}\n\nIf anything still looks off, reply here and we will reopen it with the right context.\n\nBest,\n{sender_name}',
    'support',
    JSON_ARRAY('first_name', 'issue_summary', 'sender_name'),
    TRUE,
    TRUE,
    'Customer support resolution follow-up and escalation safety net.',
    JSON_ARRAY('support', 'follow-up', 'resolution'),
    'general',
    'support_follow_up',
    TRUE,
    'CRM Team',
    '1.0',
    JSON_OBJECT('purposes', JSON_ARRAY('support_follow_up', 'resolution_check'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_open', 'support_resolved'), 'audiences', JSON_ARRAY('customer'), 'tones', JSON_ARRAY('helpful', 'clear'), 'required_variables', JSON_ARRAY('first_name', 'issue_summary', 'sender_name'), 'disqualifiers', JSON_ARRAY('sales', 'billing'))
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE slug = 'library-support-resolution-check');

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version, match_metadata_json
)
SELECT
    'Channel Setup Reminder',
    'library-channel-setup-reminder',
    'Connect email for {workspace_name}',
    '<html><body><p>Hi {owner_name},</p><p>{workspace_name} is active, but email is not connected yet. Connect it so inbox, replies, and follow-up workflows can run reliably.</p><p><a href="{setup_url}">Open setup</a></p></body></html>',
    'Hi {owner_name},\n\n{workspace_name} is active, but email is not connected yet. Connect it so inbox, replies, and follow-up workflows can run reliably.\n\nOpen setup: {setup_url}',
    'setup',
    JSON_ARRAY('owner_name', 'workspace_name', 'setup_url'),
    TRUE,
    TRUE,
    'Workspace owner reminder for email channel setup.',
    JSON_ARRAY('setup', 'channel', 'workspace_owner'),
    'general',
    'channel_setup',
    FALSE,
    'CRM Team',
    '1.0',
    JSON_OBJECT('purposes', JSON_ARRAY('channel_setup', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('channel_setup_reminder'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'operational'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url'))
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE slug = 'library-channel-setup-reminder');

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version, match_metadata_json
)
SELECT
    'Post-Onboarding Expansion Check-in',
    'library-post-onboarding-expansion-checkin',
    'How is onboarding going so far?',
    '<html><body><p>Hi {first_name},</p><p>Now that onboarding is underway, I wanted to check what is working best and what still needs attention.</p><p>If there is a useful next improvement, I can outline it with owners and timing.</p><p>Best,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nNow that onboarding is underway, I wanted to check what is working best and what still needs attention.\n\nIf there is a useful next improvement, I can outline it with owners and timing.\n\nBest,\n{sender_name}',
    'relationship',
    JSON_ARRAY('first_name', 'sender_name'),
    TRUE,
    TRUE,
    'Post-onboarding check-in for expansion and relationship health.',
    JSON_ARRAY('customer', 'onboarding', 'expansion'),
    'general',
    'expansion_checkin',
    TRUE,
    'CRM Team',
    '1.0',
    JSON_OBJECT('purposes', JSON_ARRAY('expansion_checkin', 'post_onboarding'), 'workflow_intents', JSON_ARRAY('post_onboarding_expansion_checkin'), 'lifecycle_stages', JSON_ARRAY('onboarding', 'customer'), 'audiences', JSON_ARRAY('customer'), 'tones', JSON_ARRAY('consultative', 'helpful'), 'required_variables', JSON_ARRAY('first_name', 'sender_name'))
WHERE NOT EXISTS (SELECT 1 FROM email_templates WHERE slug = 'library-post-onboarding-expansion-checkin');

-- Enrich selected workflow recipes with template_query and recipe metadata.
UPDATE workflow_templates
SET actions = '[{"type":"send_email","template_query":{"intent_key":"new_lead_nurture","preferred_template_key":"lead_intro","purpose":"lead_intro","tone":"consultative","lifecycle_stage":"new","audience":"lead","required_variables":["first_name","company","sender_name"]},"subject":"Welcome, {first_name}!","body":"Hi {first_name}, thanks for your interest. Here is what we offer..."},{"type":"wait_for_days","days":3},{"type":"send_email","template_query":{"intent_key":"new_lead_nurture","preferred_template_key":"lead_intro","purpose":"lead_intro","tone":"helpful","lifecycle_stage":"new","audience":"lead","required_variables":["first_name","company"]},"subject":"Quick follow-up","body":"Hi {first_name}, checking whether a useful next step would help."},{"type":"wait_for_days","days":4},{"type":"create_task","title":"Review lead nurture response for {first_name}","priority":"medium","due_date":"+1 days"}]',
    recipe_metadata_json = JSON_OBJECT('intent_key', 'new_lead_nurture', 'expected_outcome', 'respond quickly and create follow-up ownership', 'audience', 'new lead', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'lead_intro', 'tone', 'consultative', 'lifecycle_stage', 'new', 'audience', 'lead'), 'stop_conditions', JSON_ARRAY('email_replied', 'contact_unsubscribed', 'manual_pause'))
WHERE name = 'Lead Nurture Sequence';

UPDATE workflow_templates
SET actions = '[{"type":"create_task","title":"Send proposal to {first_name}","priority":"high","due_date":"+1 days"},{"type":"send_email","template_query":{"intent_key":"deal_stage_follow_up","preferred_template_key":"proposal_follow_up","purpose":"proposal_follow_up","tone":"consultative","lifecycle_stage":"proposal","audience":"prospect","required_variables":["first_name","company","sender_name"]},"subject":"Your proposal is ready","body":"Hi {first_name}, we have prepared a proposal for you. Let us know if you have questions."}]',
    recipe_metadata_json = JSON_OBJECT('intent_key', 'deal_stage_follow_up', 'expected_outcome', 'keep proposal-stage prospects moving', 'audience', 'proposal-stage prospect', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'proposal_follow_up', 'tone', 'consultative', 'lifecycle_stage', 'proposal', 'audience', 'prospect'), 'stop_conditions', JSON_ARRAY('email_replied', 'deal_won', 'deal_lost', 'manual_pause'))
WHERE name = 'Deal Stage Follow-up';

UPDATE workflow_templates
SET actions = '[{"type":"send_email","template_query":{"intent_key":"closed_won_onboarding","preferred_template_key":"post_win_onboarding","purpose":"customer_welcome","tone":"welcoming","lifecycle_stage":"closed_won","audience":"customer","required_variables":["first_name","company","meeting_link"]},"subject":"Thank you, {first_name}!","body":"Congratulations. We are thrilled to work with you. Here is what happens next..."},{"type":"add_tag","tag":"customer"}]',
    recipe_metadata_json = JSON_OBJECT('intent_key', 'closed_won_onboarding', 'expected_outcome', 'start customer onboarding after a won deal', 'audience', 'new customer', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'customer_welcome', 'tone', 'welcoming', 'lifecycle_stage', 'closed_won', 'audience', 'customer'), 'stop_conditions', JSON_ARRAY('onboarding_started', 'manual_pause'))
WHERE name = 'Deal Won Celebration';

UPDATE workflow_templates
SET actions = '[{"type":"send_email","template_query":{"intent_key":"proposal_stall_recovery","preferred_template_key":"proposal_follow_up","purpose":"proposal_follow_up","tone":"consultative","lifecycle_stage":"proposal","audience":"prospect","required_variables":["first_name","company","sender_name"]},"subject":"Quick check-in on your proposal","body":"Hi {first_name}, I wanted to check whether anything is blocking the proposal review."},{"type":"create_task","title":"Escalation review: stalled proposal for {first_name}","priority":"high","due_date":"+1 days"},{"type":"add_tag","tag":"deal-stalled"},{"type":"wait_for_days","days":3},{"type":"create_task","title":"Manual follow-up for stalled proposal - {first_name}","priority":"high","due_date":"+0 days"}]',
    recipe_metadata_json = JSON_OBJECT('intent_key', 'proposal_stall_recovery', 'expected_outcome', 'restart deal momentum', 'audience', 'proposal-stage prospect', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'proposal_follow_up', 'tone', 'consultative', 'lifecycle_stage', 'proposal', 'audience', 'prospect'), 'stop_conditions', JSON_ARRAY('email_replied', 'deal_won', 'deal_lost', 'manual_pause'))
WHERE name = 'Deal Stall Recovery Sequence';

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, variables, is_public, recipe_metadata_json)
SELECT
    'Invoice Payment Reminder',
    'Send a helpful payment reminder and create a human review task if payment remains unresolved.',
    'billing',
    '{"type":"invoice_overdue"}',
    '[]',
    '[{"type":"send_email","template_query":{"intent_key":"invoice_payment_reminder","purpose":"payment_reminder","tone":"helpful","lifecycle_stage":"overdue","audience":"customer","required_variables":["first_name","company","sender_name"]},"subject":"Payment reminder for {company}","body":"Hi {first_name}, this is a quick reminder that payment is still pending."},{"type":"wait_for_days","days":3},{"type":"create_task","title":"Review unpaid invoice for {first_name}","priority":"high","due_date":"+0 days"}]',
    JSON_ARRAY('first_name', 'company', 'sender_name'),
    TRUE,
    JSON_OBJECT('intent_key', 'invoice_payment_reminder', 'expected_outcome', 'recover overdue payments without losing customer goodwill', 'audience', 'customer with overdue invoice', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'payment_reminder', 'tone', 'helpful', 'lifecycle_stage', 'overdue', 'audience', 'customer'), 'stop_conditions', JSON_ARRAY('invoice_paid', 'email_replied', 'manual_pause'))
WHERE NOT EXISTS (SELECT 1 FROM workflow_templates WHERE name = 'Invoice Payment Reminder');

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, variables, is_public, recipe_metadata_json)
SELECT
    'Support Follow-up and Escalation',
    'Check resolution quality after a support interaction and create an escalation task if needed.',
    'support',
    '{"type":"support_resolved"}',
    '[]',
    '[{"type":"send_email","template_query":{"intent_key":"support_follow_up_escalation","purpose":"support_follow_up","tone":"helpful","lifecycle_stage":"support_resolved","audience":"customer","required_variables":["first_name","issue_summary","sender_name"]},"subject":"Checking that this is resolved","body":"Hi {first_name}, I wanted to confirm the support item is now resolved."},{"type":"wait_for_days","days":2},{"type":"create_task","title":"Check support resolution response for {first_name}","priority":"medium","due_date":"+0 days"}]',
    JSON_ARRAY('first_name', 'issue_summary', 'sender_name'),
    TRUE,
    JSON_OBJECT('intent_key', 'support_follow_up_escalation', 'expected_outcome', 'confirm issue resolution and preserve human escalation ownership', 'audience', 'customer with recent support issue', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'support_follow_up', 'tone', 'helpful', 'lifecycle_stage', 'support_resolved', 'audience', 'customer'), 'stop_conditions', JSON_ARRAY('email_replied', 'support_reopened', 'manual_pause'))
WHERE NOT EXISTS (SELECT 1 FROM workflow_templates WHERE name = 'Support Follow-up and Escalation');

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, variables, is_public, recipe_metadata_json)
SELECT
    'Channel Setup Reminder',
    'Remind workspace owners to finish email setup before relying on inbox and outbound workflows.',
    'setup',
    '{"type":"channel_setup_incomplete"}',
    '[]',
    '[{"type":"send_email","template_query":{"intent_key":"channel_setup_reminder","purpose":"channel_setup","tone":"operational","lifecycle_stage":"setup","audience":"workspace_owner","required_variables":["owner_name","workspace_name","setup_url"]},"subject":"Connect email for {workspace_name}","body":"Hi {owner_name}, connect your email channel so workflows can run reliably."},{"type":"create_task","title":"Help {workspace_name} connect email","priority":"medium","due_date":"+1 days"}]',
    JSON_ARRAY('owner_name', 'workspace_name', 'setup_url'),
    TRUE,
    JSON_OBJECT('intent_key', 'channel_setup_reminder', 'expected_outcome', 'complete communication setup before live automation', 'audience', 'workspace owner', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'channel_setup', 'tone', 'operational', 'lifecycle_stage', 'setup', 'audience', 'workspace_owner'), 'stop_conditions', JSON_ARRAY('channel_connected', 'manual_pause'))
WHERE NOT EXISTS (SELECT 1 FROM workflow_templates WHERE name = 'Channel Setup Reminder');

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, variables, is_public, recipe_metadata_json)
SELECT
    'Post-Onboarding Expansion Check-in',
    'Follow up after onboarding to identify blockers, wins, and a natural expansion path.',
    'relationship',
    '{"type":"no_activity_for_days","days":14}',
    '[{"field":"stage","operator":"equals","value":"customer"}]',
    '[{"type":"send_email","template_query":{"intent_key":"post_onboarding_expansion_checkin","purpose":"expansion_checkin","tone":"consultative","lifecycle_stage":"customer","audience":"customer","required_variables":["first_name","sender_name"]},"subject":"How is onboarding going so far?","body":"Hi {first_name}, now that onboarding is underway, what is working best and what still needs attention?"},{"type":"create_task","title":"Review expansion path for {first_name}","priority":"medium","due_date":"+2 days"}]',
    JSON_ARRAY('first_name', 'sender_name'),
    TRUE,
    JSON_OBJECT('intent_key', 'post_onboarding_expansion_checkin', 'expected_outcome', 'surface onboarding blockers and expansion opportunities', 'audience', 'active customer', 'safety_level', 'approval_recommended', 'recommended_template_query', JSON_OBJECT('purpose', 'expansion_checkin', 'tone', 'consultative', 'lifecycle_stage', 'customer', 'audience', 'customer'), 'stop_conditions', JSON_ARRAY('email_replied', 'manual_pause'))
WHERE NOT EXISTS (SELECT 1 FROM workflow_templates WHERE name = 'Post-Onboarding Expansion Check-in');
