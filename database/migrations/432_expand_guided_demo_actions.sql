CREATE TABLE IF NOT EXISTS guided_demo_action_runs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    session_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    step_key VARCHAR(80) NOT NULL,
    action_key VARCHAR(80) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    idempotency_key CHAR(40) NOT NULL,
    result_metadata_json JSON NULL,
    created_records_json JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_guided_demo_action_runs_idempotency (idempotency_key),
    UNIQUE KEY uq_guided_demo_action_runs_session_action (session_id, action_key),
    KEY idx_guided_demo_action_runs_workspace (workspace_id, created_at),
    KEY idx_guided_demo_action_runs_step (session_id, step_key),
    CONSTRAINT fk_guided_demo_action_runs_session FOREIGN KEY (session_id) REFERENCES guided_demo_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_guided_demo_action_runs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_guided_demo_action_runs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE guided_demo_events MODIFY event_type VARCHAR(80) NOT NULL;
