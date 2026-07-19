CREATE TABLE IF NOT EXISTS ai_incident_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_key VARCHAR(120) NOT NULL,
    fingerprint VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    severity VARCHAR(16) NOT NULL DEFAULT 'medium',
    first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_alert_id INT NULL,
    cooldown_until DATETIME NULL,
    metadata_json JSON NULL,
    INDEX idx_ai_incident_state_key_fingerprint_status (incident_key, fingerprint, status),
    INDEX idx_ai_incident_state_last_detected (last_detected_at),
    INDEX idx_ai_incident_state_cooldown (cooldown_until)
);
