CREATE TABLE IF NOT EXISTS platform_auto_admin_settings (
    id INT NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_platform_auto_admin_settings_enabled (enabled),
    CONSTRAINT fk_platform_auto_admin_settings_updated_by_user
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_auto_admin_settings (id, enabled, updated_by_user_id)
SELECT
    1,
    CASE
        WHEN LOWER(COALESCE((
            SELECT preference_value
            FROM user_preferences
            WHERE user_id = 1
              AND preference_key = 'auto_admin_enabled'
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ), '0')) IN ('1', 'true', 'yes', 'on') THEN 1
        ELSE 0
    END,
    NULL
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    updated_at = NOW();
