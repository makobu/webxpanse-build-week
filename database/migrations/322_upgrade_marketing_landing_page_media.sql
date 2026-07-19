-- Marketing Phase 30: landing page media slots and visual builder metadata.

ALTER TABLE marketing_landing_pages
    ADD COLUMN IF NOT EXISTS hero_media_file_id INT NULL AFTER meta_description,
    ADD COLUMN IF NOT EXISTS social_preview_media_file_id INT NULL AFTER hero_media_file_id,
    ADD COLUMN IF NOT EXISTS cta_media_file_id INT NULL AFTER social_preview_media_file_id,
    ADD COLUMN IF NOT EXISTS proof_media_file_id INT NULL AFTER cta_media_file_id,
    ADD COLUMN IF NOT EXISTS testimonial_media_file_id INT NULL AFTER proof_media_file_id,
    ADD COLUMN IF NOT EXISTS section_media_json JSON NULL AFTER body_sections_json,
    ADD COLUMN IF NOT EXISTS gallery_media_json JSON NULL AFTER section_media_json,
    ADD INDEX IF NOT EXISTS idx_marketing_landing_workspace_hero_media (workspace_id, hero_media_file_id),
    ADD INDEX IF NOT EXISTS idx_marketing_landing_workspace_social_media (workspace_id, social_preview_media_file_id);
