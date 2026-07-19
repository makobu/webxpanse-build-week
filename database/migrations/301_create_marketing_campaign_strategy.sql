-- Campaign strategy planner: enrich briefs with persona, offer, pillar, metrics, budget, and roadmap fields.

ALTER TABLE marketing_campaign_briefs ADD COLUMN persona_id INT NULL AFTER campaign_id;
ALTER TABLE marketing_campaign_briefs ADD COLUMN offer_context_item_id INT NULL AFTER persona_id;
ALTER TABLE marketing_campaign_briefs ADD COLUMN content_pillar_context_item_id INT NULL AFTER offer_context_item_id;
ALTER TABLE marketing_campaign_briefs ADD COLUMN landing_page_id INT NULL AFTER content_pillar_context_item_id;
ALTER TABLE marketing_campaign_briefs ADD COLUMN success_metrics TEXT NULL AFTER key_message;
ALTER TABLE marketing_campaign_briefs ADD COLUMN budget_estimate DECIMAL(12,2) NULL AFTER success_metrics;
ALTER TABLE marketing_campaign_briefs ADD COLUMN channel_plan_json JSON NULL AFTER budget_estimate;
ALTER TABLE marketing_campaign_briefs ADD COLUMN launch_timeline_json JSON NULL AFTER channel_plan_json;
ALTER TABLE marketing_campaign_briefs ADD COLUMN readiness_score INT NULL AFTER context_score;
ALTER TABLE marketing_campaign_briefs ADD COLUMN missing_strategy_json JSON NULL AFTER readiness_score;

ALTER TABLE marketing_campaign_briefs ADD INDEX idx_marketing_briefs_workspace_persona (workspace_id, persona_id);
ALTER TABLE marketing_campaign_briefs ADD INDEX idx_marketing_briefs_workspace_landing (workspace_id, landing_page_id);

ALTER TABLE marketing_campaign_briefs ADD CONSTRAINT fk_marketing_briefs_persona FOREIGN KEY (persona_id) REFERENCES marketing_personas(id) ON DELETE SET NULL;
ALTER TABLE marketing_campaign_briefs ADD CONSTRAINT fk_marketing_briefs_offer_context FOREIGN KEY (offer_context_item_id) REFERENCES marketing_context_items(id) ON DELETE SET NULL;
ALTER TABLE marketing_campaign_briefs ADD CONSTRAINT fk_marketing_briefs_pillar_context FOREIGN KEY (content_pillar_context_item_id) REFERENCES marketing_context_items(id) ON DELETE SET NULL;
ALTER TABLE marketing_campaign_briefs ADD CONSTRAINT fk_marketing_briefs_landing_page FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL;
