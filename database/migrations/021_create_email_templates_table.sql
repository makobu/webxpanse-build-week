-- Email Templates Table
CREATE TABLE IF NOT EXISTS email_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body_html TEXT NOT NULL,
    body_text TEXT,
    category VARCHAR(100) DEFAULT 'general',
    variables JSON DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_category (category),
    INDEX idx_active (is_active),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default templates
INSERT INTO email_templates (name, slug, subject, body_html, category, variables) VALUES
('Welcome Email', 'welcome', 'Welcome, {first_name}!', 
'<html><body><h1>Welcome, {first_name}!</h1><p>Thank you for joining us, {first_name} {last_name}.</p><p>We are excited to have you on board.</p></body></html>',
'welcome', '["first_name", "last_name", "email"]'),

('Follow Up Email', 'follow_up', 'Hello {first_name},', 
'<html><body><h1>Hello {first_name},</h1><p>We wanted to follow up on our recent conversation.</p><p>Is there anything we can help you with?</p></body></html>',
'follow_up', '["first_name", "last_name"]'),

('Thank You Email', 'thank_you', 'Thank you, {first_name}!', 
'<html><body><h1>Thank you, {first_name}!</h1><p>We appreciate your interest in our services.</p><p>We will get back to you soon.</p></body></html>',
'general', '["first_name", "last_name"]');
