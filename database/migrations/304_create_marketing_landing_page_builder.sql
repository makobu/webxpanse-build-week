-- Marketing Phase 9: builder-ready landing page planning fields.

ALTER TABLE marketing_landing_pages
    ADD COLUMN IF NOT EXISTS seo_title VARCHAR(255) NULL AFTER headline,
    ADD COLUMN IF NOT EXISTS meta_description TEXT NULL AFTER seo_title,
    ADD COLUMN IF NOT EXISTS cta_blocks_json JSON NULL AFTER body_sections_json,
    ADD COLUMN IF NOT EXISTS proof_blocks_json JSON NULL AFTER cta_blocks_json,
    ADD COLUMN IF NOT EXISTS faq_blocks_json JSON NULL AFTER proof_blocks_json,
    ADD COLUMN IF NOT EXISTS thank_you_copy TEXT NULL AFTER faq_blocks_json,
    ADD COLUMN IF NOT EXISTS preview_token VARCHAR(64) NULL AFTER thank_you_copy,
    ADD COLUMN IF NOT EXISTS conversion_goal VARCHAR(64) NULL AFTER preview_token,
    ADD UNIQUE KEY IF NOT EXISTS uniq_marketing_landing_preview_token (workspace_id, preview_token),
    ADD INDEX IF NOT EXISTS idx_marketing_landing_workspace_goal (workspace_id, conversion_goal);

UPDATE marketing_landing_pages
SET preview_token = LOWER(REPLACE(UUID(), '-', ''))
WHERE preview_token IS NULL OR preview_token = '';
