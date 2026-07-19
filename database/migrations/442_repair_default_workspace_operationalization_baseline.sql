-- Repair default workspace operationalization baseline after workspace security hardening.
-- This migration is intentionally idempotent.

SET @default_workspace_id := 1;
SET @platform_owner_user_id := (
    SELECT wm.user_id
    FROM workspace_memberships wm
    WHERE wm.workspace_id = @default_workspace_id
      AND wm.membership_status = 'active'
    ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'viewer'), wm.id ASC
    LIMIT 1
);
SET @platform_owner_user_id := COALESCE(@platform_owner_user_id, (SELECT MIN(id) FROM users));

UPDATE workspaces
SET slug = 'default',
    name = 'Clarity Platform Operations HQ',
    status = 'active',
    plan_status = 'active',
    settings_json = JSON_SET(
        COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
        '$.workspace_purpose', 'platform_ops',
        '$.internal_channel_ready', TRUE,
        '$.internal_team_ready', TRUE,
        '$.owner_helpline_enabled', TRUE,
        '$.default_workspace_contact_scope', 'existing_customer_or_trial',
        '$.default_workspace_nurture_auto_qualified', TRUE,
        '$.new_customer_marketing_allowed', FALSE,
        '$.cold_outreach_allowed', FALSE,
        '$.operationalized_source', 'migration_442',
        '$.seed_source', 'default_workspace_platform_ops',
        '$.seed_key', 'workspace_settings',
        '$.seed_version', '1.0.0',
        '$.last_seeded_at', UTC_TIMESTAMP(),
        '$.operationalized_at', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()), '$.operationalized_at')), UTC_TIMESTAMP())
    ),
    updated_at = NOW()
WHERE id = @default_workspace_id;

INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
SELECT @default_workspace_id, 'default', 1
FROM DUAL
WHERE EXISTS (SELECT 1 FROM workspaces WHERE id = @default_workspace_id AND slug = 'default')
ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary);

INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
SELECT @default_workspace_id, 'KES', 0, 0, 0, 0, NOW()
FROM DUAL
WHERE EXISTS (SELECT 1 FROM workspaces WHERE id = @default_workspace_id)
  AND NOT EXISTS (SELECT 1 FROM workspace_wallets WHERE workspace_id = @default_workspace_id);

INSERT INTO company_profile (
    workspace_id, company_name, company_tagline, company_description, company_mission,
    company_values, owner_company_context, company_website, company_email, company_phone,
    company_address, company_location, company_timezone, company_industry, company_size,
    icp_job_titles, icp_industries, icp_pain_points, icp_channels, is_active
)
SELECT
    @default_workspace_id,
    'Clarity Platform Operations HQ',
    'Run the platform with billing, onboarding, safety, and support in one operating workspace.',
    'Internal Super Admin workspace for nurturing existing customer and trial workspace owners through onboarding, billing, setup, support, channel readiness, security settings, and operator audits.',
    'Keep every customer and trial workspace healthy, billable, supported, secure, ready for customer-facing work, and easy for workspace owners to get help from the platform team.',
    'Operational clarity\nAuditability\nTenant safety\nFast recovery\nMeasured automation',
    'This workspace receives existing customers and trial users only. Those workspace owners appear here as CRM contacts and automatically qualify for nurturing. It is not used for marketing to convert new customers, cold outreach, or tenant promotion.',
    'workspaces.php',
    'platform-ops@example.com',
    '+254700000000',
    'Platform Operations',
    'Nairobi, Kenya',
    'Africa/Nairobi',
    'CRM SaaS platform operations',
    'Platform admin team',
    'Workspace Owner, Super Admin, Platform Operator, Support Lead, Billing Operator',
    'Tenant workspace owners, internal SaaS operations, tenant success, billing operations',
    'Stuck onboarding, failed payments, low AI Credit balances, channel setup issues, tenant recovery, security reviews',
    'Default workspace contacts, Workspace directory, Settings, dashboard, in-app notifications, email, WhatsApp drafts',
    1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM company_profile WHERE workspace_id = @default_workspace_id AND is_active = 1)
ON DUPLICATE KEY UPDATE
    company_name = VALUES(company_name),
    company_description = VALUES(company_description),
    company_industry = VALUES(company_industry),
    owner_company_context = VALUES(owner_company_context),
    updated_at = NOW();

UPDATE company_profile
SET company_name = 'Clarity Platform Operations HQ',
    company_description = COALESCE(NULLIF(company_description, ''), 'Internal Super Admin workspace for nurturing existing customer and trial workspace owners.'),
    company_industry = COALESCE(NULLIF(company_industry, ''), 'CRM SaaS platform operations'),
    owner_company_context = COALESCE(NULLIF(owner_company_context, ''), 'This workspace receives existing customers and trial users only. It is not used for marketing to convert new customers, cold outreach, or tenant promotion.'),
    updated_at = NOW()
WHERE workspace_id = @default_workspace_id
  AND is_active = 1;

INSERT INTO products (workspace_id, name, description, category, features, pricing_info, target_audience, use_cases, benefits, unit_price, display_order, seed_metadata_json)
SELECT @default_workspace_id, seed.name, seed.description, seed.category, seed.features, 'Internal service', seed.target_audience, seed.use_cases, seed.benefits, 0, seed.display_order,
       JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', seed.seed_key, 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP())
FROM (
    SELECT 1 AS display_order, 'workspace_provisioning_and_recovery' AS seed_key, 'Workspace Provisioning and Recovery' AS name, 'Create, inspect, repair, reset, and recover tenant workspaces with audit-friendly operator actions.' AS description, 'Platform Operations' AS category, JSON_ARRAY('Workspace directory', 'Setup links', 'Onboarding reset', 'Deletion guardrails') AS features, 'Super Admin and platform support' AS target_audience, 'Provision workspaces, recover stuck owners, review deletion risk.' AS use_cases, 'Faster support turnaround and safer tenant lifecycle management.' AS benefits
    UNION ALL SELECT 2, 'tenant_billing_and_ai_credit_operations', 'Tenant Billing and AI Credit Operations', 'Monitor subscription status, trial ends, AI Credit balances, checkout health, and failed provider events.', 'Billing Operations', JSON_ARRAY('Billing snapshots', 'AI Credit wallet review', 'Trial risk review', 'Payment failure tasks'), 'Billing operator and Super Admin', 'Review low wallet workspaces, failed payments, and overdue trial conversions.', 'Protect revenue and reduce billing surprises.'
    UNION ALL SELECT 3, 'platform_dashboard_and_reporting', 'Platform Dashboard and Reporting', 'Summarize operational score, stuck onboarding, billing risk, token wallet exposure, and recent operator activity for Super Admin review.', 'Reporting Operations', JSON_ARRAY('Operational score', 'Risk filters', 'Audit summaries', 'Workspace directory reporting'), 'Super Admin and platform operator', 'Run daily platform standups and see what needs attention.', 'Turns scattered platform signals into one operating rhythm.'
    UNION ALL SELECT 4, 'onboarding_lifecycle_management', 'Onboarding Lifecycle Management', 'Find stuck onboarding workspaces, draft context-aware nudges, send setup links, and track recovery actions.', 'Tenant Success', JSON_ARRAY('Lifecycle nudges', 'AI drafts', 'Recovery panel', 'Setup prompts'), 'Tenant success and platform support', 'Recover incomplete onboarding and improve activation.', 'More workspaces become operational without manual guesswork.'
    UNION ALL SELECT 5, 'channel_health_and_deliverability', 'Channel Health and Deliverability', 'Review email, assistant Gmail, IMAP, SMTP, and WhatsApp readiness signals for each workspace.', 'Channel Operations', JSON_ARRAY('Health badges', 'Safe checks', 'Assistant mailbox review', 'WhatsApp signup status'), 'Platform support and implementation admin', 'Diagnose missing inbox, OAuth, SMTP, IMAP, or WhatsApp setup.', 'Clearer setup support and fewer silent channel failures.'
    UNION ALL SELECT 6, 'automation_governance_and_security_review', 'Automation Governance and Security Review', 'Manage Super Admin 2FA, operator audit reviews, automation safety, incident follow-up, and policy settings.', 'Governance', JSON_ARRAY('2FA review', 'Operator audit', 'Automation safety', 'Security setting tasks'), 'Super Admin', 'Review risky actions, deletion attempts, impersonation, and automation changes.', 'Safer platform operations with traceable decisions.'
    UNION ALL SELECT 7, 'workspace_deletion_review_and_compliance', 'Workspace Deletion Review and Compliance', 'Review destructive delete requests, confirm guardrails, preserve audit context, and document operator reasons.', 'Governance', JSON_ARRAY('Delete guardrails', '2FA checkpoint', 'Audit metadata', 'Reason review'), 'Super Admin', 'Verify deletions are intentional, compliant, and never target protected workspaces.', 'Reduces irreversible deletion mistakes and strengthens accountability.'
    UNION ALL SELECT 8, 'platform_support_escalation_desk', 'Platform Support Escalation Desk', 'Coordinate owner recovery, billing disputes, setup blockers, failed provider events, and support escalations from one operating workspace.', 'Support Operations', JSON_ARRAY('Escalation templates', 'Support tasks', 'Owner recovery', 'Provider follow-up'), 'Platform support and Super Admin', 'Move tenant issues from signal to owner contact to resolution.', 'Gives support a consistent path for high-risk platform issues.'
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM products existing WHERE existing.workspace_id = @default_workspace_id AND existing.name = seed.name
);

INSERT INTO tasks (workspace_id, title, description, metadata_json, assigned_to, created_by, status, priority, due_date)
SELECT @default_workspace_id, seed.title, seed.description,
       JSON_OBJECT('source', 'default_workspace_platform_ops', 'platform_ops_area', seed.area, 'platform_ops_key', seed.seed_key, 'url', seed.url),
       @platform_owner_user_id, COALESCE(@platform_owner_user_id, 1), 'pending', seed.priority, DATE_ADD(NOW(), INTERVAL seed.due_days DAY)
FROM (
    SELECT 'review_stuck_onboarding' AS seed_key, 'onboarding_recovery' AS area, 'Review stuck onboarding workspaces' AS title, 'Open the workspace directory stuck-onboarding filter and recover owners with setup nudges where needed.' AS description, 'workspaces.php?onboarding=stuck' AS url, 'high' AS priority, 1 AS due_days
    UNION ALL SELECT 'review_workspace_billing', 'billing', 'Review workspace billing health', 'Check trial ends, past-due subscriptions, low tokens, and failed provider events.', 'workspaces.php', 'high', 1
    UNION ALL SELECT 'review_token_wallet_risk', 'token_wallet', 'Review token wallet risk', 'Identify low-token workspaces and decide whether to nudge, top up, suspend, or escalate.', 'workspaces.php', 'high', 1
    UNION ALL SELECT 'audit_channel_health', 'channel_health', 'Audit channel health signals', 'Review workspaces with incomplete email, assistant Gmail, IMAP, SMTP, or WhatsApp setup.', 'workspaces.php', 'medium', 3
    UNION ALL SELECT 'review_operator_actions', 'audit', 'Review recent operator actions', 'Check impersonation, deletion, reset, setup-link, and onboarding recovery audit events.', 'workspaces.php', 'medium', 3
    UNION ALL SELECT 'review_workspace_deletions', 'deletion_review', 'Review workspace deletion controls', 'Confirm deletion guardrails, recent delete attempts, and 2FA policy for destructive actions.', 'workspaces.php', 'medium', 3
    UNION ALL SELECT 'review_security_settings', 'security', 'Review Super Admin security settings', 'Confirm workspace login/delete 2FA policy and authenticator readiness.', 'workspaces.php', 'medium', 3
    UNION ALL SELECT 'refresh_platform_operating_brief', 'operating_brief', 'Refresh platform operating brief', 'Regenerate the default workspace operating brief after major platform or billing changes.', 'dashboard.php', 'low', 7
) seed
WHERE @platform_owner_user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM tasks existing
      WHERE existing.workspace_id = @default_workspace_id
        AND JSON_UNQUOTE(JSON_EXTRACT(existing.metadata_json, '$.platform_ops_key')) = seed.seed_key
  );

INSERT INTO workspace_onboarding_state (
    workspace_id, status, current_step, readiness_score, required_steps_json, completed_steps_json,
    skipped_optional_json, optional_setup_json, communication_channel, automation_launch_mode,
    technical_level, relationship_style, tone_json, ai_autoresponder_mode, ai_best_practices_enabled,
    commercial_layer_enabled, deal_automation_enabled, recommended_workflows_json, launch_summary_json,
    starter_kit_json, completed_at
)
SELECT
    @default_workspace_id, 'completed', 8, 100,
    JSON_ARRAY('start', 'channels', 'voice', 'offer', 'vision', 'money', 'autopilot', 'launch'),
    JSON_ARRAY('start', 'channels', 'voice', 'offer', 'vision', 'money', 'autopilot', 'launch'),
    JSON_ARRAY(),
    JSON_OBJECT('platform_ops', JSON_OBJECT('internal_channel_ready', TRUE, 'internal_team_ready', TRUE, 'owner_helpline_enabled', TRUE)),
    'email', 'full_auto', 'run_quietly', 'trusted_advisor',
    JSON_OBJECT('draft_tone_preset', 'consultative', 'draft_cta_style', 'clear', 'draft_formality_level', 'balanced', 'draft_reading_level', 'professional'),
    'draft_only', 1, 1, 1,
    JSON_OBJECT('source', 'migration_442', 'recommendations', JSON_ARRAY()),
    JSON_OBJECT(
        'platform_ops', TRUE,
        'owner_helpline_enabled', TRUE,
        'contact_scope', 'existing_customer_or_trial',
        'nurture_auto_qualified', TRUE,
        'not_for_new_customer_marketing', TRUE,
        'not_for_cold_outreach', TRUE,
        'operating_boundary', 'Use this workspace for nurturing existing customer and trial workspace owners only.',
        'operating_brief', JSON_OBJECT('markdown', '## Workspace Operating Brief\n\nPlatform Ops HQ is operational for existing customer and trial owner nurturing, billing recovery, onboarding recovery, support, security review, and channel readiness.', 'generated_at', UTC_TIMESTAMP(), 'readiness', JSON_OBJECT('readiness_score', 100, 'operational_score', 100, 'is_operational', TRUE)),
        'generated_at', UTC_TIMESTAMP()
    ),
    JSON_OBJECT(
        'source', 'default_workspace_platform_ops',
        'tasks', JSON_ARRAY('review_stuck_onboarding', 'review_workspace_billing', 'review_token_wallet_risk', 'audit_channel_health', 'review_operator_actions', 'review_workspace_deletions', 'review_security_settings', 'refresh_platform_operating_brief'),
        'products', JSON_ARRAY('Workspace Provisioning and Recovery', 'Tenant Billing and AI Credit Operations', 'Platform Dashboard and Reporting', 'Onboarding Lifecycle Management', 'Channel Health and Deliverability', 'Automation Governance and Security Review', 'Workspace Deletion Review and Compliance', 'Platform Support Escalation Desk'),
        'generated_at', UTC_TIMESTAMP()
    ),
    NOW()
FROM DUAL
WHERE EXISTS (SELECT 1 FROM workspaces WHERE id = @default_workspace_id)
ON DUPLICATE KEY UPDATE
    status = 'completed',
    current_step = 8,
    readiness_score = 100,
    required_steps_json = VALUES(required_steps_json),
    completed_steps_json = VALUES(completed_steps_json),
    skipped_optional_json = VALUES(skipped_optional_json),
    optional_setup_json = VALUES(optional_setup_json),
    communication_channel = VALUES(communication_channel),
    automation_launch_mode = VALUES(automation_launch_mode),
    technical_level = VALUES(technical_level),
    relationship_style = VALUES(relationship_style),
    tone_json = VALUES(tone_json),
    ai_autoresponder_mode = VALUES(ai_autoresponder_mode),
    ai_best_practices_enabled = VALUES(ai_best_practices_enabled),
    commercial_layer_enabled = VALUES(commercial_layer_enabled),
    deal_automation_enabled = VALUES(deal_automation_enabled),
    recommended_workflows_json = VALUES(recommended_workflows_json),
    launch_summary_json = VALUES(launch_summary_json),
    starter_kit_json = VALUES(starter_kit_json),
    completed_at = COALESCE(workspace_onboarding_state.completed_at, NOW()),
    updated_at = NOW();

UPDATE default_workspace_ops_events
SET status = 'resolved',
    resolved_at = COALESCE(resolved_at, NOW()),
    resolution_summary = 'Default workspace operationalization baseline repaired by migration 442.',
    updated_at = NOW()
WHERE default_workspace_id = @default_workspace_id
  AND active_signal_key = 'missing_platform_ops_asset:operationalization_pending'
  AND status <> 'resolved';
