-- Seed default-workspace conversion assets for tenant workspace leads.
-- Idempotent inserts are safe to run more than once.

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public)
SELECT
    'Platform Workspace Trial Conversion',
    'Follow up qualified tenant workspace leads from the default workspace and move them toward a paid subscription.',
    'platform-sales',
    '{"type":"contact_created"}',
    '[{"field":"stage","operator":"equals","value":"qualified"}]',
    '[
      {"type":"send_email","subject":"Your Clarity workspace is ready for revenue work","body":"Hi {first_name},\\n\\nYour workspace is live. The fastest path to value is to connect one customer channel, add your current leads, and use the trial period to prove follow-up speed.\\n\\nReply with the channel you sell through most and I will point you to the best setup path.\\n\\nBest,\\n{company} Team"},
      {"type":"create_task","title":"Convert workspace lead: {first_name} {last_name}","priority":"high","due_date":"+1 days"},
      {"type":"add_tag","tag":"workspace-qualified-lead"},
      {"type":"wait_for_days","days":5},
      {"type":"send_email","subject":"Keep your workspace active after trial","body":"Hi {first_name},\\n\\nChecking in before the trial window closes. If Clarity is helping your team keep leads and next steps visible, the next move is to activate the subscription so your workspace stays current.\\n\\nWould you like help choosing the payment path?\\n\\nBest,\\n{company} Team"}
    ]',
    TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_templates WHERE name = 'Platform Workspace Trial Conversion'
);

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version
)
SELECT
    'Workspace Trial Welcome',
    'platform-workspace-trial-welcome',
    'Your Clarity workspace is ready',
    '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:640px;margin:0 auto;padding:24px;"><p>Hi {first_name},</p><p>Your Clarity workspace for <strong>{company_name}</strong> is ready.</p><p>Use the trial to connect one customer channel, add your active leads, and confirm that follow-up is easier to manage.</p><p>Reply with your main sales channel and we will help you choose the fastest setup path.</p><p>Best regards,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nYour Clarity workspace for {company_name} is ready.\n\nUse the trial to connect one customer channel, add your active leads, and confirm that follow-up is easier to manage.\n\nReply with your main sales channel and we will help you choose the fastest setup path.\n\nBest regards,\n{sender_name}',
    'platform-sales',
    '["first_name","company_name","sender_name"]',
    TRUE,
    TRUE,
    'Welcome message for qualified tenant workspace leads.',
    '["workspace","trial","conversion"]',
    'general',
    'onboarding',
    FALSE,
    'CRM Team',
    '1.0'
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE slug = 'platform-workspace-trial-welcome'
);

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version
)
SELECT
    'Workspace Payment Nudge',
    'platform-workspace-payment-nudge',
    'Keep {company_name} active after trial',
    '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:640px;margin:0 auto;padding:24px;"><p>Hi {first_name},</p><p>Your trial workspace is set up for customer follow-up and pipeline visibility.</p><p>To keep the workspace active after trial, activate the subscription from billing. If anything is blocking payment or setup, reply and we will help clear it.</p><p>Best regards,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nYour trial workspace is set up for customer follow-up and pipeline visibility.\n\nTo keep the workspace active after trial, activate the subscription from billing. If anything is blocking payment or setup, reply and we will help clear it.\n\nBest regards,\n{sender_name}',
    'platform-sales',
    '["first_name","company_name","sender_name"]',
    TRUE,
    TRUE,
    'Subscription activation reminder for workspace leads.',
    '["workspace","payment","conversion"]',
    'general',
    'follow_up',
    TRUE,
    'CRM Team',
    '1.0'
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE slug = 'platform-workspace-payment-nudge'
);

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version
)
SELECT
    'Workspace Trial Final Conversion',
    'platform-workspace-trial-final-conversion',
    'Should we keep your workspace active?',
    '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:640px;margin:0 auto;padding:24px;"><p>Hi {first_name},</p><p>Your trial is nearing its end.</p><p>If Clarity is useful for managing leads, customer conversations, and next actions, activate the subscription so <strong>{company_name}</strong> stays current.</p><p>If now is not the right time, reply and we can pause the follow-up.</p><p>Best regards,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nYour trial is nearing its end.\n\nIf Clarity is useful for managing leads, customer conversations, and next actions, activate the subscription so {company_name} stays current.\n\nIf now is not the right time, reply and we can pause the follow-up.\n\nBest regards,\n{sender_name}',
    'platform-sales',
    '["first_name","company_name","sender_name"]',
    TRUE,
    TRUE,
    'Final trial conversion prompt for workspace leads.',
    '["workspace","trial","subscription"]',
    'general',
    'follow_up',
    FALSE,
    'CRM Team',
    '1.0'
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE slug = 'platform-workspace-trial-final-conversion'
);

INSERT INTO nurture_programs (workspace_id, name, description, program_type, status, cadence, created_by)
SELECT
    1,
    'Paid Workspace Customer Success',
    'Post-payment customer success cadence for active paying tenant workspaces.',
    'customer_success',
    'active',
    'monthly',
    NULL
WHERE EXISTS (SELECT 1 FROM workspaces WHERE id = 1 OR slug = 'default')
  AND NOT EXISTS (
      SELECT 1 FROM nurture_programs WHERE workspace_id = 1 AND name = 'Paid Workspace Customer Success'
  );
