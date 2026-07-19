-- Marketing Phase 32: AI creative direction runs and prompt registry seeds.

CREATE TABLE IF NOT EXISTS marketing_ai_creative_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    action ENUM('image_prompt','video_storyboard','thumbnail_concept','landing_visual_direction','ad_creative_concept','alt_caption','media_readiness_review') NOT NULL,
    status ENUM('completed','failed') NOT NULL DEFAULT 'completed',
    target_type ENUM('content','landing_page','media','asset','creative_brief','asset_request','distribution','general') NOT NULL DEFAULT 'general',
    content_item_id INT NULL,
    landing_page_id INT NULL,
    media_file_id INT NULL,
    asset_id INT NULL,
    creative_brief_id INT NULL,
    asset_request_id INT NULL,
    prompt_key VARCHAR(128) NULL,
    input_json JSON NULL,
    context_json JSON NULL,
    provider_json JSON NULL,
    result_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_ai_creative_run_uuid (uuid),
    KEY idx_marketing_ai_creative_workspace_action (workspace_id, action, created_at),
    KEY idx_marketing_ai_creative_workspace_content (workspace_id, content_item_id, created_at),
    KEY idx_marketing_ai_creative_workspace_landing (workspace_id, landing_page_id, created_at),
    KEY idx_marketing_ai_creative_workspace_media (workspace_id, media_file_id, created_at),
    CONSTRAINT fk_marketing_ai_creative_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_ai_creative_content FOREIGN KEY (content_item_id) REFERENCES marketing_content_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_ai_creative_landing FOREIGN KEY (landing_page_id) REFERENCES marketing_landing_pages(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_ai_creative_media FOREIGN KEY (media_file_id) REFERENCES marketing_media_files(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_ai_creative_asset FOREIGN KEY (asset_id) REFERENCES marketing_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_ai_creative_brief FOREIGN KEY (creative_brief_id) REFERENCES marketing_creative_briefs(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_ai_creative_request FOREIGN KEY (asset_request_id) REFERENCES marketing_asset_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_ai_creative_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_prompt_registry
    (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
SELECT *
FROM (
    SELECT NULL AS workspace_id, 'marketing' AS surface, 'creative_image_prompt' AS prompt_key, 1 AS version, 'active' AS status,
        'You are Clarity''s Marketing Creative Director. Produce image-generation prompts for human review only.' AS system_prompt_text,
        'Return strict JSON with keys: prompt, negative_prompt, composition_notes, usage_notes. Do not call any image generation API and do not overwrite CRM records.' AS instruction_text,
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('prompt', 'composition_notes', 'usage_notes')) AS output_contract_json,
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE) AS metadata_json,
        NULL AS created_by
    UNION ALL SELECT NULL, 'marketing', 'creative_video_storyboard', 1, 'active',
        'You are Clarity''s Marketing Video Concept Director. Draft storyboard ideas for human review only.',
        'Return strict JSON with keys: concept, scenes, shot_list, production_notes. scenes must be an array of short scene objects or strings. Do not publish or call video APIs.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('concept', 'scenes', 'production_notes')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE),
        NULL
    UNION ALL SELECT NULL, 'marketing', 'creative_thumbnail_concept', 1, 'active',
        'You are Clarity''s Thumbnail Creative Reviewer. Suggest thumbnail concepts grounded in the CRM context.',
        'Return strict JSON with keys: concept, headline_overlay, visual_elements, cautions. Keep output advisory and draft-side.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('concept', 'visual_elements', 'cautions')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE),
        NULL
    UNION ALL SELECT NULL, 'marketing', 'creative_landing_visual_direction', 1, 'active',
        'You are Clarity''s Landing Page Visual Director. Recommend visual hierarchy and media placements.',
        'Return strict JSON with keys: hero_direction, section_media, proof_media, cta_media, missing_assets. Do not modify landing page records automatically.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('hero_direction', 'section_media', 'missing_assets')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE),
        NULL
    UNION ALL SELECT NULL, 'marketing', 'creative_ad_concept', 1, 'active',
        'You are Clarity''s Ad Creative Concept Planner. Suggest ad concepts without launching ads.',
        'Return strict JSON with keys: concept, hook, visual_direction, copy_angle, compliance_notes. Keep all recommendations manual-first.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('concept', 'hook', 'visual_direction', 'compliance_notes')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE),
        NULL
    UNION ALL SELECT NULL, 'marketing', 'creative_alt_caption', 1, 'active',
        'You are Clarity''s Accessibility Copy Assistant. Draft alt text and captions for selected marketing media.',
        'Return strict JSON with keys: alt_text, caption, accessibility_notes. Do not claim image details that are not provided in context.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('alt_text', 'caption', 'accessibility_notes')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE),
        NULL
    UNION ALL SELECT NULL, 'marketing', 'creative_media_readiness', 1, 'active',
        'You are Clarity''s Marketing Media Readiness Reviewer. Review attached media for channel readiness and safe manual export.',
        'Return strict JSON with keys: readiness_score, warnings, recommendations, checklist. Never approve, publish, export, or overwrite records automatically.',
        JSON_OBJECT('type', 'json', 'required', JSON_ARRAY('readiness_score', 'warnings', 'recommendations', 'checklist')),
        JSON_OBJECT('seeded', TRUE, 'phase', 'marketing_ai_creative', 'manual_first', TRUE),
        NULL
) seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM ai_prompt_registry existing
    WHERE (existing.workspace_id IS NULL OR existing.workspace_id = seeded.workspace_id)
      AND existing.surface = seeded.surface
      AND existing.prompt_key = seeded.prompt_key
      AND existing.version = seeded.version
);
