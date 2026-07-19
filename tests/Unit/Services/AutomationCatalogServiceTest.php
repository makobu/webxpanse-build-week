<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AutomationCatalogService;
use CRM\Services\AutomationReviewQueueService;
use CRM\Tests\DatabaseTestCase;

class AutomationCatalogServiceTest extends DatabaseTestCase
{
    public function testFirstTwentyDetectorDefinitionsAreSeededWithSafeDefaults(): void
    {
        $service = new AutomationCatalogService();

        $this->assertTrue($service->schemaReady());

        $summary = $service->summary();
        $this->assertSame(20, (int) ($summary['first_twenty_seeded'] ?? 0));
        $this->assertSame([], (array) ($summary['missing_first_twenty'] ?? ['missing']));

        $definitions = $service->listDefinitions();
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[(string) $definition['automation_key']] = $definition;
        }

        foreach ($service->firstTwentyKeys() as $key) {
            $this->assertArrayHasKey($key, $byKey);
            $this->assertContains((string) $byKey[$key]['default_mode'], ['observe', 'suggest', 'prepare']);
            $this->assertFalse((bool) $byKey[$key]['is_paused']);
            $this->assertFalse((bool) $byKey[$key]['kill_switch_engaged']);
            $this->assertNotEmpty((array) $byKey[$key]['required_permissions']);
            $this->assertNotEmpty((array) $byKey[$key]['evidence']);
            $this->assertNotEmpty((array) $byKey[$key]['action']);
            $this->assertNotEmpty((array) $byKey[$key]['rollback']);
        }

        $this->assertSame('critical', (string) ($byKey['failed_migration_detector']['risk_level'] ?? ''));
        $this->assertSame('prepare', (string) ($byKey['daily_superadmin_health_digest']['default_mode'] ?? ''));

        foreach ([
            'missing_production_settings_detector',
            'failed_migration_detector',
            'demo_test_data_detector',
            'broken_template_detector',
            'disabled_critical_module_detector',
            'daily_superadmin_health_digest',
        ] as $wiredKey) {
            $this->assertSame('wired', (string) ($byKey[$wiredKey]['implementation_status'] ?? ''));
        }
    }

    public function testRecordRunCreatesOpenReviewTrailForSuggestions(): void
    {
        $service = new AutomationCatalogService();
        $runId = $service->recordRun('demo_test_data_detector', [
            'workspace_id' => 1,
            'run_status' => 'suggested',
            'severity' => 'warning',
            'evidence' => ['record_type' => 'contact', 'count' => 2],
            'recommendation' => ['action' => 'review_demo_data'],
            'source' => 'phpunit',
        ]);

        $this->assertGreaterThan(0, $runId);

        $row = Database::queryOne(
            "SELECT r.*, d.automation_key
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             WHERE r.id = ?
             LIMIT 1",
            [$runId]
        );

        $this->assertNotNull($row);
        $this->assertSame('demo_test_data_detector', (string) ($row['automation_key'] ?? ''));
        $this->assertSame('suggested', (string) ($row['run_status'] ?? ''));
        $this->assertSame('open', (string) ($row['review_status'] ?? ''));

        $recent = $service->recentRuns(1);
        $this->assertSame($runId, (int) ($recent[0]['id'] ?? 0));
        $this->assertSame(['record_type' => 'contact', 'count' => 2], (array) ($recent[0]['evidence'] ?? []));
    }

    public function testRecordRunDeduplicatesRepeatedOpenEvidenceUntilReviewed(): void
    {
        $service = new AutomationCatalogService();
        $payload = [
            'workspace_id' => 1,
            'run_status' => 'suggested',
            'severity' => 'warning',
            'evidence' => ['record_type' => 'contact', 'count' => 2],
            'recommendation' => ['action' => 'review_demo_data'],
            'source' => 'phpunit',
        ];

        $firstRunId = $service->recordRun('demo_test_data_detector', $payload);
        $secondRunId = $service->recordRun('demo_test_data_detector', $payload);

        $this->assertSame($firstRunId, $secondRunId);

        $row = Database::queryOne(
            "SELECT r.*, d.automation_key
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             WHERE r.id = ?
             LIMIT 1",
            [$firstRunId]
        );

        $this->assertNotNull($row);
        $this->assertSame('demo_test_data_detector', (string) ($row['automation_key'] ?? ''));
        $this->assertSame(1, (int) ($row['duplicate_count'] ?? 0));
        $this->assertNotEmpty((string) ($row['run_fingerprint'] ?? ''));
        $this->assertNotEmpty((string) ($row['first_seen_at'] ?? ''));
        $this->assertNotEmpty((string) ($row['last_seen_at'] ?? ''));

        $queue = new AutomationReviewQueueService();
        $queue->reviewRun($firstRunId, 'resolve', 1, 'Reviewed during phpunit.');
        $thirdRunId = $service->recordRun('demo_test_data_detector', $payload);

        $this->assertNotSame($firstRunId, $thirdRunId);

        $count = Database::queryOne(
            "SELECT COUNT(*) AS total
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             WHERE d.automation_key = 'demo_test_data_detector'"
        );
        $this->assertSame(2, (int) ($count['total'] ?? 0));
    }
}
