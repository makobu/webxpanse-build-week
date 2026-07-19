-- Seed the default workspace calendar profile used by negotiated package meeting requests.

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 AND slug = 'default' LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1),
    1
);

SET @support_user_id := (
    SELECT wm.user_id
    FROM workspace_memberships wm
    LEFT JOIN user_roles ur ON ur.user_id = wm.user_id
    LEFT JOIN roles global_role ON global_role.id = ur.role_id
    WHERE wm.workspace_id = @default_workspace_id
      AND wm.membership_status = 'active'
      AND (
          wm.is_owner = 1
          OR wm.role_slug IN ('superadmin', 'owner', 'admin')
          OR global_role.slug = 'superadmin'
      )
    ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'expert', 'viewer'), wm.id ASC
    LIMIT 1
);

INSERT INTO meeting_booking_profiles (
    workspace_id,
    owner_user_id,
    slug,
    title,
    description,
    public_enabled,
    timezone,
    default_duration_minutes,
    allowed_durations_json,
    buffer_before_minutes,
    buffer_after_minutes,
    min_notice_hours,
    max_advance_days,
    allowed_meeting_formats_json,
    approval_mode,
    status,
    created_by
)
SELECT
    @default_workspace_id,
    @support_user_id,
    'package-negotiation',
    'Package Negotiation',
    'Book time with support to discuss a negotiated workspace package.',
    1,
    'UTC',
    30,
    JSON_ARRAY(30),
    15,
    15,
    24,
    60,
    JSON_ARRAY('google_meet', 'zoom', 'phone_call'),
    'manual',
    'active',
    @support_user_id
WHERE @default_workspace_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    description = VALUES(description),
    public_enabled = 1,
    status = 'active',
    owner_user_id = COALESCE(meeting_booking_profiles.owner_user_id, VALUES(owner_user_id)),
    default_duration_minutes = VALUES(default_duration_minutes),
    allowed_durations_json = VALUES(allowed_durations_json),
    allowed_meeting_formats_json = VALUES(allowed_meeting_formats_json),
    updated_by = VALUES(created_by),
    updated_at = NOW();

SET @package_negotiation_profile_id := (
    SELECT id
    FROM meeting_booking_profiles
    WHERE workspace_id = @default_workspace_id
      AND slug = 'package-negotiation'
    LIMIT 1
);

INSERT INTO meeting_availability_windows (profile_id, day_of_week, start_time, end_time, is_enabled)
SELECT @package_negotiation_profile_id, days.day_of_week, '09:00:00', '17:00:00', 1
FROM (
    SELECT 1 AS day_of_week
    UNION ALL SELECT 2
    UNION ALL SELECT 3
    UNION ALL SELECT 4
    UNION ALL SELECT 5
) days
WHERE @package_negotiation_profile_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM meeting_availability_windows existing
      WHERE existing.profile_id = @package_negotiation_profile_id
      LIMIT 1
  );

INSERT INTO meeting_booking_profile_hosts (profile_id, user_id, is_enabled, sort_order)
SELECT @package_negotiation_profile_id, @support_user_id, 1, 1
WHERE @package_negotiation_profile_id IS NOT NULL
  AND @support_user_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    is_enabled = 1,
    updated_at = NOW();
