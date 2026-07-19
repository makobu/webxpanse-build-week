CREATE TABLE IF NOT EXISTS ai_runtime_controls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    surface VARCHAR(50) NOT NULL,
    control_mode VARCHAR(32) NOT NULL DEFAULT 'normal',
    reason TEXT NULL,
    set_by INT NULL,
    set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    metadata_json JSON NULL,
    UNIQUE KEY uniq_ai_runtime_controls_surface (surface),
    INDEX idx_ai_runtime_controls_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS ai_runtime_control_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    surface VARCHAR(50) NOT NULL,
    previous_mode VARCHAR(32) NOT NULL,
    new_mode VARCHAR(32) NOT NULL,
    reason TEXT NULL,
    set_by INT NULL,
    set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    metadata_json JSON NULL,
    INDEX idx_ai_runtime_control_log_surface_set_at (surface, set_at)
);
