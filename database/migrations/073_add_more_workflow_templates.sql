-- Migration 073: Add more workflow templates
-- Lead nurture, deal stage follow-up, contact score, re-engagement, form-to-contact, SMS welcome

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public) VALUES
('Lead Nurture Sequence', '3-step email sequence for new leads', 'onboarding',
 '{"type":"contact_created"}',
 '[]',
 '[{"type":"send_email","subject":"Welcome, {first_name}!","body":"Hi {first_name}, thanks for your interest. Here is what we offer..."},{"type":"wait_for_days","days":3},{"type":"send_email","subject":"Quick follow-up","body":"Hi {first_name}, just checking in. Any questions?"},{"type":"wait_for_days","days":4},{"type":"send_email","subject":"Last chance","body":"Hi {first_name}, we would love to help. Reach out anytime!"}]',
 TRUE),

('Deal Stage Follow-up', 'Task and email when deal moves to proposal', 'sales',
 '{"type":"deal_stage_changed","to_stage":"proposal"}',
 '[]',
 '[{"type":"create_task","title":"Send proposal to {first_name}","priority":"high","due_date":"+1 days"},{"type":"send_email","subject":"Your proposal is ready","body":"Hi {first_name}, we have prepared a proposal for you. Let us know if you have questions."}]',
 TRUE),

('Contact Score Threshold', 'Tag and assign when lead score is high', 'lead-scoring',
 '{"type":"contact_score_changed"}',
 '[{"field":"lead_score","operator":"greater_than","value":"80"}]',
 '[{"type":"add_tag","tag_name":"high-score"},{"type":"create_task","title":"Follow up high-score lead","priority":"high","due_date":"+1 days"}]',
 TRUE),

('SMS Welcome', 'Send SMS when new contact is created', 'onboarding',
 '{"type":"contact_created"}',
 '[]',
 '[{"type":"send_sms","message":"Hi {first_name}! Thanks for reaching out. We will contact you soon."}]',
 TRUE),

('Task Overdue Alert', 'Create follow-up task when task is overdue', 'task',
 '{"type":"task_overdue"}',
 '[]',
 '[{"type":"create_task","title":"Overdue task follow-up: {first_name}","priority":"high","due_date":"+0 days"},{"type":"add_tag","tag_name":"overdue-followup"}]',
 TRUE),

('New Contact Activity', 'Log activity when contact is created', 'contact',
 '{"type":"contact_created"}',
 '[]',
 '[{"type":"create_activity","activity_type":"contact_created","description":"New contact added via workflow"}]',
 TRUE);
