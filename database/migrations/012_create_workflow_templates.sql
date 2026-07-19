-- Workflow Templates Table
CREATE TABLE IF NOT EXISTS workflow_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    trigger_config JSON NOT NULL,
    conditions JSON,
    actions JSON NOT NULL,
    variables JSON,
    is_public BOOLEAN DEFAULT FALSE,
    usage_count INT DEFAULT 0,
    rating DECIMAL(3,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default templates
INSERT INTO workflow_templates (name, description, category, trigger_config, conditions, actions, is_public) VALUES
('Welcome New Contacts', 'Send a welcome email series to new contacts', 'onboarding', 
 '{"type":"contact_created"}', 
 '[]',
 '[{"type":"send_email","subject":"Welcome {first_name}!","body":"Hi {first_name}, welcome to our community!"}]',
 TRUE),
 
('Lead Nurturing Sequence', 'Nurture leads with a 3-email sequence', 'nurturing',
 '{"type":"contact_created"}',
 '[{"field":"stage","operator":"equals","value":"new"}]',
 '[{"type":"send_email","subject":"Getting Started with Us","body":"Hi {first_name}, thanks for your interest!"},{"type":"wait_for_days","days":3},{"type":"send_email","subject":"Learn More About Our Solutions","body":"Hi {first_name}, here are some resources..."},{"type":"wait_for_days","days":5},{"type":"send_email","subject":"Ready to Take the Next Step?","body":"Hi {first_name}, are you ready to get started?"}]',
 TRUE),
 
('Re-engagement Campaign', 'Re-engage contacts with no activity', 're-engagement',
 '{"type":"no_activity_for_days","days":30}',
 '[]',
 '[{"type":"send_email","subject":"We Miss You, {first_name}!","body":"Hi {first_name}, we haven''t heard from you in a while..."}]',
 TRUE),
 
('Deal Follow-up', 'Follow up when deal moves to proposal stage', 'sales',
 '{"type":"deal_stage_changed","to_stage":"proposal"}',
 '[]',
 '[{"type":"create_task","title":"Follow up on proposal for {deal.title}","priority":"high","due_date":"+3 days"},{"type":"send_email","subject":"Proposal Sent - {deal.title}","body":"Hi {first_name}, we''ve sent you a proposal..."}]',
 TRUE),
 
('Birthday Automation', 'Send birthday wishes to contacts', 'relationship',
 '{"type":"contact_birthday"}',
 '[]',
 '[{"type":"send_email","subject":"Happy Birthday, {first_name}!","body":"Happy Birthday {first_name}! We hope you have a wonderful day!"}]',
 TRUE);
