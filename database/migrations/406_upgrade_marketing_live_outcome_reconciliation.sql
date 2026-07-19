-- Marketing Phase 51: live outcome ingestion and reconciliation.
-- Reconcile provider/CRM outcomes idempotently without exposing raw provider payloads.

ALTER TABLE marketing_execution_queue
    MODIFY COLUMN live_outcome_status ENUM('not_started','queued','processing','sent','delivered','opened','clicked','replied','bounced','partially_delivered','failed','blocked','cancelled','unknown') NOT NULL DEFAULT 'not_started',
    ADD COLUMN IF NOT EXISTS live_outcome_reconciled_at DATETIME NULL AFTER live_outcome_checked_at,
    ADD COLUMN IF NOT EXISTS live_outcome_reconciliation_key CHAR(64) NULL AFTER live_outcome_reconciled_at,
    ADD COLUMN IF NOT EXISTS live_outcome_reconciliation_json JSON NULL AFTER live_outcome_reconciliation_key,
    ADD INDEX IF NOT EXISTS idx_marketing_execution_queue_reconciliation (workspace_id, live_outcome_reconciliation_key, live_outcome_reconciled_at);

CREATE TABLE IF NOT EXISTS marketing_live_outcome_reconciliation_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    queue_id INT NOT NULL,
    connector_id INT NULL,
    adapter_key VARCHAR(120) NULL,
    normalized_status ENUM('queued','processing','sent','delivered','opened','clicked','replied','bounced','failed','blocked','cancelled','unknown') NOT NULL DEFAULT 'unknown',
    queue_outcome_status VARCHAR(60) NULL,
    source_type ENUM('email','sms','whatsapp','webhook','landing_page','queue','other') NOT NULL DEFAULT 'other',
    source_reference VARCHAR(255) NULL,
    reconciliation_key CHAR(64) NOT NULL,
    evidence_json JSON NULL,
    reconciled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_live_outcome_reconciliation_key (workspace_id, reconciliation_key),
    UNIQUE KEY uniq_marketing_live_outcome_reconciliation_uuid (uuid),
    KEY idx_marketing_live_outcome_reconciliation_queue (workspace_id, queue_id, reconciled_at),
    KEY idx_marketing_live_outcome_reconciliation_status (workspace_id, normalized_status, reconciled_at),
    CONSTRAINT fk_marketing_live_outcome_reconciliation_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_outcome_reconciliation_queue FOREIGN KEY (queue_id) REFERENCES marketing_execution_queue(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_live_outcome_reconciliation_connector FOREIGN KEY (connector_id) REFERENCES marketing_channel_connectors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
