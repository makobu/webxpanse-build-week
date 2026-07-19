-- Workspace-scoped acknowledgement state for mobile Decision Center signals.

CREATE TABLE IF NOT EXISTS mobile_decision_acknowledgements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    signal_key CHAR(64) NOT NULL,
    acknowledged_by_user_id INT NULL,
    note VARCHAR(1000) NULL,
    acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mobile_decision_ack_signal (workspace_id, entity_type, entity_id, signal_key),
    KEY idx_mobile_decision_ack_entity (workspace_id, entity_type, entity_id, acknowledged_at),
    KEY idx_mobile_decision_ack_user (acknowledged_by_user_id),
    CONSTRAINT fk_mobile_decision_ack_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_mobile_decision_ack_user
        FOREIGN KEY (acknowledged_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
