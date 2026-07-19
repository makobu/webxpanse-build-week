<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\SystemReadinessService;
use CRM\Tests\DatabaseTestCase;

class SystemReadinessServiceTest extends DatabaseTestCase
{
    public function testReadinessReportsHealthyDatabaseAndLatestMigration(): void
    {
        $service = new SystemReadinessService($this->currentTestDatabaseConfig(), dirname(__DIR__, 3));
        $readiness = $service->check();

        $this->assertSame('ok', $this->checkByKey($readiness, 'database')['status'] ?? null);
        $this->assertSame('ok', $this->checkByKey($readiness, 'migrations')['status'] ?? null);
        $this->assertSame('ok', $this->checkByKey($readiness, 'default_workspace')['status'] ?? null);
        $this->assertSame('ok', $this->checkByKey($readiness, 'system_context_registry')['status'] ?? null);
        $this->assertSame('ok', $this->checkByKey($readiness, 'marketing_runtime')['status'] ?? null);
        $this->assertContains($this->checkByKey($readiness, 'production_preflight')['status'] ?? null, ['ok', 'warning']);
        $this->assertContains($this->checkByKey($readiness, 'template_validation')['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertContains($this->checkByKey($readiness, 'security_hardening')['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertContains($this->checkByKey($readiness, 'integration_readiness')['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertContains($this->checkByKey($readiness, 'demo_quarantine')['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertContains($this->checkByKey($readiness, 'backup_restore_readiness')['status'] ?? null, ['ok', 'warning', 'critical']);

        $migrationFiles = glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') ?: [];
        natsort($migrationFiles);
        $latestMigration = basename((string) end($migrationFiles));
        $migrationMetadata = (array) ($this->checkByKey($readiness, 'migrations')['metadata'] ?? []);
        $preflightMetadata = (array) ($this->checkByKey($readiness, 'production_preflight')['metadata'] ?? []);
        $systemContextMetadata = (array) ($this->checkByKey($readiness, 'system_context_registry')['metadata'] ?? []);
        $templateValidationMetadata = (array) ($this->checkByKey($readiness, 'template_validation')['metadata'] ?? []);
        $securityHardeningMetadata = (array) ($this->checkByKey($readiness, 'security_hardening')['metadata'] ?? []);
        $integrationReadinessMetadata = (array) ($this->checkByKey($readiness, 'integration_readiness')['metadata'] ?? []);
        $demoQuarantineMetadata = (array) ($this->checkByKey($readiness, 'demo_quarantine')['metadata'] ?? []);
        $backupRestoreMetadata = (array) ($this->checkByKey($readiness, 'backup_restore_readiness')['metadata'] ?? []);

        $this->assertSame($latestMigration, $migrationMetadata['latest_migration'] ?? null);
        $this->assertSame(0, (int) ($migrationMetadata['pending_count'] ?? -1));
        $this->assertSame([], (array) ($preflightMetadata['blockers'] ?? ['missing']));
        $this->assertFalse((bool) ($preflightMetadata['install_script_present'] ?? true));
        $this->assertArrayHasKey('summary', $systemContextMetadata);
        $this->assertArrayHasKey('recent_findings', $systemContextMetadata);
        $this->assertArrayHasKey('summary', $templateValidationMetadata);
        $this->assertArrayHasKey('recent_findings', $templateValidationMetadata);
        $this->assertArrayHasKey('summary', $securityHardeningMetadata);
        $this->assertArrayHasKey('recent_findings', $securityHardeningMetadata);
        $this->assertArrayHasKey('summary', $integrationReadinessMetadata);
        $this->assertArrayHasKey('domains', $integrationReadinessMetadata);
        $this->assertArrayHasKey('recent_findings', $integrationReadinessMetadata);
        $this->assertArrayHasKey('summary', $demoQuarantineMetadata);
        $this->assertArrayHasKey('recent_findings', $demoQuarantineMetadata);
        $this->assertArrayHasKey('summary', $backupRestoreMetadata);
        $this->assertArrayHasKey('expectations', $backupRestoreMetadata);
        $this->assertArrayHasKey('recent_findings', $backupRestoreMetadata);
    }

    public function testReadinessReportsPendingMigrationFiles(): void
    {
        $migrationFiles = glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') ?: [];
        natsort($migrationFiles);
        $latestMigration = basename((string) end($migrationFiles));
        Database::execute("DELETE FROM migrations WHERE migration_name = ?", [$latestMigration]);

        $service = new SystemReadinessService($this->currentTestDatabaseConfig(), dirname(__DIR__, 3));
        $readiness = $service->check();
        $migrationCheck = $this->checkByKey($readiness, 'migrations');
        $metadata = (array) ($migrationCheck['metadata'] ?? []);

        $this->assertSame('critical', $migrationCheck['status'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($metadata['pending_count'] ?? 0));
        $this->assertContains($latestMigration, (array) ($metadata['pending_migrations'] ?? []));
    }

    public function testReadinessReportsMissingDatabaseWithoutLeakingPassword(): void
    {
        $config = $this->currentTestDatabaseConfig();
        $config['name'] = 'crm_missing_' . substr(hash('sha256', __METHOD__), 0, 12);

        $service = new SystemReadinessService($config, dirname(__DIR__, 3));
        $readiness = $service->check();
        $databaseCheck = $this->checkByKey($readiness, 'database');

        $this->assertSame('critical', $databaseCheck['status'] ?? null);
        $this->assertStringContainsString($config['name'], (string) ($databaseCheck['message'] ?? ''));
        if ((string) ($config['pass'] ?? '') !== '') {
            $this->assertStringNotContainsString((string) $config['pass'], json_encode($readiness) ?: '');
        }
        $this->assertArrayNotHasKey('pass', (array) ($databaseCheck['metadata'] ?? []));
    }

    public function testProductionPreflightFlagsPublicInstallerAndSmokeData(): void
    {
        $root = $this->temporaryReadinessRoot(true);
        try {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
                 VALUES (1, UUID(), 'Preflight', 'Smoke', 'production-preflight@example.test', NOW())"
            );

            $service = new SystemReadinessService($this->currentTestDatabaseConfig(), $root);
            $readiness = $service->check();
            $preflightCheck = $this->checkByKey($readiness, 'production_preflight');
            $metadata = (array) ($preflightCheck['metadata'] ?? []);
            $dataCounts = (array) ($metadata['data_counts'] ?? []);

            $this->assertSame('critical', $preflightCheck['status'] ?? null);
            $this->assertContains('public_install_once_present', (array) ($metadata['blockers'] ?? []));
            $this->assertContains('default_workspace_smoke_contacts', (array) ($metadata['blockers'] ?? []));
            $this->assertGreaterThanOrEqual(1, (int) ($dataCounts['smoke_contacts'] ?? 0));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testProductionPreflightAllowsOperationalDefaultWorkspacePipelineDeals(): void
    {
        $root = $this->temporaryReadinessRoot(false);
        try {
            Database::execute(
                "INSERT INTO deals
                    (workspace_id, title, description, created_by, stage, value, probability, currency, lead_source, custom_fields, created_at, updated_at)
                 VALUES
                    (1, 'Real Owner conversion', 'Default workspace conversion opportunity for a real owner workspace.', 1, 'qualification', 0, 40, 'KES', 'other', ?, NOW(), NOW())",
                [json_encode([
                    'default_workspace_pipeline' => true,
                    'owner_workspace_id' => 42,
                    'owner_user_id' => 84,
                    'owner_workspace_plan_status' => 'active',
                    'conversion_signal' => 'package_active',
                ], JSON_UNESCAPED_SLASHES)]
            );

            $service = new SystemReadinessService($this->currentTestDatabaseConfig(), $root);
            $readiness = $service->check();
            $preflightCheck = $this->checkByKey($readiness, 'production_preflight');
            $metadata = (array) ($preflightCheck['metadata'] ?? []);

            $this->assertNotContains('default_workspace_smoke_deals', (array) ($metadata['blockers'] ?? []));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testProductionPreflightFlagsPresentationPipelineDeals(): void
    {
        $root = $this->temporaryReadinessRoot(false);
        try {
            Database::execute(
                "INSERT INTO deals
                    (workspace_id, title, description, created_by, stage, value, probability, currency, lead_source, custom_fields, created_at, updated_at)
                 VALUES
                    (1, 'Codex Verification Presentation Workspace conversion', 'Default workspace presentation smoke deal.', 1, 'qualification', 0, 40, 'KES', 'other', ?, NOW(), NOW())",
                [json_encode([
                    'default_workspace_pipeline' => true,
                    'owner_workspace_slug' => 'codex-verification-presentation-workspace',
                    'presentation_workspace' => true,
                ], JSON_UNESCAPED_SLASHES)]
            );

            $service = new SystemReadinessService($this->currentTestDatabaseConfig(), $root);
            $readiness = $service->check();
            $preflightCheck = $this->checkByKey($readiness, 'production_preflight');
            $metadata = (array) ($preflightCheck['metadata'] ?? []);

            $this->assertSame('critical', $preflightCheck['status'] ?? null);
            $this->assertContains('default_workspace_smoke_deals', (array) ($metadata['blockers'] ?? []));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testProductionPreflightBlocksUnsafeOutboundAutomation(): void
    {
        $root = $this->temporaryReadinessRoot(false);
        try {
            Database::execute(
                "UPDATE workspace_commercial_automation_config
                 SET auto_send_enabled = 1
                 WHERE workspace_id = 1"
            );

            $service = new SystemReadinessService($this->currentTestDatabaseConfig(), $root);
            $readiness = $service->check();
            $preflightCheck = $this->checkByKey($readiness, 'production_preflight');
            $metadata = (array) ($preflightCheck['metadata'] ?? []);

            $this->assertSame('critical', $preflightCheck['status'] ?? null);
            $this->assertContains('commercial_auto_send_enabled', (array) ($metadata['blockers'] ?? []));
        } finally {
            $this->removeDirectory($root);
        }
    }

    /**
     * @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    private function checkByKey(array $readiness, string $key): array
    {
        foreach ((array) ($readiness['checks'] ?? []) as $check) {
            if (is_array($check) && ($check['key'] ?? '') === $key) {
                return $check;
            }
        }

        return [];
    }

    private function temporaryReadinessRoot(bool $withInstaller): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-readiness-' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'public', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'cache', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'logs', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'tmp', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'uploads', 0777, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . '.env', "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://crm.example.com\n");
        if ($withInstaller) {
            file_put_contents($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'install_once.php', '<?php // test installer');
        }

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
