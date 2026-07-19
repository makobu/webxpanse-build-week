-- Add AI Context Field to Contacts Table
-- Migration 052: AI Context-Based Enrichment

-- Add ai_context JSON field to store AI-generated insights and conclusions
ALTER TABLE contacts ADD COLUMN ai_context JSON NULL;

-- Functional JSON indexes are not portable across MySQL and MariaDB.
-- Skip creating a JSON expression index here to keep fresh installs compatible.

-- Add comment to document the field
ALTER TABLE contacts MODIFY COLUMN ai_context JSON NULL COMMENT 'AI-generated contextual insights, conclusions, and summaries. Structured JSON format with insights, confidence scores, and reasoning.';
