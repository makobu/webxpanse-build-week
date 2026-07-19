CREATE TABLE IF NOT EXISTS ai_capability_state_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    surface VARCHAR(64) NOT NULL,
    capability_key VARCHAR(128) NOT NULL,
    status ENUM('ready', 'degraded', 'missing') NOT NULL DEFAULT 'ready',
    reason VARCHAR(255) DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_capability_user_surface (user_id, surface),
    INDEX idx_ai_capability_key (capability_key),
    CONSTRAINT fk_ai_capability_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_task_evidence (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    evidence_type ENUM('email_reply_received', 'deal_stage_reached', 'invoice_paid', 'workflow_step_completed', 'contact_updated', 'task_dependency_completed', 'manual_confirmation') NOT NULL,
    entity_type ENUM('communication', 'deal', 'invoice', 'workflow_execution', 'task', 'contact') NOT NULL,
    entity_id INT NOT NULL,
    confidence_score DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
    evidence_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_task_evidence_task (task_id),
    INDEX idx_ai_task_evidence_lookup (entity_type, entity_id),
    CONSTRAINT fk_ai_task_evidence_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_guidance_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    surface ENUM('coach', 'clarity_chat', 'task_automation', 'assistant') NOT NULL,
    mode VARCHAR(32) NOT NULL,
    decision ENUM('allow', 'allow_with_warning', 'suggest_only', 'blocked') NOT NULL,
    confidence_score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    context_quality_score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    goal_relevance_score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    policy_snapshot_json JSON DEFAULT NULL,
    input_snapshot_json JSON DEFAULT NULL,
    output_snapshot_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_guidance_user_surface (user_id, surface),
    INDEX idx_ai_guidance_created (created_at),
    CONSTRAINT fk_ai_guidance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tasks
    ADD COLUMN metadata_json JSON DEFAULT NULL AFTER description;
