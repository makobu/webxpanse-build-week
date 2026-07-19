ALTER TABLE ai_cross_domain_runs
    MODIFY COLUMN run_status ENUM('planned','running','waiting','ready_to_resume','suppressed','blocked','approval_required','completed','failed','canceled') NOT NULL DEFAULT 'planned';

ALTER TABLE ai_cross_domain_runs
    ADD COLUMN origin_type VARCHAR(32) NOT NULL DEFAULT 'operator' AFTER completed_at,
    ADD COLUMN trigger_source_domain VARCHAR(100) DEFAULT NULL AFTER origin_type,
    ADD COLUMN trigger_key VARCHAR(100) DEFAULT NULL AFTER trigger_source_domain,
    ADD COLUMN trigger_entity_type VARCHAR(100) DEFAULT NULL AFTER trigger_key,
    ADD COLUMN trigger_entity_id BIGINT UNSIGNED DEFAULT NULL AFTER trigger_entity_type,
    ADD COLUMN trigger_metadata_json JSON DEFAULT NULL AFTER trigger_entity_id,
    ADD COLUMN wait_state_json JSON DEFAULT NULL AFTER trigger_metadata_json,
    ADD COLUMN suppression_reason VARCHAR(255) DEFAULT NULL AFTER wait_state_json,
    ADD COLUMN last_resume_attempt_at DATETIME DEFAULT NULL AFTER suppression_reason,
    ADD KEY idx_ai_cross_domain_runs_origin (origin_type, run_status, created_at),
    ADD KEY idx_ai_cross_domain_runs_trigger (trigger_source_domain, trigger_key, trigger_entity_type, trigger_entity_id);

CREATE TABLE IF NOT EXISTS ai_cross_domain_intake_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_key VARCHAR(190) NOT NULL,
    source_domain VARCHAR(100) NOT NULL,
    trigger_key VARCHAR(100) NOT NULL,
    trigger_entity_type VARCHAR(100) DEFAULT NULL,
    trigger_entity_id BIGINT UNSIGNED DEFAULT NULL,
    objective_key VARCHAR(100) DEFAULT NULL,
    intake_decision VARCHAR(32) NOT NULL,
    linked_run_id BIGINT UNSIGNED DEFAULT NULL,
    reason_text VARCHAR(255) DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_cross_domain_intake_scope (tenant_key, source_domain, intake_decision, created_at),
    KEY idx_ai_cross_domain_intake_trigger (trigger_key, trigger_entity_type, trigger_entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
