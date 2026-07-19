-- Restore the workflow queue when an older installation recorded migration 062
-- but the table was later lost, and add recoverable worker claims.

CREATE TABLE IF NOT EXISTS workflow_queue (
    id INT NOT NULL AUTO_INCREMENT,
    workspace_id INT NULL,
    workflow_id INT NOT NULL,
    contact_id INT NOT NULL,
    event_data JSON NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    claim_token CHAR(36) NULL,
    claimed_at DATETIME NULL,
    lease_expires_at DATETIME NULL,
    worker_id VARCHAR(191) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_workflow_queue_claimable (status, lease_expires_at, created_at, id),
    KEY idx_workflow_queue_workspace_status (workspace_id, status, created_at),
    KEY idx_workflow_queue_workflow (workflow_id),
    CONSTRAINT fk_workflow_queue_workflow FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_workflow_queue_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE workflow_queue ADD COLUMN workspace_id INT NULL AFTER id;
ALTER TABLE workflow_queue ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE workflow_queue ADD COLUMN max_attempts INT NOT NULL DEFAULT 3 AFTER attempts;
ALTER TABLE workflow_queue ADD COLUMN claim_token CHAR(36) NULL AFTER max_attempts;
ALTER TABLE workflow_queue ADD COLUMN claimed_at DATETIME NULL AFTER claim_token;
ALTER TABLE workflow_queue ADD COLUMN lease_expires_at DATETIME NULL AFTER claimed_at;
ALTER TABLE workflow_queue ADD COLUMN worker_id VARCHAR(191) NULL AFTER lease_expires_at;
ALTER TABLE workflow_queue ADD INDEX idx_workflow_queue_claimable (status, lease_expires_at, created_at, id);
ALTER TABLE workflow_queue ADD INDEX idx_workflow_queue_workspace_status (workspace_id, status, created_at);
