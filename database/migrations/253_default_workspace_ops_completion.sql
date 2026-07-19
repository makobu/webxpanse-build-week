-- Complete default workspace Platform Ops automation primitives.
-- This migration is intentionally idempotent.

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 AND slug = 'default' LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1),
    1
);

CREATE TABLE IF NOT EXISTS default_workspace_owner_contact_sync_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    default_workspace_id INT NOT NULL,
    actor_user_id INT NULL,
    run_status ENUM('running','completed','completed_with_errors','failed') NOT NULL DEFAULT 'running',
    reason VARCHAR(255) NULL,
    scanned INT NOT NULL DEFAULT 0,
    created_count INT NOT NULL DEFAULT 0,
    updated_count INT NOT NULL DEFAULT 0,
    marked_inactive_count INT NOT NULL DEFAULT 0,
    skipped_default_workspace_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    warnings_json JSON NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_default_owner_sync_runs_workspace (default_workspace_id, started_at),
    KEY idx_default_owner_sync_runs_status (run_status, started_at),
    CONSTRAINT fk_default_owner_sync_runs_workspace FOREIGN KEY (default_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_sync_runs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS default_workspace_owner_contact_sync_errors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sync_run_id INT NULL,
    default_workspace_id INT NOT NULL,
    owner_workspace_id INT NULL,
    owner_user_id INT NULL,
    error_type VARCHAR(80) NOT NULL DEFAULT 'sync_failed',
    error_message VARCHAR(500) NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_default_owner_sync_errors_run (sync_run_id),
    KEY idx_default_owner_sync_errors_workspace (default_workspace_id, created_at),
    KEY idx_default_owner_sync_errors_owner (owner_workspace_id, owner_user_id),
    CONSTRAINT fk_default_owner_sync_errors_run FOREIGN KEY (sync_run_id) REFERENCES default_workspace_owner_contact_sync_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_owner_sync_errors_default_workspace FOREIGN KEY (default_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_sync_errors_owner_workspace FOREIGN KEY (owner_workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_owner_sync_errors_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS default_workspace_owner_contacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    default_workspace_id INT NOT NULL,
    owner_workspace_id INT NOT NULL,
    owner_user_id INT NOT NULL,
    contact_id INT NOT NULL,
    relationship_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    customer_state ENUM('qualified_workspace_lead', 'current_paying_customer', 'ineligible') NOT NULL DEFAULT 'qualified_workspace_lead',
    last_synced_at DATETIME NULL,
    last_synced_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_default_owner_workspace_user (owner_workspace_id, owner_user_id),
    KEY idx_default_owner_contact (contact_id),
    KEY idx_default_owner_user (owner_user_id),
    KEY idx_default_owner_state (default_workspace_id, relationship_status, customer_state),
    CONSTRAINT fk_default_owner_contacts_default_workspace FOREIGN KEY (default_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_owner_workspace FOREIGN KEY (owner_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_owner_contacts_synced_by FOREIGN KEY (last_synced_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE default_workspace_owner_contacts
    ADD COLUMN IF NOT EXISTS sync_status ENUM('synced','inactive','failed','stale') NOT NULL DEFAULT 'synced' AFTER customer_state,
    ADD COLUMN IF NOT EXISTS inactive_reason VARCHAR(120) NULL AFTER sync_status,
    ADD COLUMN IF NOT EXISTS last_sync_error VARCHAR(500) NULL AFTER inactive_reason,
    ADD COLUMN IF NOT EXISTS last_reconciled_at DATETIME NULL AFTER last_sync_error;

CREATE TABLE IF NOT EXISTS default_workspace_ops_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    default_workspace_id INT NOT NULL,
    owner_workspace_id INT NULL,
    owner_user_id INT NULL,
    contact_id INT NULL,
    signal_type VARCHAR(80) NOT NULL,
    signal_fingerprint VARCHAR(191) NOT NULL,
    active_signal_key VARCHAR(191) NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    status ENUM('open','in_progress','waiting_on_owner','waiting_on_provider','resolved','dismissed') NOT NULL DEFAULT 'open',
    assigned_user_id INT NULL,
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_at DATETIME NULL,
    resolved_at DATETIME NULL,
    resolution_summary VARCHAR(500) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_default_ops_active_signal (default_workspace_id, active_signal_key),
    KEY idx_default_ops_events_workspace_status (default_workspace_id, status, severity, priority),
    KEY idx_default_ops_events_owner_workspace (owner_workspace_id, status),
    KEY idx_default_ops_events_signal (signal_type, last_seen_at),
    KEY idx_default_ops_events_assigned (assigned_user_id, status),
    CONSTRAINT fk_default_ops_events_default_workspace FOREIGN KEY (default_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_ops_events_owner_workspace FOREIGN KEY (owner_workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_events_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_events_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_events_assigned FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO default_workspace_ops_events (
    default_workspace_id,
    signal_type,
    signal_fingerprint,
    active_signal_key,
    severity,
    priority,
    status,
    metadata_json
)
SELECT
    @default_workspace_id,
    'missing_platform_ops_asset',
    CONCAT('missing_platform_ops_asset:', missing.missing_key),
    CONCAT('missing_platform_ops_asset:', missing.missing_key),
    'warning',
    'medium',
    'open',
    JSON_OBJECT('source', 'migration_253', 'missing_key', missing.missing_key)
FROM (
    SELECT 'operationalization_pending' AS missing_key
) missing
WHERE @default_workspace_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM default_workspace_ops_events existing
      WHERE existing.default_workspace_id = @default_workspace_id
        AND existing.active_signal_key = CONCAT('missing_platform_ops_asset:', missing.missing_key)
  );
