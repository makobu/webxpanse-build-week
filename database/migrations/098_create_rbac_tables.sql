-- RBAC foundation with compatibility for legacy users.role.

CREATE TABLE IF NOT EXISTS permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    permission_key VARCHAR(120) NOT NULL UNIQUE,
    label VARCHAR(180) NOT NULL,
    description VARCHAR(255) NULL,
    is_sensitive BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    can_access BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    assigned_by INT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Core permissions
INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('admin.users.manage', 'Manage Users', 'Create, edit, and delete users', FALSE),
('admin.roles.manage', 'Manage Roles', 'Create and edit roles and permissions', TRUE),
('feature.whatsapp_migration', 'WhatsApp Migration', 'Access WhatsApp Cloud migration tools', TRUE),
('feature.ml_training', 'ML Training', 'Train/recalculate ML scoring models', TRUE),
('feature.ml_scoring_dashboard', 'ML Scoring Dashboard', 'View ML scoring dashboard', TRUE),
('settings.whatsapp', 'WhatsApp Settings', 'Manage WhatsApp settings', TRUE),
('settings.ai_autoresponder', 'Autoresponder Settings', 'Manage AI autoresponder settings', TRUE),
('settings.scoring', 'Scoring Settings', 'Manage scoring settings', TRUE),
('settings.monitoring', 'Monitoring Settings', 'Manage monitoring settings', TRUE),
('settings.deal_automation', 'Deal Automation Settings', 'Manage deal automation settings', TRUE),
('settings.general', 'General Settings', 'Manage general settings', FALSE),
('settings.email', 'Email Settings', 'Manage SMTP and IMAP settings', FALSE),
('settings.email_assistant', 'Email Assistant Settings', 'Manage email assistant settings', FALSE),
('settings.ai', 'AI Settings', 'Manage AI service settings', FALSE),
('settings.calendar', 'Calendar Settings', 'Manage calendar settings', FALSE),
('settings.sms', 'SMS Settings', 'Manage SMS settings', FALSE),
('settings.company', 'Company Settings', 'Manage company profile settings', FALSE),
('settings.enrichment', 'Enrichment Settings', 'Manage enrichment settings', FALSE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

-- System roles
INSERT INTO roles (name, slug, description, is_system, is_active) VALUES
('Admin', 'admin', 'Full system access', TRUE, TRUE),
('Owner', 'owner', 'Business owner access without technical settings', TRUE, TRUE),
('Sales', 'sales', 'Sales access', TRUE, TRUE),
('Marketing', 'marketing', 'Marketing access', TRUE, TRUE),
('Viewer', 'viewer', 'Read-only access', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_system = VALUES(is_system),
    is_active = VALUES(is_active);

-- Admin gets all permissions
INSERT IGNORE INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'admin';

-- Owner gets broad business admin permissions but not technical permissions.
INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'owner'
  AND p.permission_key IN (
    'admin.users.manage',
    'settings.general',
    'settings.email',
    'settings.email_assistant',
    'settings.ai',
    'settings.calendar',
    'settings.sms',
    'settings.company',
    'settings.enrichment'
  )
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

-- Sync legacy users.role assignments into user_roles when missing.
INSERT INTO user_roles (user_id, role_id, assigned_by)
SELECT u.id, r.id, u.id
FROM users u
JOIN roles r ON r.slug = u.role
LEFT JOIN user_roles ur ON ur.user_id = u.id
WHERE ur.user_id IS NULL;

