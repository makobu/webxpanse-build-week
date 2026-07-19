CREATE TABLE IF NOT EXISTS founder_first_customer_sprints (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    target_customer_segment VARCHAR(255) NULL,
    offer_pitch TEXT NULL,
    outreach_channel VARCHAR(120) NULL,
    weekly_outreach_target INT NOT NULL DEFAULT 0,
    demo_booking_target INT NOT NULL DEFAULT 0,
    paid_customer_target INT NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_founder_sprint_workspace_user (workspace_id, user_id),
    INDEX idx_founder_sprint_workspace_status (workspace_id, status),
    CONSTRAINT fk_founder_sprint_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_founder_sprint_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_founder_sprint_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_founder_sprint_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS founder_weekly_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    wins TEXT NULL,
    blockers TEXT NULL,
    customer_conversations INT NOT NULL DEFAULT 0,
    leads_created INT NOT NULL DEFAULT 0,
    deals_opened INT NOT NULL DEFAULT 0,
    deals_won INT NOT NULL DEFAULT 0,
    paid_revenue DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    expenses DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    runway_months DECIMAL(10,2) NULL,
    burn_rate DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    cac DECIMAL(15,2) NULL,
    break_even_deals INT NULL,
    pricing_concern TEXT NULL,
    next_week_focus TEXT NULL,
    finance_snapshot_json JSON NULL,
    review_status VARCHAR(40) NOT NULL DEFAULT 'draft',
    completed_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_founder_review_workspace_user_week (workspace_id, user_id, week_start),
    INDEX idx_founder_review_workspace_week (workspace_id, week_start, week_end),
    INDEX idx_founder_review_status (workspace_id, review_status),
    CONSTRAINT fk_founder_review_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_founder_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_founder_review_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_founder_review_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS founder_weekly_review_commitments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    review_id INT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    task_id INT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_founder_commitment_review (review_id),
    INDEX idx_founder_commitment_workspace_status (workspace_id, status),
    INDEX idx_founder_commitment_task (task_id),
    CONSTRAINT fk_founder_commitment_review FOREIGN KEY (review_id) REFERENCES founder_weekly_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_founder_commitment_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_founder_commitment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_founder_commitment_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('founder_loop.view', 'View Founder Operating Loop', 'View founder setup, finance, pricing, first-customer sprint, and weekly review rhythm', FALSE),
('founder_loop.manage', 'Manage Founder Operating Loop', 'Manage first-customer sprint setup, weekly reviews, commitments, and generated founder tasks', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('admin', 'owner')
  AND p.permission_key IN ('founder_loop.view', 'founder_loop.manage')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
