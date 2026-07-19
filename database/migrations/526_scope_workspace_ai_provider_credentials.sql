-- Scope AI provider credentials so content generation can use a dedicated key.
-- Plain ALTER statements keep this import compatible with live phpMyAdmin users
-- that cannot use MariaDB-only IF NOT EXISTS syntax.

ALTER TABLE workspace_ai_provider_configs ADD COLUMN credential_scope VARCHAR(32) NOT NULL DEFAULT 'general' AFTER workspace_id;
ALTER TABLE workspace_ai_provider_configs ADD UNIQUE KEY uniq_workspace_ai_provider_config_scope (workspace_id, credential_scope);
ALTER TABLE workspace_ai_provider_configs DROP INDEX uniq_workspace_ai_provider_config_workspace;
ALTER TABLE workspace_ai_provider_configs ADD KEY idx_workspace_ai_provider_configs_scope_mode (credential_scope, mode, updated_at);

ALTER TABLE workspace_ai_usage ADD COLUMN credential_scope VARCHAR(32) NOT NULL DEFAULT 'general' AFTER provider_config_workspace_id;
ALTER TABLE workspace_ai_usage ADD KEY idx_workspace_ai_usage_scope_today (workspace_id, credential_scope, created_at);
