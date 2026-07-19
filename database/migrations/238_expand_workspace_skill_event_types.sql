ALTER TABLE workspace_skill_events
    MODIFY event_type ENUM(
        'installed',
        'uninstalled',
        'enabled',
        'disabled',
        'configured',
        'context_saved'
    ) NOT NULL;
