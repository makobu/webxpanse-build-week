ALTER TABLE workspace_marketplace_activation_bundle_events
    MODIFY surface ENUM('marketplace', 'clarity_chat') NOT NULL DEFAULT 'marketplace';
