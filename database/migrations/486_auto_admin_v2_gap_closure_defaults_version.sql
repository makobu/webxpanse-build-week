-- Auto Admin v2 gap closure: bump workspace managed defaults version.

UPDATE workspace_auto_admin_settings
SET managed_defaults_version = 3,
    updated_at = CURRENT_TIMESTAMP
WHERE managed_defaults_version IS NULL
   OR managed_defaults_version < 3;
