<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AutomationCatalogService;
use CRM\Services\AutomationDetectorExecutionService;
use CRM\Services\SystemReadinessService;
use CRM\Tests\DatabaseTestCase;

class AutomationDetectorExecutionServiceTest extends DatabaseTestCase
{
    public function testProductionReadinessDetectorsRecordReviewRunsFromPreflightEvidence(): void
    {
        $service = new AutomationDetectorExecutionService(null, new StubReadinessService($this->readinessPayload()));

        $result = $service->runProductionReadinessDetectors(1);

        $this->assertTrue((bool) ($result['persisted'] ?? false));
        $this->assertSame(20, (int) ($result['summary']['evaluated'] ?? 0));
        $this->assertSame(20, (int) ($result['summary']['recorded'] ?? 0));
        $this->assertSame(0, (int) ($result['summary']['skipped'] ?? -1));
        $this->assertSame((new AutomationCatalogService())->firstTwentyKeys(), (array) ($result['supported_detectors'] ?? []));

        $rows = $this->runsByAutomationKey();
        foreach ((new AutomationCatalogService())->firstTwentyKeys() as $key) {
            $this->assertArrayHasKey($key, $rows);
        }

        $this->assertSame('suggested', (string) ($rows['missing_production_settings_detector']['run_status'] ?? ''));
        $this->assertSame('open', (string) ($rows['missing_production_settings_detector']['review_status'] ?? ''));
        $this->assertSame('critical', (string) ($rows['failed_migration_detector']['severity'] ?? ''));
        $this->assertSame('suggested', (string) ($rows['demo_test_data_detector']['run_status'] ?? ''));
        $this->assertSame('observed', (string) ($rows['broken_template_detector']['run_status'] ?? ''));
        $this->assertSame('suggested', (string) ($rows['disabled_critical_module_detector']['run_status'] ?? ''));
        $this->assertSame('prepared', (string) ($rows['daily_superadmin_health_digest']['run_status'] ?? ''));

        $digestEvidence = json_decode((string) ($rows['daily_superadmin_health_digest']['evidence_json'] ?? ''), true);
        $this->assertIsArray($digestEvidence);
        $this->assertGreaterThanOrEqual(1, (int) ($digestEvidence['critical_count'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($digestEvidence['warning_count'] ?? 0));
        $this->assertContains('failed_migration_detector', (array) ($digestEvidence['finding_keys'] ?? []));

        $secondResult = $service->runProductionReadinessDetectors(1);
        $this->assertSame(20, (int) ($secondResult['summary']['recorded'] ?? 0));

        $runSummary = Database::queryOne(
            "SELECT COUNT(*) AS total_runs, COALESCE(SUM(duplicate_count), 0) AS duplicate_refresh_count
             FROM automation_catalog_runs"
        );
        $this->assertSame(20, (int) ($runSummary['total_runs'] ?? 0));
        $this->assertSame(20, (int) ($runSummary['duplicate_refresh_count'] ?? 0));
    }

    public function testPausedDetectorIsNotRecorded(): void
    {
        $definition = Database::queryOne(
            "SELECT id FROM automation_catalog_definitions WHERE automation_key = 'missing_production_settings_detector' LIMIT 1"
        );
        $this->assertNotNull($definition);

        Database::execute(
            "UPDATE automation_catalog_controls SET is_paused = 1, pause_reason = 'phpunit' WHERE automation_id = ? AND scope_key = 'global'",
            [(int) ($definition['id'] ?? 0)]
        );

        $service = new AutomationDetectorExecutionService(null, new StubReadinessService($this->readinessPayload()));
        $result = $service->runProductionReadinessDetectors(1);

        $this->assertSame(19, (int) ($result['summary']['recorded'] ?? 0));
        $this->assertSame(1, (int) ($result['summary']['skipped'] ?? 0));

        $evaluations = [];
        foreach ((array) ($result['evaluations'] ?? []) as $evaluation) {
            if (is_array($evaluation)) {
                $evaluations[(string) ($evaluation['automation_key'] ?? '')] = $evaluation;
            }
        }

        $this->assertSame('paused', (string) ($evaluations['missing_production_settings_detector']['skip_reason'] ?? ''));
        $this->assertArrayNotHasKey('missing_production_settings_detector', $this->runsByAutomationKey());
    }

    public function testNewDetectorFindingPathsProduceEvidence(): void
    {
        $workspaceId = $this->createWorkspace('detector-risk-workspace', 'active', 'active');

        Database::execute(
            "INSERT INTO email_integrations (provider, scope, access_token, refresh_token, token_expires_at, is_active)
             VALUES ('detector_missing', 'phpunit_missing', '', '', NULL, 1),
                    ('detector_expiring', 'phpunit_expiring', 'token', 'refresh', DATE_ADD(NOW(), INTERVAL 1 DAY), 1)"
        );

        $ownerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        $platformPermission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = 'platform.settings.manage' LIMIT 1");
        $this->assertNotNull($ownerRole);
        $this->assertNotNull($platformPermission);
        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
            [(int) ($ownerRole['id'] ?? 0), (int) ($platformPermission['id'] ?? 0)]
        );

        Database::execute(
            "DELETE ur
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'superadmin'"
        );
        Database::execute("DELETE FROM workspace_memberships WHERE role_slug = 'superadmin'");

        Database::execute(
            "INSERT INTO workspace_marketplace_setup_journey_steps
                (workspace_id, user_id, skill_key, step_key, label, status, source)
             VALUES (?, NULL, 'detector_skill', 'connect_provider', 'Connect provider', 'pending', 'phpunit')",
            [$workspaceId]
        );

        Database::execute(
            "INSERT INTO automation_job_health
                (job_key, status, last_run_at, last_success_at, last_failure_at, last_message)
             VALUES ('phpunit_detector_failed_job', 'failed', NOW(), DATE_SUB(NOW(), INTERVAL 2 DAY), NOW(), 'simulated failure')
             ON DUPLICATE KEY UPDATE status = VALUES(status), last_failure_at = VALUES(last_failure_at)"
        );

        $evaluations = $this->evaluationsByKey(
            (new AutomationDetectorExecutionService(null, new StubReadinessService($this->readinessPayload())))
                ->evaluateProductionReadinessDetectors()
        );

        $this->assertTrue((bool) ($evaluations['integration_credential_missing_detector']['finding'] ?? false));
        $this->assertGreaterThanOrEqual(
            1,
            (int) ($evaluations['integration_credential_missing_detector']['evidence']['missing_credentials'] ?? 0)
        );
        $this->assertTrue((bool) ($evaluations['expiring_integration_credential_warning']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['billing_package_mismatch_detector']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['permission_drift_detector']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['superadmin_access_repair_suggestion']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['workspace_owner_missing_detector']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['broken_marketplace_setup_detector']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['empty_onboarding_context_detector']['finding'] ?? false));
        $this->assertTrue((bool) ($evaluations['failed_background_job_monitor']['finding'] ?? false));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function runsByAutomationKey(): array
    {
        $rows = Database::query(
            "SELECT r.*, d.automation_key
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id"
        );
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) ($row['automation_key'] ?? '')] = $row;
        }
        return $byKey;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,array<string,mixed>>
     */
    private function evaluationsByKey(array $result): array
    {
        $byKey = [];
        foreach ((array) ($result['evaluations'] ?? []) as $evaluation) {
            if (is_array($evaluation)) {
                $byKey[(string) ($evaluation['automation_key'] ?? '')] = $evaluation;
            }
        }
        return $byKey;
    }

    private function createWorkspace(string $slug, string $status = 'active', string $planStatus = 'inactive'): int
    {
        $uuid = '10000000-0000-4000-8000-' . substr(str_pad(dechex(random_int(1, 0xFFFFFFFFFFFF)), 12, '0', STR_PAD_LEFT), -12);
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, settings_json, created_by)
             VALUES (?, ?, ?, ?, ?, JSON_OBJECT(), NULL)",
            [$uuid, ucwords(str_replace('-', ' ', $slug)), $slug, $status, $planStatus]
        );
        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function readinessPayload(): array
    {
        return [
            'status' => 'critical',
            'checked_at' => '2026-07-04T16:00:00+03:00',
            'summary' => ['ok' => 7, 'warning' => 1, 'critical' => 1, 'unknown' => 0],
            'checks' => [
                [
                    'key' => 'migrations',
                    'status' => 'critical',
                    'message' => '2 migration file(s) are pending. Run database/migrations/migrate.php.',
                    'metadata' => [
                        'latest_migration' => '999_test.sql',
                        'latest_applied_migration' => '998_test.sql',
                        'pending_count' => 2,
                        'pending_migrations' => ['999_test.sql', '1000_test.sql'],
                    ],
                ],
                [
                    'key' => 'production_preflight',
                    'status' => 'critical',
                    'message' => 'Production blockers detected.',
                    'metadata' => [
                        'blockers' => ['default_workspace_smoke_contacts', 'cold_outreach_enabled'],
                        'warnings' => ['app_url_missing', 'workspace_auto_admin_disabled'],
                        'environment' => [
                            'app_env' => 'production',
                            'app_debug_enabled' => false,
                            'app_url_configured' => false,
                            'app_url_host' => '',
                            'warnings' => ['app_url_missing'],
                        ],
                        'data_counts' => [
                            'smoke_contacts' => 3,
                            'smoke_deals' => 1,
                            'stale_email_templates' => 2,
                            'starter_workflow_templates' => 5,
                            'platform_ops_email_templates' => 7,
                            'platform_ops_workflow_templates' => 2,
                        ],
                        'automation_safety' => [
                            'auto_admin_enabled' => false,
                            'cold_outreach_enabled_channels' => ['email'],
                        ],
                    ],
                ],
            ],
        ];
    }
}

class StubReadinessService extends SystemReadinessService
{
    /** @var array<string,mixed> */
    private array $payload;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        return $this->payload;
    }
}
