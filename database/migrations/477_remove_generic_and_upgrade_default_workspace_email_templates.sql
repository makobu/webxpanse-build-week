-- Remove generic email templates from active surfaces and upgrade default workspace operational templates.

SET @email_templates_next_id := COALESCE((SELECT MAX(id) FROM email_templates WHERE id > 0), 0);

UPDATE email_templates
SET id = (@email_templates_next_id := @email_templates_next_id + 1)
WHERE id <= 0
ORDER BY created_at, slug;

SET @email_templates_has_primary := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND INDEX_NAME = 'PRIMARY'
);

SET @email_templates_add_primary_sql := IF(
    @email_templates_has_primary = 0,
    'ALTER TABLE email_templates ADD PRIMARY KEY (id)',
    'SELECT 1'
);
PREPARE email_templates_add_primary_stmt FROM @email_templates_add_primary_sql;
EXECUTE email_templates_add_primary_stmt;
DEALLOCATE PREPARE email_templates_add_primary_stmt;

SET @email_templates_id_extra := (
    SELECT LOWER(EXTRA)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'email_templates'
      AND COLUMN_NAME = 'id'
    LIMIT 1
);

SET @email_templates_auto_increment_sql := IF(
    COALESCE(@email_templates_id_extra, '') NOT LIKE '%auto_increment%',
    'ALTER TABLE email_templates MODIFY id INT NOT NULL AUTO_INCREMENT',
    'SELECT 1'
);
PREPARE email_templates_auto_increment_stmt FROM @email_templates_auto_increment_sql;
EXECUTE email_templates_auto_increment_stmt;
DEALLOCATE PREPARE email_templates_auto_increment_stmt;

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 AND slug = 'default' LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1),
    1
);

SET @platform_owner_user_id := (
    SELECT wm.user_id
    FROM workspace_memberships wm
    WHERE wm.workspace_id = @default_workspace_id
      AND wm.membership_status = 'active'
    ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'viewer'), wm.id ASC
    LIMIT 1
);

UPDATE email_templates
SET is_active = 0,
    updated_at = NOW()
WHERE slug IN ('welcome', 'follow_up', 'thank_you')
  AND COALESCE(is_ai_generated, 0) = 0;

UPDATE email_templates
SET is_active = 0,
    updated_at = NOW()
WHERE slug LIKE 'library-%'
  AND (workspace_id IS NULL OR workspace_id IN (@default_workspace_id, 1));

CREATE TEMPORARY TABLE default_workspace_email_template_upgrades (
    template_key VARCHAR(100) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    description TEXT NOT NULL,
    variables_json LONGTEXT NOT NULL,
    tags_json LONGTEXT NOT NULL,
    body_html TEXT NOT NULL,
    body_text TEXT NOT NULL,
    match_metadata_json LONGTEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO default_workspace_email_template_upgrades
    (template_key, name, subject, description, variables_json, tags_json, body_html, body_text, match_metadata_json)
VALUES
    (
        'owner_welcome_setup',
        'Platform Ops - Owner Welcome and Setup',
        'Welcome to Clarity, {owner_name}',
        'Warm setup note for a new workspace owner with the right next step.',
        JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'welcome', 'owner_welcome_setup'),
        '<p>Hi {owner_name},</p><p>Welcome to Clarity. <strong>{workspace_name}</strong> is ready for setup.</p><p><a href="{setup_url}">Start setup</a></p><p>If anything gets blocked, reply here or use the <a href="{support_url}">support link</a>.</p>',
        'Hi {owner_name},\n\nWelcome to Clarity. {workspace_name} is ready for setup.\n\nStart setup: {setup_url}\n\nIf anything gets blocked, reply here or use the support link: {support_url}',
        JSON_OBJECT('purposes', JSON_ARRAY('welcome', 'setup'), 'workflow_intents', JSON_ARRAY('owner_welcome_setup'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('welcoming', 'helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'))
    ),
    (
        'onboarding_recovery',
        'Platform Ops - Onboarding Recovery',
        'Finish setup for {workspace_name}',
        'Helpful owner nudge for a workspace stuck in onboarding.',
        JSON_ARRAY('owner_name', 'workspace_name', 'next_action', 'next_action_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'onboarding', 'onboarding_recovery'),
        '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is close to being ready. The next setup step is: <strong>{next_action}</strong>.</p><p><a href="{next_action_url}">Continue setup</a></p><p>If that step is blocked, reply here or <a href="{support_url}">open support</a>.</p>',
        'Hi {owner_name},\n\n{workspace_name} is close to being ready. The next setup step is: {next_action}.\n\nContinue setup: {next_action_url}\n\nIf that step is blocked, reply here or open support: {support_url}',
        JSON_OBJECT('purposes', JSON_ARRAY('onboarding_recovery', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_stuck_onboarding_review'), 'lifecycle_stages', JSON_ARRAY('setup', 'stuck_onboarding'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'direct'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'next_action', 'next_action_url', 'support_url'))
    ),
    (
        'billing_follow_up',
        'Platform Ops - Billing Follow-up',
        'Billing review needed for {workspace_name}',
        'Clear billing review request for trials, subscriptions, or provider events.',
        JSON_ARRAY('owner_name', 'workspace_name', 'billing_status', 'action_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'billing', 'billing_follow_up'),
        '<p>Hi {owner_name},</p><p>A billing item needs review for <strong>{workspace_name}</strong>: {billing_status}.</p><p><a href="{action_url}">Review billing</a></p><p>Reply here if the status looks wrong or you need help resolving it.</p>',
        'Hi {owner_name},\n\nA billing item needs review for {workspace_name}: {billing_status}.\n\nReview billing: {action_url}\n\nReply here if the status looks wrong or you need help resolving it.',
        JSON_OBJECT('purposes', JSON_ARRAY('billing_follow_up', 'payment_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('billing_risk', 'past_due'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('direct', 'helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'billing_status', 'action_url', 'support_url'))
    ),
    (
        'failed_payment_review',
        'Platform Ops - Failed Payment Review',
        'Payment needs attention for {workspace_name}',
        'Payment recovery note with checkout and support paths.',
        JSON_ARRAY('owner_name', 'workspace_name', 'payment_reference', 'checkout_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'billing', 'failed_payment_review'),
        '<p>Hi {owner_name},</p><p>The latest payment attempt for <strong>{workspace_name}</strong> needs attention. Reference: {payment_reference}.</p><p><a href="{checkout_url}">Continue payment</a></p><p>If the payment should have completed, reply here or <a href="{support_url}">contact support</a>.</p>',
        'Hi {owner_name},\n\nThe latest payment attempt for {workspace_name} needs attention. Reference: {payment_reference}.\n\nContinue payment: {checkout_url}\n\nIf the payment should have completed, reply here or contact support: {support_url}',
        JSON_OBJECT('purposes', JSON_ARRAY('failed_payment', 'payment_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('payment_failed'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('direct', 'helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'payment_reference', 'checkout_url', 'support_url'))
    ),
    (
        'low_token_warning',
        'Platform Ops - Low AI Credit Warning',
        '{workspace_name} AI Credit balance is low',
        'Low AI Credit balance warning before assistance is interrupted.',
        JSON_ARRAY('owner_name', 'workspace_name', 'available_tokens', 'top_up_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'tokens', 'low_token_warning'),
        '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> has {available_tokens} AI Credits available. Top up soon so AI assistance keeps running without interruption.</p><p><a href="{top_up_url}">Top up AI Credits</a></p><p>Reply here if you want help choosing the right credit level.</p>',
        'Hi {owner_name},\n\n{workspace_name} has {available_tokens} AI Credits available. Top up soon so AI assistance keeps running without interruption.\n\nTop up AI Credits: {top_up_url}\n\nReply here if you want help choosing the right credit level.',
        JSON_OBJECT('purposes', JSON_ARRAY('token_warning', 'billing_follow_up'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('low_tokens'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('operational', 'direct'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'available_tokens', 'top_up_url', 'support_url'))
    ),
    (
        'channel_setup_reminder',
        'Platform Ops - Channel Setup Reminder',
        'Connect email for {workspace_name}',
        'Owner reminder for workspaces missing email channel setup.',
        JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'channel_setup', 'channel_setup_reminder'),
        '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is active, but email is not connected yet. Connect email so inbox, replies, and customer follow-up can run reliably.</p><p><a href="{setup_url}">Connect email</a></p><p>If you want us to check the setup with you, reply here.</p>',
        'Hi {owner_name},\n\n{workspace_name} is active, but email is not connected yet. Connect email so inbox, replies, and customer follow-up can run reliably.\n\nConnect email: {setup_url}\n\nIf you want us to check the setup with you, reply here.',
        JSON_OBJECT('purposes', JSON_ARRAY('channel_setup', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('channel_setup_reminder'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'operational'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'))
    ),
    (
        'workspace_suspension_notice',
        'Platform Ops - Workspace Suspension Notice',
        '{workspace_name} access needs review',
        'Account access notice with a direct support path.',
        JSON_ARRAY('owner_name', 'workspace_name', 'reason', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'workspace_suspension_notice'),
        '<p>Hi {owner_name},</p><p>Access for <strong>{workspace_name}</strong> needs review: {reason}.</p><p><a href="{support_url}">Review next steps</a></p><p>Reply here if you believe access should already be restored.</p>',
        'Hi {owner_name},\n\nAccess for {workspace_name} needs review: {reason}.\n\nReview next steps: {support_url}\n\nReply here if you believe access should already be restored.',
        JSON_OBJECT('purposes', JSON_ARRAY('suspension_notice', 'support'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('suspended'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('direct', 'supportive'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'reason', 'support_url'))
    ),
    (
        'setup_link_resend',
        'Platform Ops - Setup Link Resend',
        'Your setup link for {workspace_name}',
        'Manual setup or login recovery message for a workspace owner.',
        JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'reset_url', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'setup', 'setup_link_resend'),
        '<p>Hi {owner_name},</p><p>Here is the setup link for <strong>{workspace_name}</strong>: <a href="{setup_url}">continue setup</a>.</p><p>If you need to reset your password first, use this link: <a href="{reset_url}">reset password</a>.</p><p>Reply here if either link does not work.</p>',
        'Hi {owner_name},\n\nHere is the setup link for {workspace_name}: {setup_url}\n\nIf you need to reset your password first, use this link: {reset_url}\n\nReply here if either link does not work.',
        JSON_OBJECT('purposes', JSON_ARRAY('setup_link', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_stuck_onboarding_review'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'reset_url', 'support_url'))
    ),
    (
        'owner_problem_followup',
        'Platform Ops - Owner Problem Follow-up',
        'Following up on {workspace_name}',
        'Follow-up for a problem reported by a workspace owner.',
        JSON_ARRAY('owner_name', 'workspace_name', 'issue_summary', 'recommended_action', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'owner_problem_followup'),
        '<p>Hi {owner_name},</p><p>I reviewed the issue for <strong>{workspace_name}</strong>: {issue_summary}</p><p><strong>Recommended next step:</strong> {recommended_action}</p><p>You can reply here or <a href="{support_url}">open support</a>.</p>',
        'Hi {owner_name},\n\nI reviewed the issue for {workspace_name}: {issue_summary}.\n\nRecommended next step: {recommended_action}\n\nYou can reply here or open support: {support_url}',
        JSON_OBJECT('purposes', JSON_ARRAY('support_follow_up'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_open'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'clear'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'issue_summary', 'recommended_action', 'support_url'))
    ),
    (
        'owner_support_resolution',
        'Platform Ops - Owner Support Resolution',
        'Resolved: {workspace_name} support update',
        'Resolution note for owner support requests.',
        JSON_ARRAY('owner_name', 'workspace_name', 'resolution_summary', 'next_best_action', 'support_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'owner_support_resolution'),
        '<p>Hi {owner_name},</p><p>This is resolved for <strong>{workspace_name}</strong>: {resolution_summary}</p><p><strong>Next best action:</strong> {next_best_action}</p><p>Reply here if anything still looks off, or <a href="{support_url}">reopen support</a>.</p>',
        'Hi {owner_name},\n\nThis is resolved for {workspace_name}: {resolution_summary}.\n\nNext best action: {next_best_action}\n\nReply here if anything still looks off, or reopen support: {support_url}',
        JSON_OBJECT('purposes', JSON_ARRAY('resolution_check', 'support_follow_up'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_resolved'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'clear'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'resolution_summary', 'next_best_action', 'support_url'))
    ),
    (
        'support_escalation',
        'Platform Ops - Support Escalation',
        'Support escalation: {workspace_name}',
        'Internal escalation summary for support/admin follow-through.',
        JSON_ARRAY('workspace_name', 'owner_email', 'issue_summary', 'recommended_action', 'operator_action_url'),
        JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'support_escalation'),
        '<p><strong>Workspace:</strong> {workspace_name}</p><p><strong>Owner:</strong> {owner_email}</p><p><strong>Issue:</strong> {issue_summary}</p><p><strong>Recommended action:</strong> {recommended_action}</p><p><a href="{operator_action_url}">Open operator action</a></p>',
        'Workspace: {workspace_name}\nOwner: {owner_email}\nIssue: {issue_summary}\nRecommended action: {recommended_action}\nOpen operator action: {operator_action_url}',
        JSON_OBJECT('purposes', JSON_ARRAY('support_escalation'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_escalated'), 'audiences', JSON_ARRAY('operator'), 'tones', JSON_ARRAY('operational'), 'required_variables', JSON_ARRAY('workspace_name', 'owner_email', 'issue_summary', 'recommended_action', 'operator_action_url'))
    );

UPDATE email_templates et
JOIN default_workspace_email_template_upgrades upgrades
  ON et.slug = CONCAT('platform-ops-', upgrades.template_key)
SET et.workspace_id = @default_workspace_id,
    et.name = upgrades.name,
    et.subject = upgrades.subject,
    et.body_html = upgrades.body_html,
    et.body_text = upgrades.body_text,
    et.category = 'platform_ops',
    et.variables = upgrades.variables_json,
    et.is_active = 1,
    et.is_library = 0,
    et.description = upgrades.description,
    et.tags = upgrades.tags_json,
    et.industry = 'SaaS operations',
    et.purpose = 'platform_ops',
    et.is_featured = 0,
    et.author = 'Clarity Platform Ops',
    et.version = '1.1',
    et.is_ai_generated = 1,
    et.template_key = upgrades.template_key,
    et.match_metadata_json = upgrades.match_metadata_json,
    et.created_by = COALESCE(et.created_by, @platform_owner_user_id),
    et.seed_metadata_json = JSON_OBJECT(
        'seed_source', 'default_workspace_platform_ops',
        'seed_key', upgrades.template_key,
        'seed_version', '1.0.0',
        'last_seeded_at', UTC_TIMESTAMP()
    ),
    et.updated_at = NOW()
WHERE et.workspace_id = @default_workspace_id
  AND (
      et.seed_metadata_json IS NULL
      OR JSON_UNQUOTE(JSON_EXTRACT(et.seed_metadata_json, '$.seed_source')) = 'default_workspace_platform_ops'
  )
  AND (
      et.seed_metadata_json IS NULL
      OR JSON_EXTRACT(et.seed_metadata_json, '$.customized_at') IS NULL
  );

DROP TEMPORARY TABLE default_workspace_email_template_upgrades;
