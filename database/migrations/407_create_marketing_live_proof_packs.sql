-- Marketing Phase 52: live execution proof packs.
-- Proof packs are sanitized evidence bundles for manager/admin reporting.

CREATE TABLE IF NOT EXISTS marketing_live_proof_packs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    queue_id INT NOT NULL,
    connector_id INT NULL,
    title VARCHAR(190) NOT NULL,
    status ENUM('draft','ready','archived') NOT NULL DEFAULT 'ready',
    proof_status ENUM('complete','partial','blocked','failed','unknown') NOT NULL DEFAULT 'unknown',
    normalized_outcome_status VARCHAR(60) NULL,
    evidence_json JSON NULL,
    summary_json JSON NULL,
    generated_by INT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_proof_packs_uuid (uuid),
    KEY idx_marketing_live_proof_packs_queue (workspace_id, queue_id, generated_at),
    KEY idx_marketing_live_proof_packs_status (workspace_id, proof_status, generated_at),
    CONSTRAINT fk_marketing_live_proof_packs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_proof_packs_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_proof_packs_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_proof_packs_generator FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
