CREATE TABLE IF NOT EXISTS nurture_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    contact_id INT NOT NULL,
    lifecycle_lane ENUM('prospect_nurture','sales_ready','customer_success','expansion','at_risk','inactive') NOT NULL DEFAULT 'prospect_nurture',
    nurture_status ENUM('active','needs_touch','paused','completed','exited') NOT NULL DEFAULT 'active',
    temperature ENUM('hot','warm','cool','cold') NOT NULL DEFAULT 'cool',
    cadence ENUM('weekly','biweekly','monthly','quarterly','manual') NOT NULL DEFAULT 'monthly',
    owner_user_id INT NULL,
    last_touch_at DATETIME NULL,
    next_touch_at DATETIME NULL,
    next_touch_reason VARCHAR(255) NULL,
    health_score INT NOT NULL DEFAULT 50,
    health_signals_json JSON NULL,
    suggested_touch_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_nurture_profile_contact (workspace_id, contact_id),
    INDEX idx_nurture_profiles_lane_status (workspace_id, lifecycle_lane, nurture_status),
    INDEX idx_nurture_profiles_next_touch (workspace_id, next_touch_at),
    INDEX idx_nurture_profiles_owner (workspace_id, owner_user_id),
    CONSTRAINT fk_nurture_profiles_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_nurture_profiles_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nurture_programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    program_type ENUM('prospect','customer_success','expansion','risk_recovery') NOT NULL DEFAULT 'prospect',
    status ENUM('draft','active','paused','archived') NOT NULL DEFAULT 'draft',
    cadence ENUM('weekly','biweekly','monthly','quarterly','manual') NOT NULL DEFAULT 'monthly',
    linked_campaign_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nurture_programs_workspace_status (workspace_id, status, program_type),
    INDEX idx_nurture_programs_campaign (linked_campaign_id),
    CONSTRAINT fk_nurture_programs_campaign FOREIGN KEY (linked_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_nurture_programs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nurture_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    profile_id INT NOT NULL,
    program_id INT NOT NULL,
    contact_id INT NOT NULL,
    status ENUM('active','paused','completed','exited') NOT NULL DEFAULT 'active',
    current_step_label VARCHAR(255) NULL,
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_touch_at DATETIME NULL,
    completed_at DATETIME NULL,
    exit_reason VARCHAR(255) NULL,
    metadata_json JSON NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_nurture_enrollment_program_contact (workspace_id, program_id, contact_id),
    INDEX idx_nurture_enrollments_status_next (workspace_id, status, next_touch_at),
    INDEX idx_nurture_enrollments_contact (workspace_id, contact_id),
    CONSTRAINT fk_nurture_enrollments_profile FOREIGN KEY (profile_id) REFERENCES nurture_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_nurture_enrollments_program FOREIGN KEY (program_id) REFERENCES nurture_programs(id) ON DELETE CASCADE,
    CONSTRAINT fk_nurture_enrollments_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nurture_touchpoints (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    profile_id INT NOT NULL,
    contact_id INT NOT NULL,
    enrollment_id INT NULL,
    task_id INT NULL,
    activity_id INT NULL,
    campaign_id INT NULL,
    touch_type ENUM('planned','manual_task','campaign','activity','check_in','renewal','expansion','risk_recovery') NOT NULL DEFAULT 'planned',
    channel ENUM('email','phone','whatsapp','meeting','task','note','other') NOT NULL DEFAULT 'task',
    status ENUM('planned','completed','skipped','cancelled') NOT NULL DEFAULT 'planned',
    subject VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    scheduled_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_by INT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nurture_touchpoints_profile_status (workspace_id, profile_id, status, scheduled_at),
    INDEX idx_nurture_touchpoints_contact (workspace_id, contact_id, created_at),
    INDEX idx_nurture_touchpoints_task (task_id),
    INDEX idx_nurture_touchpoints_activity (activity_id),
    CONSTRAINT fk_nurture_touchpoints_profile FOREIGN KEY (profile_id) REFERENCES nurture_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_nurture_touchpoints_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_nurture_touchpoints_enrollment FOREIGN KEY (enrollment_id) REFERENCES nurture_enrollments(id) ON DELETE SET NULL,
    CONSTRAINT fk_nurture_touchpoints_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_nurture_touchpoints_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_nurture_touchpoints_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('nurture.read', 'Read Nurture', 'View nurture workspace and relationship profiles', FALSE),
('nurture.write', 'Write Nurture', 'Update nurture status, cadence, and follow-up tasks', FALSE),
('nurture.manage', 'Manage Nurture', 'Create programs and manage nurture enrollments', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('admin', 'owner')
  AND p.permission_key IN ('nurture.read', 'nurture.write', 'nurture.manage')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('sales', 'marketing')
  AND p.permission_key IN ('nurture.read', 'nurture.write')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'viewer'
  AND p.permission_key = 'nurture.read'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
