-- Marketing Phase 104: live SMS and WhatsApp queue handoff ledger.
-- Approved live executions can now hand SMS/WhatsApp messages to existing CRM queues.

CREATE TABLE IF NOT EXISTS marketing_live_channel_handoffs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    queue_id INT NOT NULL,
    connector_id INT NULL,
    execution_type ENUM('sms','whatsapp') NOT NULL,
    contact_id INT NULL,
    recipient_identifier VARCHAR(255) NOT NULL,
    source_table VARCHAR(64) NOT NULL,
    source_message_id INT NULL,
    source_queue_id INT NULL,
    status ENUM('queued','processing','sent','failed','blocked','cancelled') NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_channel_handoffs_uuid (uuid),
    KEY idx_marketing_live_channel_handoffs_workspace_queue (workspace_id, queue_id, status),
    KEY idx_marketing_live_channel_handoffs_workspace_type (workspace_id, execution_type, status),
    KEY idx_marketing_live_channel_handoffs_workspace_source (workspace_id, source_table, source_message_id),
    KEY idx_marketing_live_channel_handoffs_workspace_created (workspace_id, created_at),
    CONSTRAINT fk_marketing_live_channel_handoffs_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_channel_handoffs_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_channel_handoffs_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_channel_handoffs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_live_channel_handoffs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
