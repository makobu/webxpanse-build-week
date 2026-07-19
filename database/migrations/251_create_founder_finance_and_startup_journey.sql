CREATE TABLE IF NOT EXISTS finance_expense_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    category_type ENUM('operations','marketing','payroll','software','inventory','taxes','other') NOT NULL DEFAULT 'operations',
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_expense_category_workspace_name (workspace_id, name),
    KEY idx_finance_expense_categories_workspace (workspace_id),
    CONSTRAINT fk_finance_expense_categories_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_recurring_expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    category_id INT NULL,
    vendor VARCHAR(180) NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    frequency ENUM('weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    payment_method ENUM('cash','card','bank_transfer','mobile_money','other') NOT NULL DEFAULT 'other',
    start_date DATE NULL,
    next_due_date DATE NULL,
    end_date DATE NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_recurring_workspace (workspace_id),
    KEY idx_finance_recurring_category (category_id),
    KEY idx_finance_recurring_due (next_due_date),
    CONSTRAINT fk_finance_recurring_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_recurring_category
        FOREIGN KEY (category_id) REFERENCES finance_expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    category_id INT NULL,
    recurring_expense_id INT NULL,
    vendor VARCHAR(180) NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    expense_date DATE NOT NULL,
    payment_method ENUM('cash','card','bank_transfer','mobile_money','other') NOT NULL DEFAULT 'other',
    status ENUM('planned','paid','reimbursed','cancelled') NOT NULL DEFAULT 'paid',
    linked_deal_id INT NULL,
    linked_invoice_id INT NULL,
    receipt_path VARCHAR(500) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_expenses_workspace_date (workspace_id, expense_date),
    KEY idx_finance_expenses_category (category_id),
    KEY idx_finance_expenses_status (status),
    KEY idx_finance_expenses_invoice (linked_invoice_id),
    KEY idx_finance_expenses_deal (linked_deal_id),
    CONSTRAINT fk_finance_expenses_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_expenses_category
        FOREIGN KEY (category_id) REFERENCES finance_expense_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_finance_expenses_recurring
        FOREIGN KEY (recurring_expense_id) REFERENCES finance_recurring_expenses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_budgets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    name VARCHAR(160) NOT NULL DEFAULT 'Monthly founder budget',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    target_revenue DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    target_cash_reserve DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_budget_workspace_period (workspace_id, period_start, period_end),
    KEY idx_finance_budgets_workspace (workspace_id),
    KEY idx_finance_budgets_user (user_id),
    CONSTRAINT fk_finance_budgets_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_budget_lines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    budget_id INT NOT NULL,
    workspace_id INT NOT NULL,
    category_id INT NULL,
    label VARCHAR(160) NOT NULL,
    planned_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_finance_budget_lines_budget (budget_id),
    KEY idx_finance_budget_lines_workspace (workspace_id),
    KEY idx_finance_budget_lines_category (category_id),
    CONSTRAINT fk_finance_budget_lines_budget
        FOREIGN KEY (budget_id) REFERENCES finance_budgets(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_budget_lines_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_budget_lines_category
        FOREIGN KEY (category_id) REFERENCES finance_expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    summary_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_finance_snapshot_workspace_period (workspace_id, period_start, period_end),
    CONSTRAINT fk_finance_snapshots_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS startup_journeys (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('draft','active','completed') NOT NULL DEFAULT 'active',
    current_stage_key VARCHAR(80) NOT NULL DEFAULT 'customer_discovery',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_startup_journey_workspace_user (workspace_id, user_id),
    KEY idx_startup_journeys_workspace (workspace_id),
    KEY idx_startup_journeys_user (user_id),
    CONSTRAINT fk_startup_journeys_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS startup_journey_stage_responses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    journey_id INT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    stage_key VARCHAR(80) NOT NULL,
    stage_order INT NOT NULL DEFAULT 0,
    status ENUM('not_started','draft','completed') NOT NULL DEFAULT 'not_started',
    responses_json JSON NULL,
    notes TEXT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_startup_journey_stage (journey_id, stage_key),
    KEY idx_startup_stage_workspace (workspace_id),
    KEY idx_startup_stage_user (user_id),
    KEY idx_startup_stage_status (status),
    CONSTRAINT fk_startup_stage_journey
        FOREIGN KEY (journey_id) REFERENCES startup_journeys(id) ON DELETE CASCADE,
    CONSTRAINT fk_startup_stage_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS startup_journey_stage_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    journey_id INT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    stage_key VARCHAR(80) NOT NULL,
    event_type ENUM('stage_viewed','stage_saved','stage_completed','stage_reopened','legacy_lean_canvas_imported') NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_startup_events_journey (journey_id),
    KEY idx_startup_events_workspace (workspace_id),
    KEY idx_startup_events_stage (stage_key),
    KEY idx_startup_events_type (event_type),
    CONSTRAINT fk_startup_events_journey
        FOREIGN KEY (journey_id) REFERENCES startup_journeys(id) ON DELETE CASCADE,
    CONSTRAINT fk_startup_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS startup_journey_artifacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    journey_id INT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    artifact_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    content_json JSON NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_startup_artifacts_journey (journey_id),
    KEY idx_startup_artifacts_workspace (workspace_id),
    CONSTRAINT fk_startup_artifacts_journey
        FOREIGN KEY (journey_id) REFERENCES startup_journeys(id) ON DELETE CASCADE,
    CONSTRAINT fk_startup_artifacts_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO finance_expense_categories (workspace_id, name, category_type, is_default)
SELECT w.id, seed.name, seed.category_type, TRUE
FROM workspaces w
JOIN (
    SELECT 'Operations' AS name, 'operations' AS category_type
    UNION ALL SELECT 'Marketing', 'marketing'
    UNION ALL SELECT 'Payroll', 'payroll'
    UNION ALL SELECT 'Software', 'software'
    UNION ALL SELECT 'Inventory', 'inventory'
    UNION ALL SELECT 'Taxes', 'taxes'
    UNION ALL SELECT 'Other', 'other'
) seed
LEFT JOIN finance_expense_categories existing
    ON existing.workspace_id = w.id
   AND existing.name = seed.name
WHERE existing.id IS NULL;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('finance.view', 'View Founder Finance', 'View expenses, budgets, cash flow, runway, and founder finance reports', FALSE),
('finance.manage', 'Manage Founder Finance', 'Create and manage expenses, budgets, recurring expenses, and finance setup', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('admin', 'owner')
  AND p.permission_key IN ('finance.view', 'finance.manage')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('sales', 'marketing')
  AND p.permission_key = 'finance.view'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO startup_journeys (workspace_id, user_id, status, current_stage_key)
SELECT COALESCE(NULLIF(workspace_id, 0), 1), user_id, 'active', 'lean_canvas'
FROM user_strategy_profiles
WHERE user_id > 0
  AND (
      COALESCE(lean_problem, '') <> ''
      OR COALESCE(lean_customer_segments, '') <> ''
      OR COALESCE(lean_unique_value_proposition, '') <> ''
      OR COALESCE(lean_solution, '') <> ''
      OR COALESCE(lean_channels, '') <> ''
      OR COALESCE(lean_revenue_streams, '') <> ''
      OR COALESCE(lean_cost_structure, '') <> ''
      OR COALESCE(lean_key_metrics, '') <> ''
      OR COALESCE(lean_unfair_advantage, '') <> ''
  )
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO startup_journey_stage_responses (
    journey_id,
    workspace_id,
    user_id,
    stage_key,
    stage_order,
    status,
    responses_json,
    completed_at
)
SELECT
    j.id,
    j.workspace_id,
    j.user_id,
    'lean_canvas',
    4,
    'completed',
    JSON_OBJECT(
        'problem', COALESCE(sp.lean_problem, ''),
        'customer_segments', COALESCE(sp.lean_customer_segments, ''),
        'unique_value_proposition', COALESCE(sp.lean_unique_value_proposition, ''),
        'solution', COALESCE(sp.lean_solution, ''),
        'channels', COALESCE(sp.lean_channels, ''),
        'revenue_streams', COALESCE(sp.lean_revenue_streams, ''),
        'cost_structure', COALESCE(sp.lean_cost_structure, ''),
        'key_metrics', COALESCE(sp.lean_key_metrics, ''),
        'unfair_advantage', COALESCE(sp.lean_unfair_advantage, '')
    ),
    NOW()
FROM startup_journeys j
JOIN user_strategy_profiles sp
  ON sp.user_id = j.user_id
 AND COALESCE(NULLIF(sp.workspace_id, 0), 1) = j.workspace_id
WHERE (
      COALESCE(sp.lean_problem, '') <> ''
      OR COALESCE(sp.lean_customer_segments, '') <> ''
      OR COALESCE(sp.lean_unique_value_proposition, '') <> ''
      OR COALESCE(sp.lean_solution, '') <> ''
      OR COALESCE(sp.lean_channels, '') <> ''
      OR COALESCE(sp.lean_revenue_streams, '') <> ''
      OR COALESCE(sp.lean_cost_structure, '') <> ''
      OR COALESCE(sp.lean_key_metrics, '') <> ''
      OR COALESCE(sp.lean_unfair_advantage, '') <> ''
)
ON DUPLICATE KEY UPDATE
    responses_json = VALUES(responses_json),
    status = IF(startup_journey_stage_responses.status = 'completed', startup_journey_stage_responses.status, VALUES(status)),
    updated_at = NOW();
