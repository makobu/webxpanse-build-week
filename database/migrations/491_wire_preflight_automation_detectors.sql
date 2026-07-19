-- Mark the first read-only production readiness detector runner as wired.

UPDATE automation_catalog_definitions
SET implementation_status = 'wired',
    source = 'migration_491_preflight_wiring',
    updated_at = CURRENT_TIMESTAMP
WHERE automation_key IN (
    'missing_production_settings_detector',
    'failed_migration_detector',
    'demo_test_data_detector',
    'broken_template_detector',
    'disabled_critical_module_detector',
    'daily_superadmin_health_digest'
);
