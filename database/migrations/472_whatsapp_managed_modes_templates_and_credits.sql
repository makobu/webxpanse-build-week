-- WhatsApp dual connection modes, managed credits, template center, and consent guardrails.

ALTER TABLE workspace_whatsapp_integrations
    ADD COLUMN IF NOT EXISTS connection_mode ENUM('self_managed','platform_managed') NOT NULL DEFAULT 'self_managed' AFTER connected_by_user_id,
    ADD COLUMN IF NOT EXISTS managed_status VARCHAR(50) NULL AFTER connection_status,
    ADD COLUMN IF NOT EXISTS managed_billing_status VARCHAR(50) NULL AFTER managed_status,
    ADD COLUMN IF NOT EXISTS managed_credit_balance DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER managed_billing_status,
    ADD COLUMN IF NOT EXISTS managed_credit_reserved DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER managed_credit_balance,
    ADD COLUMN IF NOT EXISTS managed_currency VARCHAR(8) NOT NULL DEFAULT 'KES' AFTER managed_credit_reserved,
    ADD COLUMN IF NOT EXISTS managed_low_balance_threshold DECIMAL(14,4) NOT NULL DEFAULT 100 AFTER managed_currency,
    ADD COLUMN IF NOT EXISTS managed_daily_spend_cap DECIMAL(14,4) NULL AFTER managed_low_balance_threshold,
    ADD COLUMN IF NOT EXISTS managed_monthly_spend_cap DECIMAL(14,4) NULL AFTER managed_daily_spend_cap,
    ADD COLUMN IF NOT EXISTS managed_provider_reference VARCHAR(191) NULL AFTER managed_monthly_spend_cap,
    ADD COLUMN IF NOT EXISTS managed_provider_metadata_json JSON NULL AFTER managed_provider_reference,
    ADD COLUMN IF NOT EXISTS managed_last_health_at DATETIME NULL AFTER managed_provider_metadata_json,
    ADD COLUMN IF NOT EXISTS managed_last_health_status VARCHAR(50) NULL AFTER managed_last_health_at,
    ADD COLUMN IF NOT EXISTS managed_last_health_error TEXT NULL AFTER managed_last_health_status,
    ADD COLUMN IF NOT EXISTS mode_switch_requested_at DATETIME NULL AFTER managed_last_health_error,
    ADD COLUMN IF NOT EXISTS mode_switch_notes TEXT NULL AFTER mode_switch_requested_at;

UPDATE workspace_whatsapp_integrations
SET connection_mode = COALESCE(connection_mode, 'self_managed'),
    managed_status = COALESCE(managed_status, CASE WHEN connection_mode = 'platform_managed' THEN 'needs_review' ELSE 'not_applicable' END),
    managed_billing_status = COALESCE(managed_billing_status, CASE WHEN connection_mode = 'platform_managed' THEN 'inactive' ELSE 'not_applicable' END)
WHERE connection_mode IS NULL
   OR managed_status IS NULL
   OR managed_billing_status IS NULL;

CREATE INDEX IF NOT EXISTS idx_workspace_whatsapp_mode_status
    ON workspace_whatsapp_integrations (connection_mode, connection_status, managed_status);

CREATE TABLE IF NOT EXISTS workspace_whatsapp_credit_wallets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    integration_id INT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'KES',
    credit_balance DECIMAL(14,4) NOT NULL DEFAULT 0,
    reserved_credits DECIMAL(14,4) NOT NULL DEFAULT 0,
    lifetime_credited DECIMAL(14,4) NOT NULL DEFAULT 0,
    lifetime_debited DECIMAL(14,4) NOT NULL DEFAULT 0,
    low_balance_threshold DECIMAL(14,4) NOT NULL DEFAULT 100,
    auto_topup_enabled TINYINT(1) NOT NULL DEFAULT 0,
    auto_topup_threshold DECIMAL(14,4) NULL,
    auto_topup_amount DECIMAL(14,4) NULL,
    daily_spend_cap DECIMAL(14,4) NULL,
    monthly_spend_cap DECIMAL(14,4) NULL,
    billing_status ENUM('inactive','active','low_balance','depleted','suspended') NOT NULL DEFAULT 'inactive',
    last_activity_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_whatsapp_credit_wallet (workspace_id),
    KEY idx_whatsapp_credit_wallet_status (billing_status),
    CONSTRAINT fk_whatsapp_credit_wallet_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_credit_wallet_integration FOREIGN KEY (integration_id) REFERENCES workspace_whatsapp_integrations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_credit_ledger (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    wallet_id INT NOT NULL,
    user_id INT NULL,
    entry_type ENUM('credit','reserve','release','debit','adjustment','refund') NOT NULL,
    reference_type VARCHAR(64) NOT NULL,
    reference_id VARCHAR(191) NOT NULL,
    external_reference VARCHAR(191) NULL,
    credit_delta DECIMAL(14,4) NOT NULL,
    balance_after DECIMAL(14,4) NOT NULL,
    reserved_after DECIMAL(14,4) NOT NULL,
    status ENUM('pending','posted','released','failed','void') NOT NULL DEFAULT 'posted',
    description VARCHAR(255) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_whatsapp_credit_ledger_reference (workspace_id, reference_type, reference_id, entry_type),
    KEY idx_whatsapp_credit_ledger_wallet (wallet_id, created_at),
    KEY idx_whatsapp_credit_ledger_external (external_reference),
    CONSTRAINT fk_whatsapp_credit_ledger_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_credit_ledger_wallet FOREIGN KEY (wallet_id) REFERENCES workspace_whatsapp_credit_wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_credit_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_rate_cards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    market_code VARCHAR(8) NOT NULL,
    template_category ENUM('marketing','utility','authentication','service') NOT NULL,
    currency VARCHAR(8) NOT NULL DEFAULT 'KES',
    provider_unit_cost DECIMAL(14,6) NOT NULL DEFAULT 0,
    platform_unit_price DECIMAL(14,6) NOT NULL DEFAULT 0,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_whatsapp_rate_lookup (market_code, template_category, is_active, effective_from),
    UNIQUE KEY uniq_whatsapp_rate_period (market_code, template_category, currency, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_billable_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    wallet_id INT NULL,
    ledger_entry_id INT NULL,
    whatsapp_message_id VARCHAR(191) NOT NULL,
    whatsapp_message_row_id INT NULL,
    communication_id INT NULL,
    event_type ENUM('estimated','delivered','failed','refunded','adjusted') NOT NULL DEFAULT 'estimated',
    billable_status ENUM('estimated','billable','not_billable','refunded','failed') NOT NULL DEFAULT 'estimated',
    connection_mode ENUM('self_managed','platform_managed') NOT NULL DEFAULT 'self_managed',
    recipient_number VARCHAR(32) NULL,
    recipient_country VARCHAR(8) NULL,
    template_name VARCHAR(191) NULL,
    template_category ENUM('marketing','utility','authentication','service') NULL,
    estimated_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
    final_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
    currency VARCHAR(8) NOT NULL DEFAULT 'KES',
    provider_payload_json JSON NULL,
    occurred_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_whatsapp_billable_provider_event (workspace_id, whatsapp_message_id, event_type),
    KEY idx_whatsapp_billable_workspace (workspace_id, occurred_at),
    KEY idx_whatsapp_billable_status (billable_status, occurred_at),
    CONSTRAINT fk_whatsapp_billable_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_billable_wallet FOREIGN KEY (wallet_id) REFERENCES workspace_whatsapp_credit_wallets(id) ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_billable_ledger FOREIGN KEY (ledger_entry_id) REFERENCES workspace_whatsapp_credit_ledger(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    integration_id INT NULL,
    connection_mode ENUM('self_managed','platform_managed') NOT NULL DEFAULT 'self_managed',
    provider_template_id VARCHAR(191) NULL,
    template_name VARCHAR(191) NOT NULL,
    language_code VARCHAR(20) NOT NULL DEFAULT 'en_US',
    category ENUM('marketing','utility','authentication') NOT NULL DEFAULT 'utility',
    status ENUM('draft','submitted','pending','approved','rejected','paused','disabled','sync_failed') NOT NULL DEFAULT 'draft',
    header_type VARCHAR(40) NULL,
    header_text TEXT NULL,
    body_text TEXT NOT NULL,
    footer_text TEXT NULL,
    buttons_json JSON NULL,
    variables_json JSON NULL,
    sample_values_json JSON NULL,
    media_example_url TEXT NULL,
    rejection_reason TEXT NULL,
    last_submitted_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_whatsapp_template_locale (workspace_id, template_name, language_code),
    KEY idx_whatsapp_templates_status (workspace_id, status, category),
    KEY idx_whatsapp_templates_provider (provider_template_id),
    CONSTRAINT fk_whatsapp_templates_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_templates_integration FOREIGN KEY (integration_id) REFERENCES workspace_whatsapp_integrations(id) ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_templates_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_templates_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_template_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    workspace_id INT NOT NULL,
    version_number INT NOT NULL,
    status ENUM('draft','submitted','pending','approved','rejected','paused','disabled','sync_failed') NOT NULL DEFAULT 'draft',
    payload_json JSON NOT NULL,
    provider_response_json JSON NULL,
    submitted_at DATETIME NULL,
    synced_at DATETIME NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_whatsapp_template_version (template_id, version_number),
    KEY idx_whatsapp_template_versions_workspace (workspace_id, created_at),
    CONSTRAINT fk_whatsapp_template_versions_template FOREIGN KEY (template_id) REFERENCES workspace_whatsapp_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_template_versions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_template_versions_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_template_sync_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    integration_id INT NULL,
    provider_template_id VARCHAR(191) NULL,
    template_name VARCHAR(191) NOT NULL,
    language_code VARCHAR(20) NOT NULL DEFAULT 'en_US',
    provider_status VARCHAR(50) NOT NULL,
    provider_category VARCHAR(50) NULL,
    provider_payload_json JSON NULL,
    synced_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_whatsapp_template_sync_workspace (workspace_id, synced_at),
    KEY idx_whatsapp_template_sync_name (workspace_id, template_name, language_code),
    CONSTRAINT fk_whatsapp_template_sync_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_template_sync_integration FOREIGN KEY (integration_id) REFERENCES workspace_whatsapp_integrations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_template_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    template_id INT NOT NULL,
    version_id INT NULL,
    submitted_by_user_id INT NULL,
    connection_mode ENUM('self_managed','platform_managed') NOT NULL DEFAULT 'self_managed',
    provider_submission_id VARCHAR(191) NULL,
    submission_status ENUM('submitted','pending','approved','rejected','failed') NOT NULL DEFAULT 'submitted',
    request_payload_json JSON NULL,
    response_payload_json JSON NULL,
    rejection_reason TEXT NULL,
    submitted_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_whatsapp_template_submissions_workspace (workspace_id, submitted_at),
    KEY idx_whatsapp_template_submissions_status (submission_status, submitted_at),
    CONSTRAINT fk_whatsapp_template_submissions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_template_submissions_template FOREIGN KEY (template_id) REFERENCES workspace_whatsapp_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_template_submissions_version FOREIGN KEY (version_id) REFERENCES workspace_whatsapp_template_versions(id) ON DELETE SET NULL,
    CONSTRAINT fk_whatsapp_template_submissions_user FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS whatsapp_opt_in_status ENUM('unknown','opted_in','opted_out') NOT NULL DEFAULT 'unknown' AFTER phone,
    ADD COLUMN IF NOT EXISTS whatsapp_opt_in_source VARCHAR(100) NULL AFTER whatsapp_opt_in_status,
    ADD COLUMN IF NOT EXISTS whatsapp_opt_in_at DATETIME NULL AFTER whatsapp_opt_in_source,
    ADD COLUMN IF NOT EXISTS whatsapp_allowed_categories JSON NULL AFTER whatsapp_opt_in_at,
    ADD COLUMN IF NOT EXISTS whatsapp_opt_out_at DATETIME NULL AFTER whatsapp_allowed_categories,
    ADD COLUMN IF NOT EXISTS whatsapp_consent_evidence TEXT NULL AFTER whatsapp_opt_out_at;

CREATE TABLE IF NOT EXISTS workspace_whatsapp_suppression_list (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    reason VARCHAR(191) NULL,
    source VARCHAR(100) NOT NULL DEFAULT 'manual',
    suppressed_by_user_id INT NULL,
    suppressed_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_whatsapp_suppression_active (workspace_id, phone_number, released_at),
    KEY idx_whatsapp_suppression_workspace (workspace_id, suppressed_at),
    CONSTRAINT fk_whatsapp_suppression_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_whatsapp_suppression_user FOREIGN KEY (suppressed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE whatsapp_messages
    ADD COLUMN IF NOT EXISTS connection_mode ENUM('self_managed','platform_managed') NOT NULL DEFAULT 'self_managed' AFTER user_id,
    ADD COLUMN IF NOT EXISTS template_category ENUM('marketing','utility','authentication','service') NULL AFTER template_name,
    ADD COLUMN IF NOT EXISTS recipient_country VARCHAR(8) NULL AFTER to_number,
    ADD COLUMN IF NOT EXISTS estimated_cost DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER error_message,
    ADD COLUMN IF NOT EXISTS final_cost DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER estimated_cost,
    ADD COLUMN IF NOT EXISTS billable_event_id INT NULL AFTER final_cost,
    ADD COLUMN IF NOT EXISTS credit_reservation_ledger_id INT NULL AFTER billable_event_id,
    ADD COLUMN IF NOT EXISTS billing_status ENUM('not_applicable','estimated','reserved','billable','released','failed') NOT NULL DEFAULT 'not_applicable' AFTER credit_reservation_ledger_id;

CREATE INDEX IF NOT EXISTS idx_whatsapp_messages_billing
    ON whatsapp_messages (workspace_id, connection_mode, billing_status, created_at);

INSERT INTO workspace_whatsapp_credit_wallets
    (workspace_id, integration_id, currency, credit_balance, reserved_credits, low_balance_threshold, billing_status, last_activity_at)
SELECT wwi.workspace_id,
       wwi.id,
       COALESCE(NULLIF(wwi.managed_currency, ''), 'KES'),
       COALESCE(wwi.managed_credit_balance, 0),
       COALESCE(wwi.managed_credit_reserved, 0),
       COALESCE(wwi.managed_low_balance_threshold, 100),
       CASE WHEN wwi.connection_mode = 'platform_managed' THEN 'active' ELSE 'inactive' END,
       NOW()
FROM workspace_whatsapp_integrations wwi
WHERE NOT EXISTS (
    SELECT 1 FROM workspace_whatsapp_credit_wallets existing
    WHERE existing.workspace_id = wwi.workspace_id
);

INSERT INTO workspace_whatsapp_rate_cards
    (market_code, template_category, currency, provider_unit_cost, platform_unit_price, effective_from, metadata_json)
SELECT seed.market_code, seed.template_category, seed.currency, seed.provider_unit_cost, seed.platform_unit_price, CURDATE(),
       JSON_OBJECT('source', 'default_seed', 'note', 'Replace with current Meta rate card before production billing.')
FROM (
    SELECT 'default' AS market_code, 'marketing' AS template_category, 'KES' AS currency, 0.80 AS provider_unit_cost, 1.00 AS platform_unit_price
    UNION ALL SELECT 'default', 'utility', 'KES', 0.35, 0.50
    UNION ALL SELECT 'default', 'authentication', 'KES', 0.25, 0.40
    UNION ALL SELECT 'default', 'service', 'KES', 0.00, 0.00
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM workspace_whatsapp_rate_cards rc
    WHERE rc.market_code = seed.market_code
      AND rc.template_category = seed.template_category
      AND rc.currency = seed.currency
      AND rc.is_active = 1
);

INSERT INTO permissions (permission_key, label, description, is_sensitive)
SELECT seed.permission_key, seed.label, seed.description, seed.is_sensitive
FROM (
    SELECT 'settings.whatsapp.manage_connection' AS permission_key, 'Manage WhatsApp connection' AS label, 'Manage workspace WhatsApp connection mode and credentials.' AS description, 1 AS is_sensitive
    UNION ALL SELECT 'settings.whatsapp.manage_billing', 'Manage WhatsApp billing', 'Manage platform-managed WhatsApp credits and spend controls.', 1
    UNION ALL SELECT 'whatsapp.templates.manage', 'Manage WhatsApp templates', 'Create and edit workspace WhatsApp templates.', 0
    UNION ALL SELECT 'whatsapp.templates.submit', 'Submit WhatsApp templates', 'Submit WhatsApp templates for approval.', 0
) seed
WHERE EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'permissions'
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'settings.whatsapp.manage_connection',
    'settings.whatsapp.manage_billing',
    'whatsapp.templates.manage',
    'whatsapp.templates.submit'
)
WHERE r.slug IN ('admin', 'owner', 'super_admin')
  AND EXISTS (
      SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'role_permissions'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(
        COALESCE(settings_schema_json, JSON_OBJECT()),
        '$.requires_configuration', TRUE,
        '$.settings_url', 'workspace_skills.php?module=whatsapp&setup_tab=managed#setup',
        '$.feature_flags.whatsapp_dual_setup_modes', TRUE,
        '$.feature_flags.whatsapp_managed_billing', TRUE,
        '$.feature_flags.whatsapp_template_center', TRUE
    ),
    plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.setup_url', 'workspace_skills.php?module=whatsapp&setup_tab=managed#setup',
        '$.marketplace_profile.pitch',
            'Choose self-managed Meta setup or managed WhatsApp with platform-billed credits, shared inbox, approved templates, and delivery guardrails.',
        '$.marketplace_profile.setup_guide',
            JSON_ARRAY(
                'Pick Managed WhatsApp for click-to-connect and platform-billed credits, or Self-Managed for your own Meta account.',
                'Complete the connection and webhook health checks.',
                'Create or sync approved WhatsApp templates from the Template Center.',
                'Review consent, spending caps, and campaign estimates before sending live traffic.'
            )
    ),
    updated_at = NOW()
WHERE skill_key = 'whatsapp';
