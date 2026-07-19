-- Repair production-ready Platform Ops defaults for the protected default workspace.
-- This migration is intentionally idempotent and keeps risky live-send surfaces supervised.

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 AND slug = 'default' LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY CASE WHEN id = 1 THEN 0 ELSE 1 END, id ASC LIMIT 1),
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
        '$.production_defaults_repaired_at', UTC_TIMESTAMP(),
        '$.operationalized_source', 'migration_489',
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
WHERE @default_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id),
    is_primary = VALUES(is_primary);

INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
SELECT @default_workspace_id, 'KES', 0, 0, 0, 0, NOW()
FROM DUAL
WHERE @default_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id);

INSERT INTO company_profile (
    workspace_id, company_name, company_legal_name, company_tax_id, company_tagline, company_description, company_mission,
    company_values, owner_company_context, company_website, company_email, company_phone,
    company_address, company_location, company_timezone, company_industry, company_size,
    icp_job_titles, icp_industries, icp_pain_points, icp_channels, is_active
)
SELECT
    @default_workspace_id,
    'Clarity Platform Operations HQ',
    'Clarity Platform Operations HQ',
    'PLATFORM-OPS',
    'Run the platform with billing, onboarding, safety, and support in one operating workspace.',
    'Internal Super Admin workspace for nurturing existing customer and trial workspace owners through onboarding, billing, setup, support, channel readiness, token operations, security settings, and operator audits.',
    'Keep every customer and trial workspace healthy, billable, supported, secure, ready for customer-facing work, and easy for workspace owners to get help from the platform team.',
    'Operational clarity\nAuditability\nTenant safety\nFast recovery\nMeasured automation',
    'This workspace receives existing customers and trial users only. Those workspace owners appear here as CRM contacts and automatically qualify for nurturing: support, welcome emails, nudges, billing follow-up, and reported-problem management. It is not used for marketing to convert new customers, cold outreach, or tenant promotion.',
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
WHERE @default_workspace_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM company_profile WHERE workspace_id = @default_workspace_id AND is_active = 1);

UPDATE company_profile
SET company_name = 'Clarity Platform Operations HQ',
    company_legal_name = 'Clarity Platform Operations HQ',
    company_tax_id = 'PLATFORM-OPS',
    company_tagline = 'Run the platform with billing, onboarding, safety, and support in one operating workspace.',
    company_description = 'Internal Super Admin workspace for nurturing existing customer and trial workspace owners through onboarding, billing, setup, support, channel readiness, token operations, security settings, and operator audits.',
    company_mission = 'Keep every customer and trial workspace healthy, billable, supported, secure, ready for customer-facing work, and easy for workspace owners to get help from the platform team.',
    company_values = 'Operational clarity\nAuditability\nTenant safety\nFast recovery\nMeasured automation',
    owner_company_context = 'This workspace receives existing customers and trial users only. Those workspace owners appear here as CRM contacts and automatically qualify for nurturing: support, welcome emails, nudges, billing follow-up, and reported-problem management. It is not used for marketing to convert new customers, cold outreach, or tenant promotion.',
    company_website = COALESCE(NULLIF(company_website, ''), 'workspaces.php'),
    company_email = COALESCE(NULLIF(company_email, ''), 'platform-ops@example.com'),
    company_phone = COALESCE(NULLIF(company_phone, ''), '+254700000000'),
    company_address = COALESCE(NULLIF(company_address, ''), 'Platform Operations'),
    company_location = 'Nairobi, Kenya',
    company_timezone = 'Africa/Nairobi',
    company_industry = 'CRM SaaS platform operations',
    company_size = 'Platform admin team',
    icp_job_titles = 'Workspace Owner, Super Admin, Platform Operator, Support Lead, Billing Operator',
    icp_industries = 'Tenant workspace owners, internal SaaS operations, tenant success, billing operations',
    icp_pain_points = 'Stuck onboarding, failed payments, low AI Credit balances, channel setup issues, tenant recovery, security reviews',
    icp_channels = 'Default workspace contacts, Workspace directory, Settings, dashboard, in-app notifications, email, WhatsApp drafts',
    updated_at = NOW()
WHERE workspace_id = @default_workspace_id
  AND is_active = 1;

INSERT INTO invoice_settings (
    id, enabled, invoice_prefix, invoice_next_number, proforma_prefix, proforma_next_number,
    quote_prefix, quote_next_number, default_currency, default_tax_mode, default_tax_rate,
    default_payment_terms_days, default_validity_days, default_notes, default_terms,
    company_legal_name, company_tax_id, company_address, company_email, company_phone,
    bank_name, bank_account_name, bank_account_number, bank_branch, bank_swift,
    bank_instructions, logo_asset_path, footer_text, visual_theme, default_template_key,
    preview_document_type, proposal_intro_text, acceptance_instructions,
    ai_create_quotes, ai_revise_documents, ai_send_documents, ai_finalize_invoices, ai_mark_paid,
    ai_require_approval_send, ai_require_approval_finalize, ai_allowed_channels,
    ai_max_discount_percent, ai_max_total_change_percent, ai_allowed_document_types_by_stage, updated_by
)
VALUES (
    1, 1, 'OPS-INV-', 1, 'OPS-PF-', 1,
    'OPS-QT-', 1, 'KES', 'exclusive', 0,
    7, 14, 'Internal platform operations document. Review tenant billing or support context before sending externally.',
    'Payment or token allocation terms should match the tenant billing record and operator audit trail.',
    'Clarity Platform Operations HQ', 'PLATFORM-OPS', 'Platform Operations', 'platform-ops@example.com', '+254700000000',
    'Platform Operations Bank', 'Clarity Platform Operations', '0000000000', 'Nairobi', 'PLATFORM',
    'Use the payment reference shown on the invoice. Confirm payment in the workspace billing panel after settlement.',
    '', 'Generated from the Platform Ops default workspace.', 'classic', 'classic',
    'invoice', 'Prepared for platform operations review.',
    'Confirm the operational action in the workspace admin panel and retain the audit trail.',
    1, 1, 1, 1, 0,
    0, 0, JSON_OBJECT('email', TRUE, 'whatsapp', TRUE),
    20.0000, 25.0000,
    JSON_OBJECT('proposal', JSON_ARRAY('quote', 'proforma'), 'negotiation', JSON_ARRAY('quote', 'proforma', 'invoice'), 'closed_won', JSON_ARRAY('invoice')),
    @platform_owner_user_id
)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    invoice_prefix = VALUES(invoice_prefix),
    proforma_prefix = VALUES(proforma_prefix),
    quote_prefix = VALUES(quote_prefix),
    default_currency = VALUES(default_currency),
    default_tax_mode = VALUES(default_tax_mode),
    default_tax_rate = VALUES(default_tax_rate),
    default_payment_terms_days = VALUES(default_payment_terms_days),
    default_validity_days = VALUES(default_validity_days),
    default_notes = VALUES(default_notes),
    default_terms = VALUES(default_terms),
    company_legal_name = VALUES(company_legal_name),
    company_tax_id = VALUES(company_tax_id),
    company_address = VALUES(company_address),
    company_email = VALUES(company_email),
    company_phone = VALUES(company_phone),
    bank_name = VALUES(bank_name),
    bank_account_name = VALUES(bank_account_name),
    bank_account_number = VALUES(bank_account_number),
    bank_branch = VALUES(bank_branch),
    bank_swift = VALUES(bank_swift),
    bank_instructions = VALUES(bank_instructions),
    footer_text = VALUES(footer_text),
    visual_theme = VALUES(visual_theme),
    default_template_key = VALUES(default_template_key),
    preview_document_type = VALUES(preview_document_type),
    proposal_intro_text = VALUES(proposal_intro_text),
    acceptance_instructions = VALUES(acceptance_instructions),
    ai_create_quotes = VALUES(ai_create_quotes),
    ai_revise_documents = VALUES(ai_revise_documents),
    ai_send_documents = VALUES(ai_send_documents),
    ai_finalize_invoices = VALUES(ai_finalize_invoices),
    ai_mark_paid = VALUES(ai_mark_paid),
    ai_require_approval_send = VALUES(ai_require_approval_send),
    ai_require_approval_finalize = VALUES(ai_require_approval_finalize),
    ai_allowed_channels = VALUES(ai_allowed_channels),
    ai_max_discount_percent = VALUES(ai_max_discount_percent),
    ai_max_total_change_percent = VALUES(ai_max_total_change_percent),
    ai_allowed_document_types_by_stage = VALUES(ai_allowed_document_types_by_stage),
    updated_by = VALUES(updated_by);

INSERT INTO user_strategy_profiles (
    workspace_id, user_id, target_market_focus, ideal_customer_profile, offer_angle,
    segment_focus, sales_motion, deal_movement_strategy, outreach_posture, positioning_notes,
    draft_tone_preset, draft_voice_notes, draft_cta_style, draft_formality_level, draft_reading_level,
    lean_problem, lean_customer_segments, lean_unique_value_proposition, lean_solution, lean_channels,
    lean_revenue_streams, lean_cost_structure, lean_key_metrics, lean_unfair_advantage
)
SELECT
    @default_workspace_id,
    @platform_owner_user_id,
    'Existing customer and trial workspace owner nurturing, support, and tenant lifecycle management',
    'Existing customer and trial workspace owners who need setup help, billing clarity, support follow-up, and reliable recovery from the Super Admin platform team.',
    'A single nurture workspace that turns eligible customer and trial owners into owner contacts, repeatable services, tasks, templates, and operating briefs.',
    'Tenant health, billing risk, setup completion, channel readiness, and governance reviews',
    'Internal nurturing rhythm for customers and trials, not new-customer marketing or outbound sales',
    'Move operational items from detection to review, owner contact, recovery, and audit closure.',
    'Helpful, specific, nurturing, and recovery-focused. Do not use this workspace for promotional cold outreach or marketing to convert new customers.',
    'Clarity should treat this workspace as the platform management HQ for existing customer and trial owner nurturing. Contacts are eligible workspace owners, so drafts should use owner workspace status, onboarding gaps, billing risk, AI Credit balance, channel health, and recent admin context.',
    'consultative',
    'Calm, precise, owner-friendly, and action-oriented. Explain the issue, the next step, and the business impact without sounding promotional.',
    'clear',
    'balanced',
    'professional',
    'Platform work gets scattered across tenant setup, billing, tokens, channel health, support, and security.',
    'Workspace owners, Super Admin, platform support, billing operations, tenant success, technical operator.',
    'A default workspace that runs customer and trial nurturing with owner contacts, operational context, templates, tasks, and audit-aware guidance.',
    'Centralize eligible owner contacts, workspace directory, onboarding recovery, billing guide, nudge drafts, support follow-up, security review, and platform follow-up tasks.',
    'Default workspace contacts, Dashboard, Settings, Workspace Directory, Clarity Super Admin Ops, in-app notifications, email, WhatsApp draft paths.',
    'Tenant subscriptions, token top-ups, paid support, setup assistance, platform managed services.',
    'AI Credits, messaging providers, payment provider fees, support time, hosting, compliance reviews.',
    'Operational score, stuck onboarding count, failed billing events, low token workspaces, unresolved operator actions.',
    'Direct access to platform-wide tenant, billing, audit, and setup context.'
FROM DUAL
WHERE @platform_owner_user_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    target_market_focus = VALUES(target_market_focus),
    ideal_customer_profile = VALUES(ideal_customer_profile),
    offer_angle = VALUES(offer_angle),
    segment_focus = VALUES(segment_focus),
    sales_motion = VALUES(sales_motion),
    deal_movement_strategy = VALUES(deal_movement_strategy),
    outreach_posture = VALUES(outreach_posture),
    positioning_notes = VALUES(positioning_notes),
    draft_tone_preset = VALUES(draft_tone_preset),
    draft_voice_notes = VALUES(draft_voice_notes),
    draft_cta_style = VALUES(draft_cta_style),
    draft_formality_level = VALUES(draft_formality_level),
    draft_reading_level = VALUES(draft_reading_level),
    lean_problem = VALUES(lean_problem),
    lean_customer_segments = VALUES(lean_customer_segments),
    lean_unique_value_proposition = VALUES(lean_unique_value_proposition),
    lean_solution = VALUES(lean_solution),
    lean_channels = VALUES(lean_channels),
    lean_revenue_streams = VALUES(lean_revenue_streams),
    lean_cost_structure = VALUES(lean_cost_structure),
    lean_key_metrics = VALUES(lean_key_metrics),
    lean_unfair_advantage = VALUES(lean_unfair_advantage);

DROP TEMPORARY TABLE IF EXISTS default_workspace_product_repair;
CREATE TEMPORARY TABLE default_workspace_product_repair (
    seed_key VARCHAR(100) PRIMARY KEY,
    display_order INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100) NOT NULL,
    features LONGTEXT NOT NULL,
    target_audience TEXT NOT NULL,
    use_cases TEXT NOT NULL,
    benefits TEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO default_workspace_product_repair
    (seed_key, display_order, name, description, category, features, target_audience, use_cases, benefits)
VALUES
    ('workspace_provisioning_and_recovery', 1, 'Workspace Provisioning and Recovery', 'Create, inspect, repair, reset, and recover tenant workspaces with audit-friendly operator actions.', 'Platform Operations', JSON_ARRAY('Workspace directory', 'Setup links', 'Onboarding reset', 'Deletion guardrails'), 'Super Admin and platform support', 'Provision workspaces, recover stuck owners, review deletion risk.', 'Faster support turnaround and safer tenant lifecycle management.'),
    ('tenant_billing_and_ai_credit_operations', 2, 'Tenant Billing and AI Credit Operations', 'Monitor subscription status, trial ends, AI Credit balances, checkout health, and failed provider events.', 'Billing Operations', JSON_ARRAY('Billing snapshots', 'AI Credit wallet review', 'Trial risk review', 'Payment failure tasks'), 'Billing operator and Super Admin', 'Review low wallet workspaces, failed payments, and overdue trial conversions.', 'Protect revenue and reduce billing surprises.'),
    ('platform_dashboard_and_reporting', 3, 'Platform Dashboard and Reporting', 'Summarize operational score, stuck onboarding, billing risk, token wallet exposure, and recent operator activity for Super Admin review.', 'Reporting Operations', JSON_ARRAY('Operational score', 'Risk filters', 'Audit summaries', 'Workspace directory reporting'), 'Super Admin and platform operator', 'Run daily platform standups and see what needs attention.', 'Turns scattered platform signals into one operating rhythm.'),
    ('onboarding_lifecycle_management', 4, 'Onboarding Lifecycle Management', 'Find stuck onboarding workspaces, draft context-aware nudges, send setup links, and track recovery actions.', 'Tenant Success', JSON_ARRAY('Lifecycle nudges', 'AI drafts', 'Recovery panel', 'Setup prompts'), 'Tenant success and platform support', 'Recover incomplete onboarding and improve activation.', 'More workspaces become operational without manual guesswork.'),
    ('channel_health_and_deliverability', 5, 'Channel Health and Deliverability', 'Review email, assistant Gmail, IMAP, SMTP, and WhatsApp readiness signals for each workspace.', 'Channel Operations', JSON_ARRAY('Health badges', 'Safe checks', 'Assistant mailbox review', 'WhatsApp signup status'), 'Platform support and implementation admin', 'Diagnose missing inbox, OAuth, SMTP, IMAP, or WhatsApp setup.', 'Clearer setup support and fewer silent channel failures.'),
    ('automation_governance_and_security_review', 6, 'Automation Governance and Security Review', 'Manage Super Admin 2FA, operator audit reviews, automation safety, incident follow-up, and policy settings.', 'Governance', JSON_ARRAY('2FA review', 'Operator audit', 'Automation safety', 'Security setting tasks'), 'Super Admin', 'Review risky actions, deletion attempts, impersonation, and automation changes.', 'Safer platform operations with traceable decisions.'),
    ('workspace_deletion_review_and_compliance', 7, 'Workspace Deletion Review and Compliance', 'Review destructive delete requests, confirm guardrails, preserve audit context, and document operator reasons.', 'Governance', JSON_ARRAY('Delete guardrails', '2FA checkpoint', 'Audit metadata', 'Reason review'), 'Super Admin', 'Verify deletions are intentional, compliant, and never target protected workspaces.', 'Reduces irreversible deletion mistakes and strengthens accountability.'),
    ('platform_support_escalation_desk', 8, 'Platform Support Escalation Desk', 'Coordinate owner recovery, billing disputes, setup blockers, failed provider events, and support escalations from one operating workspace.', 'Support Operations', JSON_ARRAY('Escalation templates', 'Support tasks', 'Owner recovery', 'Provider follow-up'), 'Platform support and Super Admin', 'Move tenant issues from signal to owner contact to resolution.', 'Gives support a consistent path for high-risk platform issues.');

UPDATE products p
JOIN default_workspace_product_repair seed ON seed.name = p.name
SET p.description = seed.description,
    p.category = seed.category,
    p.features = seed.features,
    p.pricing_info = 'Internal service',
    p.target_audience = seed.target_audience,
    p.use_cases = seed.use_cases,
    p.benefits = seed.benefits,
    p.unit_price = 0,
    p.display_order = seed.display_order,
    p.is_active = 1,
    p.seed_metadata_json = JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', seed.seed_key, 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP()),
    p.updated_at = NOW()
WHERE p.workspace_id = @default_workspace_id
  AND (CASE WHEN JSON_VALID(COALESCE(p.seed_metadata_json, '')) THEN JSON_EXTRACT(p.seed_metadata_json, '$.customized_at') IS NULL ELSE TRUE END);

INSERT INTO products (workspace_id, name, description, category, features, pricing_info, target_audience, use_cases, benefits, unit_price, display_order, seed_metadata_json)
SELECT @default_workspace_id, seed.name, seed.description, seed.category, seed.features, 'Internal service', seed.target_audience, seed.use_cases, seed.benefits, 0, seed.display_order,
       JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', seed.seed_key, 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP())
FROM default_workspace_product_repair seed
WHERE @default_workspace_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM products existing
      WHERE existing.workspace_id = @default_workspace_id
        AND existing.name = seed.name
  );

DROP TEMPORARY TABLE IF EXISTS default_workspace_task_repair;
CREATE TEMPORARY TABLE default_workspace_task_repair (
    seed_key VARCHAR(100) PRIMARY KEY,
    area VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    url VARCHAR(255) NOT NULL,
    priority VARCHAR(20) NOT NULL
) ENGINE=InnoDB;

INSERT INTO default_workspace_task_repair (seed_key, area, title, description, url, priority)
VALUES
    ('review_stuck_onboarding', 'onboarding_recovery', 'Review stuck onboarding workspaces', 'Open the workspace directory stuck-onboarding filter and recover owners with setup nudges where needed.', 'workspaces.php?onboarding=stuck', 'high'),
    ('review_workspace_billing', 'billing', 'Review workspace billing health', 'Check trial ends, past-due subscriptions, low tokens, and failed provider events.', 'workspaces.php', 'high'),
    ('review_token_wallet_risk', 'token_wallet', 'Review token wallet risk', 'Identify low-token workspaces and decide whether to nudge, top up, suspend, or escalate.', 'workspaces.php', 'high'),
    ('audit_channel_health', 'channel_health', 'Audit channel health signals', 'Review workspaces with incomplete email, assistant Gmail, IMAP, SMTP, or WhatsApp setup.', 'workspaces.php', 'medium'),
    ('review_operator_actions', 'audit', 'Review recent operator actions', 'Check impersonation, deletion, reset, setup-link, and onboarding recovery audit events.', 'workspaces.php', 'medium'),
    ('review_workspace_deletions', 'deletion_review', 'Review workspace deletion controls', 'Confirm deletion guardrails, recent delete attempts, and 2FA policy for destructive actions.', 'workspaces.php', 'medium'),
    ('review_security_settings', 'security', 'Review Super Admin security settings', 'Confirm workspace login/delete 2FA policy and authenticator readiness.', 'workspaces.php', 'medium'),
    ('refresh_platform_operating_brief', 'operating_brief', 'Refresh platform operating brief', 'Regenerate the default workspace operating brief after major platform or billing changes.', 'dashboard.php', 'low');

UPDATE tasks t
JOIN default_workspace_task_repair seed
  ON t.workspace_id = @default_workspace_id
 AND (
      t.title = seed.title
      OR CASE
          WHEN JSON_VALID(COALESCE(t.metadata_json, '')) THEN (
              JSON_UNQUOTE(JSON_EXTRACT(t.metadata_json, '$.seed_key')) = seed.seed_key
              OR JSON_UNQUOTE(JSON_EXTRACT(t.metadata_json, '$.platform_ops_key')) = seed.seed_key
          )
          ELSE FALSE
      END
 )
SET t.title = seed.title,
    t.description = seed.description,
    t.assigned_to = @platform_owner_user_id,
    t.created_by = COALESCE(t.created_by, @platform_owner_user_id),
    t.status = 'completed',
    t.priority = seed.priority,
    t.metadata_json = JSON_OBJECT(
        'source', 'default_workspace_platform_ops',
        'seed_source', 'default_workspace_platform_ops',
        'seed_key', seed.seed_key,
        'seed_version', '1.0.0',
        'last_seeded_at', UTC_TIMESTAMP(),
        'checklist_key', seed.seed_key,
        'platform_ops_area', seed.area,
        'url', seed.url,
        'auto_complete_allowed', FALSE
    ),
    t.updated_at = NOW();

INSERT INTO tasks (workspace_id, title, description, metadata_json, assigned_to, created_by, status, priority, created_at, updated_at)
SELECT @default_workspace_id, seed.title, seed.description,
       JSON_OBJECT(
           'source', 'default_workspace_platform_ops',
           'seed_source', 'default_workspace_platform_ops',
           'seed_key', seed.seed_key,
           'seed_version', '1.0.0',
           'last_seeded_at', UTC_TIMESTAMP(),
           'checklist_key', seed.seed_key,
           'platform_ops_area', seed.area,
           'url', seed.url,
           'auto_complete_allowed', FALSE
       ),
       @platform_owner_user_id,
       @platform_owner_user_id,
       'completed',
       seed.priority,
       NOW(),
       NOW()
FROM default_workspace_task_repair seed
WHERE @default_workspace_id IS NOT NULL
  AND @platform_owner_user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM tasks existing
      WHERE existing.workspace_id = @default_workspace_id
        AND (
            existing.title = seed.title
            OR CASE
                WHEN JSON_VALID(COALESCE(existing.metadata_json, '')) THEN (
                    JSON_UNQUOTE(JSON_EXTRACT(existing.metadata_json, '$.seed_key')) = seed.seed_key
                    OR JSON_UNQUOTE(JSON_EXTRACT(existing.metadata_json, '$.platform_ops_key')) = seed.seed_key
                )
                ELSE FALSE
            END
        )
  );

DROP TEMPORARY TABLE IF EXISTS default_workspace_email_template_repair;
CREATE TEMPORARY TABLE default_workspace_email_template_repair (
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

INSERT INTO default_workspace_email_template_repair
    (template_key, name, subject, description, variables_json, tags_json, body_html, body_text, match_metadata_json)
VALUES
    ('owner_welcome_setup', 'Platform Ops - Owner Welcome and Setup', 'Welcome to Clarity, {owner_name}', 'Warm setup note for a new workspace owner with the right next step.', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'welcome', 'owner_welcome_setup'), '<p>Hi {owner_name},</p><p>Welcome to Clarity. <strong>{workspace_name}</strong> is ready for setup.</p><p><a href="{setup_url}">Start setup</a></p><p>If anything gets blocked, reply here or use the <a href="{support_url}">support link</a>.</p>', 'Hi {owner_name},\n\nWelcome to Clarity. {workspace_name} is ready for setup.\n\nStart setup: {setup_url}\n\nIf anything gets blocked, reply here or use the support link: {support_url}', JSON_OBJECT('purposes', JSON_ARRAY('welcome', 'setup'), 'workflow_intents', JSON_ARRAY('owner_welcome_setup'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('welcoming', 'helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'))),
    ('onboarding_recovery', 'Platform Ops - Onboarding Recovery', 'Finish setup for {workspace_name}', 'Helpful owner nudge for a workspace stuck in onboarding.', JSON_ARRAY('owner_name', 'workspace_name', 'next_action', 'next_action_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'onboarding', 'onboarding_recovery'), '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is close to being ready. The next setup step is: <strong>{next_action}</strong>.</p><p><a href="{next_action_url}">Continue setup</a></p><p>If that step is blocked, reply here or <a href="{support_url}">open support</a>.</p>', 'Hi {owner_name},\n\n{workspace_name} is close to being ready. The next setup step is: {next_action}.\n\nContinue setup: {next_action_url}\n\nIf that step is blocked, reply here or open support: {support_url}', JSON_OBJECT('purposes', JSON_ARRAY('onboarding_recovery', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_stuck_onboarding_review'), 'lifecycle_stages', JSON_ARRAY('setup', 'stuck_onboarding'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'direct'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'next_action', 'next_action_url', 'support_url'))),
    ('billing_follow_up', 'Platform Ops - Billing Follow-up', 'Billing review needed for {workspace_name}', 'Clear billing review request for trials, subscriptions, or provider events.', JSON_ARRAY('owner_name', 'workspace_name', 'billing_status', 'action_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'billing', 'billing_follow_up'), '<p>Hi {owner_name},</p><p>A billing item needs review for <strong>{workspace_name}</strong>: {billing_status}.</p><p><a href="{action_url}">Review billing</a></p><p>Reply here if the status looks wrong or you need help resolving it.</p>', 'Hi {owner_name},\n\nA billing item needs review for {workspace_name}: {billing_status}.\n\nReview billing: {action_url}\n\nReply here if the status looks wrong or you need help resolving it.', JSON_OBJECT('purposes', JSON_ARRAY('billing_follow_up', 'payment_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('billing_risk', 'past_due'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('direct', 'helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'billing_status', 'action_url', 'support_url'))),
    ('failed_payment_review', 'Platform Ops - Failed Payment Review', 'Payment needs attention for {workspace_name}', 'Payment recovery note with checkout and support paths.', JSON_ARRAY('owner_name', 'workspace_name', 'payment_reference', 'checkout_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'billing', 'failed_payment_review'), '<p>Hi {owner_name},</p><p>The latest payment attempt for <strong>{workspace_name}</strong> needs attention. Reference: {payment_reference}.</p><p><a href="{checkout_url}">Continue payment</a></p><p>If the payment should have completed, reply here or <a href="{support_url}">contact support</a>.</p>', 'Hi {owner_name},\n\nThe latest payment attempt for {workspace_name} needs attention. Reference: {payment_reference}.\n\nContinue payment: {checkout_url}\n\nIf the payment should have completed, reply here or contact support: {support_url}', JSON_OBJECT('purposes', JSON_ARRAY('failed_payment', 'payment_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('payment_failed'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('direct', 'helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'payment_reference', 'checkout_url', 'support_url'))),
    ('low_token_warning', 'Platform Ops - Low AI Credit Warning', '{workspace_name} AI Credit balance is low', 'Low AI Credit balance warning before assistance is interrupted.', JSON_ARRAY('owner_name', 'workspace_name', 'available_tokens', 'top_up_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'tokens', 'low_token_warning'), '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> has {available_tokens} AI Credits available. Top up soon so AI assistance keeps running without interruption.</p><p><a href="{top_up_url}">Top up AI Credits</a></p><p>Reply here if you want help choosing the right credit level.</p>', 'Hi {owner_name},\n\n{workspace_name} has {available_tokens} AI Credits available. Top up soon so AI assistance keeps running without interruption.\n\nTop up AI Credits: {top_up_url}\n\nReply here if you want help choosing the right credit level.', JSON_OBJECT('purposes', JSON_ARRAY('token_warning', 'billing_follow_up'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('low_tokens'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('operational', 'direct'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'available_tokens', 'top_up_url', 'support_url'))),
    ('channel_setup_reminder', 'Platform Ops - Channel Setup Reminder', 'Connect email for {workspace_name}', 'Owner reminder for workspaces missing email channel setup.', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'channel_setup', 'channel_setup_reminder'), '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is active, but email is not connected yet. Connect email so inbox, replies, and customer follow-up can run reliably.</p><p><a href="{setup_url}">Connect email</a></p><p>If you want us to check the setup with you, reply here.</p>', 'Hi {owner_name},\n\n{workspace_name} is active, but email is not connected yet. Connect email so inbox, replies, and customer follow-up can run reliably.\n\nConnect email: {setup_url}\n\nIf you want us to check the setup with you, reply here.', JSON_OBJECT('purposes', JSON_ARRAY('channel_setup', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('channel_setup_reminder'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'operational'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'support_url'))),
    ('workspace_suspension_notice', 'Platform Ops - Workspace Suspension Notice', '{workspace_name} access needs review', 'Account access notice with a direct support path.', JSON_ARRAY('owner_name', 'workspace_name', 'reason', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'workspace_suspension_notice'), '<p>Hi {owner_name},</p><p>Access for <strong>{workspace_name}</strong> needs review: {reason}.</p><p><a href="{support_url}">Review next steps</a></p><p>Reply here if you believe access should already be restored.</p>', 'Hi {owner_name},\n\nAccess for {workspace_name} needs review: {reason}.\n\nReview next steps: {support_url}\n\nReply here if you believe access should already be restored.', JSON_OBJECT('purposes', JSON_ARRAY('suspension_notice', 'support'), 'workflow_intents', JSON_ARRAY('platform_ops_billing_risk_review'), 'lifecycle_stages', JSON_ARRAY('suspended'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('direct', 'supportive'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'reason', 'support_url'))),
    ('setup_link_resend', 'Platform Ops - Setup Link Resend', 'Your setup link for {workspace_name}', 'Manual setup or login recovery message for a workspace owner.', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'reset_url', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'setup', 'setup_link_resend'), '<p>Hi {owner_name},</p><p>Here is the setup link for <strong>{workspace_name}</strong>: <a href="{setup_url}">continue setup</a>.</p><p>If you need to reset your password first, use this link: <a href="{reset_url}">reset password</a>.</p><p>Reply here if either link does not work.</p>', 'Hi {owner_name},\n\nHere is the setup link for {workspace_name}: {setup_url}\n\nIf you need to reset your password first, use this link: {reset_url}\n\nReply here if either link does not work.', JSON_OBJECT('purposes', JSON_ARRAY('setup_link', 'setup_reminder'), 'workflow_intents', JSON_ARRAY('platform_ops_stuck_onboarding_review'), 'lifecycle_stages', JSON_ARRAY('setup'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'setup_url', 'reset_url', 'support_url'))),
    ('owner_problem_followup', 'Platform Ops - Owner Problem Follow-up', 'Following up on {workspace_name}', 'Follow-up for a problem reported by a workspace owner.', JSON_ARRAY('owner_name', 'workspace_name', 'issue_summary', 'recommended_action', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'owner_problem_followup'), '<p>Hi {owner_name},</p><p>I reviewed the issue for <strong>{workspace_name}</strong>: {issue_summary}</p><p><strong>Recommended next step:</strong> {recommended_action}</p><p>You can reply here or <a href="{support_url}">open support</a>.</p>', 'Hi {owner_name},\n\nI reviewed the issue for {workspace_name}: {issue_summary}.\n\nRecommended next step: {recommended_action}\n\nYou can reply here or open support: {support_url}', JSON_OBJECT('purposes', JSON_ARRAY('support_follow_up'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_open'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'clear'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'issue_summary', 'recommended_action', 'support_url'))),
    ('owner_support_resolution', 'Platform Ops - Owner Support Resolution', 'Resolved: {workspace_name} support update', 'Resolution note for owner support requests.', JSON_ARRAY('owner_name', 'workspace_name', 'resolution_summary', 'next_best_action', 'support_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'owner_support_resolution'), '<p>Hi {owner_name},</p><p>This is resolved for <strong>{workspace_name}</strong>: {resolution_summary}</p><p><strong>Next best action:</strong> {next_best_action}</p><p>Reply here if anything still looks off, or <a href="{support_url}">reopen support</a>.</p>', 'Hi {owner_name},\n\nThis is resolved for {workspace_name}: {resolution_summary}.\n\nNext best action: {next_best_action}\n\nReply here if anything still looks off, or reopen support: {support_url}', JSON_OBJECT('purposes', JSON_ARRAY('resolution_check', 'support_follow_up'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_resolved'), 'audiences', JSON_ARRAY('workspace_owner'), 'tones', JSON_ARRAY('helpful', 'clear'), 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'resolution_summary', 'next_best_action', 'support_url'))),
    ('support_escalation', 'Platform Ops - Support Escalation', 'Support escalation: {workspace_name}', 'Internal escalation summary for support/admin follow-through.', JSON_ARRAY('workspace_name', 'owner_email', 'issue_summary', 'recommended_action', 'operator_action_url'), JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'support', 'support_escalation'), '<p><strong>Workspace:</strong> {workspace_name}</p><p><strong>Owner:</strong> {owner_email}</p><p><strong>Issue:</strong> {issue_summary}</p><p><strong>Recommended action:</strong> {recommended_action}</p><p><a href="{operator_action_url}">Open operator action</a></p>', 'Workspace: {workspace_name}\nOwner: {owner_email}\nIssue: {issue_summary}\nRecommended action: {recommended_action}\nOpen operator action: {operator_action_url}', JSON_OBJECT('purposes', JSON_ARRAY('support_escalation'), 'workflow_intents', JSON_ARRAY('support_follow_up_escalation'), 'lifecycle_stages', JSON_ARRAY('support_escalated'), 'audiences', JSON_ARRAY('operator'), 'tones', JSON_ARRAY('operational'), 'required_variables', JSON_ARRAY('workspace_name', 'owner_email', 'issue_summary', 'recommended_action', 'operator_action_url')));

UPDATE email_templates
SET is_active = 0,
    updated_at = NOW()
WHERE slug IN ('welcome', 'follow_up', 'thank_you', 'product-announcement', 'default-webxpanse-owner-welcome', 'platform-workspace-payment-nudge', 'platform-workspace-trial-final-conversion', 'platform-workspace-trial-welcome')
  AND (workspace_id = @default_workspace_id OR workspace_id IS NULL);

UPDATE email_templates
SET is_active = 0,
    is_library = 0,
    updated_at = NOW()
WHERE workspace_id = @default_workspace_id
  AND slug LIKE 'library-%';

UPDATE email_templates et
JOIN default_workspace_email_template_repair seed
  ON et.slug = CONCAT('platform-ops-', seed.template_key)
SET et.workspace_id = @default_workspace_id,
    et.name = seed.name,
    et.subject = seed.subject,
    et.body_html = seed.body_html,
    et.body_text = seed.body_text,
    et.category = 'platform_ops',
    et.variables = seed.variables_json,
    et.is_active = 1,
    et.created_by = COALESCE(et.created_by, @platform_owner_user_id),
    et.is_library = 0,
    et.description = seed.description,
    et.tags = seed.tags_json,
    et.industry = 'SaaS operations',
    et.purpose = 'platform_ops',
    et.is_featured = 0,
    et.author = 'Clarity Platform Ops',
    et.version = '1.0',
    et.is_ai_generated = 1,
    et.template_key = seed.template_key,
    et.match_metadata_json = seed.match_metadata_json,
    et.seed_metadata_json = JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', seed.template_key, 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP()),
    et.updated_at = NOW()
WHERE (et.workspace_id = @default_workspace_id OR et.workspace_id IS NULL)
  AND (CASE WHEN JSON_VALID(COALESCE(et.seed_metadata_json, '')) THEN JSON_EXTRACT(et.seed_metadata_json, '$.customized_at') IS NULL ELSE TRUE END);

INSERT INTO email_templates (
    workspace_id, name, slug, subject, body_html, body_text, category, variables, is_active, created_by,
    is_library, description, tags, industry, purpose, is_featured, author, version, is_ai_generated,
    template_key, match_metadata_json, seed_metadata_json
)
SELECT
    @default_workspace_id,
    seed.name,
    CONCAT('platform-ops-', seed.template_key),
    seed.subject,
    seed.body_html,
    seed.body_text,
    'platform_ops',
    seed.variables_json,
    1,
    @platform_owner_user_id,
    0,
    seed.description,
    seed.tags_json,
    'SaaS operations',
    'platform_ops',
    0,
    'Clarity Platform Ops',
    '1.0',
    1,
    seed.template_key,
    seed.match_metadata_json,
    JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', seed.template_key, 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP())
FROM default_workspace_email_template_repair seed
WHERE @default_workspace_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM email_templates existing
      WHERE existing.workspace_id = @default_workspace_id
        AND existing.slug = CONCAT('platform-ops-', seed.template_key)
  );

DROP TEMPORARY TABLE IF EXISTS default_workspace_workflow_repair;
CREATE TEMPORARY TABLE default_workspace_workflow_repair (
    template_key VARCHAR(100) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    trigger_config LONGTEXT NOT NULL,
    conditions_json LONGTEXT NOT NULL,
    actions_json LONGTEXT NOT NULL,
    variables_json LONGTEXT NOT NULL,
    recipe_metadata_json LONGTEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO default_workspace_workflow_repair
    (template_key, name, description, trigger_config, conditions_json, actions_json, variables_json, recipe_metadata_json)
VALUES
    ('platform_ops_stuck_onboarding_review', 'Platform Ops - Stuck Onboarding Review', 'Review stuck onboarding workspaces and draft owner nudges.', JSON_OBJECT('type', 'scheduled_review', 'cadence', 'daily'), JSON_ARRAY(JSON_OBJECT('field', 'operational_score', 'operator', 'less_than', 'value', 80)), JSON_ARRAY(JSON_OBJECT('type', 'send_email', 'template_query', JSON_OBJECT('intent_key', 'platform_ops_stuck_onboarding_review', 'preferred_template_key', 'onboarding_recovery', 'purpose', 'onboarding_recovery', 'tone', 'helpful', 'lifecycle_stage', 'setup', 'audience', 'workspace_owner', 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'next_action_url')), 'subject', 'Finish setup for {workspace_name}', 'body', 'Hi {owner_name}, your workspace is nearly ready. Finish setup here: {next_action_url}'), JSON_OBJECT('type', 'create_task', 'title', 'Review stuck onboarding workspace'), JSON_OBJECT('type', 'send_in_app_notification', 'title', 'Workspace needs onboarding recovery', 'message', 'A workspace is stuck in onboarding and needs review.')), JSON_ARRAY('workspace_name', 'owner_email', 'owner_name', 'next_action_url'), JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', 'platform_ops_stuck_onboarding_review', 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP())),
    ('platform_ops_billing_risk_review', 'Platform Ops - Billing Risk Review', 'Create review tasks for failed payments, past-due subscriptions, and low AI Credit balances.', JSON_OBJECT('type', 'billing_signal'), JSON_ARRAY(JSON_OBJECT('field', 'plan_status', 'operator', 'in', 'value', JSON_ARRAY('trialing', 'past_due'))), JSON_ARRAY(JSON_OBJECT('type', 'send_email', 'template_query', JSON_OBJECT('intent_key', 'platform_ops_billing_risk_review', 'preferred_template_key', 'billing_follow_up', 'purpose', 'billing_follow_up', 'tone', 'direct', 'lifecycle_stage', 'billing_risk', 'audience', 'workspace_owner', 'required_variables', JSON_ARRAY('owner_name', 'workspace_name', 'billing_status', 'action_url')), 'subject', 'Billing review needed for {workspace_name}', 'body', 'Hi {owner_name}, billing needs review for {workspace_name}: {billing_status}. Review here: {action_url}'), JSON_OBJECT('type', 'create_task', 'title', 'Review billing risk for {workspace_name}')), JSON_ARRAY('workspace_name', 'plan_status', 'available_tokens', 'owner_name', 'billing_status', 'action_url'), JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', 'platform_ops_billing_risk_review', 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP())),
    ('platform_ops_security_review', 'Platform Ops - Security Review', 'Review Super Admin security settings and recent operator actions.', JSON_OBJECT('type', 'scheduled_review', 'cadence', 'weekly'), JSON_ARRAY(), JSON_ARRAY(JSON_OBJECT('type', 'create_task', 'title', 'Review operator audit and Super Admin 2FA settings'), JSON_OBJECT('type', 'send_in_app_notification', 'title', 'Security review due', 'message', 'Review Super Admin security settings and recent operator actions.')), JSON_ARRAY('operator_action_count', 'security_setting'), JSON_OBJECT('seed_source', 'default_workspace_platform_ops', 'seed_key', 'platform_ops_security_review', 'seed_version', '1.0.0', 'last_seeded_at', UTC_TIMESTAMP()));

UPDATE workflow_templates wt
JOIN default_workspace_workflow_repair seed ON seed.template_key = wt.template_key
SET wt.name = seed.name,
    wt.description = seed.description,
    wt.category = 'platform_ops',
    wt.trigger_config = seed.trigger_config,
    wt.conditions = seed.conditions_json,
    wt.actions = seed.actions_json,
    wt.variables = seed.variables_json,
    wt.is_public = 0,
    wt.created_by = COALESCE(wt.created_by, @platform_owner_user_id),
    wt.is_active = 1,
    wt.is_ai_generated = 0,
    wt.recipe_metadata_json = seed.recipe_metadata_json,
    wt.seed_metadata_json = seed.recipe_metadata_json
WHERE wt.template_key = seed.template_key
  AND (CASE WHEN JSON_VALID(COALESCE(wt.seed_metadata_json, '')) THEN JSON_EXTRACT(wt.seed_metadata_json, '$.customized_at') IS NULL ELSE TRUE END);

INSERT INTO workflow_templates (
    name, description, category, trigger_config, conditions, actions, variables,
    is_public, created_by, is_active, is_ai_generated, template_key, recipe_metadata_json, seed_metadata_json
)
SELECT seed.name, seed.description, 'platform_ops', seed.trigger_config, seed.conditions_json, seed.actions_json, seed.variables_json,
       0, @platform_owner_user_id, 1, 0, seed.template_key, seed.recipe_metadata_json, seed.recipe_metadata_json
FROM default_workspace_workflow_repair seed
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_templates existing WHERE existing.template_key = seed.template_key
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
    JSON_OBJECT('source', 'migration_489', 'recommendations', JSON_ARRAY('platform_ops_stuck_onboarding_review', 'platform_ops_billing_risk_review', 'platform_ops_security_review')),
    JSON_OBJECT(
        'company', JSON_OBJECT('name', 'Clarity Platform Operations HQ', 'industry', 'CRM SaaS platform operations', 'location', 'Nairobi, Kenya', 'description', 'Internal Super Admin workspace for nurturing existing customer and trial workspace owners.'),
        'offer', JSON_OBJECT('name', 'Workspace Provisioning and Recovery', 'pricing', 'Internal platform operations', 'ideal_customer', 'Existing customer and trial workspace owners', 'offer_angle', 'Run tenant health, billing, onboarding, security, and support from one workspace. This workspace receives existing customers and trial users only; they automatically qualify for nurturing. It is explicitly not for marketing to convert new customers, cold outreach, or tenant promotion.'),
        'platform_ops', TRUE,
        'owner_helpline_enabled', TRUE,
        'contact_scope', 'existing_customer_or_trial',
        'nurture_auto_qualified', TRUE,
        'not_for_new_customer_marketing', TRUE,
        'not_for_cold_outreach', TRUE,
        'operating_boundary', 'Use this workspace for nurturing existing customer and trial workspace owners only.',
        'operating_brief', JSON_OBJECT('markdown', '## Workspace Operating Brief\n\nPlatform Ops HQ is operational for existing customer and trial owner nurturing, billing recovery, onboarding recovery, support, security review, and channel readiness. Do not use it for new-customer marketing or cold outreach.', 'generated_at', UTC_TIMESTAMP(), 'readiness', JSON_OBJECT('readiness_score', 100, 'operational_score', 100, 'is_operational', TRUE)),
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
WHERE @default_workspace_id IS NOT NULL
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

INSERT INTO platform_auto_admin_settings (id, enabled, updated_by_user_id)
VALUES (1, 1, @platform_owner_user_id)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    updated_by_user_id = VALUES(updated_by_user_id),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workspace_auto_admin_settings (
    workspace_id, enabled, manual_freeze, managed_tabs_json, target_modes_json, effective_modes_json,
    readiness_snapshot_json, managed_defaults_version, last_evaluated_at, last_applied_at, updated_by_user_id
)
VALUES (
    @default_workspace_id, 1, 0,
    JSON_ARRAY('ai', 'ai_autoresponder', 'commercial_automation', 'deal_automation', 'workflow_automation'),
    JSON_OBJECT('deal_automation', 'suggest_only', 'workflow_automation', 'auto_safe', 'ai_autoresponder', 'draft_only', 'commercial_automation', 'auto_safe'),
    JSON_OBJECT('deal_automation', 'suggest_only', 'workflow_automation', 'auto_safe', 'ai_autoresponder', 'draft_only', 'commercial_automation', 'auto_safe'),
    JSON_OBJECT('source', 'migration_489', 'readiness', 'production_baseline', 'cold_outreach_allowed', FALSE, 'evaluated_at', UTC_TIMESTAMP()),
    3, NOW(), NOW(), @platform_owner_user_id
)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    manual_freeze = 0,
    freeze_reason = NULL,
    managed_tabs_json = VALUES(managed_tabs_json),
    target_modes_json = VALUES(target_modes_json),
    effective_modes_json = VALUES(effective_modes_json),
    readiness_snapshot_json = VALUES(readiness_snapshot_json),
    managed_defaults_version = VALUES(managed_defaults_version),
    last_evaluated_at = VALUES(last_evaluated_at),
    last_applied_at = VALUES(last_applied_at),
    updated_by_user_id = VALUES(updated_by_user_id),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workspace_ai_autoresponder_config (workspace_id, enabled, mode, default_confidence_threshold, config_json)
VALUES (
    @default_workspace_id, 0, 'draft_only', 0.850,
    JSON_OBJECT('source', 'migration_489', 'safe_default', TRUE, 'customer_facing_auto_send', FALSE)
)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    mode = VALUES(mode),
    default_confidence_threshold = VALUES(default_confidence_threshold),
    config_json = VALUES(config_json),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workspace_commercial_automation_config (
    workspace_id, enabled, mode, stage_entry_enabled, negotiation_revisions_enabled, auto_send_enabled,
    auto_convert_on_won_enabled, auto_mark_overdue_enabled, followup_reminders_enabled, approval_mode,
    send_delay_minutes, max_auto_discount_percent, max_auto_total_change_percent, max_revision_count_before_approval,
    require_recipient_for_send, require_nonzero_total_for_send, require_billing_identity_for_final_invoice,
    auto_convert_requires_status, negotiation_stale_hours, proposal_followup_hours, delivery_retry_limit,
    delivery_retry_backoff_minutes, task_owner_mode, config_json
)
VALUES (
    @default_workspace_id, 1, 'auto_safe', 1, 1, 0,
    0, 1, 1, 'threshold_only',
    0, 20.00, 25.00, 2,
    1, 1, 1,
    'accepted', 48, 24, 2,
    30, 'deal_owner', JSON_OBJECT('source', 'migration_489', 'safe_default', TRUE, 'customer_facing_auto_send', FALSE)
)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    mode = VALUES(mode),
    stage_entry_enabled = VALUES(stage_entry_enabled),
    negotiation_revisions_enabled = VALUES(negotiation_revisions_enabled),
    auto_send_enabled = VALUES(auto_send_enabled),
    auto_convert_on_won_enabled = VALUES(auto_convert_on_won_enabled),
    auto_mark_overdue_enabled = VALUES(auto_mark_overdue_enabled),
    followup_reminders_enabled = VALUES(followup_reminders_enabled),
    approval_mode = VALUES(approval_mode),
    require_recipient_for_send = VALUES(require_recipient_for_send),
    require_nonzero_total_for_send = VALUES(require_nonzero_total_for_send),
    require_billing_identity_for_final_invoice = VALUES(require_billing_identity_for_final_invoice),
    config_json = VALUES(config_json),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workspace_deal_automation_config (
    workspace_id, enabled, mode, min_confidence, lookback_days, cooldown_hours,
    require_approval_terminal, min_terminal_confidence, inactivity_days_for_loss, allow_multi_stage_jump,
    dry_run, reopen_lost_on_reengagement, config_json, schema_version
)
VALUES (
    @default_workspace_id, 1, 'suggest_only', 0.85, 14, 24,
    1, 0.92, 14, 0,
    0, 0, JSON_OBJECT('source', 'migration_489', 'safe_default', TRUE), 1
)
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    mode = VALUES(mode),
    min_confidence = VALUES(min_confidence),
    lookback_days = VALUES(lookback_days),
    cooldown_hours = VALUES(cooldown_hours),
    require_approval_terminal = VALUES(require_approval_terminal),
    min_terminal_confidence = VALUES(min_terminal_confidence),
    inactivity_days_for_loss = VALUES(inactivity_days_for_loss),
    allow_multi_stage_jump = VALUES(allow_multi_stage_jump),
    dry_run = VALUES(dry_run),
    reopen_lost_on_reengagement = VALUES(reopen_lost_on_reengagement),
    config_json = VALUES(config_json),
    schema_version = VALUES(schema_version),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO workspace_cold_outreach_warmup_config (
    workspace_id, channel, enabled, initial_daily_cold_limit, current_daily_cold_limit,
    auto_admin_warmup_enabled, weekly_increment, max_limit, last_auto_adjusted_at, updated_by
)
SELECT @default_workspace_id, channels.channel, 0, 0, 0, 0, 0, 0, NULL, @platform_owner_user_id
FROM (
    SELECT 'email' AS channel
    UNION ALL SELECT 'whatsapp'
) channels
WHERE @default_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    initial_daily_cold_limit = VALUES(initial_daily_cold_limit),
    current_daily_cold_limit = VALUES(current_daily_cold_limit),
    auto_admin_warmup_enabled = VALUES(auto_admin_warmup_enabled),
    weekly_increment = VALUES(weekly_increment),
    max_limit = VALUES(max_limit),
    updated_by = VALUES(updated_by),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO ai_autonomy_domain_controls (
    workspace_id, tenant_key, domain_key, autonomy_mode, demonstration_capture_enabled, policy_learning_enabled,
    review_ui_enabled, fast_promotion_enabled, promotion_status, min_precision_to_promote,
    max_reversal_rate_to_promote, max_edit_rate_to_promote, metadata_json, updated_by
)
VALUES (
    @default_workspace_id, CONCAT('workspace:', @default_workspace_id), 'workflow_execution', 'auto_safe', 1, 1,
    1, 1, 'auto_safe', 0.9000,
    0.0800, 0.1200,
    JSON_OBJECT(
        'allowed_actions', JSON_ARRAY(),
        'max_daily_auto_actions', 50,
        'max_customer_facing_risk', 0.95,
        'require_human_checkpoint_actions', JSON_ARRAY(),
        'block_customer_facing_full_auto', TRUE,
        'min_sample_size_to_promote', 10,
        'max_duplicate_rate_to_promote', 0.05,
        'max_override_rate_to_promote', 0.12,
        'min_eval_runs_to_promote', 1,
        'manual_freeze', FALSE,
        'paused', FALSE,
        'pause_customer_facing_only', FALSE,
        'forced_safe_mode', FALSE,
        'temporary_daily_auto_action_cap', NULL,
        'approval_required_for_promotion', FALSE,
        'latest_rollout_reason', 'Seeded by migration 489 production baseline.',
        'auto_downgrade_on_drift', TRUE
    ),
    @platform_owner_user_id
)
ON DUPLICATE KEY UPDATE
    workspace_id = VALUES(workspace_id),
    autonomy_mode = VALUES(autonomy_mode),
    demonstration_capture_enabled = VALUES(demonstration_capture_enabled),
    policy_learning_enabled = VALUES(policy_learning_enabled),
    review_ui_enabled = VALUES(review_ui_enabled),
    fast_promotion_enabled = VALUES(fast_promotion_enabled),
    promotion_status = VALUES(promotion_status),
    metadata_json = VALUES(metadata_json),
    updated_by = VALUES(updated_by),
    updated_at = CURRENT_TIMESTAMP;

DROP TEMPORARY TABLE IF EXISTS default_workspace_cleanup_contacts;
CREATE TEMPORARY TABLE default_workspace_cleanup_contacts (
    id INT PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO default_workspace_cleanup_contacts (id)
SELECT c.id
FROM contacts c
WHERE c.workspace_id = @default_workspace_id
  AND (
      c.email LIKE 'contact-%@example.test'
      OR c.email LIKE 'presentation-%@demo.local.invalid'
      OR c.email LIKE '%@demo.local.invalid'
      OR (c.email LIKE '%@example.test' AND c.metadata_json LIKE '%Playwright%')
  );

DELETE dowc
FROM default_workspace_owner_contacts dowc
JOIN default_workspace_cleanup_contacts cleanup ON cleanup.id = dowc.contact_id
WHERE dowc.default_workspace_id = @default_workspace_id;

DELETE d
FROM deals d
LEFT JOIN default_workspace_cleanup_contacts cleanup ON cleanup.id = d.contact_id
WHERE d.workspace_id = @default_workspace_id
  AND (
      cleanup.id IS NOT NULL
      OR d.title = 'Codex Verification Presentation Workspace conversion'
      OR d.custom_fields LIKE '%"default_workspace_pipeline":true%'
      OR d.custom_fields LIKE '%codex-verification-presentation-workspace%'
  );

DELETE c
FROM contacts c
JOIN default_workspace_cleanup_contacts cleanup ON cleanup.id = c.id
WHERE c.workspace_id = @default_workspace_id;

UPDATE default_workspace_ops_events e
LEFT JOIN workspaces owner_workspace ON owner_workspace.id = e.owner_workspace_id
SET e.status = 'dismissed',
    e.active_signal_key = NULL,
    e.resolved_at = COALESCE(e.resolved_at, NOW()),
    e.resolution_summary = 'Dismissed generated demo, smoke, or presentation workspace signal by migration 489.',
    e.updated_at = NOW()
WHERE e.default_workspace_id = @default_workspace_id
  AND e.status IN ('open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider')
  AND (
      owner_workspace.status = 'archived'
      OR owner_workspace.slug LIKE '%demo%'
      OR owner_workspace.slug LIKE 'codex-verification-%'
      OR owner_workspace.settings_json LIKE '%demo_workspace%'
      OR owner_workspace.settings_json LIKE '%presentation_workspace%'
  );

UPDATE default_workspace_ops_events
SET status = 'resolved',
    active_signal_key = NULL,
    resolved_at = COALESCE(resolved_at, NOW()),
    resolution_summary = 'Default workspace production baseline repaired by migration 489.',
    updated_at = NOW()
WHERE default_workspace_id = @default_workspace_id
  AND signal_type = 'missing_platform_ops_asset'
  AND status IN ('open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider');

DROP TEMPORARY TABLE IF EXISTS default_workspace_cleanup_contacts;
DROP TEMPORARY TABLE IF EXISTS default_workspace_workflow_repair;
DROP TEMPORARY TABLE IF EXISTS default_workspace_email_template_repair;
DROP TEMPORARY TABLE IF EXISTS default_workspace_task_repair;
DROP TEMPORARY TABLE IF EXISTS default_workspace_product_repair;
