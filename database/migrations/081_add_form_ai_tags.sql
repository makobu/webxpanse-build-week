-- Add AI tags and follow-up suggestion to form submissions
ALTER TABLE form_submissions ADD COLUMN ai_tags JSON NULL AFTER form_data;
ALTER TABLE form_submissions ADD COLUMN ai_follow_up VARCHAR(500) NULL AFTER ai_tags;
ALTER TABLE form_submissions ADD COLUMN ai_suggested_stage VARCHAR(50) NULL AFTER ai_follow_up;
