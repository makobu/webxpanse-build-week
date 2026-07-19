-- Super Admin automation catalog foundation.
-- This creates a risk-scored catalog for production readiness detectors before any detector acts automatically.

CREATE TABLE IF NOT EXISTS automation_catalog_definitions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    automation_key VARCHAR(120) NOT NULL,
    name VARCHAR(190) NOT NULL,
    category VARCHAR(80) NOT NULL,
    purpose TEXT NOT NULL,
    risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    trigger_key VARCHAR(120) NOT NULL,
    frequency VARCHAR(80) NOT NULL DEFAULT 'on_demand',
    required_permissions_json JSON NULL,
    default_mode ENUM('disabled','observe','suggest','prepare','approval_required','auto') NOT NULL DEFAULT 'observe',
    evidence_json JSON NULL,
    action_json JSON NULL,
    rollback_json JSON NULL,
    owner_scope ENUM('platform','workspace','system') NOT NULL DEFAULT 'platform',
    implementation_status ENUM('planned','wired','active','archived') NOT NULL DEFAULT 'planned',
    source VARCHAR(120) NOT NULL DEFAULT 'migration_490',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_automation_catalog_key (automation_key),
    KEY idx_automation_catalog_category (category, implementation_status),
    KEY idx_automation_catalog_risk_mode (risk_level, default_mode),
    KEY idx_automation_catalog_scope (owner_scope, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_catalog_controls (
    id INT PRIMARY KEY AUTO_INCREMENT,
    automation_id INT NOT NULL,
    scope_key VARCHAR(120) NOT NULL DEFAULT 'global',
    workspace_id INT NULL,
    mode_override ENUM('disabled','observe','suggest','prepare','approval_required','auto') NULL,
    is_paused TINYINT(1) NOT NULL DEFAULT 0,
    pause_reason VARCHAR(500) NULL,
    kill_switch_engaged TINYINT(1) NOT NULL DEFAULT 0,
    kill_switch_reason VARCHAR(500) NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_automation_catalog_control_scope (automation_id, scope_key),
    KEY idx_automation_catalog_controls_workspace (workspace_id, is_paused, kill_switch_engaged),
    KEY idx_automation_catalog_controls_user (updated_by_user_id),
    CONSTRAINT fk_automation_catalog_controls_definition
        FOREIGN KEY (automation_id) REFERENCES automation_catalog_definitions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_automation_catalog_controls_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_automation_catalog_controls_user
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_catalog_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    automation_id INT NOT NULL,
    workspace_id INT NULL,
    actor_user_id INT NULL,
    run_status ENUM('observed','suggested','prepared','approval_required','applied','skipped','failed') NOT NULL DEFAULT 'observed',
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    evidence_json JSON NULL,
    recommendation_json JSON NULL,
    action_taken_json JSON NULL,
    review_status ENUM('none','open','approved','rejected','resolved') NOT NULL DEFAULT 'none',
    source VARCHAR(120) NOT NULL DEFAULT 'automation_catalog',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_automation_catalog_runs_automation (automation_id, created_at),
    KEY idx_automation_catalog_runs_workspace (workspace_id, created_at),
    KEY idx_automation_catalog_runs_review (review_status, severity, created_at),
    KEY idx_automation_catalog_runs_actor (actor_user_id, created_at),
    CONSTRAINT fk_automation_catalog_runs_definition
        FOREIGN KEY (automation_id) REFERENCES automation_catalog_definitions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_automation_catalog_runs_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_automation_catalog_runs_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO automation_catalog_definitions (
    automation_key, name, category, purpose, risk_level, trigger_key, frequency,
    required_permissions_json, default_mode, evidence_json, action_json, rollback_json,
    owner_scope, implementation_status, source
) VALUES
('missing_production_settings_detector', 'Missing Production Settings Detector', 'production_readiness', 'Detect production environment settings that are missing, local-only, or unsafe before live upload.', 'high', 'production_preflight', 'on_demand_and_daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('APP_ENV', 'APP_DEBUG', 'APP_URL', 'runtime_paths', 'public_setup_scripts'), JSON_OBJECT('type', 'recommend_settings_fix', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'platform', 'planned', 'migration_490'),
('broken_template_detector', 'Broken Template Detector', 'templates', 'Detect missing, inactive, empty, demo-language, or placeholder-broken message templates.', 'medium', 'template_catalog_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('template_slug', 'channel', 'required_placeholders', 'body_length', 'demo_language_flags'), JSON_OBJECT('type', 'open_template_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'platform', 'planned', 'migration_490'),
('demo_test_data_detector', 'Demo/Test Data Detector', 'data_quality', 'Detect demo, smoke, and test records that should not pollute production workspaces.', 'high', 'data_quality_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('workspace_id', 'record_type', 'email_domain', 'demo_visibility', 'metadata_flags'), JSON_OBJECT('type', 'recommend_quarantine_or_cleanup', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore from backup if cleanup is approved later'), 'platform', 'planned', 'migration_490'),
('failed_migration_detector', 'Failed Migration Detector', 'reliability', 'Detect pending or failed database migrations before production upload or after deployment.', 'critical', 'migration_status_scan', 'on_deploy_and_daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('latest_file', 'latest_applied', 'pending_count', 'pending_migrations'), JSON_OBJECT('type', 'block_deploy_and_recommend_migrate', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'rerun migration after fix'), 'system', 'planned', 'migration_490'),
('failed_email_delivery_monitor', 'Failed Email Delivery Monitor', 'communication', 'Monitor failed email sends and surface delivery issues for Super Admin review.', 'medium', 'email_delivery_scan', 'hourly', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('failed_count', 'smtp_profile', 'provider_error', 'affected_workspace_ids'), JSON_OBJECT('type', 'open_delivery_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'platform', 'planned', 'migration_490'),
('failed_whatsapp_delivery_monitor', 'Failed WhatsApp Delivery Monitor', 'communication', 'Monitor failed WhatsApp delivery and webhook outcomes.', 'medium', 'whatsapp_delivery_scan', 'hourly', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('failed_count', 'phone_number_id', 'provider_error', 'webhook_status'), JSON_OBJECT('type', 'open_whatsapp_delivery_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'platform', 'planned', 'migration_490'),
('integration_credential_missing_detector', 'Integration Credential Missing Detector', 'integrations', 'Detect required provider credentials that are missing for enabled modules.', 'high', 'integration_readiness_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('provider', 'module', 'workspace_id', 'missing_secret_keys', 'enabled_state'), JSON_OBJECT('type', 'recommend_integration_setup', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'remove recommendation after credential setup'), 'platform', 'planned', 'migration_490'),
('expiring_integration_credential_warning', 'Expiring Integration Credential Warning', 'integrations', 'Warn before OAuth grants, webhooks, or provider credentials expire or need reconnection.', 'medium', 'integration_expiry_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('provider', 'expires_at', 'days_remaining', 'workspace_id'), JSON_OBJECT('type', 'open_reconnect_task', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'platform', 'planned', 'migration_490'),
('queue_backlog_monitor', 'Queue Backlog Monitor', 'reliability', 'Detect stuck or growing queues before customer-facing work is delayed.', 'medium', 'queue_health_scan', 'hourly', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('queue_name', 'pending_count', 'oldest_pending_at', 'failed_count'), JSON_OBJECT('type', 'open_queue_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'system', 'planned', 'migration_490'),
('failed_background_job_monitor', 'Failed Background Job Monitor', 'reliability', 'Detect failed or stale background jobs and scheduled workers.', 'high', 'job_health_scan', 'hourly', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('job_key', 'last_success_at', 'last_failure_at', 'derived_status'), JSON_OBJECT('type', 'recommend_worker_repair', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'mark resolved after worker recovers'), 'system', 'planned', 'migration_490'),
('billing_package_mismatch_detector', 'Billing/Package Mismatch Detector', 'billing', 'Detect workspace package, billing status, token balance, and enabled feature mismatches.', 'high', 'billing_package_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('workspace_id', 'plan_status', 'package_key', 'enabled_features', 'token_balance'), JSON_OBJECT('type', 'open_billing_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore prior package if approved change is reverted'), 'platform', 'planned', 'migration_490'),
('permission_drift_detector', 'Permission Drift Detector', 'security', 'Detect role and permission changes that drift from production defaults.', 'critical', 'permission_scan', 'daily', JSON_ARRAY('admin.roles.manage'), 'suggest', JSON_ARRAY('role_slug', 'permission_key', 'expected_state', 'actual_state'), JSON_OBJECT('type', 'open_permission_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore previous role permission snapshot'), 'platform', 'planned', 'migration_490'),
('superadmin_access_repair_suggestion', 'Superadmin Access Repair Suggestion', 'security', 'Detect missing or degraded Super Admin access and suggest repair steps.', 'critical', 'superadmin_access_scan', 'daily', JSON_ARRAY('admin.users.manage'), 'suggest', JSON_ARRAY('superadmin_count', 'locked_accounts', 'missing_role_assignments'), JSON_OBJECT('type', 'recommend_superadmin_access_repair', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore previous account state if approved repair is reverted'), 'platform', 'planned', 'migration_490'),
('workspace_owner_missing_detector', 'Workspace Owner Missing Detector', 'workspace_health', 'Detect active workspaces without a clear active owner membership/contact.', 'high', 'workspace_owner_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('workspace_id', 'owner_membership_count', 'owner_contact_id', 'workspace_status'), JSON_OBJECT('type', 'open_owner_repair_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'remove suggested owner assignment'), 'platform', 'planned', 'migration_490'),
('duplicate_default_templates_detector', 'Duplicate Default Templates Detector', 'templates', 'Detect duplicate default or starter templates that confuse workspace setup.', 'medium', 'template_catalog_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('template_type', 'template_key', 'slug', 'active_count'), JSON_OBJECT('type', 'open_template_dedupe_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'reactivate archived duplicate if cleanup is approved later'), 'platform', 'planned', 'migration_490'),
('empty_onboarding_context_detector', 'Empty Onboarding Context Detector', 'onboarding', 'Detect workspaces with missing operating context, starter kits, or setup state.', 'medium', 'onboarding_context_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('workspace_id', 'status', 'readiness_score', 'missing_context_keys'), JSON_OBJECT('type', 'recommend_onboarding_context_repair', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore previous onboarding state if approved repair is reverted'), 'platform', 'planned', 'migration_490'),
('ai_runtime_overuse_detector', 'AI Runtime Overuse Detector', 'ai_governance', 'Detect unusual AI usage, token spend, or unsafe runtime pressure.', 'high', 'ai_usage_scan', 'hourly', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('workspace_id', 'token_usage', 'budget', 'run_count', 'blocked_count'), JSON_OBJECT('type', 'recommend_ai_runtime_limit_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore prior runtime limits if approved change is reverted'), 'platform', 'planned', 'migration_490'),
('disabled_critical_module_detector', 'Disabled Critical Module Detector', 'settings', 'Detect critical modules or feature flags disabled in production unexpectedly.', 'high', 'module_state_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'suggest', JSON_ARRAY('module_key', 'workspace_id', 'expected_state', 'actual_state'), JSON_OBJECT('type', 'open_module_state_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none_by_default', 'undo', 'restore prior module state if approved repair is reverted'), 'platform', 'planned', 'migration_490'),
('broken_marketplace_setup_detector', 'Broken Marketplace Setup Detector', 'marketplace', 'Detect installed marketplace modules with incomplete setup or broken readiness requirements.', 'medium', 'marketplace_setup_scan', 'daily', JSON_ARRAY('settings.monitoring'), 'observe', JSON_ARRAY('workspace_id', 'skill_key', 'installed_state', 'readiness_failures'), JSON_OBJECT('type', 'open_marketplace_setup_review', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'none', 'undo', 'dismiss recommendation'), 'platform', 'planned', 'migration_490'),
('daily_superadmin_health_digest', 'Daily Superadmin Health Digest', 'reporting', 'Prepare a daily digest of production readiness, workspace health, failed jobs, and automation findings.', 'low', 'daily_digest_schedule', 'daily', JSON_ARRAY('settings.monitoring'), 'prepare', JSON_ARRAY('critical_count', 'warning_count', 'workspace_count', 'failed_job_count', 'open_review_count'), JSON_OBJECT('type', 'prepare_digest_draft', 'customer_facing', FALSE), JSON_OBJECT('mutation', 'draft_only', 'undo', 'discard digest draft'), 'platform', 'planned', 'migration_490')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    category = VALUES(category),
    purpose = VALUES(purpose),
    risk_level = VALUES(risk_level),
    trigger_key = VALUES(trigger_key),
    frequency = VALUES(frequency),
    required_permissions_json = VALUES(required_permissions_json),
    default_mode = VALUES(default_mode),
    evidence_json = VALUES(evidence_json),
    action_json = VALUES(action_json),
    rollback_json = VALUES(rollback_json),
    owner_scope = VALUES(owner_scope),
    implementation_status = VALUES(implementation_status),
    source = VALUES(source),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO automation_catalog_controls (automation_id, scope_key)
SELECT id, 'global'
FROM automation_catalog_definitions
WHERE source = 'migration_490'
ON DUPLICATE KEY UPDATE
    automation_id = VALUES(automation_id),
    updated_at = CURRENT_TIMESTAMP;
