CREATE TABLE IF NOT EXISTS workspace_ai_autoresponder_quiet_hours (
    workspace_id INT PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    start_time TIME NOT NULL DEFAULT '20:00:00',
    end_time TIME NOT NULL DEFAULT '08:00:00',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_workspace_ai_quiet_enabled (enabled),
    CONSTRAINT fk_workspace_ai_quiet_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workspace_ai_quiet_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_ai_autoresponder_quiet_hours (
    workspace_id,
    enabled,
    start_time,
    end_time,
    timezone
)
SELECT
    w.id,
    CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(a.config_json, '$.quiet_hours.enabled')) IN ('true', '1') THEN 1
        ELSE 0
    END,
    COALESCE(
        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(a.config_json, '$.quiet_hours.start')), '%H:%i'),
        '20:00:00'
    ),
    COALESCE(
        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(a.config_json, '$.quiet_hours.end')), '%H:%i'),
        '08:00:00'
    ),
    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(a.config_json, '$.quiet_hours.timezone')), ''), 'UTC')
FROM workspaces w
LEFT JOIN ai_autoresponder_config a ON a.id = 1
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    start_time = VALUES(start_time),
    end_time = VALUES(end_time),
    timezone = VALUES(timezone),
    updated_at = CURRENT_TIMESTAMP;
