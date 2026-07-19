-- Marketing live execution: SMS/WhatsApp channel delivery proof snapshots.
-- Records downstream CRM queue/message evidence without calling external providers.

CREATE TABLE IF NOT EXISTS marketing_live_channel_delivery_proofs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    handoff_id INT NOT NULL,
    queue_id INT NOT NULL,
    connector_id INT NULL,
    execution_type ENUM('sms','whatsapp') NOT NULL,
    source_table VARCHAR(80) NOT NULL,
    source_message_id INT NOT NULL,
    source_queue_id INT NOT NULL,
    proof_status ENUM('queued','processing','sent','delivered','read','failed','blocked','cancelled','undelivered','unknown') NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(255) NULL,
    recipient_identifier VARCHAR(255) NULL,
    evidence_json JSON NULL,
    error_message VARCHAR(500) NULL,
    proved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_channel_delivery_uuid (uuid),
    UNIQUE KEY uniq_marketing_live_channel_delivery_status (workspace_id, handoff_id, proof_status),
    KEY idx_marketing_live_channel_delivery_workspace_status (workspace_id, execution_type, proof_status, proved_at),
    KEY idx_marketing_live_channel_delivery_handoff (workspace_id, handoff_id, proved_at),
    KEY idx_marketing_live_channel_delivery_queue (workspace_id, queue_id, source_queue_id),
    CONSTRAINT fk_marketing_live_channel_delivery_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_channel_delivery_handoff FOREIGN KEY (handoff_id) REFERENCES marketing_live_channel_handoffs(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_channel_delivery_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_channel_delivery_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
