-- Per-user calendar availability ownership and source selection.

ALTER TABLE calendar_integrations
    ADD COLUMN IF NOT EXISTS availability_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER sync_enabled,
    ADD COLUMN IF NOT EXISTS provider_account_email VARCHAR(255) NULL AFTER calendar_name,
    ADD COLUMN IF NOT EXISTS availability_last_checked_at DATETIME NULL AFTER last_sync_at,
    ADD COLUMN IF NOT EXISTS availability_last_error TEXT NULL AFTER availability_last_checked_at,
    ADD KEY IF NOT EXISTS idx_calendar_integrations_availability_user (workspace_id, user_id, availability_enabled),
    ADD KEY IF NOT EXISTS idx_calendar_integrations_availability_checked (workspace_id, availability_enabled, availability_last_checked_at);

ALTER TABLE meeting_booking_profiles
    ADD COLUMN IF NOT EXISTS availability_source_mode ENUM('owner_all','selected_integrations','none') NOT NULL DEFAULT 'owner_all' AFTER owner_user_id,
    ADD COLUMN IF NOT EXISTS availability_integration_ids_json JSON NULL AFTER availability_source_mode;

UPDATE meeting_booking_profiles
SET availability_source_mode = 'owner_all'
WHERE availability_source_mode IS NULL OR availability_source_mode = '';

UPDATE meeting_booking_profiles
SET owner_user_id = created_by
WHERE owner_user_id IS NULL
  AND created_by IS NOT NULL;

UPDATE meeting_booking_profiles p
JOIN (
    SELECT workspace_id, MIN(user_id) AS owner_user_id
    FROM workspace_memberships
    WHERE membership_status = 'active'
      AND (is_owner = 1 OR role_slug IN ('owner', 'admin'))
    GROUP BY workspace_id
) wm ON wm.workspace_id = p.workspace_id
SET p.owner_user_id = wm.owner_user_id
WHERE p.owner_user_id IS NULL;
