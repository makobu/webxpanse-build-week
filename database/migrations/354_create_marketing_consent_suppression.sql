-- Marketing Phase 65: consent and suppression safety for manual exports.

CREATE TABLE IF NOT EXISTS marketing_consent_policies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    policy_name VARCHAR(180) NOT NULL,
    channel ENUM('email','whatsapp','sms','ads','social','all','other') NOT NULL DEFAULT 'email',
    consent_basis ENUM('explicit_opt_in','legitimate_interest','existing_customer','manual_review','unknown') NOT NULL DEFAULT 'manual_review',
    requires_unsubscribe TINYINT(1) NOT NULL DEFAULT 1,
    requires_suppression_check TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_consent_policies_uuid (uuid),
    KEY idx_marketing_consent_workspace_channel (workspace_id, channel, status),
    KEY idx_marketing_consent_workspace_owner (workspace_id, owner_user_id, status),
    CONSTRAINT fk_marketing_consent_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_consent_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_consent_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_suppression_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    channel ENUM('email','whatsapp','sms','ads','social','all','other') NOT NULL DEFAULT 'email',
    identifier VARCHAR(255) NOT NULL,
    identifier_hash CHAR(64) NOT NULL,
    reason VARCHAR(180) NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    source VARCHAR(120) NULL,
    metadata_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_marketing_suppression_workspace_hash (workspace_id, channel, identifier_hash),
    UNIQUE KEY uniq_marketing_suppression_uuid (uuid),
    KEY idx_marketing_suppression_workspace_status (workspace_id, status, channel),
    CONSTRAINT fk_marketing_suppression_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_suppression_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketing_email_campaign_runs
    ADD COLUMN IF NOT EXISTS consent_policy_id INT NULL AFTER audience_segment_id,
    ADD COLUMN IF NOT EXISTS consent_status ENUM('not_reviewed','approved','needs_review','blocked') NOT NULL DEFAULT 'not_reviewed' AFTER consent_policy_id,
    ADD COLUMN IF NOT EXISTS suppression_list_checked_at DATETIME NULL AFTER suppression_notes,
    ADD COLUMN IF NOT EXISTS safety_warnings_json JSON NULL AFTER readiness_warnings_json,
    ADD INDEX IF NOT EXISTS idx_marketing_email_runs_consent_policy (workspace_id, consent_policy_id),
    ADD CONSTRAINT fk_marketing_email_runs_consent_policy FOREIGN KEY (consent_policy_id) REFERENCES marketing_consent_policies(id) ON DELETE SET NULL;

ALTER TABLE marketing_channel_export_bundles
    ADD COLUMN IF NOT EXISTS consent_status ENUM('not_reviewed','approved','needs_review','blocked') NOT NULL DEFAULT 'not_reviewed' AFTER status,
    ADD COLUMN IF NOT EXISTS safety_warnings_json JSON NULL AFTER required_fields_json;
