-- Add lead_score to contacts table
-- This migration may fail if column/index already exists, which is fine
-- The migration script should be updated to handle duplicate errors gracefully
ALTER TABLE contacts ADD COLUMN lead_score INT DEFAULT 0;
CREATE INDEX idx_lead_score ON contacts(lead_score DESC);
