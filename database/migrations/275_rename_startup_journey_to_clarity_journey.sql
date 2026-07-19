UPDATE workspace_skill_definitions
SET
    label = 'Clarity Journey',
    summary = 'Guides teams from customer discovery through JTBD, value proposition, Lean Canvas, MVP, go-to-market, AARRR, and OKRs so AI guidance can reason from the full clarity path.',
    plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.marketplace_profile.thumbnail_alt', 'Clarity journey cards from customer discovery through growth metrics.',
        '$.marketplace_profile.pitch', 'Set up Clarity Journey as the workspace strategy context skill. It prompts the owner to move from customer evidence into jobs, value, business model, MVP, go-to-market, growth metrics, and quarterly execution.',
        '$.marketplace_profile.overview_headline', 'Clarity journey context for AI guidance',
        '$.marketplace_profile.overview_intro', 'Clarity Journey owns the progressive strategy assumptions that other AI surfaces read. Lean Canvas remains one stage inside the journey for compatibility and business-model context.',
        '$.marketplace_profile.outcome_bullets', JSON_ARRAY(
            'A complete eight-stage Clarity Journey saved to the workspace.',
            'Clear readiness signals for missing stages and the next clarity action.',
            'Journey-backed prompts and task templates for strategic advice.'
        ),
        '$.marketplace_profile.how_it_works_bullets', JSON_ARRAY(
            'The dedicated Clarity Journey hub saves each stage and keeps Marketplace readiness current.',
            'Readiness is based on completing the eight core journey stages.',
            'Ready journey context is exposed through installed skill contracts.'
        ),
        '$.marketplace_profile.setup_guide', JSON_ARRAY(
            'Install Clarity Journey from Marketplace.',
            'Complete the eight core stages with concise working assumptions and evidence.',
            'Return to AI Coach or Clarity for validation experiments and next actions grounded in the journey.'
        )
    ),
    navigation_json = JSON_SET(
        COALESCE(navigation_json, JSON_OBJECT()),
        '$.label', 'Clarity Journey',
        '$.url', 'startup_journey.php'
    )
WHERE skill_key = 'lean_canvas'
  AND definition_source = 'platform';

UPDATE workspace_skill_catalog_overrides
SET
    label = CASE
        WHEN label IS NULL OR label = '' OR label = 'Startup Journey' THEN 'Clarity Journey'
        ELSE label
    END,
    summary = CASE
        WHEN summary IS NULL OR summary = '' OR summary LIKE 'Guides founders from customer discovery%' THEN 'Guides teams from customer discovery through JTBD, value proposition, Lean Canvas, MVP, go-to-market, AARRR, and OKRs so AI guidance can reason from the full clarity path.'
        ELSE summary
    END,
    marketplace_profile_json = JSON_SET(
        COALESCE(marketplace_profile_json, JSON_OBJECT()),
        '$.thumbnail_alt', 'Clarity journey cards from customer discovery through growth metrics.',
        '$.pitch', 'Set up Clarity Journey as the workspace strategy context skill. It prompts the owner to move from customer evidence into jobs, value, business model, MVP, go-to-market, growth metrics, and quarterly execution.',
        '$.overview_headline', 'Clarity journey context for AI guidance',
        '$.overview_intro', 'Clarity Journey owns the progressive strategy assumptions that other AI surfaces read. Lean Canvas remains one stage inside the journey for compatibility and business-model context.',
        '$.outcome_bullets', JSON_ARRAY(
            'A complete eight-stage Clarity Journey saved to the workspace.',
            'Clear readiness signals for missing stages and the next clarity action.',
            'Journey-backed prompts and task templates for strategic advice.'
        ),
        '$.how_it_works_bullets', JSON_ARRAY(
            'The dedicated Clarity Journey hub saves each stage and keeps Marketplace readiness current.',
            'Readiness is based on completing the eight core journey stages.',
            'Ready journey context is exposed through installed skill contracts.'
        ),
        '$.setup_guide', JSON_ARRAY(
            'Install Clarity Journey from Marketplace.',
            'Complete the eight core stages with concise working assumptions and evidence.',
            'Return to AI Coach or Clarity for validation experiments and next actions grounded in the journey.'
        )
    )
WHERE skill_key = 'lean_canvas';

UPDATE marketplace_page_explainers
SET label = 'Clarity Journey page guide'
WHERE page_key = 'startup_journey'
  AND label = 'Startup Journey page guide';
