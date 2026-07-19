-- Migration 063: Add additional workflow templates
-- Expands template library with lead scoring, deal closing, WhatsApp, form-to-deal, etc.

INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public) VALUES
('WhatsApp Welcome Reply', 'Auto-reply when a contact sends their first WhatsApp message', 'onboarding',
 '{"type":"whatsapp_message_received"}',
 '[]',
 '[{"type":"send_whatsapp","message":"Hi {first_name}! Thanks for reaching out. We will get back to you shortly."}]',
 TRUE),

('Deal Closing Reminder', 'Create task and send reminder when deal enters negotiation', 'sales',
 '{"type":"deal_stage_changed","to_stage":"negotiation"}',
 '[]',
 '[{"type":"create_task","title":"Follow up on negotiation - {deal.title}","priority":"high","due_date":"+2 days"},{"type":"send_email","subject":"Next steps for {deal.title}","body":"Hi {first_name}, we are excited to move forward. Here are the next steps..."}]',
 TRUE),

('High Lead Score Alert', 'Notify when contact ML score crosses threshold', 'lead-scoring',
 '{"type":"ml_score_threshold","threshold":0.8,"operator":">="}',
 '[]',
 '[{"type":"add_tag","tag":"hot-lead"},{"type":"create_task","title":"High-value lead: {first_name} - follow up","priority":"high","due_date":"+1 days"}]',
 TRUE),

('Form to Deal', 'Create deal and assign when form is submitted', 'sales',
 '{"type":"form_submitted"}',
 '[]',
 '[{"type":"create_deal","title":"New lead from form - {first_name}","value":0,"stage":"prospecting"},{"type":"add_tag","tag":"form-lead"}]',
 TRUE),

('Win-back Campaign', 'Re-engage inactive contacts with special offer', 're-engagement',
 '{"type":"no_activity_for_days","days":60}',
 '[]',
 '[{"type":"send_email","subject":"We have something special for you, {first_name}","body":"Hi {first_name}, we miss you! Here is an exclusive offer to welcome you back..."},{"type":"add_tag","tag":"winback-sent"}]',
 TRUE),

('Deal Won Celebration', 'Send thank you and add tag when deal is won', 'sales',
 '{"type":"deal_won"}',
 '[]',
 '[{"type":"send_email","subject":"Thank you, {first_name}!","body":"Congratulations! We are thrilled to work with you. Here is what happens next..."},{"type":"add_tag","tag":"customer"}]',
 TRUE),

('Contact Anniversary', 'Send anniversary message to contacts', 'relationship',
 '{"type":"contact_anniversary"}',
 '[]',
 '[{"type":"send_email","subject":"Happy Anniversary, {first_name}!","body":"Hi {first_name}, thank you for being with us for another year!"}]',
 TRUE);
