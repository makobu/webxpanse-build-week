-- Give the marketing operating workspace and its nested AI workspace literal,
-- two-word product names while preserving the existing compatibility keys.

UPDATE workspace_skill_definitions
SET
    label = 'Campaign Manager',
    navigation_json = JSON_SET(
        COALESCE(navigation_json, JSON_OBJECT()),
        '$.label', 'Campaign Manager',
        '$.url', 'marketing.php'
    ),
    plugin_metadata_json = CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.pitch')) IS NULL
          OR JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.pitch')) LIKE '%Marketing Pro%'
          OR JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.pitch')) LIKE '%pro plugin%'
        THEN JSON_SET(
            COALESCE(plugin_metadata_json, JSON_OBJECT()),
            '$.marketplace_profile.pitch',
            'Manage strategy context, campaigns, launch readiness, analytics, attribution, and live orchestration in one workspace while Social Media and Design handle focused execution.'
        )
        ELSE plugin_metadata_json
    END
WHERE skill_key = 'marketing_pro'
  AND definition_source = 'platform';

UPDATE workspace_skill_definitions
SET
    label = 'Marketing Assistants',
    navigation_json = JSON_SET(
        COALESCE(navigation_json, JSON_OBJECT()),
        '$.label', 'Marketing Assistants',
        '$.url', 'marketing_assistants.php'
    )
WHERE skill_key = 'professional_marketer'
  AND definition_source = 'platform';

UPDATE workspace_skill_catalog_overrides
SET
    label = CASE
        WHEN label IS NULL OR label = '' OR label = 'Marketing Pro' THEN 'Campaign Manager'
        ELSE label
    END,
    marketplace_profile_json = CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.pitch')) IS NULL
          OR JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.pitch')) LIKE '%Marketing Pro%'
          OR JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.pitch')) LIKE '%pro plugin%'
        THEN JSON_SET(
            COALESCE(marketplace_profile_json, JSON_OBJECT()),
            '$.pitch',
            'Manage strategy context, campaigns, launch readiness, analytics, attribution, and live orchestration in one workspace while Social Media and Design handle focused execution.'
        )
        ELSE marketplace_profile_json
    END
WHERE skill_key = 'marketing_pro';

UPDATE workspace_skill_catalog_overrides
SET label = CASE
    WHEN label IS NULL OR label = '' OR label = 'Professional Marketer' THEN 'Marketing Assistants'
    ELSE label
END
WHERE skill_key = 'professional_marketer';
