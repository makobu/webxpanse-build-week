<?php

namespace CRM\Services;

use PDO;
use PDOException;

class SystemReadinessService
{
    /** @var array<string,mixed> */
    private array $config;
    private string $rootPath;
    private SystemContextRegistryService $contextRegistry;

    /**
     * @param array<string,mixed>|null $config
     */
    public function __construct(?array $config = null, ?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?: dirname(__DIR__);
        $this->config = $config ?? $this->loadDatabaseConfig();
        $this->contextRegistry = new SystemContextRegistryService();
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $checks = [];
        $checks[] = $this->checkEnvironmentFile();
        $checks[] = $this->checkDatabaseConfig();
        $checks[] = $this->checkPdoMysql();
        $checks[] = $this->checkPhpConcurrencyRuntime();

        $serverConnection = null;
        if ($this->hasPdoMysql()) {
            $serverConnection = $this->connect(false);
            $checks[] = $this->checkServerConnection($serverConnection);
            $databaseConnection = $this->connect(true);
            $checks[] = $this->checkDatabaseConnection($databaseConnection);

            if (($databaseConnection['pdo'] ?? null) instanceof PDO) {
                $checks[] = $this->checkMigrations($databaseConnection['pdo']);
                $checks[] = $this->checkConcurrencyRuntime($databaseConnection['pdo']);
                $checks[] = $this->checkDefaultWorkspace($databaseConnection['pdo']);
                $checks[] = $this->checkSystemContextRegistry($databaseConnection['pdo']);
                $checks[] = $this->checkMarketingRuntime($databaseConnection['pdo']);
                $checks[] = $this->checkProductionPreflight($databaseConnection['pdo']);
                $checks[] = $this->checkTemplateValidation($databaseConnection['pdo']);
                $checks[] = $this->checkSecurityHardening($databaseConnection['pdo']);
                $checks[] = $this->checkIntegrationReadiness($databaseConnection['pdo']);
                $checks[] = $this->checkDemoQuarantine($databaseConnection['pdo']);
                $checks[] = $this->checkBackupRestoreReadiness($databaseConnection['pdo']);
            } else {
                $checks[] = $this->makeCheck('migrations', 'Migrations', 'unknown', 'Migrations cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('concurrency_runtime', 'Concurrent-use Runtime', 'unknown', 'Concurrent-use database checks require a reachable database.');
                $checks[] = $this->makeCheck('default_workspace', 'Default Workspace', 'unknown', 'Default workspace cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('system_context_registry', 'System Context Registry', 'unknown', 'System context registry cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('marketing_runtime', 'Marketing Runtime', 'unknown', 'Marketing tables cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('production_preflight', 'Production Preflight', 'unknown', 'Production preflight cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('template_validation', 'Template Validation', 'unknown', 'Template validation cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('security_hardening', 'Security Hardening', 'unknown', 'Security hardening cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('integration_readiness', 'Integration Readiness', 'unknown', 'Integration readiness cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('demo_quarantine', 'Demo Quarantine', 'unknown', 'Demo quarantine cannot be checked until the database is reachable.');
                $checks[] = $this->makeCheck('backup_restore_readiness', 'Backup & Restore', 'unknown', 'Backup and restore readiness cannot be checked until the database is reachable.');
            }
        } else {
            $checks[] = $this->makeCheck('mysql_server', 'MySQL Server', 'critical', 'PDO MySQL is not available.');
            $checks[] = $this->makeCheck('database', 'Configured Database', 'critical', 'PDO MySQL is not available.');
            $checks[] = $this->makeCheck('migrations', 'Migrations', 'unknown', 'Migrations cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('concurrency_runtime', 'Concurrent-use Runtime', 'unknown', 'Concurrent-use database checks require PDO MySQL.');
            $checks[] = $this->makeCheck('default_workspace', 'Default Workspace', 'unknown', 'Default workspace cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('system_context_registry', 'System Context Registry', 'unknown', 'System context registry cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('marketing_runtime', 'Marketing Runtime', 'unknown', 'Marketing tables cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('production_preflight', 'Production Preflight', 'unknown', 'Production preflight cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('template_validation', 'Template Validation', 'unknown', 'Template validation cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('security_hardening', 'Security Hardening', 'unknown', 'Security hardening cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('integration_readiness', 'Integration Readiness', 'unknown', 'Integration readiness cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('demo_quarantine', 'Demo Quarantine', 'unknown', 'Demo quarantine cannot be checked until PDO MySQL is available.');
            $checks[] = $this->makeCheck('backup_restore_readiness', 'Backup & Restore', 'unknown', 'Backup and restore readiness cannot be checked until PDO MySQL is available.');
        }

        $checks[] = $this->checkWritablePaths();

        return [
            'status' => $this->overallStatus($checks),
            'checked_at' => date('c'),
            'checks' => $checks,
            'summary' => $this->summarize($checks),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function safeConfigSummary(): array
    {
        return [
            'host' => $this->safeString($this->config['host'] ?? ''),
            'database' => $this->safeString($this->config['name'] ?? ''),
            'user' => $this->safeString($this->config['user'] ?? ''),
            'password_configured' => array_key_exists('pass', $this->config) && (string) $this->config['pass'] !== '',
            'charset' => $this->safeString($this->config['charset'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEnvironmentFile(): array
    {
        $envPath = $this->rootPath . '/.env';
        return $this->makeCheck(
            'environment_file',
            'Environment File',
            file_exists($envPath) ? 'ok' : 'warning',
            file_exists($envPath)
                ? '.env is present.'
                : '.env is missing; the app is using default database settings.',
            ['path' => '.env']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDatabaseConfig(): array
    {
        $missing = [];
        foreach (['host', 'name', 'user'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $this->makeCheck(
            'database_config',
            'Database Configuration',
            $missing === [] ? 'ok' : 'critical',
            $missing === []
                ? 'Database host, name, and user are configured.'
                : 'Database configuration is incomplete: ' . implode(', ', $missing) . '.',
            $this->safeConfigSummary()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPdoMysql(): array
    {
        return $this->makeCheck(
            'pdo_mysql',
            'PDO MySQL Extension',
            $this->hasPdoMysql() ? 'ok' : 'critical',
            $this->hasPdoMysql()
                ? 'PDO MySQL is available.'
                : 'Enable the PDO MySQL PHP extension before running the CRM.',
            ['drivers' => class_exists(PDO::class) ? PDO::getAvailableDrivers() : []]
        );
    }

    /**
     * @param array<string,mixed> $connection
     * @return array<string,mixed>
     */
    private function checkServerConnection(array $connection): array
    {
        if (($connection['pdo'] ?? null) instanceof PDO) {
            return $this->makeCheck('mysql_server', 'MySQL Server', 'ok', 'MySQL accepted a server-level connection.');
        }

        return $this->makeCheck(
            'mysql_server',
            'MySQL Server',
            'critical',
            'MySQL server connection failed: ' . $this->friendlyDatabaseMessage((string) ($connection['error'] ?? 'Unknown error.')),
            ['category' => $this->categorizeDatabaseError((string) ($connection['error'] ?? ''))]
        );
    }

    /**
     * @param array<string,mixed> $connection
     * @return array<string,mixed>
     */
    private function checkDatabaseConnection(array $connection): array
    {
        if (($connection['pdo'] ?? null) instanceof PDO) {
            return $this->makeCheck('database', 'Configured Database', 'ok', 'The configured database is reachable.');
        }

        $error = (string) ($connection['error'] ?? '');
        $category = $this->categorizeDatabaseError($error);
        $message = $category === 'missing_database'
            ? sprintf(
                "The configured database '%s' does not exist. Create it or update DB_NAME in .env.",
                $this->safeString($this->config['name'] ?? 'crm_db')
            )
            : 'Database connection failed: ' . $this->friendlyDatabaseMessage($error);

        return $this->makeCheck(
            'database',
            'Configured Database',
            'critical',
            $message,
            ['category' => $category] + $this->safeConfigSummary()
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMigrations(PDO $pdo): array
    {
        try {
            $exists = $this->tableExists($pdo, 'migrations');
            if (!$exists) {
                return $this->makeCheck('migrations', 'Migrations', 'critical', 'The migrations table is missing. Run database/migrations/migrate.php.');
            }

            $files = glob($this->rootPath . '/database/migrations/*.sql') ?: [];
            natsort($files);
            $migrationFiles = array_map('basename', $files);
            $latestFile = (string) end($migrationFiles);
            $rows = $pdo->query("SELECT migration_name, executed_at FROM migrations")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $executed = [];
            $executedAt = [];
            foreach ($rows as $row) {
                $name = (string) ($row['migration_name'] ?? '');
                if ($name !== '') {
                    $executed[$name] = true;
                    $executedAt[$name] = (string) ($row['executed_at'] ?? '');
                }
            }
            $pending = array_values(array_diff($migrationFiles, array_keys($executed)));
            $appliedFiles = array_values(array_intersect($migrationFiles, array_keys($executed)));
            $latestApplied = $appliedFiles !== [] ? (string) end($appliedFiles) : '';

            return $this->makeCheck(
                'migrations',
                'Migrations',
                $pending === [] ? 'ok' : 'critical',
                $pending === []
                    ? 'All migration files have been applied. Latest migration: ' . $latestFile . '.'
                    : count($pending) . ' migration file(s) are pending. Run database/migrations/migrate.php.',
                [
                    'latest_migration' => $latestFile,
                    'latest_applied_migration' => $latestApplied,
                    'executed_at' => $latestApplied !== '' ? ($executedAt[$latestApplied] ?? '') : '',
                    'pending_count' => count($pending),
                    'pending_migrations' => array_slice($pending, 0, 10),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck('migrations', 'Migrations', 'warning', 'Migration status could not be checked.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDefaultWorkspace(PDO $pdo): array
    {
        try {
            if (!$this->tableExists($pdo, 'workspaces')) {
                return $this->makeCheck('default_workspace', 'Default Workspace', 'critical', 'The workspaces table is missing.');
            }

            $contract = $this->contextRegistry->defaultWorkspaceContract();
            $workspaceId = (int) ($contract['workspace_id'] ?? DefaultWorkspaceService::DEFAULT_ID);
            $slug = (string) ($contract['slug'] ?? DefaultWorkspaceService::DEFAULT_SLUG);
            $workspace = $pdo->query("SELECT id, slug, name, settings_json FROM workspaces WHERE id = " . $workspaceId . " LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
            $slugCount = (int) $pdo->query("SELECT COUNT(*) FROM workspaces WHERE slug = " . $pdo->quote($slug))->fetchColumn();
            $settings = $this->decodeJson((string) ($workspace['settings_json'] ?? ''));
            $settingsValidation = $this->contextRegistry->validateDefaultWorkspaceSettings($settings);
            $identityOk = $workspace !== null && (int) ($workspace['id'] ?? 0) === $workspaceId && (string) ($workspace['slug'] ?? '') === $slug && $slugCount === 1;
            $settingsOk = (bool) ($settingsValidation['ok'] ?? false);
            $onboarding = $this->tableExists($pdo, 'workspace_onboarding_state')
                ? ($pdo->query("SELECT status, readiness_score, launch_summary_json, starter_kit_json, optional_setup_json FROM workspace_onboarding_state WHERE workspace_id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [])
                : [];
            $summary = $this->decodeJson((string) ($onboarding['launch_summary_json'] ?? ''));
            $onboardingOk = ($onboarding['status'] ?? '') === 'completed'
                && (int) ($onboarding['readiness_score'] ?? 0) >= 100
                && !empty($onboarding['starter_kit_json'])
                && !empty($onboarding['optional_setup_json'])
                && !empty($summary['operating_brief']);
            $status = $identityOk && $settingsOk && $onboardingOk ? 'ok' : 'warning';
            $missing = [];
            if (!$identityOk) {
                $missing[] = 'canonical_identity';
            }
            if (!$settingsOk) {
                $missing[] = 'platform_ops_settings';
            }
            if (!$onboardingOk) {
                $missing[] = 'operational_onboarding_state';
            }

            return $this->makeCheck(
                'default_workspace',
                'Default Workspace',
                $status,
                $status === 'ok'
                    ? 'Default workspace is canonical and operationalized.'
                    : 'Default workspace needs operationalization repair.',
                [
                    'workspace_id' => (int) ($workspace['id'] ?? 0),
                    'slug' => (string) ($workspace['slug'] ?? ''),
                    'default_slug_count' => $slugCount,
                    'operationalized' => $settingsOk && $onboardingOk,
                    'operational_score' => (int) ($onboarding['readiness_score'] ?? 0),
                    'missing' => $missing,
                    'expected_settings' => (array) ($settingsValidation['expected'] ?? []),
                    'missing_settings' => (array) ($settingsValidation['missing'] ?? []),
                    'mismatched_settings' => (array) ($settingsValidation['mismatched'] ?? []),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck('default_workspace', 'Default Workspace', 'warning', 'Default workspace status could not be checked.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSystemContextRegistry(PDO $pdo): array
    {
        try {
            $report = $this->contextRegistry->validateProductionContext();
            $status = (string) ($report['status'] ?? 'unknown');
            $summary = (array) ($report['summary'] ?? []);
            $findings = array_values(array_filter((array) ($report['findings'] ?? []), 'is_array'));

            return $this->makeCheck(
                'system_context_registry',
                'System Context Registry',
                $status,
                $status === 'ok'
                    ? 'Reusable system, workspace, template, and automation context passed validation.'
                    : 'System context registry has missing or risky production context.',
                [
                    'checked_at' => $report['checked_at'] ?? null,
                    'summary' => $summary,
                    'recent_findings' => array_slice($findings, 0, 10),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck('system_context_registry', 'System Context Registry', 'warning', 'System context registry could not be fully checked.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMarketingRuntime(PDO $pdo): array
    {
        $requiredTables = [
            'marketing_content_items',
            'marketing_campaign_briefs',
            'marketing_brand_profiles',
            'marketing_distribution_posts',
            'marketing_assistant_runs',
        ];
        $missing = [];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($pdo, $table)) {
                $missing[] = $table;
            }
        }

        return $this->makeCheck(
            'marketing_runtime',
            'Marketing Runtime',
            $missing === [] ? 'ok' : 'warning',
            $missing === []
                ? 'Marketing Phase 1-12 tables are present.'
                : 'Some marketing tables are missing: ' . implode(', ', $missing) . '.',
            ['missing_tables' => $missing]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkProductionPreflight(PDO $pdo): array
    {
        try {
            $installerPresent = is_file($this->rootPath . '/public/install_once.php');
            $environment = $this->productionEnvironmentSummary();
            $dataCounts = $this->productionDataSignalCounts($pdo);
            $automationSafety = $this->productionAutomationSafety($pdo);
            $templateBaseline = $this->contextRegistry->defaultWorkspaceTemplateBaseline();

            $blockers = [];
            $warnings = [];

            if ($installerPresent) {
                $blockers[] = 'public_install_once_present';
            }
            if ($dataCounts['smoke_contacts'] > 0) {
                $blockers[] = 'default_workspace_smoke_contacts';
            }
            if ($dataCounts['smoke_deals'] > 0) {
                $blockers[] = 'default_workspace_smoke_deals';
            }
            if ($dataCounts['stale_email_templates'] > 0) {
                $warnings[] = 'stale_generic_email_templates';
            }
            if ($dataCounts['platform_ops_email_templates'] < (int) ($templateBaseline['minimum_platform_ops_email_templates'] ?? 8)) {
                $warnings[] = 'platform_ops_email_templates_missing';
            }
            if ($dataCounts['platform_ops_workflow_templates'] < count((array) ($templateBaseline['required_platform_ops_workflow_template_keys'] ?? []))) {
                $warnings[] = 'platform_ops_workflow_templates_missing';
            }

            $blockers = array_values(array_unique(array_merge($blockers, $automationSafety['blockers'])));
            $warnings = array_values(array_unique(array_merge($warnings, $environment['warnings'], $automationSafety['warnings'])));
            $status = $blockers !== [] ? 'critical' : ($warnings !== [] ? 'warning' : 'ok');

            if ($status === 'critical') {
                $message = count($blockers) . ' production blocker(s) detected before live upload.';
            } elseif ($status === 'warning') {
                $message = 'Automated blockers are clear, but production warnings or live-setting confirmations remain.';
            } else {
                $message = 'Automated production preflight checks are clear; complete the manual live-setting confirmations before upload.';
            }

            return $this->makeCheck(
                'production_preflight',
                'Production Preflight',
                $status,
                $message,
                [
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'install_script_present' => $installerPresent,
                    'environment' => $environment,
                    'data_counts' => $dataCounts,
                    'automation_safety' => $automationSafety['summary'],
                    'manual_confirmations' => [
                        'Set live APP_URL/HTTPS and force HTTPS at the host.',
                        'Confirm SMTP, WhatsApp, and sender identities are live and allowlisted.',
                        'Confirm payment provider mode, plan codes, and webhooks point to live endpoints.',
                        'Confirm scheduled jobs, queue workers, backups, and restore access are configured.',
                        'Confirm secrets and environment files are managed outside git and upload bundles.',
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'production_preflight',
                'Production Preflight',
                'warning',
                'Production preflight could not be fully checked.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTemplateValidation(PDO $pdo): array
    {
        try {
            $report = (new TemplateValidationService($pdo, $this->contextRegistry))->validate();
            $status = (string) ($report['status'] ?? 'unknown');
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

            $message = match ($status) {
                'ok' => 'Production email and workflow templates passed validation.',
                'warning' => (int) ($summary['warning'] ?? 0) . ' template validation warning(s) need review.',
                'critical' => (int) ($summary['critical'] ?? 0) . ' critical template validation finding(s) need repair before live upload.',
                default => 'Template validation status is unknown.',
            };

            return $this->makeCheck(
                'template_validation',
                'Template Validation',
                in_array($status, ['ok', 'warning', 'critical'], true) ? $status : 'unknown',
                $message,
                [
                    'checked_at' => (string) ($report['checked_at'] ?? ''),
                    'summary' => $summary,
                    'recent_findings' => array_slice($findings, 0, 10),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'template_validation',
                'Template Validation',
                'warning',
                'Template validation could not be fully checked.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSecurityHardening(PDO $pdo): array
    {
        try {
            $report = (new SecurityRoleHardeningAuditService($pdo, $this->rootPath))->audit();
            $status = (string) ($report['status'] ?? 'unknown');
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

            $message = match ($status) {
                'ok' => 'RBAC, Super Admin, and public endpoint hardening checks passed.',
                'warning' => (int) ($summary['warning'] ?? 0) . ' security hardening warning(s) need review.',
                'critical' => (int) ($summary['critical'] ?? 0) . ' critical security hardening finding(s) need repair before live upload.',
                default => 'Security hardening status is unknown.',
            };

            return $this->makeCheck(
                'security_hardening',
                'Security Hardening',
                in_array($status, ['ok', 'warning', 'critical'], true) ? $status : 'unknown',
                $message,
                [
                    'checked_at' => (string) ($report['checked_at'] ?? ''),
                    'summary' => $summary,
                    'recent_findings' => array_slice($findings, 0, 12),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'security_hardening',
                'Security Hardening',
                'warning',
                'Security hardening could not be fully checked.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkIntegrationReadiness(PDO $pdo): array
    {
        try {
            $report = (new IntegrationReadinessService($pdo, $this->rootPath))->check();
            $status = (string) ($report['status'] ?? 'unknown');
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
            $domains = is_array($report['domains'] ?? null) ? $report['domains'] : [];

            $message = match ($status) {
                'ok' => 'SMTP, WhatsApp, calendar, payment, AI, jobs, and queues passed readiness checks.',
                'warning' => (int) ($summary['findings'] ?? 0) . ' integration readiness warning(s) need review.',
                'critical' => (int) ($summary['critical'] ?? 0) . ' critical integration readiness domain(s) need repair before live upload.',
                default => 'Integration readiness status is unknown.',
            };

            return $this->makeCheck(
                'integration_readiness',
                'Integration Readiness',
                in_array($status, ['ok', 'warning', 'critical'], true) ? $status : 'unknown',
                $message,
                [
                    'checked_at' => (string) ($report['checked_at'] ?? ''),
                    'summary' => $summary,
                    'domains' => $domains,
                    'recent_findings' => array_slice($findings, 0, 12),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'integration_readiness',
                'Integration Readiness',
                'warning',
                'Integration readiness could not be fully checked.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDemoQuarantine(PDO $pdo): array
    {
        try {
            $report = (new DemoQuarantineVerificationService($pdo, $this->contextRegistry))->verify();
            $status = (string) ($report['status'] ?? 'unknown');
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

            $message = match ($status) {
                'ok' => 'Protected demo, session, and presentation data are quarantined away from the default workspace.',
                'warning' => (int) ($summary['warning'] ?? 0) . ' demo quarantine warning(s) need review.',
                'critical' => (int) ($summary['critical'] ?? 0) . ' critical demo quarantine finding(s) need repair before live upload.',
                default => 'Demo quarantine status is unknown.',
            };

            return $this->makeCheck(
                'demo_quarantine',
                'Demo Quarantine',
                in_array($status, ['ok', 'warning', 'critical'], true) ? $status : 'unknown',
                $message,
                [
                    'checked_at' => (string) ($report['checked_at'] ?? ''),
                    'summary' => $summary,
                    'recent_findings' => array_slice($findings, 0, 12),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'demo_quarantine',
                'Demo Quarantine',
                'warning',
                'Demo quarantine could not be fully checked.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkBackupRestoreReadiness(PDO $pdo): array
    {
        try {
            $report = (new BackupRestoreReadinessService($pdo, $this->rootPath))->check();
            $status = (string) ($report['status'] ?? 'unknown');
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];

            $message = match ($status) {
                'ok' => 'Backup freshness, restore tooling, and recovery expectations are ready.',
                'warning' => (int) ($summary['warning'] ?? 0) . ' backup or restore readiness warning(s) need review.',
                'critical' => (int) ($summary['critical'] ?? 0) . ' critical backup or restore readiness finding(s) need repair before live upload.',
                default => 'Backup and restore readiness status is unknown.',
            };

            return $this->makeCheck(
                'backup_restore_readiness',
                'Backup & Restore',
                in_array($status, ['ok', 'warning', 'critical'], true) ? $status : 'unknown',
                $message,
                [
                    'checked_at' => (string) ($report['checked_at'] ?? ''),
                    'summary' => $summary,
                    'expectations' => is_array($report['expectations'] ?? null) ? $report['expectations'] : [],
                    'artifacts' => is_array($report['artifacts'] ?? null) ? $report['artifacts'] : [],
                    'tools' => is_array($report['tools'] ?? null) ? $report['tools'] : [],
                    'recent_findings' => array_slice($findings, 0, 12),
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'backup_restore_readiness',
                'Backup & Restore',
                'warning',
                'Backup and restore readiness could not be fully checked.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkWritablePaths(): array
    {
        $runtimePaths = (new RuntimePathService($this->rootPath))->status();
        $paths = (array) ($runtimePaths['paths_checked'] ?? RuntimePathService::requiredPaths());
        $notWritable = (array) ($runtimePaths['not_writable'] ?? []);
        $missing = (array) ($runtimePaths['missing'] ?? []);
        $problemPaths = array_values(array_unique(array_merge($missing, $notWritable)));

        return $this->makeCheck(
            'writable_paths',
            'Writable Runtime Paths',
            $problemPaths === [] ? 'ok' : 'warning',
            $problemPaths === []
                ? 'Runtime paths are writable: ' . implode(', ', $paths) . '.'
                : 'Some runtime paths are missing or not writable: ' . implode(', ', $problemPaths) . '.',
            $runtimePaths
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPhpConcurrencyRuntime(): array
    {
        $opcacheEnabled = extension_loaded('Zend OPcache')
            && filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL);
        $sessionHandler = strtolower((string) ini_get('session.save_handler'));
        $cacheDriver = strtolower(trim((string) ($_ENV['CACHE_DRIVER'] ?? getenv('CACHE_DRIVER') ?: 'auto')));
        $instanceCount = max(1, (int) ($_ENV['APP_INSTANCE_COUNT'] ?? getenv('APP_INSTANCE_COUNT') ?: 1));
        $sharedSession = in_array($sessionHandler, ['redis', 'memcached'], true);
        $warnings = [];
        if (!$opcacheEnabled) {
            $warnings[] = 'opcache_disabled';
        }
        if ($instanceCount > 1 && !$sharedSession) {
            $warnings[] = 'multi_node_sessions_not_shared';
        }
        if ($instanceCount > 1 && !in_array($cacheDriver, ['redis', 'memcached'], true)) {
            $warnings[] = 'multi_node_cache_not_shared';
        }

        return $this->makeCheck(
            'php_concurrency_runtime',
            'PHP Concurrent-use Runtime',
            $warnings === [] ? 'ok' : 'warning',
            $warnings === []
                ? 'OPcache and the configured session/cache topology are concurrency-ready.'
                : 'PHP runtime tuning still needs attention: ' . implode(', ', $warnings) . '.',
            [
                'opcache_enabled' => $opcacheEnabled,
                'session_handler' => $sessionHandler,
                'cache_driver' => $cacheDriver,
                'app_instance_count' => $instanceCount,
                'redis_extension' => extension_loaded('redis'),
                'memcached_extension' => extension_loaded('memcached'),
                'warnings' => $warnings,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkConcurrencyRuntime(PDO $pdo): array
    {
        try {
            $database = (string) ($this->config['name'] ?? '');
            $schema = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = ? AND table_type = 'BASE TABLE') AS base_tables,
                    (SELECT COUNT(DISTINCT table_name) FROM information_schema.statistics
                     WHERE table_schema = ? AND index_name = 'PRIMARY') AS primary_key_tables,
                    (SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = ? AND table_type = 'BASE TABLE' AND engine <> 'InnoDB') AS non_innodb_tables"
            );
            $schema->execute([$database, $database, $database]);
            $schemaRow = $schema->fetch(PDO::FETCH_ASSOC) ?: [];
            $baseTables = (int) ($schemaRow['base_tables'] ?? 0);
            $primaryKeyTables = (int) ($schemaRow['primary_key_tables'] ?? 0);
            $nonInnoDbTables = (int) ($schemaRow['non_innodb_tables'] ?? 0);
            $sqlMode = strtoupper((string) ($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn() ?: ''));

            $leaseColumns = $this->fetchCount(
                $pdo,
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = 'email_queue'
                   AND column_name IN ('claim_token', 'claimed_at', 'lease_expires_at', 'worker_id')",
                [$database]
            );
            $workflowLeaseColumns = $this->fetchCount(
                $pdo,
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = 'workflow_queue'
                   AND column_name IN ('claim_token', 'claimed_at', 'lease_expires_at', 'worker_id')",
                [$database]
            );
            $heartbeatTable = $this->tableExists($pdo, 'queue_worker_heartbeats');
            $latestHeartbeat = null;
            if ($heartbeatTable) {
                $latestHeartbeat = $pdo->query(
                    "SELECT MAX(heartbeat_at) FROM queue_worker_heartbeats WHERE status = 'running'"
                )->fetchColumn();
            }

            $bufferPoolBytes = 0;
            $variableStatement = $pdo->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
            $variableRow = $variableStatement ? $variableStatement->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($variableRow)) {
                $bufferPoolBytes = (int) ($variableRow['Value'] ?? 0);
            }
            $dataBytesStatement = $pdo->prepare(
                "SELECT COALESCE(SUM(data_length + index_length), 0)
                 FROM information_schema.tables WHERE table_schema = ?"
            );
            $dataBytesStatement->execute([$database]);
            $databaseBytes = (int) $dataBytesStatement->fetchColumn();

            $critical = [];
            $warnings = [];
            if ($baseTables <= 0 || $baseTables !== $primaryKeyTables) {
                $critical[] = 'missing_primary_keys';
            }
            if ($nonInnoDbTables > 0) {
                $critical[] = 'non_innodb_tables';
            }
            if (!str_contains($sqlMode, 'STRICT_TRANS_TABLES') && !str_contains($sqlMode, 'STRICT_ALL_TABLES')) {
                $critical[] = 'strict_sql_mode_disabled';
            }
            if ($leaseColumns !== 4 || $workflowLeaseColumns !== 4 || !$heartbeatTable) {
                $critical[] = 'queue_concurrency_controls_missing';
            }
            if ($latestHeartbeat === false || $latestHeartbeat === null || $latestHeartbeat === '') {
                $warnings[] = 'no_running_worker_heartbeat';
            } elseif (strtotime((string) $latestHeartbeat) < time() - 300) {
                $warnings[] = 'worker_heartbeat_stale';
            }
            $recommendedBufferBytes = max(128 * 1024 * 1024, (int) ceil($databaseBytes * 1.25));
            if ($bufferPoolBytes < $recommendedBufferBytes) {
                $warnings[] = 'innodb_buffer_pool_small';
            }

            $status = $critical !== [] ? 'critical' : ($warnings !== [] ? 'warning' : 'ok');
            return $this->makeCheck(
                'concurrency_runtime',
                'Concurrent-use Runtime',
                $status,
                $status === 'ok'
                    ? 'Database identities, transactions, queue leases, workers, and memory sizing are concurrency-ready.'
                    : 'Concurrent-use findings: ' . implode(', ', array_merge($critical, $warnings)) . '.',
                [
                    'base_tables' => $baseTables,
                    'primary_key_tables' => $primaryKeyTables,
                    'non_innodb_tables' => $nonInnoDbTables,
                    'strict_sql_mode' => $sqlMode,
                    'email_queue_lease_columns' => $leaseColumns,
                    'workflow_queue_lease_columns' => $workflowLeaseColumns,
                    'latest_running_worker_heartbeat' => $latestHeartbeat ?: null,
                    'innodb_buffer_pool_bytes' => $bufferPoolBytes,
                    'database_bytes' => $databaseBytes,
                    'recommended_buffer_pool_bytes' => $recommendedBufferBytes,
                    'critical' => $critical,
                    'warnings' => $warnings,
                ]
            );
        } catch (\Throwable $e) {
            return $this->makeCheck(
                'concurrency_runtime',
                'Concurrent-use Runtime',
                'warning',
                'Concurrent-use runtime checks could not be completed.'
            );
        }
    }

    /**
     * @return array{pdo:?PDO,error:?string}
     */
    private function connect(bool $withDatabase): array
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $database = trim((string) ($this->config['name'] ?? ''));
        $user = (string) ($this->config['user'] ?? '');
        $pass = (string) ($this->config['pass'] ?? '');

        if ($host === '' || ($withDatabase && $database === '')) {
            return ['pdo' => null, 'error' => 'Database host or name is not configured.'];
        }

        $dsn = 'mysql:host=' . $host;
        if ($withDatabase) {
            $dsn .= ';dbname=' . $database;
        }
        $charset = trim((string) ($this->config['charset'] ?? ''));
        if ($charset !== '') {
            $dsn .= ';charset=' . $charset;
        }

        try {
            $connectionOptions = $this->config['options'] ?? [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ];
            $pdo = new PDO($dsn, $user, $pass, $connectionOptions);
            return ['pdo' => $pdo, 'error' => null];
        } catch (PDOException $e) {
            return ['pdo' => null, 'error' => $this->sanitizeDatabaseError($e->getMessage())];
        }
    }

    private function hasPdoMysql(): bool
    {
        return class_exists(PDO::class) && in_array('mysql', PDO::getAvailableDrivers(), true);
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        $stmt = $pdo->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table) . ' WHERE Field = ' . $pdo->quote($column));
        return (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<int,mixed> $params
     */
    private function fetchCount(PDO $pdo, string $sql, array $params = []): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function productionEnvironmentSummary(): array
    {
        $values = $this->readEnvironmentValues(['APP_ENV', 'APP_DEBUG', 'APP_URL']);
        $appEnv = strtolower(trim((string) ($values['APP_ENV'] ?? getenv('APP_ENV') ?: '')));
        $appDebugRaw = strtolower(trim((string) ($values['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '')));
        $appUrl = trim((string) ($values['APP_URL'] ?? getenv('APP_URL') ?: ''));
        $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');

        $warnings = [];
        if ($appEnv === '' || $appEnv !== 'production') {
            $warnings[] = 'app_env_not_production';
        }
        if (in_array($appDebugRaw, ['1', 'true', 'yes', 'on'], true)) {
            $warnings[] = 'app_debug_enabled';
        }
        if ($appUrl === '') {
            $warnings[] = 'app_url_missing';
        } elseif (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            $warnings[] = 'app_url_local';
        }

        return [
            'app_env' => $appEnv !== '' ? $appEnv : 'not_set',
            'app_debug_enabled' => in_array($appDebugRaw, ['1', 'true', 'yes', 'on'], true),
            'app_url_configured' => $appUrl !== '',
            'app_url_host' => $this->safeString($host, 120),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int,string> $keys
     * @return array<string,string>
     */
    private function readEnvironmentValues(array $keys): array
    {
        $envPath = $this->rootPath . '/.env';
        if (!is_file($envPath)) {
            return [];
        }

        $allowed = array_fill_keys($keys, true);
        $values = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!isset($allowed[$key])) {
                continue;
            }

            $values[$key] = trim($value, "\"'");
        }

        return $values;
    }

    /**
     * @return array<string,int>
     */
    private function productionDataSignalCounts(PDO $pdo): array
    {
        return [
            'smoke_contacts' => $this->countDefaultWorkspaceSmokeContacts($pdo),
            'smoke_deals' => $this->countDefaultWorkspaceSmokeDeals($pdo),
            'stale_email_templates' => $this->countStaleGenericEmailTemplates($pdo),
            'starter_workflow_templates' => $this->countStarterWorkflowTemplates($pdo),
            'platform_ops_email_templates' => $this->countPlatformOpsEmailTemplates($pdo),
            'platform_ops_workflow_templates' => $this->countPlatformOpsWorkflowTemplates($pdo),
        ];
    }

    private function countDefaultWorkspaceSmokeContacts(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'contacts') || !$this->columnExists($pdo, 'contacts', 'workspace_id')) {
            return 0;
        }

        $conditions = [];
        if ($this->columnExists($pdo, 'contacts', 'email')) {
            $conditions[] = "LOWER(COALESCE(email, '')) LIKE '%@example.test'";
            $conditions[] = "LOWER(COALESCE(email, '')) LIKE '%@demo.local.invalid'";
        }
        if ($this->columnExists($pdo, 'contacts', 'metadata_json')) {
            $conditions[] = "LOWER(COALESCE(metadata_json, '')) LIKE '%playwright%'";
            $conditions[] = "LOWER(COALESCE(metadata_json, '')) LIKE '%codex verification%'";
            $conditions[] = "LOWER(COALESCE(metadata_json, '')) LIKE '%presentation_workspace%'";
        }
        if ($this->columnExists($pdo, 'contacts', 'demo_session_id')) {
            $conditions[] = 'demo_session_id IS NOT NULL';
        }
        if ($this->columnExists($pdo, 'contacts', 'demo_visibility')) {
            $conditions[] = "COALESCE(demo_visibility, '') <> ''";
        }

        if ($conditions === []) {
            return 0;
        }

        return $this->fetchCount(
            $pdo,
            'SELECT COUNT(*) FROM contacts WHERE workspace_id = 1 AND (' . implode(' OR ', $conditions) . ')'
        );
    }

    private function countDefaultWorkspaceSmokeDeals(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'deals') || !$this->columnExists($pdo, 'deals', 'workspace_id')) {
            return 0;
        }

        $conditions = [];
        if ($this->columnExists($pdo, 'deals', 'title')) {
            $conditions[] = "LOWER(COALESCE(title, '')) LIKE '%codex verification%'";
            $conditions[] = "LOWER(COALESCE(title, '')) LIKE '%playwright%'";
            $conditions[] = "LOWER(COALESCE(title, '')) LIKE '%presentation workspace%'";
        }
        if ($this->columnExists($pdo, 'deals', 'custom_fields')) {
            $conditions[] = "LOWER(COALESCE(custom_fields, '')) LIKE '%codex-verification-presentation-workspace%'";
            $conditions[] = "LOWER(COALESCE(custom_fields, '')) LIKE '%codex verification%'";
            $conditions[] = "LOWER(COALESCE(custom_fields, '')) LIKE '%playwright%'";
            $conditions[] = "LOWER(COALESCE(custom_fields, '')) LIKE '%presentation_workspace%'";
        }
        if ($this->columnExists($pdo, 'deals', 'demo_session_id')) {
            $conditions[] = 'demo_session_id IS NOT NULL';
        }
        if ($this->columnExists($pdo, 'deals', 'demo_visibility')) {
            $conditions[] = "COALESCE(demo_visibility, '') <> ''";
        }

        if ($conditions === []) {
            return 0;
        }

        return $this->fetchCount(
            $pdo,
            'SELECT COUNT(*) FROM deals WHERE workspace_id = 1 AND (' . implode(' OR ', $conditions) . ')'
        );
    }

    private function countStaleGenericEmailTemplates(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'email_templates') || !$this->columnExists($pdo, 'email_templates', 'slug')) {
            return 0;
        }

        $conditions = ["slug IN ('welcome', 'follow_up', 'thank_you')"];
        if ($this->columnExists($pdo, 'email_templates', 'is_active')) {
            $conditions[] = 'is_active = 1';
        }
        if ($this->columnExists($pdo, 'email_templates', 'workspace_id')) {
            $conditions[] = '(workspace_id = 1 OR workspace_id IS NULL)';
        }

        return $this->fetchCount($pdo, 'SELECT COUNT(*) FROM email_templates WHERE ' . implode(' AND ', $conditions));
    }

    private function countStarterWorkflowTemplates(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'workflow_templates') || !$this->columnExists($pdo, 'workflow_templates', 'name')) {
            return 0;
        }

        $conditions = ["name IN ('Welcome New Contacts', 'Lead Nurturing Sequence', 'Re-engagement Campaign', 'Deal Follow-up', 'Birthday Automation')"];
        if ($this->columnExists($pdo, 'workflow_templates', 'is_active')) {
            $conditions[] = 'is_active = 1';
        }

        return $this->fetchCount($pdo, 'SELECT COUNT(*) FROM workflow_templates WHERE ' . implode(' AND ', $conditions));
    }

    private function countPlatformOpsEmailTemplates(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'email_templates') || !$this->columnExists($pdo, 'email_templates', 'slug')) {
            return 0;
        }

        $conditions = ["slug LIKE 'platform-ops-%'"];
        if ($this->columnExists($pdo, 'email_templates', 'is_active')) {
            $conditions[] = 'is_active = 1';
        }
        if ($this->columnExists($pdo, 'email_templates', 'workspace_id')) {
            $conditions[] = 'workspace_id = 1';
        }

        return $this->fetchCount($pdo, 'SELECT COUNT(*) FROM email_templates WHERE ' . implode(' AND ', $conditions));
    }

    private function countPlatformOpsWorkflowTemplates(PDO $pdo): int
    {
        if (!$this->tableExists($pdo, 'workflow_templates') || !$this->columnExists($pdo, 'workflow_templates', 'template_key')) {
            return 0;
        }

        $baseline = $this->contextRegistry->defaultWorkspaceTemplateBaseline();
        $templateKeys = array_values(array_filter(array_map('strval', (array) ($baseline['required_platform_ops_workflow_template_keys'] ?? []))));
        if ($templateKeys === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($templateKeys), '?'));
        $conditions = ["template_key IN ({$placeholders})"];
        if ($this->columnExists($pdo, 'workflow_templates', 'is_active')) {
            $conditions[] = 'is_active = 1';
        }

        return $this->fetchCount($pdo, 'SELECT COUNT(*) FROM workflow_templates WHERE ' . implode(' AND ', $conditions), $templateKeys);
    }

    /**
     * @return array{blockers:array<int,string>,warnings:array<int,string>,summary:array<string,mixed>}
     */
    private function productionAutomationSafety(PDO $pdo): array
    {
        $blockers = [];
        $warnings = [];
        $summary = [
            'auto_admin_enabled' => false,
            'auto_admin_effective_modes' => [],
            'ai_autoresponder_mode' => 'unknown',
            'commercial_automation_mode' => 'unknown',
            'commercial_auto_send_enabled' => null,
            'deal_automation_mode' => 'unknown',
            'cold_outreach_enabled_channels' => [],
            'workflow_autonomy_mode' => 'unknown',
        ];

        if ($this->tableExists($pdo, 'workspace_auto_admin_settings')) {
            $row = $pdo->query('SELECT enabled, manual_freeze, effective_modes_json FROM workspace_auto_admin_settings WHERE workspace_id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['auto_admin_enabled'] = !empty($row['enabled']);
            $summary['auto_admin_effective_modes'] = $this->decodeJson((string) ($row['effective_modes_json'] ?? ''));
            $expectedModes = [
                'deal_automation' => 'suggest_only',
                'workflow_automation' => 'auto_safe',
                'ai_autoresponder' => 'draft_only',
                'commercial_automation' => 'auto_safe',
            ];
            foreach ($expectedModes as $key => $expected) {
                if (($summary['auto_admin_effective_modes'][$key] ?? '') !== $expected) {
                    $warnings[] = 'auto_admin_mode_' . $key . '_not_baseline';
                }
            }
            if (empty($row['enabled'])) {
                $warnings[] = 'workspace_auto_admin_disabled';
            }
            if (!empty($row['manual_freeze'])) {
                $warnings[] = 'workspace_auto_admin_frozen';
            }
        } else {
            $warnings[] = 'workspace_auto_admin_settings_missing';
        }

        if ($this->tableExists($pdo, 'workspace_ai_autoresponder_config')) {
            $row = $pdo->query('SELECT enabled, mode FROM workspace_ai_autoresponder_config WHERE workspace_id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['ai_autoresponder_mode'] = (string) ($row['mode'] ?? 'unknown');
            if ((string) ($row['mode'] ?? '') === 'full_auto') {
                $blockers[] = 'ai_autoresponder_full_auto';
            } elseif ((string) ($row['mode'] ?? '') !== 'draft_only') {
                $warnings[] = 'ai_autoresponder_not_draft_only';
            }
        } else {
            $warnings[] = 'workspace_ai_autoresponder_config_missing';
        }

        if ($this->tableExists($pdo, 'workspace_commercial_automation_config')) {
            $row = $pdo->query('SELECT enabled, mode, auto_send_enabled FROM workspace_commercial_automation_config WHERE workspace_id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['commercial_automation_mode'] = (string) ($row['mode'] ?? 'unknown');
            $summary['commercial_auto_send_enabled'] = isset($row['auto_send_enabled']) ? !empty($row['auto_send_enabled']) : null;
            if ((string) ($row['mode'] ?? '') === 'full_auto') {
                $blockers[] = 'commercial_automation_full_auto';
            }
            if (!empty($row['auto_send_enabled'])) {
                $blockers[] = 'commercial_auto_send_enabled';
            }
        } else {
            $warnings[] = 'workspace_commercial_automation_config_missing';
        }

        if ($this->tableExists($pdo, 'workspace_deal_automation_config')) {
            $row = $pdo->query('SELECT enabled, mode FROM workspace_deal_automation_config WHERE workspace_id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['deal_automation_mode'] = (string) ($row['mode'] ?? 'unknown');
            if ((string) ($row['mode'] ?? '') === 'full_auto') {
                $blockers[] = 'deal_automation_full_auto';
            } elseif ((string) ($row['mode'] ?? '') !== 'suggest_only') {
                $warnings[] = 'deal_automation_not_suggest_only';
            }
        } else {
            $warnings[] = 'workspace_deal_automation_config_missing';
        }

        if ($this->tableExists($pdo, 'workspace_cold_outreach_warmup_config')) {
            $rows = $pdo->query(
                'SELECT channel FROM workspace_cold_outreach_warmup_config WHERE workspace_id = 1 AND (enabled = 1 OR auto_admin_warmup_enabled = 1)'
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $summary['cold_outreach_enabled_channels'] = array_values(array_map('strval', $rows));
            if ($rows !== []) {
                $blockers[] = 'cold_outreach_enabled';
            }
        } else {
            $warnings[] = 'workspace_cold_outreach_warmup_config_missing';
        }

        if ($this->tableExists($pdo, 'ai_autonomy_domain_controls')) {
            $row = $pdo->query(
                "SELECT autonomy_mode FROM ai_autonomy_domain_controls WHERE workspace_id = 1 AND domain_key = 'workflow_execution' LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['workflow_autonomy_mode'] = (string) ($row['autonomy_mode'] ?? 'unknown');
            if ((string) ($row['autonomy_mode'] ?? '') === 'full_auto') {
                $blockers[] = 'workflow_autonomy_full_auto';
            } elseif ((string) ($row['autonomy_mode'] ?? '') !== 'auto_safe') {
                $warnings[] = 'workflow_autonomy_not_auto_safe';
            }
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function makeCheck(string $key, string $label, string $status, string $message, array $metadata = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     */
    private function overallStatus(array $checks): string
    {
        $statuses = array_map(static fn(array $check): string => (string) ($check['status'] ?? 'unknown'), $checks);
        if (in_array('critical', $statuses, true)) {
            return 'critical';
        }
        if (in_array('warning', $statuses, true) || in_array('unknown', $statuses, true)) {
            return 'warning';
        }
        return 'ok';
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @return array<string,int>
     */
    private function summarize(array $checks): array
    {
        $summary = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'unknown');
            if (!array_key_exists($status, $summary)) {
                $status = 'unknown';
            }
            $summary[$status]++;
        }
        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadDatabaseConfig(): array
    {
        $configFile = $this->rootPath . '/config/database.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }

    private function categorizeDatabaseError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'unknown database')) {
            return 'missing_database';
        }
        if (str_contains($lower, 'access denied')) {
            return 'access_denied';
        }
        if (
            str_contains($lower, 'connection refused')
            || str_contains($lower, 'no such host')
            || str_contains($lower, 'php_network_getaddresses')
            || str_contains($lower, 'getaddrinfo')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'actively refused')
        ) {
            return 'server_unreachable';
        }
        return 'connection_failed';
    }

    private function friendlyDatabaseMessage(string $message): string
    {
        $category = $this->categorizeDatabaseError($message);
        return match ($category) {
            'missing_database' => sprintf(
                "Database '%s' does not exist. Create it or update DB_NAME in .env.",
                $this->safeString($this->config['name'] ?? 'crm_db')
            ),
            'access_denied' => 'The configured database user or password was rejected.',
            'server_unreachable' => 'The database host could not be reached.',
            default => $this->safeString($message),
        };
    }

    private function sanitizeDatabaseError(string $message): string
    {
        $sanitized = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        $password = (string) ($this->config['pass'] ?? '');
        if ($password !== '') {
            $sanitized = str_replace($password, '[redacted]', $sanitized);
        }
        return $this->safeString($sanitized, 500);
    }

    private function safeString(mixed $value, int $length = 160): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, $length);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
