-- Mark the remaining first-20 Super Admin automation detectors as wired.

UPDATE automation_catalog_definitions
SET implementation_status = 'wired',
    source = 'migration_496_first_20_detector_wiring',
    updated_at = CURRENT_TIMESTAMP
WHERE automation_key IN (
    'failed_email_delivery_monitor',
    'failed_whatsapp_delivery_monitor',
    'integration_credential_missing_detector',
    'expiring_integration_credential_warning',
    'queue_backlog_monitor',
    'failed_background_job_monitor',
    'billing_package_mismatch_detector',
    'permission_drift_detector',
    'superadmin_access_repair_suggestion',
    'workspace_owner_missing_detector',
    'duplicate_default_templates_detector',
    'empty_onboarding_context_detector',
    'ai_runtime_overuse_detector',
    'broken_marketplace_setup_detector'
);
