-- Enhance Email Templates Table for Library Support
-- Add columns to distinguish library templates from user-created templates

ALTER TABLE email_templates ADD COLUMN (
    is_library BOOLEAN DEFAULT FALSE,
    description TEXT,
    tags JSON DEFAULT NULL,
    usage_count INT DEFAULT 0,
    thumbnail_url VARCHAR(500) DEFAULT NULL,
    author VARCHAR(255) DEFAULT NULL,
    version VARCHAR(20) DEFAULT '1.0',
    is_featured BOOLEAN DEFAULT FALSE,
    industry VARCHAR(100) DEFAULT NULL,
    purpose VARCHAR(100) DEFAULT NULL
);

-- Add indexes for better query performance
CREATE INDEX idx_library ON email_templates(is_library);
CREATE INDEX idx_featured ON email_templates(is_featured);
CREATE INDEX idx_industry ON email_templates(industry);
CREATE INDEX idx_purpose ON email_templates(purpose);

-- Mark existing templates as user-created (not library)
UPDATE email_templates SET is_library = FALSE WHERE is_library IS NULL;

-- Insert 20+ Professional Pre-built Templates

-- Welcome Series Templates
INSERT INTO email_templates (name, slug, subject, body_html, body_text, category, variables, is_active, is_library, description, tags, industry, purpose, is_featured, author, version) VALUES
('Welcome Email', 'library-welcome-email', 'Welcome to {company}, {first_name}!', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; color: white; border-radius: 8px 8px 0 0;">
<h1 style="margin: 0; font-size: 28px;">Welcome to {company}!</h1>
</div>
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px;">
<p style="font-size: 16px; margin-bottom: 20px;">Hi {first_name},</p>
<p style="font-size: 16px; margin-bottom: 20px;">We''re thrilled to have you join the {company} community! Your journey with us starts now, and we''re here to help you every step of the way.</p>
<p style="font-size: 16px; margin-bottom: 20px;">Here''s what you can expect:</p>
<ul style="font-size: 16px; margin-bottom: 20px;">
<li>Access to all our features and resources</li>
<li>Dedicated support from our team</li>
<li>Regular updates and tips to help you succeed</li>
</ul>
<p style="font-size: 16px; margin-bottom: 30px;">If you have any questions, don''t hesitate to reach out. We''re here to help!</p>
<div style="text-align: center; margin-top: 30px;">
<a href="#" style="background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Get Started</a>
</div>
<p style="font-size: 14px; color: #666; margin-top: 30px; text-align: center;">Best regards,<br>The {company} Team</p>
</div>
</body></html>',
'Welcome to {company}, {first_name}!\n\nWe''re thrilled to have you join the {company} community! Your journey with us starts now, and we''re here to help you every step of the way.\n\nHere''s what you can expect:\n- Access to all our features and resources\n- Dedicated support from our team\n- Regular updates and tips to help you succeed\n\nIf you have any questions, don''t hesitate to reach out. We''re here to help!\n\nBest regards,\nThe {company} Team',
'welcome', '["first_name", "last_name", "email", "company"]', TRUE, TRUE, 
'Professional welcome email for new users or customers', '["welcome", "onboarding", "introduction"]', 'general', 'onboarding', TRUE, 'CRM Team', '1.0'),

('Getting Started Guide', 'library-getting-started', 'Your {company} Getting Started Guide', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #f8f9fa; padding: 30px; border-radius: 8px;">
<h1 style="color: #333; margin-top: 0;">Getting Started with {company}</h1>
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">Ready to make the most of {company}? Here''s a quick guide to get you started:</p>
<div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #667eea; border-radius: 4px;">
<h2 style="margin-top: 0; color: #667eea;">Step 1: Complete Your Profile</h2>
<p>Add your information and preferences to personalize your experience.</p>
</div>
<div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #667eea; border-radius: 4px;">
<h2 style="margin-top: 0; color: #667eea;">Step 2: Explore Features</h2>
<p>Take a tour of our key features and see what {company} can do for you.</p>
</div>
<div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #667eea; border-radius: 4px;">
<h2 style="margin-top: 0; color: #667eea;">Step 3: Connect Your Tools</h2>
<p>Integrate with your favorite apps and services to streamline your workflow.</p>
</div>
<p style="font-size: 16px;">Need help? Check out our <a href="#" style="color: #667eea;">help center</a> or reply to this email.</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Happy exploring!<br>The {company} Team</p>
</div>
</body></html>',
'Getting Started with {company}\n\nHi {first_name},\n\nReady to make the most of {company}? Here''s a quick guide:\n\nStep 1: Complete Your Profile\nAdd your information and preferences.\n\nStep 2: Explore Features\nTake a tour of our key features.\n\nStep 3: Connect Your Tools\nIntegrate with your favorite apps.\n\nNeed help? Check out our help center.\n\nHappy exploring!\nThe {company} Team',
'welcome', '["first_name", "company"]', TRUE, TRUE,
'Step-by-step guide to help new users get started', '["onboarding", "guide", "tutorial"]', 'general', 'onboarding', FALSE, 'CRM Team', '1.0'),

-- Sales Templates
('Proposal Follow-up', 'library-proposal-followup', 'Following up on your proposal, {first_name}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">I wanted to follow up on the proposal we sent for {deal_title}. I hope you''ve had a chance to review it.</p>
<p style="font-size: 16px;">I''m happy to answer any questions you might have or discuss how we can customize the solution to better fit your needs.</p>
<div style="background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 4px;">
<p style="margin: 0; font-weight: bold;">Key Highlights:</p>
<ul style="margin: 10px 0 0 0;">
<li>Tailored solution for your business</li>
<li>Flexible implementation timeline</li>
<li>Dedicated support included</li>
</ul>
</div>
<p style="font-size: 16px;">Would you be available for a quick call this week to discuss next steps?</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Best regards,<br>{sender_name}</p>
</div>
</body></html>',
'Hi {first_name},\n\nI wanted to follow up on the proposal we sent for {deal_title}. I hope you''ve had a chance to review it.\n\nI''m happy to answer any questions or discuss customization options.\n\nKey Highlights:\n- Tailored solution for your business\n- Flexible implementation timeline\n- Dedicated support included\n\nWould you be available for a quick call this week?\n\nBest regards,\n{sender_name}',
'sales', '["first_name", "deal_title", "sender_name"]', TRUE, TRUE,
'Professional follow-up email after sending a proposal', '["sales", "proposal", "follow-up"]', 'general', 'follow_up', TRUE, 'CRM Team', '1.0'),

('Demo Request', 'library-demo-request', 'Schedule a personalized demo with {company}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 30px; text-align: center; color: white; border-radius: 8px 8px 0 0;">
<h1 style="margin: 0; font-size: 28px;">See {company} in Action</h1>
</div>
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">Thanks for your interest in {company}! I''d love to show you how we can help {company_name} achieve your goals.</p>
<p style="font-size: 16px;">During our demo, we''ll cover:</p>
<ul style="font-size: 16px;">
<li>How {company} addresses your specific needs</li>
<li>Key features and capabilities</li>
<li>Implementation and onboarding process</li>
<li>Pricing and packages</li>
</ul>
<div style="text-align: center; margin: 30px 0;">
<a href="#" style="background: #f5576c; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Schedule Your Demo</a>
</div>
<p style="font-size: 14px; color: #666; text-align: center;">Or reply to this email with your preferred time, and I''ll send you a calendar invite.</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Looking forward to speaking with you!<br>{sender_name}</p>
</div>
</body></html>',
'Hi {first_name},\n\nThanks for your interest in {company}! I''d love to show you how we can help {company_name} achieve your goals.\n\nDuring our demo, we''ll cover:\n- How {company} addresses your specific needs\n- Key features and capabilities\n- Implementation process\n- Pricing and packages\n\nSchedule your demo or reply with your preferred time.\n\nLooking forward to speaking with you!\n{sender_name}',
'sales', '["first_name", "company", "company_name", "sender_name"]', TRUE, TRUE,
'Invitation to schedule a product demo', '["sales", "demo", "meeting"]', 'general', 'demo_request', TRUE, 'CRM Team', '1.0'),

-- Marketing Templates
('Newsletter Template', 'library-newsletter', '{newsletter_title} - {month} {year}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<h1 style="color: #333; margin-top: 0;">{newsletter_title}</h1>
<p style="font-size: 14px; color: #666; margin-bottom: 30px;">{month} {year}</p>
<div style="border-top: 2px solid #e0e0e0; padding-top: 20px; margin-bottom: 30px;">
<h2 style="color: #667eea; margin-top: 0;">Featured Article</h2>
<h3 style="color: #333;">{article_title}</h3>
<p style="font-size: 16px;">{article_excerpt}</p>
<a href="#" style="color: #667eea; text-decoration: none; font-weight: bold;">Read More →</a>
</div>
<div style="border-top: 1px solid #e0e0e0; padding-top: 20px; margin-bottom: 30px;">
<h2 style="color: #667eea;">What''s New</h2>
<ul style="font-size: 16px;">
<li>{update_1}</li>
<li>{update_2}</li>
<li>{update_3}</li>
</ul>
</div>
<div style="background: #f8f9fa; padding: 20px; border-radius: 4px; text-align: center;">
<p style="margin: 0; font-size: 16px;">Have feedback? <a href="#" style="color: #667eea;">Let us know</a></p>
</div>
<p style="font-size: 14px; color: #666; margin-top: 30px; text-align: center;">Thanks for reading!<br>The {company} Team</p>
</div>
</body></html>',
'{newsletter_title} - {month} {year}\n\nFeatured Article\n{article_title}\n{article_excerpt}\n\nWhat''s New\n- {update_1}\n- {update_2}\n- {update_3}\n\nThanks for reading!\nThe {company} Team',
'marketing', '["newsletter_title", "month", "year", "article_title", "article_excerpt", "update_1", "update_2", "update_3", "company"]', TRUE, TRUE,
'Professional newsletter template for regular updates', '["newsletter", "marketing", "updates"]', 'general', 'newsletter', FALSE, 'CRM Team', '1.0'),

('Product Announcement', 'library-product-announcement', 'Introducing {product_name} - {tagline}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px; text-align: center; color: white; border-radius: 8px 8px 0 0;">
<h1 style="margin: 0; font-size: 32px;">{product_name}</h1>
<p style="margin: 10px 0 0 0; font-size: 18px;">{tagline}</p>
</div>
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">We''re excited to announce {product_name} - {product_description}</p>
<div style="background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 4px;">
<h3 style="margin-top: 0; color: #667eea;">Key Features:</h3>
<ul style="font-size: 16px;">
<li>{feature_1}</li>
<li>{feature_2}</li>
<li>{feature_3}</li>
</ul>
</div>
<div style="text-align: center; margin: 30px 0;">
<a href="#" style="background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Learn More</a>
</div>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Ready to get started? <a href="#" style="color: #667eea;">Try it now</a></p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Best regards,<br>The {company} Team</p>
</div>
</body></html>',
'Introducing {product_name} - {tagline}\n\nHi {first_name},\n\nWe''re excited to announce {product_name} - {product_description}\n\nKey Features:\n- {feature_1}\n- {feature_2}\n- {feature_3}\n\nLearn more and try it now!\n\nBest regards,\nThe {company} Team',
'marketing', '["first_name", "product_name", "tagline", "product_description", "feature_1", "feature_2", "feature_3", "company"]', TRUE, TRUE,
'Announce new products or features to your audience', '["marketing", "announcement", "product"]', 'general', 'announcement', TRUE, 'CRM Team', '1.0'),

-- Support Templates
('Ticket Acknowledgment', 'library-ticket-acknowledgment', 'We''ve received your support request #{ticket_number}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<div style="background: #e3f2fd; padding: 15px; border-left: 4px solid #2196f3; border-radius: 4px; margin-bottom: 20px;">
<p style="margin: 0; font-weight: bold; color: #1976d2;">Support Ticket #{ticket_number}</p>
</div>
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">Thank you for contacting us. We''ve received your support request and created ticket #{ticket_number}.</p>
<div style="background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 4px;">
<p style="margin: 0 0 10px 0; font-weight: bold;">Your Request:</p>
<p style="margin: 0; font-size: 14px; color: #666;">{ticket_subject}</p>
</div>
<p style="font-size: 16px;">Our support team will review your request and respond within {response_time}. You''ll receive an email update as soon as we have a response.</p>
<p style="font-size: 16px;">You can track the status of your ticket <a href="#" style="color: #2196f3;">here</a>.</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Best regards,<br>{company} Support Team</p>
</div>
</body></html>',
'Support Ticket #{ticket_number}\n\nHi {first_name},\n\nThank you for contacting us. We''ve received your support request and created ticket #{ticket_number}.\n\nYour Request: {ticket_subject}\n\nOur support team will review your request and respond within {response_time}. You''ll receive an email update as soon as we have a response.\n\nTrack your ticket status online.\n\nBest regards,\n{company} Support Team',
'support', '["first_name", "ticket_number", "ticket_subject", "response_time", "company"]', TRUE, TRUE,
'Acknowledge receipt of support tickets', '["support", "ticket", "acknowledgment"]', 'general', 'ticket_acknowledgment', FALSE, 'CRM Team', '1.0'),

-- Onboarding Templates
('Day 1 Welcome', 'library-day1-welcome', 'Welcome to Day 1 with {company}!', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">Welcome to Day 1! We''re so excited to have you here.</p>
<p style="font-size: 16px;">Today, let''s start with the basics. Here''s what we recommend:</p>
<ol style="font-size: 16px;">
<li>Complete your profile setup</li>
<li>Explore the dashboard</li>
<li>Check out our getting started guide</li>
</ol>
<p style="font-size: 16px;">Questions? Just reply to this email - we''re here to help!</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Cheers,<br>The {company} Team</p>
</div>
</body></html>',
'Hi {first_name},\n\nWelcome to Day 1! We''re so excited to have you here.\n\nToday, let''s start with the basics:\n1. Complete your profile setup\n2. Explore the dashboard\n3. Check out our getting started guide\n\nQuestions? Just reply - we''re here to help!\n\nCheers,\nThe {company} Team',
'welcome', '["first_name", "company"]', TRUE, TRUE,
'First day welcome email for onboarding sequences', '["onboarding", "day1", "welcome"]', 'general', 'onboarding', FALSE, 'CRM Team', '1.0'),

('Day 3 Tips', 'library-day3-tips', '3 Tips to Get the Most from {company}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">You''ve been with us for 3 days - awesome! Here are 3 tips to help you get even more value:</p>
<div style="background: #f8f9fa; padding: 20px; margin: 15px 0; border-left: 4px solid #667eea; border-radius: 4px;">
<h3 style="margin-top: 0; color: #667eea;">Tip 1: {tip_1_title}</h3>
<p style="margin-bottom: 0;">{tip_1_description}</p>
</div>
<div style="background: #f8f9fa; padding: 20px; margin: 15px 0; border-left: 4px solid #667eea; border-radius: 4px;">
<h3 style="margin-top: 0; color: #667eea;">Tip 2: {tip_2_title}</h3>
<p style="margin-bottom: 0;">{tip_2_description}</p>
</div>
<div style="background: #f8f9fa; padding: 20px; margin: 15px 0; border-left: 4px solid #667eea; border-radius: 4px;">
<h3 style="margin-top: 0; color: #667eea;">Tip 3: {tip_3_title}</h3>
<p style="margin-bottom: 0;">{tip_3_description}</p>
</div>
<p style="font-size: 16px;">Try these out and let us know how it goes!</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Best,<br>The {company} Team</p>
</div>
</body></html>',
'Hi {first_name},\n\nYou''ve been with us for 3 days - awesome! Here are 3 tips:\n\nTip 1: {tip_1_title}\n{tip_1_description}\n\nTip 2: {tip_2_title}\n{tip_2_description}\n\nTip 3: {tip_3_title}\n{tip_3_description}\n\nTry these out and let us know how it goes!\n\nBest,\nThe {company} Team',
'welcome', '["first_name", "tip_1_title", "tip_1_description", "tip_2_title", "tip_2_description", "tip_3_title", "tip_3_description", "company"]', TRUE, TRUE,
'Helpful tips email for day 3 of onboarding', '["onboarding", "tips", "day3"]', 'general', 'onboarding', FALSE, 'CRM Team', '1.0'),

-- Re-engagement Templates
('Win-Back Campaign', 'library-win-back', 'We miss you, {first_name}!', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">We noticed you haven''t been active with {company} lately, and we wanted to check in.</p>
<p style="font-size: 16px;">We''ve made some exciting updates since you last visited:</p>
<ul style="font-size: 16px;">
<li>{update_1}</li>
<li>{update_2}</li>
<li>{update_3}</li>
</ul>
<div style="background: #fff3cd; padding: 20px; margin: 20px 0; border-left: 4px solid #ffc107; border-radius: 4px;">
<p style="margin: 0; font-weight: bold;">Special Offer Just for You</p>
<p style="margin: 10px 0 0 0;">{special_offer}</p>
</div>
<div style="text-align: center; margin: 30px 0;">
<a href="#" style="background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Come Back</a>
</div>
<p style="font-size: 14px; color: #666; margin-top: 30px;">We''d love to have you back!<br>The {company} Team</p>
</div>
</body></html>',
'Hi {first_name},\n\nWe noticed you haven''t been active with {company} lately.\n\nWe''ve made some exciting updates:\n- {update_1}\n- {update_2}\n- {update_3}\n\nSpecial Offer: {special_offer}\n\nWe''d love to have you back!\n\nThe {company} Team',
'marketing', '["first_name", "company", "update_1", "update_2", "update_3", "special_offer"]', TRUE, TRUE,
'Re-engage inactive users with updates and special offers', '["re-engagement", "win-back", "inactive"]', 'general', 'win_back', TRUE, 'CRM Team', '1.0'),

-- Notification Templates
('Password Reset', 'library-password-reset', 'Reset your {company} password', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">We received a request to reset your password for your {company} account.</p>
<div style="text-align: center; margin: 30px 0;">
<a href="{reset_link}" style="background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Reset Password</a>
</div>
<p style="font-size: 14px; color: #666;">Or copy and paste this link into your browser:<br><a href="{reset_link}" style="color: #667eea; word-break: break-all;">{reset_link}</a></p>
<p style="font-size: 14px; color: #666; margin-top: 20px;">This link will expire in {expiry_time}. If you didn''t request a password reset, please ignore this email.</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Best regards,<br>{company} Security Team</p>
</div>
</body></html>',
'Hi {first_name},\n\nWe received a request to reset your password.\n\nReset your password: {reset_link}\n\nThis link expires in {expiry_time}. If you didn''t request this, please ignore this email.\n\nBest regards,\n{company} Security Team',
'notification', '["first_name", "company", "reset_link", "expiry_time"]', TRUE, TRUE,
'Secure password reset email with expiration', '["notification", "security", "password"]', 'general', 'password_reset', FALSE, 'CRM Team', '1.0'),

('Order Confirmation', 'library-order-confirmation', 'Order Confirmation #{order_number}', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<div style="background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50; border-radius: 4px; margin-bottom: 20px;">
<p style="margin: 0; font-weight: bold; color: #2e7d32;">Order Confirmed!</p>
</div>
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">Thank you for your order! We''ve received your payment and your order is being processed.</p>
<div style="background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 4px;">
<p style="margin: 0 0 10px 0; font-weight: bold;">Order Details:</p>
<p style="margin: 5px 0;"><strong>Order #:</strong> {order_number}</p>
<p style="margin: 5px 0;"><strong>Date:</strong> {order_date}</p>
<p style="margin: 5px 0;"><strong>Total:</strong> {order_total}</p>
</div>
<p style="font-size: 16px;">You''ll receive a shipping confirmation email once your order ships.</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">Thank you for your business!<br>{company}</p>
</div>
</body></html>',
'Order Confirmed!\n\nHi {first_name},\n\nThank you for your order! We''ve received your payment.\n\nOrder Details:\nOrder #: {order_number}\nDate: {order_date}\nTotal: {order_total}\n\nYou''ll receive a shipping confirmation once your order ships.\n\nThank you for your business!\n{company}',
'notification', '["first_name", "order_number", "order_date", "order_total", "company"]', TRUE, TRUE,
'Confirm order receipt and payment', '["notification", "order", "confirmation"]', 'ecommerce', 'order_confirmation', FALSE, 'CRM Team', '1.0'),

-- Relationship Templates
('Birthday Wishes', 'library-birthday', 'Happy Birthday, {first_name}!', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 40px; text-align: center; color: white; border-radius: 8px 8px 0 0;">
<h1 style="margin: 0; font-size: 36px;">🎉 Happy Birthday! 🎉</h1>
</div>
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-top: none; border-radius: 0 0 8px 8px;">
<p style="font-size: 18px; text-align: center;">Hi {first_name},</p>
<p style="font-size: 16px; text-align: center;">We hope your special day is filled with joy, laughter, and everything you love!</p>
<div style="background: #fff3cd; padding: 20px; margin: 20px 0; border-radius: 4px; text-align: center;">
<p style="margin: 0; font-weight: bold;">{birthday_offer}</p>
</div>
<p style="font-size: 16px; text-align: center;">Thank you for being part of the {company} family. Here''s to another amazing year!</p>
<p style="font-size: 14px; color: #666; margin-top: 30px; text-align: center;">Warmest wishes,<br>The {company} Team</p>
</div>
</body></html>',
'🎉 Happy Birthday, {first_name}! 🎉\n\nWe hope your special day is filled with joy and everything you love!\n\n{birthday_offer}\n\nThank you for being part of the {company} family. Here''s to another amazing year!\n\nWarmest wishes,\nThe {company} Team',
'relationship', '["first_name", "birthday_offer", "company"]', TRUE, TRUE,
'Personalized birthday wishes with special offer', '["relationship", "birthday", "celebration"]', 'general', 'birthday', FALSE, 'CRM Team', '1.0'),

('Thank You Email', 'library-thank-you', 'Thank you, {first_name}!', 
'<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
<div style="background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; border-radius: 8px;">
<p style="font-size: 16px;">Hi {first_name},</p>
<p style="font-size: 16px;">We wanted to take a moment to say thank you.</p>
<p style="font-size: 16px;">{thank_you_message}</p>
<p style="font-size: 16px;">Your support means the world to us, and we''re grateful to have you as part of the {company} community.</p>
<p style="font-size: 16px;">If there''s anything we can do to help, please don''t hesitate to reach out.</p>
<p style="font-size: 14px; color: #666; margin-top: 30px;">With gratitude,<br>The {company} Team</p>
</div>
</body></html>',
'Hi {first_name},\n\nWe wanted to take a moment to say thank you.\n\n{thank_you_message}\n\nYour support means the world to us, and we''re grateful to have you as part of the {company} community.\n\nIf there''s anything we can do to help, please don''t hesitate to reach out.\n\nWith gratitude,\nThe {company} Team',
'relationship', '["first_name", "thank_you_message", "company"]', TRUE, TRUE,
'Express gratitude to customers or partners', '["relationship", "thank-you", "gratitude"]', 'general', 'thank_you', FALSE, 'CRM Team', '1.0');
