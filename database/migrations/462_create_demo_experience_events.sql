-- Timed protected demo experience schedule state.

CREATE TABLE IF NOT EXISTS demo_experience_events (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    demo_session_id BIGINT NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    status ENUM('pending', 'sent', 'skipped', 'paused') NOT NULL DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    payload_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demo_experience_session_event (demo_session_id, event_key),
    KEY idx_demo_experience_due (workspace_id, demo_session_id, status, scheduled_at),
    KEY idx_demo_experience_type (event_type, status, scheduled_at),
    CONSTRAINT fk_demo_experience_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_demo_experience_session FOREIGN KEY (demo_session_id) REFERENCES demo_visitor_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
