-- Split the legacy Professional Marketer marketplace skill into focused Marketing plugins.

INSERT INTO workspace_skill_definitions (
    skill_key,
    label,
    summary,
    category,
    module_type,
    version,
    capabilities_json,
    onboarding_fields_json,
    settings_schema_json,
    plugin_metadata_json,
    ai_context_provider,
    navigation_json,
    permissions_json,
    is_active
) VALUES
(
    'social_media',
    'Social Media',
    'Owns social connectors, content creation, scheduling/export workflows, social analytics, and social ad preparation.',
    'marketing',
    'plugin',
    '1.0.0',
    JSON_OBJECT('runtime_plugin', TRUE, 'social_connectors', TRUE, 'content_creation', TRUE, 'content_calendar', TRUE, 'social_analytics', TRUE, 'social_ads', TRUE, 'ai_context', TRUE),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=social_media#setup'),
    JSON_OBJECT(
        'readiness_provider', 'marketing_social_media',
        'runtime_provider', 'Marketing',
        'setup_url', 'workspace_skills.php?module=social_media#setup',
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/professional-marketer.webp',
            'thumbnail_alt', 'Social media command board with posts, connectors, and ad readiness cards.',
            'pitch', 'Plan, prepare, and package social content from one focused plugin. Social Media keeps channel connectors, publishing readiness, post variants, campaign copy, and social analytics out of the heavier marketing management layer.',
            'tags', JSON_ARRAY('Marketing', 'Social', 'Content')
        )
    ),
    'social_media',
    JSON_OBJECT('label', 'Social Media', 'url', 'marketing_content.php'),
    JSON_ARRAY('marketing.read'),
    1
),
(
    'design',
    'Design',
    'Owns forms, websites, landing pages, page previews, and creative/page design workflows.',
    'marketing',
    'plugin',
    '1.0.0',
    JSON_OBJECT('runtime_plugin', TRUE, 'forms', TRUE, 'websites', TRUE, 'landing_pages', TRUE, 'creative_assets', TRUE, 'conversion_design', TRUE, 'ai_context', TRUE),
    JSON_ARRAY(),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=design#setup'),
    JSON_OBJECT(
        'readiness_provider', 'marketing_design',
        'runtime_provider', 'Marketing',
        'setup_url', 'workspace_skills.php?module=design#setup',
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/professional-marketer.webp',
            'thumbnail_alt', 'Landing page and form design workspace with creative asset checks.',
            'pitch', 'Build the conversion surfaces around a campaign without carrying the full marketing management toolkit. Design owns forms, landing pages, page previews, creative readiness, and website-style campaign pages.',
            'tags', JSON_ARRAY('Marketing', 'Design', 'Landing pages')
        )
    ),
    'design',
    JSON_OBJECT('label', 'Design', 'url', 'marketing_landing_pages.php'),
    JSON_ARRAY('marketing.read'),
    1
),
(
    'marketing_pro',
    'Marketing Pro',
    'Owns marketing management, campaign setup, heavy analytics, attribution, orchestration, and strategic operating context.',
    'marketing',
    'plugin',
    '1.0.0',
    JSON_OBJECT('runtime_plugin', TRUE, 'ai_context', TRUE, 'campaign_management', TRUE, 'marketing_setup', TRUE, 'heavy_analytics', TRUE, 'attribution', TRUE, 'live_orchestration', TRUE, 'strategy_context', TRUE),
    JSON_ARRAY('target_market_focus', 'segment_focus', 'outreach_posture', 'positioning_notes'),
    JSON_OBJECT('requires_configuration', TRUE, 'settings_url', 'workspace_skills.php?module=marketing_pro#setup'),
    JSON_OBJECT(
        'readiness_provider', 'marketing_pro',
        'runtime_provider', 'Marketing',
        'setup_url', 'workspace_skills.php?module=marketing_pro#setup',
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/professional-marketer.webp',
            'thumbnail_alt', 'Marketing management dashboard with analytics, campaign setup, and attribution signals.',
            'pitch', 'Keep the heavyweight marketing operating system in one pro plugin. Marketing Pro owns strategy context, campaign management, launch readiness, analytics, attribution, and live orchestration while Social Media and Design handle focused execution surfaces.',
            'tags', JSON_ARRAY('AI guidance', 'Marketing', 'Analytics')
        )
    ),
    'marketing_pro',
    JSON_OBJECT('label', 'Marketing Pro', 'url', 'marketing.php'),
    JSON_ARRAY('marketing.read'),
    1
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    summary = VALUES(summary),
    category = VALUES(category),
    module_type = VALUES(module_type),
    version = VALUES(version),
    capabilities_json = VALUES(capabilities_json),
    onboarding_fields_json = VALUES(onboarding_fields_json),
    settings_schema_json = VALUES(settings_schema_json),
    plugin_metadata_json = VALUES(plugin_metadata_json),
    ai_context_provider = VALUES(ai_context_provider),
    navigation_json = VALUES(navigation_json),
    permissions_json = VALUES(permissions_json),
    is_active = VALUES(is_active),
    updated_at = NOW();

UPDATE workspace_skill_definitions
SET
    summary = 'Legacy compatibility alias for workspaces that installed Professional Marketer before Social Media, Design, and Marketing Pro were split into separate Marketplace plugins.',
    plugin_metadata_json = JSON_SET(COALESCE(plugin_metadata_json, JSON_OBJECT()), '$.legacy_hidden_from_marketplace', TRUE),
    updated_at = NOW()
WHERE skill_key = 'professional_marketer';

INSERT INTO workspace_skill_catalog_overrides (
    skill_key,
    catalog_status,
    marketplace_profile_json
) VALUES (
    'professional_marketer',
    'hidden',
    JSON_OBJECT('legacy_hidden_from_marketplace', TRUE)
)
ON DUPLICATE KEY UPDATE
    catalog_status = 'hidden',
    marketplace_profile_json = JSON_SET(COALESCE(workspace_skill_catalog_overrides.marketplace_profile_json, JSON_OBJECT()), '$.legacy_hidden_from_marketplace', TRUE),
    updated_at = NOW();

INSERT INTO workspace_skill_installs (
    workspace_id,
    skill_key,
    status,
    config_json,
    installed_by_user_id,
    updated_by_user_id,
    installed_at,
    disabled_at,
    uninstalled_at
)
SELECT
    legacy.workspace_id,
    split_plugins.skill_key,
    'installed',
    JSON_OBJECT('source', 'grandfathered_from_professional_marketer', 'legacy_skill_key', 'professional_marketer'),
    legacy.installed_by_user_id,
    legacy.updated_by_user_id,
    COALESCE(legacy.installed_at, NOW()),
    NULL,
    NULL
FROM workspace_skill_installs legacy
JOIN (
    SELECT 'social_media' AS skill_key
    UNION ALL SELECT 'design'
    UNION ALL SELECT 'marketing_pro'
) split_plugins
WHERE legacy.skill_key = 'professional_marketer'
  AND legacy.status = 'installed'
ON DUPLICATE KEY UPDATE
    status = 'installed',
    config_json = CASE
        WHEN workspace_skill_installs.config_json IS NULL THEN VALUES(config_json)
        ELSE JSON_SET(
            workspace_skill_installs.config_json,
            '$.grandfathered_from_professional_marketer',
            TRUE,
            '$.legacy_skill_key',
            'professional_marketer'
        )
    END,
    updated_by_user_id = VALUES(updated_by_user_id),
    disabled_at = NULL,
    uninstalled_at = NULL,
    updated_at = NOW();
