CREATE TABLE IF NOT EXISTS commercial_automation_config (
    id INT PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode VARCHAR(20) NOT NULL DEFAULT 'auto_safe',
    stage_entry_enabled TINYINT(1) NOT NULL DEFAULT 1,
    negotiation_revisions_enabled TINYINT(1) NOT NULL DEFAULT 1,
    auto_send_enabled TINYINT(1) NOT NULL DEFAULT 1,
    auto_convert_on_won_enabled TINYINT(1) NOT NULL DEFAULT 0,
    auto_mark_overdue_enabled TINYINT(1) NOT NULL DEFAULT 1,
    followup_reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
    approval_mode VARCHAR(32) NOT NULL DEFAULT 'threshold_only',
    send_delay_minutes INT NOT NULL DEFAULT 0,
    max_auto_discount_percent DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    max_auto_total_change_percent DECIMAL(10,2) NOT NULL DEFAULT 25.00,
    max_revision_count_before_approval INT NOT NULL DEFAULT 2,
    require_recipient_for_send TINYINT(1) NOT NULL DEFAULT 1,
    require_nonzero_total_for_send TINYINT(1) NOT NULL DEFAULT 1,
    require_billing_identity_for_final_invoice TINYINT(1) NOT NULL DEFAULT 1,
    auto_convert_requires_status VARCHAR(32) NOT NULL DEFAULT 'accepted',
    negotiation_stale_hours INT NOT NULL DEFAULT 48,
    proposal_followup_hours INT NOT NULL DEFAULT 24,
    delivery_retry_limit INT NOT NULL DEFAULT 2,
    delivery_retry_backoff_minutes INT NOT NULL DEFAULT 30,
    task_owner_mode VARCHAR(32) NOT NULL DEFAULT 'deal_owner',
    config_json JSON NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO commercial_automation_config (
    id, enabled, mode, stage_entry_enabled, negotiation_revisions_enabled, auto_send_enabled,
    auto_convert_on_won_enabled, auto_mark_overdue_enabled, followup_reminders_enabled,
    approval_mode, send_delay_minutes, max_auto_discount_percent, max_auto_total_change_percent,
    max_revision_count_before_approval, require_recipient_for_send, require_nonzero_total_for_send,
    require_billing_identity_for_final_invoice, auto_convert_requires_status, negotiation_stale_hours,
    proposal_followup_hours, delivery_retry_limit, delivery_retry_backoff_minutes, task_owner_mode, config_json
) VALUES (
    1, 0, 'auto_safe', 1, 1, 1,
    0, 1, 1,
    'threshold_only', 0, 20.00, 25.00,
    2, 1, 1,
    1, 'accepted', 48,
    24, 2, 30, 'deal_owner',
    JSON_OBJECT(
        'default_document_by_stage', JSON_OBJECT(
            'proposal', 'quote',
            'negotiation', 'quote',
            'closed_won', 'invoice'
        ),
        'send_channels', JSON_OBJECT(
            'email', TRUE,
            'whatsapp', TRUE
        ),
        'schema_version', 1
    )
)
ON DUPLICATE KEY UPDATE
    updated_at = CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS commercial_automation_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deal_id INT NULL,
    contact_id INT NULL,
    invoice_id INT NULL,
    trigger_type VARCHAR(32) NOT NULL,
    trigger_ref_id INT NULL,
    decision VARCHAR(32) NOT NULL,
    action_plan_json JSON NULL,
    evidence_json JSON NULL,
    policy_snapshot_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_commercial_automation_runs_deal (deal_id),
    INDEX idx_commercial_automation_runs_invoice (invoice_id),
    INDEX idx_commercial_automation_runs_trigger (trigger_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commercial_automation_approvals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deal_id INT NULL,
    invoice_id INT NULL,
    action_key VARCHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reason VARCHAR(255) NULL,
    requested_by_type VARCHAR(20) NOT NULL DEFAULT 'system',
    requested_by_id INT NULL,
    resolved_by INT NULL,
    resolved_at DATETIME NULL,
    payload_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_commercial_automation_approvals_status (status, created_at),
    INDEX idx_commercial_automation_approvals_deal (deal_id),
    INDEX idx_commercial_automation_approvals_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commercial_automation_idempotency (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scope_key VARCHAR(120) NOT NULL,
    action_key VARCHAR(64) NOT NULL,
    fingerprint VARCHAR(190) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_commercial_automation_idempotency (scope_key, action_key, fingerprint),
    INDEX idx_commercial_automation_idempotency_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('settings.commercial_automation', 'Commercial Automation Settings', 'Manage commercial automation settings', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'settings.commercial_automation'
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
