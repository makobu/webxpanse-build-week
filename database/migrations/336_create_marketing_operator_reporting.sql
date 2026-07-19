-- Marketing Phase 44: release hardening and operator reporting.

CREATE TABLE IF NOT EXISTS marketing_report_exports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    report_type ENUM('weekly','monthly','performance','budget','experiments','operator','custom') NOT NULL DEFAULT 'operator',
    report_format ENUM('json','csv','pdf_placeholder') NOT NULL DEFAULT 'json',
    status ENUM('draft','generated','archived') NOT NULL DEFAULT 'generated',
    period_start DATE NULL,
    period_end DATE NULL,
    payload_json JSON NULL,
    metadata_json JSON NULL,
    exported_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_report_exports_uuid (uuid),
    KEY idx_marketing_report_exports_workspace_type (workspace_id, report_type, created_at),
    KEY idx_marketing_report_exports_workspace_status (workspace_id, status, created_at),
    CONSTRAINT fk_marketing_report_exports_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_report_exports_exporter FOREIGN KEY (exported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_scheduled_report_drafts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(180) NOT NULL,
    report_type ENUM('weekly','monthly','performance','budget','experiments','operator','custom') NOT NULL DEFAULT 'weekly',
    cadence ENUM('weekly','monthly','quarterly') NOT NULL DEFAULT 'weekly',
    next_run_at DATETIME NULL,
    recipients_json JSON NULL,
    filters_json JSON NULL,
    status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_scheduled_report_drafts_uuid (uuid),
    KEY idx_marketing_scheduled_report_drafts_workspace_status (workspace_id, status, next_run_at),
    CONSTRAINT fk_marketing_scheduled_report_drafts_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_scheduled_report_drafts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_operator_readiness_checks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    check_key VARCHAR(120) NOT NULL,
    label VARCHAR(180) NOT NULL,
    status ENUM('ready','warning','blocked') NOT NULL DEFAULT 'warning',
    message TEXT NULL,
    metadata_json JSON NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_operator_readiness_workspace_key (workspace_id, check_key),
    KEY idx_marketing_operator_readiness_workspace_status (workspace_id, status, checked_at),
    CONSTRAINT fk_marketing_operator_readiness_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
