CREATE TABLE IF NOT EXISTS ai_autonomy_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_key VARCHAR(190) NOT NULL,
    domain_key VARCHAR(100) NOT NULL,
    action_key VARCHAR(100) DEFAULT NULL,
    incident_key VARCHAR(100) NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    status ENUM('open','queued','resolved') NOT NULL DEFAULT 'open',
    reason_codes_json JSON DEFAULT NULL,
    details_json JSON DEFAULT NULL,
    linked_run_id BIGINT UNSIGNED DEFAULT NULL,
    linked_eval_run_id BIGINT UNSIGNED DEFAULT NULL,
    linked_entity_type VARCHAR(100) DEFAULT NULL,
    linked_entity_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ai_autonomy_incidents_scope (tenant_key, domain_key, status),
    KEY idx_ai_autonomy_incidents_key (incident_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_autonomy_recovery_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    tenant_key VARCHAR(190) NOT NULL,
    domain_key VARCHAR(100) NOT NULL,
    action_key VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','in_progress','resolved') NOT NULL DEFAULT 'pending',
    suggested_manual_action VARCHAR(190) NOT NULL,
    payload_json JSON DEFAULT NULL,
    assigned_to BIGINT UNSIGNED DEFAULT NULL,
    resolved_by BIGINT UNSIGNED DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ai_autonomy_recovery_scope (tenant_key, domain_key, status),
    KEY idx_ai_autonomy_recovery_incident (incident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
