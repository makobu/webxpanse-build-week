-- Add Platform Ops event automation state and history.
-- This migration is intentionally idempotent.

SET @default_workspace_id := COALESCE(
    (SELECT id FROM workspaces WHERE id = 1 AND slug = 'default' LIMIT 1),
    (SELECT id FROM workspaces WHERE slug = 'default' ORDER BY id ASC LIMIT 1),
    1
);

SET @platform_owner_user_id := (
    SELECT wm.user_id
    FROM workspace_memberships wm
    WHERE wm.workspace_id = @default_workspace_id
      AND wm.membership_status = 'active'
    ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'viewer'), wm.id ASC
    LIMIT 1
);

ALTER TABLE default_workspace_ops_events
    ADD COLUMN IF NOT EXISTS automation_status ENUM('not_attempted','sent','skipped','failed','human_only') NOT NULL DEFAULT 'not_attempted' AFTER resolution_summary,
    ADD COLUMN IF NOT EXISTS automation_action VARCHAR(80) NULL AFTER automation_status,
    ADD COLUMN IF NOT EXISTS automation_template_slug VARCHAR(120) NULL AFTER automation_action,
    ADD COLUMN IF NOT EXISTS automation_last_attempted_at DATETIME NULL AFTER automation_template_slug,
    ADD COLUMN IF NOT EXISTS automation_last_sent_at DATETIME NULL AFTER automation_last_attempted_at,
    ADD COLUMN IF NOT EXISTS automation_attempt_count INT NOT NULL DEFAULT 0 AFTER automation_last_sent_at,
    ADD COLUMN IF NOT EXISTS automation_last_result VARCHAR(120) NULL AFTER automation_attempt_count,
    ADD COLUMN IF NOT EXISTS automation_last_error VARCHAR(500) NULL AFTER automation_last_result,
    ADD COLUMN IF NOT EXISTS automation_email_uuid CHAR(36) NULL AFTER automation_last_error,
    ADD COLUMN IF NOT EXISTS automation_delivery_json JSON NULL AFTER automation_email_uuid;

ALTER TABLE default_workspace_ops_events
    ADD KEY IF NOT EXISTS idx_default_ops_events_automation (default_workspace_id, automation_status, status, automation_last_attempted_at);

CREATE TABLE IF NOT EXISTS default_workspace_ops_automation_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    default_workspace_id INT NOT NULL,
    ops_event_id INT NULL,
    owner_workspace_id INT NULL,
    owner_user_id INT NULL,
    contact_id INT NULL,
    actor_user_id INT NULL,
    signal_type VARCHAR(80) NOT NULL,
    action_key VARCHAR(80) NULL,
    template_slug VARCHAR(120) NULL,
    run_status ENUM('sent','skipped','failed','human_only','resolved') NOT NULL,
    reason_code VARCHAR(120) NULL,
    message VARCHAR(500) NULL,
    email_uuid CHAR(36) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_default_ops_automation_workspace (default_workspace_id, created_at),
    KEY idx_default_ops_automation_event (ops_event_id, created_at),
    KEY idx_default_ops_automation_status (run_status, created_at),
    CONSTRAINT fk_default_ops_automation_default_workspace FOREIGN KEY (default_workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_default_ops_automation_event FOREIGN KEY (ops_event_id) REFERENCES default_workspace_ops_events(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_automation_owner_workspace FOREIGN KEY (owner_workspace_id) REFERENCES workspaces(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_automation_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_automation_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_default_ops_automation_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO email_templates (
    workspace_id,
    name,
    slug,
    subject,
    body_html,
    body_text,
    category,
    variables,
    is_active,
    is_library,
    description,
    tags,
    industry,
    purpose,
    is_featured,
    author,
    version,
    is_ai_generated,
    template_key,
    created_by,
    seed_metadata_json
)
SELECT
    @default_workspace_id,
    'Platform Ops - Channel Setup Reminder',
    'platform-ops-channel_setup_reminder',
    'Connect email for {workspace_name}',
    '<p>Hi {owner_name},</p><p><strong>{workspace_name}</strong> is active, but email is not connected yet. Connect your email channel so Clarity can support inbox, replies, and customer follow-up.</p><p><a href="{setup_url}">Connect email</a></p>',
    'Hi {owner_name},\n\n{workspace_name} is active, but email is not connected yet. Connect your email channel so Clarity can support inbox, replies, and customer follow-up.\n\nConnect email: {setup_url}',
    'platform_ops',
    JSON_ARRAY('owner_name', 'workspace_name', 'setup_url'),
    1,
    0,
    'Owner reminder for workspaces missing email channel setup.',
    JSON_ARRAY('platform_ops', 'platform_ops_owner_helpline', 'workspace_owner', 'channel_setup', 'channel_setup_reminder'),
    'SaaS operations',
    'platform_ops',
    0,
    'Clarity Platform Ops',
    '1.0',
    1,
    'channel_setup_reminder',
    @platform_owner_user_id,
    JSON_OBJECT(
        'seed_source', 'default_workspace_platform_ops',
        'seed_key', 'channel_setup_reminder',
        'seed_version', '1.0.0',
        'last_seeded_at', UTC_TIMESTAMP()
    )
WHERE @default_workspace_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM email_templates existing
      WHERE existing.slug = 'platform-ops-channel_setup_reminder'
      LIMIT 1
  );
