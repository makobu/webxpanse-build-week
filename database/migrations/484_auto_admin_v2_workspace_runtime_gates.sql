-- Auto Admin v2: workspace runtime gates and defaults version bump.

ALTER TABLE workspace_auto_admin_events
    ADD KEY IF NOT EXISTS idx_workspace_auto_admin_events_workspace_type_created (workspace_id, event_type, created_at);

UPDATE workspace_auto_admin_settings
SET managed_defaults_version = 2,
    target_modes_json = COALESCE(
        target_modes_json,
        JSON_OBJECT(
            'deal_automation', 'suggest_only',
            'workflow_automation', 'auto_safe',
            'ai_autoresponder', 'draft_only',
            'commercial_automation', 'auto_safe'
        )
    ),
    updated_at = CURRENT_TIMESTAMP
WHERE managed_defaults_version < 2
   OR managed_defaults_version IS NULL;
