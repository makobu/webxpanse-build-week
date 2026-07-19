-- Add settings column to email_signatures for logo, colors, etc.
ALTER TABLE email_signatures ADD COLUMN settings JSON NULL AFTER content_text;
