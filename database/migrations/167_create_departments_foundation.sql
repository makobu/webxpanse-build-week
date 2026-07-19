CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_departments_slug (slug),
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO departments (name, slug, description, is_active, is_system) VALUES
('Admin', 'admin', 'Administrative and executive leadership department', 1, 1),
('Sales', 'sales', 'Revenue generation and sales operations department', 1, 1),
('Marketing', 'marketing', 'Marketing, brand, and growth department', 1, 1),
('Operations', 'operations', 'Operations and general staff department', 1, 1)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    is_active = VALUES(is_active),
    is_system = VALUES(is_system);

ALTER TABLE users
    ADD COLUMN department_id INT NULL AFTER role;

ALTER TABLE users
    ADD INDEX idx_users_department_id (department_id);

ALTER TABLE users
    ADD CONSTRAINT fk_users_department
    FOREIGN KEY (department_id) REFERENCES departments(id)
    ON DELETE SET NULL;

UPDATE users u
JOIN departments d ON d.slug = CASE
    WHEN LOWER(COALESCE(u.role, 'viewer')) = 'admin' THEN 'admin'
    WHEN LOWER(COALESCE(u.role, 'viewer')) = 'sales' THEN 'sales'
    WHEN LOWER(COALESCE(u.role, 'viewer')) = 'marketing' THEN 'marketing'
    ELSE 'operations'
END
SET u.department_id = d.id
WHERE u.department_id IS NULL;

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES ('org.departments.manage', 'Manage Departments', 'Create, edit, activate, and manage departments', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'org.departments.manage'
WHERE r.slug = 'admin'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
