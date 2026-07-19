-- Marketplace-gated Finance setup and owner-linked ROI.

CREATE TABLE IF NOT EXISTS finance_owner_equity_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    ownership_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    opening_owner_capital DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    opening_owner_draws DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    notes TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_owner_equity_workspace_user (workspace_id, user_id),
    KEY idx_finance_owner_equity_workspace_active (workspace_id, is_active),
    KEY idx_finance_owner_equity_user (user_id),
    CONSTRAINT fk_finance_owner_equity_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_owner_equity_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE finance_transactions
    ADD COLUMN IF NOT EXISTS owner_user_id INT NULL AFTER counterparty,
    ADD KEY IF NOT EXISTS idx_finance_transactions_owner_user (workspace_id, owner_user_id);

INSERT INTO workspace_skill_definitions (
    owner_workspace_id, skill_key, definition_source, label, summary, category, module_type, version,
    capabilities_json, advice_domains_json, onboarding_fields_json, context_schema_json,
    task_templates_json, boundary_policy, routing_examples_json, settings_schema_json,
    plugin_metadata_json, ai_context_provider, navigation_json, permissions_json, is_active
) VALUES (
    NULL,
    'finance',
    'platform',
    'Finance',
    'Gates Finance behind Marketplace setup so opening balances, funding context, and owner equity are reviewed before statements are used.',
    'finance',
    'plugin',
    '1.0.0',
    JSON_OBJECT('runtime_plugin', true, 'finance_gate', true, 'opening_balance_setup', true, 'owner_roi', true),
    JSON_ARRAY('finance', 'cash_flow', 'equity', 'roi'),
    JSON_ARRAY(),
    JSON_OBJECT('fields', JSON_ARRAY()),
    JSON_ARRAY(),
    'strict',
    JSON_ARRAY('Open Finance after setup', 'Show owner ROI', 'Review opening balances'),
    JSON_OBJECT('requires_configuration', true, 'settings_url', 'workspace_skills.php?module=finance'),
    JSON_OBJECT(
        'readiness_provider', 'finance',
        'runtime_provider', 'WorkspaceFinanceGateService',
        'setup_url', 'workspace_skills.php?module=finance',
        'marketplace_profile', JSON_OBJECT(
            'thumbnail_url', 'images/marketplace/finance.webp',
            'thumbnail_alt', 'Finance setup module with opening balances and owner equity checks.',
            'pitch', 'Install Finance when the workspace is ready to track cash, expenses, funding, opening balances, and owner ROI from one calm operator view.',
            'tags', JSON_ARRAY('Setup required', 'Finance', 'Owner ROI'),
            'recommendations', JSON_ARRAY(
                'Complete opening balances before relying on statements.',
                'Tie owner equity to workspace owner accounts for private ROI views.',
                'Use Finance after invoices, expenses, vendors, and funding entries are being tracked.'
            ),
            'prerequisites', JSON_ARRAY(
                'Workspace owner or admin access.',
                'Opening cash, receivables, payables, loans, assets, and equity figures, even when zero.',
                'Active workspace owner accounts for equity allocation.'
            ),
            'setup_guide', JSON_ARRAY(
                'Install Finance from Marketplace.',
                'Save explicit opening figures and currency.',
                'Allocate owner equity across active owner accounts.',
                'Open Finance after the readiness check turns ready.'
            )
        )
    ),
    'finance',
    JSON_OBJECT('label', 'Finance', 'url', 'finance.php'),
    JSON_ARRAY('finance.view'),
    1
) ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    summary = VALUES(summary),
    category = VALUES(category),
    module_type = VALUES(module_type),
    capabilities_json = VALUES(capabilities_json),
    advice_domains_json = VALUES(advice_domains_json),
    settings_schema_json = VALUES(settings_schema_json),
    plugin_metadata_json = VALUES(plugin_metadata_json),
    ai_context_provider = VALUES(ai_context_provider),
    navigation_json = VALUES(navigation_json),
    permissions_json = VALUES(permissions_json),
    is_active = 1,
    updated_at = NOW();
