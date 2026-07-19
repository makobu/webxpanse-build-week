-- Runtime capability foundation for workspace plugins and skills.
-- Idempotent: safe to run on existing tenant databases.

CREATE TABLE IF NOT EXISTS workspace_plugin_capabilities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    skill_key VARCHAR(80) NOT NULL,
    capability_key VARCHAR(120) NOT NULL,
    capability_type ENUM(
        'task_provider',
        'task_enricher',
        'target_metric_provider',
        'target_advice_provider',
        'workflow_action',
        'workflow_trigger',
        'ai_context_provider',
        'runtime_gate',
        'background_job'
    ) NOT NULL,
    handler_class VARCHAR(190) NULL,
    handler_method VARCHAR(120) NULL,
    schema_json JSON NULL,
    permissions_json JSON NULL,
    status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_plugin_capability (workspace_id, skill_key, capability_key),
    KEY idx_workspace_plugin_capabilities_workspace (workspace_id),
    KEY idx_workspace_plugin_capabilities_skill (skill_key),
    KEY idx_workspace_plugin_capabilities_type_status (capability_type, status),
    CONSTRAINT fk_workspace_plugin_capabilities_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_metric_providers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    skill_key VARCHAR(80) NOT NULL,
    metric_source VARCHAR(80) NOT NULL,
    metric_key VARCHAR(120) NOT NULL,
    label VARCHAR(190) NOT NULL,
    handler_class VARCHAR(190) NULL,
    schema_json JSON NULL,
    status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_target_metric_provider (workspace_id, metric_source, metric_key),
    KEY idx_target_metric_providers_workspace (workspace_id),
    KEY idx_target_metric_providers_skill (skill_key),
    KEY idx_target_metric_providers_status (status),
    CONSTRAINT fk_target_metric_providers_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workspace_plugin_runtime_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NULL,
    skill_key VARCHAR(80) NULL,
    capability_key VARCHAR(120) NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    event_type ENUM(
        'capability_invoked',
        'capability_succeeded',
        'capability_failed',
        'task_created',
        'task_enriched',
        'target_rollup_computed',
        'workflow_action_executed',
        'authorization_blocked',
        'readiness_blocked'
    ) NOT NULL,
    status ENUM('success','failed','blocked','info') NOT NULL DEFAULT 'info',
    duration_ms INT NULL,
    error_code VARCHAR(80) NULL,
    error_message TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_plugin_runtime_events_workspace (workspace_id),
    KEY idx_plugin_runtime_events_user (user_id),
    KEY idx_plugin_runtime_events_skill (skill_key),
    KEY idx_plugin_runtime_events_capability (capability_key),
    KEY idx_plugin_runtime_events_entity (entity_type, entity_id),
    KEY idx_plugin_runtime_events_created (created_at),
    CONSTRAINT fk_plugin_runtime_events_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_plugin_runtime_events_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @tasks_source_skill_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'source_skill_key'
);
SET @sql := IF(
    @tasks_source_skill_exists = 0,
    'ALTER TABLE tasks ADD COLUMN source_skill_key VARCHAR(80) NULL AFTER metadata_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tasks_source_plugin_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'source_plugin_key'
);
SET @sql := IF(
    @tasks_source_plugin_exists = 0,
    'ALTER TABLE tasks ADD COLUMN source_plugin_key VARCHAR(80) NULL AFTER source_skill_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tasks_source_surface_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'source_surface'
);
SET @sql := IF(
    @tasks_source_surface_exists = 0,
    'ALTER TABLE tasks ADD COLUMN source_surface VARCHAR(80) NULL AFTER source_plugin_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tasks_source_capability_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'source_capability_key'
);
SET @sql := IF(
    @tasks_source_capability_exists = 0,
    'ALTER TABLE tasks ADD COLUMN source_capability_key VARCHAR(120) NULL AFTER source_surface',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tasks_source_run_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND column_name = 'source_run_id'
);
SET @sql := IF(
    @tasks_source_run_exists = 0,
    'ALTER TABLE tasks ADD COLUMN source_run_id VARCHAR(120) NULL AFTER source_capability_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tasks_source_idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tasks'
      AND index_name = 'idx_tasks_plugin_source'
);
SET @sql := IF(
    @tasks_source_idx_exists = 0,
    'CREATE INDEX idx_tasks_plugin_source ON tasks (workspace_id, source_plugin_key, source_skill_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE tasks
SET source_skill_key = COALESCE(
        source_skill_key,
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.marketplace_skill_key')), 'null')
    ),
    source_plugin_key = COALESCE(
        source_plugin_key,
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.marketplace_plugin_key')), 'null')
    ),
    source_surface = COALESCE(
        source_surface,
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.source_surface')), 'null')
    ),
    source_run_id = COALESCE(
        source_run_id,
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.guidance_run_id')), 'null'),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.assistant_run_id')), 'null'),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.commercial_run_id')), 'null')
    )
WHERE metadata_json IS NOT NULL
  AND JSON_VALID(metadata_json);

SET @targets_source_skill_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'targets'
      AND column_name = 'source_skill_key'
);
SET @sql := IF(
    @targets_source_skill_exists = 0,
    'ALTER TABLE targets ADD COLUMN source_skill_key VARCHAR(80) NULL AFTER metadata_json',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @targets_source_plugin_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'targets'
      AND column_name = 'source_plugin_key'
);
SET @sql := IF(
    @targets_source_plugin_exists = 0,
    'ALTER TABLE targets ADD COLUMN source_plugin_key VARCHAR(80) NULL AFTER source_skill_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @targets_source_capability_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'targets'
      AND column_name = 'source_capability_key'
);
SET @sql := IF(
    @targets_source_capability_exists = 0,
    'ALTER TABLE targets ADD COLUMN source_capability_key VARCHAR(120) NULL AFTER source_plugin_key',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @targets_source_idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'targets'
      AND index_name = 'idx_targets_plugin_source'
);
SET @sql := IF(
    @targets_source_idx_exists = 0,
    'CREATE INDEX idx_targets_plugin_source ON targets (workspace_id, source_plugin_key, source_skill_key)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO workspace_plugin_capabilities (
    workspace_id,
    skill_key,
    capability_key,
    capability_type,
    handler_class,
    handler_method,
    schema_json,
    permissions_json,
    status
)
SELECT i.workspace_id, i.skill_key, CONCAT(i.skill_key, '.ai_context'), 'ai_context_provider',
       'CRM\\Services\\BuiltInPluginCapabilityHandler', 'provideAIContext',
       JSON_OBJECT('source', 'marketplace_install'), JSON_ARRAY('workspace.skills.view'), 'active'
FROM workspace_skill_installs i
WHERE i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND i.skill_key IN ('email_assistant','whatsapp_assistant','sms_channel','calendar_meetings','finance','ai_coach','lean_canvas','professional_marketer')
ON DUPLICATE KEY UPDATE
    capability_type = VALUES(capability_type),
    handler_class = VALUES(handler_class),
    handler_method = VALUES(handler_method),
    schema_json = VALUES(schema_json),
    permissions_json = VALUES(permissions_json),
    status = VALUES(status),
    updated_at = NOW();

INSERT INTO workspace_plugin_capabilities (
    workspace_id,
    skill_key,
    capability_key,
    capability_type,
    handler_class,
    handler_method,
    schema_json,
    permissions_json,
    status
)
SELECT i.workspace_id, i.skill_key, CONCAT(i.skill_key, '.task_enricher'), 'task_enricher',
       'CRM\\Services\\BuiltInPluginCapabilityHandler', 'enrichTaskPayload',
       JSON_OBJECT('source', 'marketplace_install'), JSON_ARRAY('workspace.skills.view'), 'active'
FROM workspace_skill_installs i
WHERE i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND i.skill_key IN ('email_assistant','whatsapp_assistant','sms_channel','calendar_meetings','finance','ai_coach','lean_canvas','professional_marketer')
ON DUPLICATE KEY UPDATE
    capability_type = VALUES(capability_type),
    handler_class = VALUES(handler_class),
    handler_method = VALUES(handler_method),
    schema_json = VALUES(schema_json),
    permissions_json = VALUES(permissions_json),
    status = VALUES(status),
    updated_at = NOW();

INSERT INTO target_metric_providers (
    workspace_id,
    skill_key,
    metric_source,
    metric_key,
    label,
    handler_class,
    schema_json,
    status
)
SELECT i.workspace_id, i.skill_key, i.skill_key, 'runtime_activity_count',
       CONCAT(REPLACE(i.skill_key, '_', ' '), ' runtime activity'),
       'CRM\\Services\\BuiltInPluginCapabilityHandler',
       JSON_OBJECT('source', 'workspace_plugin_runtime_events', 'metric', 'activity_count'),
       'active'
FROM workspace_skill_installs i
WHERE i.status = 'installed'
  AND i.uninstalled_at IS NULL
  AND i.skill_key IN ('email_assistant','whatsapp_assistant','sms_channel','calendar_meetings','finance','ai_coach')
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    handler_class = VALUES(handler_class),
    schema_json = VALUES(schema_json),
    status = VALUES(status),
    updated_at = NOW();
