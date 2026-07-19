CREATE TABLE IF NOT EXISTS workspace_launch_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    active_package VARCHAR(50) NOT NULL DEFAULT 'core',
    target_niche VARCHAR(80) NOT NULL DEFAULT 'interiors_contractors',
    launch_model VARCHAR(80) NOT NULL DEFAULT 'service_led_saas',
    success_milestone VARCHAR(80) NOT NULL DEFAULT 'first_5_customers',
    notes TEXT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_launch_settings (id, active_package, target_niche, launch_model, success_milestone)
SELECT 1, 'core', 'interiors_contractors', 'service_led_saas', 'first_5_customers'
WHERE NOT EXISTS (
    SELECT 1 FROM workspace_launch_settings WHERE id = 1
);

CREATE TABLE IF NOT EXISTS implementation_checklist_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    checklist_key VARCHAR(80) NOT NULL,
    checklist_label VARCHAR(180) NOT NULL,
    notes TEXT NULL,
    is_complete TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_implementation_checklist_key (checklist_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
