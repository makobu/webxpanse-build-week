-- Keep setup journey event storage aligned with WorkspaceMarketplaceSetupJourneyEventService.

ALTER TABLE workspace_marketplace_setup_journey_events
    MODIFY event_type ENUM(
        'journey_impression',
        'setup_opened',
        'setup_saved',
        'install_completed',
        'step_completed',
        'step_skipped',
        'step_reset'
    ) NOT NULL;
