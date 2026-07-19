-- Remove legacy Marketplace overview fields after preserving old headline/intro copy.

UPDATE workspace_skill_definitions
SET plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.marketplace_profile.overview_brief_format', 'text',
        '$.marketplace_profile.overview_brief_content',
        TRIM(CONCAT_WS('\n\n',
            NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.overview_headline')), '')), 'null'), ''),
            NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.overview_intro')), '')), 'null'), '')
        ))
    )
WHERE JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile') IS NOT NULL
  AND NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.overview_brief_content')), '')), 'null'), '') IS NULL
  AND (
      NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.overview_headline')), '')), 'null'), '') IS NOT NULL
      OR NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(plugin_metadata_json, '$.marketplace_profile.overview_intro')), '')), 'null'), '') IS NOT NULL
  );

UPDATE workspace_skill_definitions
SET plugin_metadata_json = JSON_REMOVE(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.marketplace_profile.overview_headline',
        '$.marketplace_profile.overview_intro',
        '$.marketplace_profile.overview_brief_image_url',
        '$.marketplace_profile.overview_brief_image_alt',
        '$.marketplace_profile.overview_deep_dive_image_url',
        '$.marketplace_profile.overview_deep_dive_image_alt'
    )
WHERE JSON_CONTAINS_PATH(
        plugin_metadata_json,
        'one',
        '$.marketplace_profile.overview_headline',
        '$.marketplace_profile.overview_intro',
        '$.marketplace_profile.overview_brief_image_url',
        '$.marketplace_profile.overview_brief_image_alt',
        '$.marketplace_profile.overview_deep_dive_image_url',
        '$.marketplace_profile.overview_deep_dive_image_alt'
    );

UPDATE workspace_skill_catalog_overrides
SET marketplace_profile_json = JSON_SET(
        COALESCE(marketplace_profile_json, JSON_OBJECT()),
        '$.overview_brief_format', 'text',
        '$.overview_brief_content',
        TRIM(CONCAT_WS('\n\n',
            NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.overview_headline')), '')), 'null'), ''),
            NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.overview_intro')), '')), 'null'), '')
        ))
    )
WHERE NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.overview_brief_content')), '')), 'null'), '') IS NULL
  AND (
      NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.overview_headline')), '')), 'null'), '') IS NOT NULL
      OR NULLIF(NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(marketplace_profile_json, '$.overview_intro')), '')), 'null'), '') IS NOT NULL
  );

UPDATE workspace_skill_catalog_overrides
SET marketplace_profile_json = JSON_REMOVE(
        COALESCE(marketplace_profile_json, JSON_OBJECT()),
        '$.overview_headline',
        '$.overview_intro',
        '$.overview_brief_image_url',
        '$.overview_brief_image_alt',
        '$.overview_deep_dive_image_url',
        '$.overview_deep_dive_image_alt'
    )
WHERE JSON_CONTAINS_PATH(
        marketplace_profile_json,
        'one',
        '$.overview_headline',
        '$.overview_intro',
        '$.overview_brief_image_url',
        '$.overview_brief_image_alt',
        '$.overview_deep_dive_image_url',
        '$.overview_deep_dive_image_alt'
    );
