-- Add Three-Score System Fields to Contacts Table
-- Migration 053: Three-Score System with Configurable Weights

-- Add engagement_score field (0-100)
ALTER TABLE contacts ADD COLUMN engagement_score INT DEFAULT 0 COMMENT 'Engagement-based score (0-100) based on activities, email opens, clicks, etc.';

-- Add ai_score field (0-100)
ALTER TABLE contacts ADD COLUMN ai_score INT DEFAULT 0 COMMENT 'AI insight-based score (0-100) based on AI context analysis';

-- Add score_weights JSON field (per-contact weight overrides)
ALTER TABLE contacts ADD COLUMN score_weights JSON NULL COMMENT 'Per-contact weight overrides: {"engagement": 0.4, "ml": 0.4, "ai": 0.2}';

-- Add recommended_weights JSON field (system-recommended weights)
ALTER TABLE contacts ADD COLUMN recommended_weights JSON NULL COMMENT 'System-recommended weights based on data availability and confidence';

-- Add indexes for score fields
CREATE INDEX idx_engagement_score ON contacts(engagement_score);
CREATE INDEX idx_ai_score ON contacts(ai_score);

-- Update enrichment_config table to store global default weights
ALTER TABLE enrichment_config ADD COLUMN default_score_weights JSON NULL COMMENT 'Global default score weights: {"engagement": 0.4, "ml": 0.4, "ai": 0.2}';

-- Set default weights in enrichment_config if not exists
UPDATE enrichment_config 
SET default_score_weights = '{"engagement": 0.4, "ml": 0.4, "ai": 0.2}'
WHERE id = 1 AND default_score_weights IS NULL;
