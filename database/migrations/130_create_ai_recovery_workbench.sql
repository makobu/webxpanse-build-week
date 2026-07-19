ALTER TABLE ai_autonomy_incidents
    MODIFY COLUMN status ENUM('open','queued','in_progress','resolved','suppressed') NOT NULL DEFAULT 'open';

ALTER TABLE ai_autonomy_incidents
    ADD COLUMN assigned_to BIGINT UNSIGNED DEFAULT NULL AFTER linked_entity_id,
    ADD COLUMN assigned_at DATETIME DEFAULT NULL AFTER assigned_to,
    ADD COLUMN resolved_by BIGINT UNSIGNED DEFAULT NULL AFTER assigned_at,
    ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER resolved_by,
    ADD COLUMN suppressed_by BIGINT UNSIGNED DEFAULT NULL AFTER resolved_at,
    ADD COLUMN suppressed_at DATETIME DEFAULT NULL AFTER suppressed_by,
    ADD COLUMN last_operator_action VARCHAR(100) DEFAULT NULL AFTER suppressed_at,
    ADD COLUMN operator_notes_json JSON DEFAULT NULL AFTER last_operator_action,
    ADD KEY idx_ai_autonomy_incidents_assignment (assigned_to, status);

ALTER TABLE ai_autonomy_recovery_queue
    MODIFY COLUMN status ENUM('pending','assigned','in_progress','resolved','suppressed','failed_retry') NOT NULL DEFAULT 'pending';

ALTER TABLE ai_autonomy_recovery_queue
    ADD COLUMN assigned_at DATETIME DEFAULT NULL AFTER assigned_to,
    ADD COLUMN suppressed_by BIGINT UNSIGNED DEFAULT NULL AFTER resolved_at,
    ADD COLUMN suppressed_at DATETIME DEFAULT NULL AFTER suppressed_by,
    ADD COLUMN last_attempted_at DATETIME DEFAULT NULL AFTER suppressed_at,
    ADD COLUMN last_error VARCHAR(255) DEFAULT NULL AFTER last_attempted_at,
    ADD COLUMN operator_notes_json JSON DEFAULT NULL AFTER last_error,
    ADD KEY idx_ai_autonomy_recovery_assignment (assigned_to, status);

CREATE TABLE IF NOT EXISTS ai_autonomy_operator_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_key VARCHAR(190) NOT NULL,
    domain_key VARCHAR(100) NOT NULL,
    operator_user_id BIGINT UNSIGNED DEFAULT NULL,
    action_key VARCHAR(100) NOT NULL,
    incident_id BIGINT UNSIGNED DEFAULT NULL,
    recovery_queue_id BIGINT UNSIGNED DEFAULT NULL,
    target_type VARCHAR(100) DEFAULT NULL,
    target_id BIGINT UNSIGNED DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    prior_state_json JSON DEFAULT NULL,
    result_state_json JSON DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_autonomy_operator_actions_scope (tenant_key, domain_key, created_at),
    KEY idx_ai_autonomy_operator_actions_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
