ALTER TABLE workspace_marketplace_recommendation_events
    MODIFY event_type ENUM(
        'impression',
        'module_page_view',
        'catalog_click',
        'cta_clicked',
        'dismissed',
        'snoozed',
        'task_created',
        'installed',
        'uninstalled',
        'test_attempted',
        'test_passed',
        'test_failed'
    ) NOT NULL;
