ALTER TABLE targets
    ADD COLUMN scope ENUM('personal','team','company') NOT NULL DEFAULT 'personal' AFTER user_id,
    ADD COLUMN progress_mode ENUM('manual','auto_rollup','hybrid') NOT NULL DEFAULT 'manual' AFTER target_type,
    ADD COLUMN manual_adjustment_value DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER current_value,
    ADD COLUMN metadata_json JSON NULL AFTER custom_reminder_days,
    ADD INDEX idx_targets_scope (scope),
    ADD INDEX idx_targets_progress_mode (progress_mode),
    ADD INDEX idx_targets_scope_status (scope, status);

ALTER TABLE target_advice
    ADD COLUMN metadata_json JSON NULL AFTER advice_type;

CREATE TABLE IF NOT EXISTS target_milestones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    target_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    target_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    sort_order INT NOT NULL DEFAULT 0,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_target_milestones_target (target_id),
    INDEX idx_target_milestones_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
