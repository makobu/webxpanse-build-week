-- Migration 099: Brilliant and editable workflow + email templates
-- Idempotent inserts (safe to run multiple times).

-- ---------------------------------------------------------------------
-- Workflow templates (editable after creating workflow from template)
-- ---------------------------------------------------------------------

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public)
SELECT
    'Speed-to-Lead Qualification Sprint',
    'Respond instantly to new leads, create a high-priority follow-up task, and move serious prospects into contacted stage quickly. Fully editable.',
    'sales',
    '{"type":"contact_created"}',
    '[]',
    '[
      {"type":"send_email","subject":"Thanks for reaching out, {first_name}","body":"Hi {first_name},\\n\\nThanks for your interest. I can help you pick the fastest path based on your goals.\\n\\nReply with your top priority and expected timeline, and I will send a tailored recommendation.\\n\\nBest,\\n{company} Team"},
      {"type":"create_task","title":"Speed-to-lead call for {first_name} {last_name}","priority":"high","due_date":"+0 days"},
      {"type":"add_tag","tag":"new-lead-priority"},
      {"type":"wait_for_days","days":1},
      {"type":"change_stage","stage":"contacted"}
    ]',
    TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_templates WHERE name = 'Speed-to-Lead Qualification Sprint'
);

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public)
SELECT
    'Deal Stall Recovery Sequence',
    'When a deal stalls in proposal stage, re-open momentum with value-driven follow-up and a manager visibility task. Fully editable.',
    'sales',
    '{"type":"no_activity_for_days","days":7}',
    '[{"field":"stage","operator":"equals","value":"proposal"}]',
    '[
      {"type":"send_email","subject":"Quick check-in on your proposal","body":"Hi {first_name},\\n\\nI wanted to check whether anything is blocking the proposal review.\\n\\nIf useful, I can send a 1-page summary and revised options aligned to your priorities.\\n\\nRegards,\\n{company} Team"},
      {"type":"create_task","title":"Escalation review: stalled proposal for {first_name}","priority":"high","due_date":"+1 days"},
      {"type":"add_tag","tag":"deal-stalled"},
      {"type":"wait_for_days","days":3},
      {"type":"send_email","subject":"Can we close this this week?","body":"Hi {first_name},\\n\\nHappy to align pricing, scope, or rollout plan so you can make a confident decision.\\n\\nWould a 15-minute call help close this out?\\n\\nBest,\\n{company} Team"}
    ]',
    TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_templates WHERE name = 'Deal Stall Recovery Sequence'
);

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public)
SELECT
    'Silent Lead Re-Engagement (30-60)',
    'Revives quiet leads with a two-step sequence and clear human follow-up ownership. Fully editable.',
    're-engagement',
    '{"type":"no_activity_for_days","days":30}',
    '[]',
    '[
      {"type":"send_email","subject":"Still evaluating options, {first_name}?","body":"Hi {first_name},\\n\\nJust checking in. If timing changed, no pressure.\\n\\nIf you are still exploring, reply with your current objective and I will send the most relevant path.\\n\\nBest,\\n{company} Team"},
      {"type":"add_tag","tag":"reengage-30"},
      {"type":"wait_for_days","days":5},
      {"type":"send_email","subject":"Should I close your file for now?","body":"Hi {first_name},\\n\\nIf this is not a priority right now, I can pause follow-ups.\\n\\nIf you want to continue, reply with yes and I will share next steps immediately.\\n\\nThanks,\\n{company} Team"},
      {"type":"create_task","title":"Manual outreach after re-engagement sequence - {first_name}","priority":"medium","due_date":"+1 days"}
    ]',
    TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_templates WHERE name = 'Silent Lead Re-Engagement (30-60)'
);

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public)
SELECT
    'Post-Win Expansion Playbook',
    'Turns closed-won momentum into onboarding confidence, review requests, and expansion opportunities. Fully editable.',
    'relationship',
    '{"type":"deal_won"}',
    '[]',
    '[
      {"type":"send_email","subject":"Welcome aboard, {first_name}","body":"Hi {first_name},\\n\\nGreat working with you. We are excited to kick this off.\\n\\nI will send your onboarding checklist and timeline next.\\n\\nRegards,\\n{company} Team"},
      {"type":"add_tag","tag":"customer-won"},
      {"type":"wait_for_days","days":14},
      {"type":"send_email","subject":"How is onboarding going so far?","body":"Hi {first_name},\\n\\nTwo quick questions:\\n1) What is working best so far?\\n2) Any blockers we should remove this week?\\n\\nWe want fast wins for your team.\\n\\nBest,\\n{company} Team"},
      {"type":"create_task","title":"Ask for testimonial / expansion path - {first_name}","priority":"medium","due_date":"+21 days"}
    ]',
    TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_templates WHERE name = 'Post-Win Expansion Playbook'
);

-- ---------------------------------------------------------------------
-- Email templates (library templates, editable after install/copy)
-- ---------------------------------------------------------------------

INSERT INTO email_templates (
    name, slug, subject, body_html, body_text, category, variables,
    is_active, is_library, description, tags, industry, purpose, is_featured, author, version
)
SELECT
    'Executive Value Follow-Up',
    'library-executive-value-follow-up',
    'Next-step recommendation for {company_name}',
    '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:640px;margin:0 auto;padding:24px;"><h2 style="margin:0 0 16px;">Hi {first_name},</h2><p>Thank you for the conversation. Based on your priorities, here is the most direct next step I recommend for <strong>{company_name}</strong>:</p><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0;"><p style="margin:0;"><strong>Recommendation:</strong> {recommended_next_step}</p><p style="margin:8px 0 0;"><strong>Expected impact:</strong> {expected_impact}</p></div><p>If useful, I can share a concise rollout plan with milestones and owners so your team can execute immediately.</p><p>Would you like me to send that over?</p><p style="margin-top:24px;">Best regards,<br>{sender_name}</p></body></html>',
    'Hi {first_name},\n\nThank you for the conversation. Based on your priorities, here is the most direct next step I recommend for {company_name}:\n\nRecommendation: {recommended_next_step}\nExpected impact: {expected_impact}\n\nIf useful, I can share a concise rollout plan with milestones and owners so your team can execute immediately.\n\nWould you like me to send that over?\n\nBest regards,\n{sender_name}',
    'sales',
    '["first_name","company_name","recommended_next_step","expected_impact","sender_name"]',
    TRUE,
    TRUE,
    'High-conversion follow-up template for decision-stage prospects. Clean, executive tone and fully editable content blocks.',
    '["sales","follow-up","executive","high-conversion"]',
    'general',
    'follow_up',
    TRUE,
    'CRM Team',
    '1.0'
WHERE NOT EXISTS (
    SELECT 1 FROM email_templates WHERE slug = 'library-executive-value-follow-up'
);

