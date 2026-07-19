-- Extend the Social Media audit stream for explicit operator retries.

ALTER TABLE social_media_events
    MODIFY event_type ENUM(
        'settings_saved',
        'oauth_started',
        'account_connected',
        'account_verified',
        'account_disconnected',
        'job_created',
        'job_approved',
        'job_cancelled',
        'job_retried',
        'publish_started',
        'publish_succeeded',
        'publish_failed',
        'metrics_synced'
    ) NOT NULL;
