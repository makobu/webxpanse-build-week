CREATE TABLE IF NOT EXISTS platform_security_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    require_superadmin_workspace_2fa BOOLEAN NOT NULL DEFAULT FALSE,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_platform_security_settings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_security_settings (id, require_superadmin_workspace_2fa)
VALUES (1, FALSE)
ON DUPLICATE KEY UPDATE id = VALUES(id);
