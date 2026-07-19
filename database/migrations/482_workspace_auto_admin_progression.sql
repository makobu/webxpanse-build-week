-- Workspace-aware Auto Admin progression and scoped automation config.

CREATE TABLE IF NOT EXISTS workspace_auto_admin_settings (
    workspace_id INT NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    manual_freeze TINYINT(1) NOT NULL DEFAULT 0,
    freeze_reason VARCHAR(500) NULL,
    managed_tabs_json JSON NULL,
    target_modes_json JSON NULL,
    effective_modes_json JSON NULL,
    readiness_snapshot_json JSON NULL,
    managed_defaults_version INT NOT NULL DEFAULT 1,
    last_evaluated_at DATETIME NULL,
    last_applied_at DATETIME NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_workspace_auto_admin_enabled (enabled, manual_freeze),
    KEY idx_workspace_auto_admin_updated_by (updated_by_user_id),
    CONSTRAINT fk_workspace_auto_admin_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workspace_auto_admin_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_auto_admin_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    reason VARCHAR(500) NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_workspace_auto_admin_events_workspace_created (workspace_id, created_at),
    KEY idx_workspace_auto_admin_events_type (event_type, created_at),
    CONSTRAINT fk_workspace_auto_admin_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workspace_auto_admin_events_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_auto_admin_settings (
    workspace_id,
    enabled,
    managed_tabs_json,
    target_modes_json,
    effective_modes_json,
    readiness_snapshot_json,
    managed_defaults_version
)
SELECT
    w.id,
    COALESCE(paas.enabled, 0),
    JSON_ARRAY('ai', 'ai_autoresponder', 'commercial_automation', 'deal_automation', 'workflow_automation'),
    JSON_OBJECT(
        'deal_automation', 'suggest_only',
        'workflow_automation', 'auto_safe',
        'ai_autoresponder', 'draft_only',
        'commercial_automation', 'auto_safe'
    ),
    JSON_OBJECT(),
    JSON_OBJECT(),
    1
FROM workspaces w
LEFT JOIN platform_auto_admin_settings paas ON paas.id = 1
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    enabled = VALUES(enabled),
    managed_tabs_json = COALESCE(workspace_auto_admin_settings.managed_tabs_json, VALUES(managed_tabs_json)),
    target_modes_json = COALESCE(workspace_auto_admin_settings.target_modes_json, VALUES(target_modes_json)),
    effective_modes_json = COALESCE(workspace_auto_admin_settings.effective_modes_json, VALUES(effective_modes_json)),
    readiness_snapshot_json = COALESCE(workspace_auto_admin_settings.readiness_snapshot_json, VALUES(readiness_snapshot_json));

CREATE TABLE IF NOT EXISTS workspace_deal_automation_config (
    workspace_id INT NOT NULL PRIMARY KEY,
    enabled TINYINT(1) DEFAULT 0,
    mode VARCHAR(20) DEFAULT 'suggest_only',
    min_confidence DECIMAL(3,2) DEFAULT 0.85,
    lookback_days INT DEFAULT 14,
    cooldown_hours INT DEFAULT 24,
    require_approval_terminal TINYINT(1) DEFAULT 1,
    min_terminal_confidence DECIMAL(3,2) DEFAULT 0.92,
    inactivity_days_for_loss INT DEFAULT 14,
    allow_multi_stage_jump TINYINT(1) DEFAULT 0,
    dry_run TINYINT(1) DEFAULT 0,
    reopen_lost_on_reengagement TINYINT(1) DEFAULT 0,
    config_json JSON NULL,
    schema_version INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workspace_deal_automation_config_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_deal_automation_config (
    workspace_id, enabled, mode, min_confidence, lookback_days, cooldown_hours,
    require_approval_terminal, min_terminal_confidence, inactivity_days_for_loss,
    allow_multi_stage_jump, dry_run, reopen_lost_on_reengagement, config_json, schema_version
)
SELECT
    w.id,
    COALESCE(c.enabled, 0),
    COALESCE(c.mode, 'suggest_only'),
    COALESCE(c.min_confidence, 0.85),
    COALESCE(c.lookback_days, 14),
    COALESCE(c.cooldown_hours, 24),
    COALESCE(c.require_approval_terminal, 1),
    COALESCE(c.min_terminal_confidence, 0.92),
    COALESCE(c.inactivity_days_for_loss, 14),
    COALESCE(c.allow_multi_stage_jump, 0),
    COALESCE(c.dry_run, 0),
    COALESCE(c.reopen_lost_on_reengagement, 0),
    c.config_json,
    COALESCE(c.schema_version, 1)
FROM workspaces w
LEFT JOIN deal_automation_config c ON c.id = 1
WHERE 1 = 1
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);

CREATE TABLE IF NOT EXISTS workspace_ai_autoresponder_config (
    workspace_id INT NOT NULL PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    mode ENUM('off', 'draft_only', 'hybrid', 'full_auto') NOT NULL DEFAULT 'draft_only',
    default_confidence_threshold DECIMAL(4,3) NOT NULL DEFAULT 0.850,
    config_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workspace_ai_autoresponder_config_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_ai_autoresponder_config (
    workspace_id, enabled, mode, default_confidence_threshold, config_json
)
SELECT
    w.id,
    COALESCE(c.enabled, 0),
    COALESCE(c.mode, 'draft_only'),
    COALESCE(c.default_confidence_threshold, 0.850),
    c.config_json
FROM workspaces w
LEFT JOIN ai_autoresponder_config c ON c.id = 1
WHERE 1 = 1
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);

CREATE TABLE IF NOT EXISTS workspace_commercial_automation_config (
    workspace_id INT NOT NULL PRIMARY KEY,
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workspace_commercial_automation_config_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_commercial_automation_config (
    workspace_id, enabled, mode, stage_entry_enabled, negotiation_revisions_enabled,
    auto_send_enabled, auto_convert_on_won_enabled, auto_mark_overdue_enabled,
    followup_reminders_enabled, approval_mode, send_delay_minutes,
    max_auto_discount_percent, max_auto_total_change_percent,
    max_revision_count_before_approval, require_recipient_for_send,
    require_nonzero_total_for_send, require_billing_identity_for_final_invoice,
    auto_convert_requires_status, negotiation_stale_hours, proposal_followup_hours,
    delivery_retry_limit, delivery_retry_backoff_minutes, task_owner_mode, config_json
)
SELECT
    w.id,
    COALESCE(c.enabled, 0),
    COALESCE(c.mode, 'auto_safe'),
    COALESCE(c.stage_entry_enabled, 1),
    COALESCE(c.negotiation_revisions_enabled, 1),
    COALESCE(c.auto_send_enabled, 1),
    COALESCE(c.auto_convert_on_won_enabled, 0),
    COALESCE(c.auto_mark_overdue_enabled, 1),
    COALESCE(c.followup_reminders_enabled, 1),
    COALESCE(c.approval_mode, 'threshold_only'),
    COALESCE(c.send_delay_minutes, 0),
    COALESCE(c.max_auto_discount_percent, 20.00),
    COALESCE(c.max_auto_total_change_percent, 25.00),
    COALESCE(c.max_revision_count_before_approval, 2),
    COALESCE(c.require_recipient_for_send, 1),
    COALESCE(c.require_nonzero_total_for_send, 1),
    COALESCE(c.require_billing_identity_for_final_invoice, 1),
    COALESCE(c.auto_convert_requires_status, 'accepted'),
    COALESCE(c.negotiation_stale_hours, 48),
    COALESCE(c.proposal_followup_hours, 24),
    COALESCE(c.delivery_retry_limit, 2),
    COALESCE(c.delivery_retry_backoff_minutes, 30),
    COALESCE(c.task_owner_mode, 'deal_owner'),
    c.config_json
FROM workspaces w
LEFT JOIN commercial_automation_config c ON c.id = 1
WHERE 1 = 1
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);

CREATE TABLE IF NOT EXISTS workspace_cold_outreach_warmup_config (
    workspace_id INT NOT NULL,
    channel VARCHAR(20) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    initial_daily_cold_limit INT NOT NULL DEFAULT 10,
    current_daily_cold_limit INT NOT NULL DEFAULT 10,
    auto_admin_warmup_enabled TINYINT(1) NOT NULL DEFAULT 0,
    weekly_increment INT NOT NULL DEFAULT 5,
    max_limit INT NOT NULL DEFAULT 50,
    last_auto_adjusted_at DATETIME NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id, channel),
    KEY idx_workspace_cold_outreach_channel (channel, enabled),
    CONSTRAINT fk_workspace_cold_outreach_warmup_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_workspace_cold_outreach_channel CHECK (channel IN ('email', 'whatsapp'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_cold_outreach_warmup_config (
    workspace_id, channel, enabled, initial_daily_cold_limit, current_daily_cold_limit,
    auto_admin_warmup_enabled, weekly_increment, max_limit, last_auto_adjusted_at, updated_by
)
SELECT
    w.id,
    channels.channel,
    COALESCE(c.enabled, 0),
    COALESCE(c.initial_daily_cold_limit, 10),
    COALESCE(c.current_daily_cold_limit, 10),
    COALESCE(c.auto_admin_warmup_enabled, 0),
    COALESCE(c.weekly_increment, 5),
    COALESCE(c.max_limit, 50),
    c.last_auto_adjusted_at,
    c.updated_by
FROM workspaces w
JOIN (
    SELECT 'email' AS channel
    UNION ALL SELECT 'whatsapp'
) channels
LEFT JOIN cold_outreach_warmup_config c ON c.channel = channels.channel
WHERE 1 = 1
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);

ALTER TABLE deal_automation_audit
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id,
    ADD KEY IF NOT EXISTS idx_deal_automation_audit_workspace_created (workspace_id, created_at),
    ADD KEY IF NOT EXISTS idx_deal_automation_audit_workspace_deal (workspace_id, deal_id);

SET @default_workspace_id := COALESCE((SELECT MIN(id) FROM workspaces), 1);

UPDATE deal_automation_audit daa
LEFT JOIN deals d ON d.id = daa.deal_id
LEFT JOIN contacts c ON c.id = daa.contact_id
SET daa.workspace_id = COALESCE(
    NULLIF(daa.workspace_id, 0),
    d.workspace_id,
    c.workspace_id,
    @default_workspace_id
);
