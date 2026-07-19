-- Marketing Command Center and Content Studio phase one.

CREATE TABLE IF NOT EXISTS marketing_content_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    content_type ENUM('blog_post','social_post','email','newsletter','whatsapp','sms','ad_copy','video_script','case_study') NOT NULL DEFAULT 'social_post',
    channel ENUM('blog','linkedin','facebook','instagram','x','email','newsletter','whatsapp','sms','ads','youtube','website','other') NOT NULL DEFAULT 'other',
    status ENUM('idea','draft','review','approved','scheduled','published','archived') NOT NULL DEFAULT 'idea',
    funnel_stage VARCHAR(80) NULL,
    objective VARCHAR(255) NULL,
    target_audience VARCHAR(255) NULL,
    campaign_id INT NULL,
    form_id INT NULL,
    email_template_id INT NULL,
    task_id INT NULL,
    scheduled_at DATETIME NULL,
    published_at DATETIME NULL,
    draft_body MEDIUMTEXT NULL,
    ai_context_json JSON NULL,
    metadata_json JSON NULL,
    owner_user_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_content_workspace_status (workspace_id, status, updated_at),
    INDEX idx_marketing_content_workspace_schedule (workspace_id, scheduled_at),
    INDEX idx_marketing_content_workspace_owner (workspace_id, owner_user_id),
    INDEX idx_marketing_content_workspace_campaign (workspace_id, campaign_id),
    INDEX idx_marketing_content_workspace_channel (workspace_id, channel, content_type),
    CONSTRAINT fk_marketing_content_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_marketing_content_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_content_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_content_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_content_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketing_content_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('marketing.read', 'Read Marketing', 'View Marketing Command Center and Content Studio items', FALSE),
('marketing.write', 'Write Marketing', 'Create and update marketing content plans and drafts', FALSE),
('marketing.manage', 'Manage Marketing', 'Delete marketing content and manage marketing operations', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('admin', 'owner')
  AND p.permission_key IN ('marketing.read', 'marketing.write', 'marketing.manage')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug = 'marketing'
  AND p.permission_key IN ('marketing.read', 'marketing.write')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p
WHERE r.slug IN ('sales', 'viewer')
  AND p.permission_key = 'marketing.read'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
