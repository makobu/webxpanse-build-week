<?php

namespace CRM\Tests\Unit\Services;

use CRM\CacheManager;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AutomationBatteryService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceAutomationReadinessSettingsService;
use CRM\Tests\DatabaseTestCase;

class AutomationBatteryServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $otherUserId;
    private array $cacheDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser('battery@example.com');
        $this->otherUserId = $this->createUser('battery-two@example.com');
    }

    protected function tearDown(): void
    {
        foreach ($this->cacheDirectories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
        $this->cacheDirectories = [];

        parent::tearDown();
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('battery-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function makeSetupReady(?int $userId = null): void
    {
        $userId = $userId ?? $this->userId;
        Database::execute(
            "UPDATE company_profile
             SET company_name = ?, company_description = ?, is_active = 1
             WHERE id = 1",
            ['Battery Co', 'Automation-ready company profile']
        );
        Database::execute(
            "UPDATE invoice_settings
             SET enabled = 1, company_legal_name = ?
             WHERE id = 1",
            ['Battery Co LLC']
        );
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, unit_price, is_active, display_order, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, 1, 1, NOW(), NOW()), (1, ?, ?, ?, ?, 1, 2, NOW(), NOW())",
            ['Starter Plan', 'Primary offer', 'service', 199.00, 'Growth Plan', 'Expanded offer', 'service', 299.00]
        );
        Database::execute(
            "INSERT INTO user_strategy_profiles (user_id, target_market_focus, ideal_customer_profile, offer_angle, sales_motion, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                target_market_focus = VALUES(target_market_focus),
                ideal_customer_profile = VALUES(ideal_customer_profile),
                offer_angle = VALUES(offer_angle),
                sales_motion = VALUES(sales_motion),
                updated_at = NOW()",
            [$userId, 'SMB founders', 'Owner-led services businesses', 'Automation-first sales ops', 'Consultative']
        );
        Database::execute(
            "INSERT INTO idea_validation_context (user_id, value_proposition, target_market, pain_points, differentiator, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                value_proposition = VALUES(value_proposition),
                target_market = VALUES(target_market),
                pain_points = VALUES(pain_points),
                differentiator = VALUES(differentiator),
                updated_at = NOW()",
            [$userId, 'Faster sales follow-up', 'Growing SMEs', 'Leads go cold', 'CRM-native AI automations']
        );
    }

    public function testStatusIncludesLearningAndAutomationLayers(): void
    {
        $service = $this->batteryService();

        $status = $service->getStatus($this->userId);

        $this->assertArrayHasKey('layers', $status);
        $this->assertArrayHasKey('setup', $status['layers']);
        $this->assertArrayHasKey('autoresponder', $status['layers']);
        $this->assertArrayHasKey('customer_care', $status['layers']);
        $this->assertArrayHasKey('commercial', $status['layers']);
        $this->assertArrayHasKey('workflow_automation', $status['layers']);
        $this->assertArrayHasKey('deal', $status['layers']);
        $this->assertArrayHasKey('learning', $status['layers']);
        $this->assertArrayHasKey('top_actions', $status);
        $this->assertArrayHasKey('top_progress_signals', $status);
        $this->assertArrayHasKey('is_visible', $status);
        $this->assertArrayHasKey('context_enrichments', $status);
        $this->assertArrayHasKey('attention_counts', $status);
        $this->assertArrayHasKey('health_summary', $status);
        $this->assertArrayHasKey('refresh_state_label', $status);
        $this->assertIsArray($status['top_actions']);
        $this->assertIsArray($status['top_progress_signals']);
        $this->assertIsArray($status['context_enrichments']);
        $this->assertIsArray($status['attention_counts']);
        $this->assertIsArray($status['health_summary']);
        $this->assertArrayHasKey('required_actions', $status['attention_counts']);
        $this->assertArrayHasKey('failed_jobs', $status['attention_counts']);
        $this->assertArrayHasKey('label', $status['health_summary']);
        $this->assertArrayHasKey('message', $status['health_summary']);
        $this->assertNotEmpty($status['layers']['learning']['status_label'] ?? '');
        $this->assertContains((string) ($status['status_label'] ?? ''), [
            'Early automation',
            'Building automation',
            'Near full automation',
            'Full automation',
        ]);
    }

    public function testCustomerCarePayingCustomerGapIsProgressOnlyWhenEmpty(): void
    {
        $service = $this->batteryService();

        $status = $service->getStatus($this->userId);
        $layer = (array) ($status['layers']['customer_care'] ?? []);

        $this->assertSame('Customer Care', (string) ($layer['label'] ?? ''));
        $this->assertContains('Add paying customers to Customer Care', (array) ($layer['blockers'] ?? []));
        $this->assertContains('Complete more check-ins so the system can learn', (array) ($layer['blockers'] ?? []));
        $this->assertStringContainsString('care profiles', (string) ($layer['detail_value'] ?? ''));

        $actions = [];
        foreach ((array) ($layer['actions'] ?? []) as $action) {
            if (is_array($action) && isset($action['key'])) {
                $actions[(string) $action['key']] = $action;
            }
        }
        $progressSignals = [];
        foreach ((array) ($layer['progress_signals'] ?? []) as $signal) {
            if (is_array($signal) && isset($signal['key'])) {
                $progressSignals[(string) $signal['key']] = $signal;
            }
        }

        $this->assertArrayNotHasKey('customer_care.add_customers', $actions);
        $this->assertArrayHasKey('customer_care.paying_customer_evidence', $progressSignals);
        $this->assertSame(
            'No paying customer care evidence yet. This will improve once paying customers/check-ins exist.',
            (string) ($progressSignals['customer_care.paying_customer_evidence']['label'] ?? '')
        );
        $this->assertNotContains('Add paying customers to Customer Care', (array) ($status['top_blockers'] ?? []));
    }

    public function testProductPricingBlockerReturnsRequiredAction(): void
    {
        Authorization::assignUserRoleBySlug($this->userId, 'superadmin', $this->userId);
        $context = new class extends AIOperatingContextService {
            public function __construct()
            {
            }

            public function buildForUser(int $userId, array $options = []): array
            {
                return [
                    'feature_state' => [
                        'company_profile_ready' => true,
                        'products_priced' => false,
                        'invoicing_ready' => true,
                        'workflow_graph_ready' => true,
                        'commercial_automation_ready' => true,
                        'products_total' => 3,
                        'priced_products' => 1,
                        'readiness_gaps' => ['product pricing'],
                    ],
                    'deal_automation_state' => [
                        'current_mode' => 'manual',
                        'current_mode_label' => 'Manual',
                        'readiness_status' => 'not_ready',
                        'is_managed_by_auto_admin' => false,
                        'blocking_reasons' => ['Deal automation is disabled.'],
                        'recent_audit_summary' => ['recent_total' => 0, 'recent_applied' => 0],
                    ],
                ];
            }
        };
        $service = $this->batteryService($context);

        $status = $service->getStatus($this->userId);
        $setupActions = [];
        foreach ((array) ($status['layers']['setup']['actions'] ?? []) as $action) {
            if (is_array($action) && isset($action['key'])) {
                $setupActions[(string) $action['key']] = $action;
            }
        }
        $topActionKeys = array_map(
            static fn(array $action): string => (string) ($action['key'] ?? ''),
            array_values(array_filter((array) ($status['top_actions'] ?? []), 'is_array'))
        );

        $this->assertArrayHasKey('setup.product_pricing', $setupActions);
        $this->assertSame('Add unit prices to 1 of 3 active products', (string) ($setupActions['setup.product_pricing']['label'] ?? ''));
        $this->assertSame('required', (string) ($setupActions['setup.product_pricing']['kind'] ?? ''));
        $this->assertSame('settings.php?tab=products', (string) ($setupActions['setup.product_pricing']['href'] ?? ''));
        $this->assertSame('Complete Product pricing', (string) ($setupActions['setup.product_pricing']['source_blocker'] ?? ''));
        $this->assertSame('priced_products >= 2 of 3 active products', (string) ($setupActions['setup.product_pricing']['completion_signal'] ?? ''));
        $this->assertContains('setup.product_pricing', $topActionKeys);
    }

    public function testProductPricingDoesNotClearFromPricingInfoAlone(): void
    {
        Database::execute("DELETE FROM products WHERE workspace_id = 1");
        Database::execute(
            "UPDATE company_profile
             SET company_name = ?, company_description = ?, is_active = 1
             WHERE id = 1",
            ['Battery Co', 'Automation-ready company profile']
        );
        Database::execute(
            "UPDATE invoice_settings
             SET enabled = 1, company_legal_name = ?
             WHERE id = 1",
            ['Battery Co LLC']
        );
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, pricing_info, unit_price, is_active, display_order, created_at, updated_at)
             VALUES
                (1, ?, ?, ?, ?, 0, 1, 1, NOW(), NOW()),
                (1, ?, ?, ?, ?, 0, 1, 2, NOW(), NOW()),
                (1, ?, ?, ?, ?, 0, 1, 3, NOW(), NOW())",
            [
                'Text Pricing A', 'Has text pricing only', 'service', 'Starts at 500 USD',
                'Text Pricing B', 'Has text pricing only', 'service', 'Starts at 800 USD',
                'Text Pricing C', 'Has text pricing only', 'service', 'Custom quote',
            ]
        );

        $service = $this->batteryService();
        $status = $service->getStatus($this->userId);
        $setupLayer = (array) ($status['layers']['setup'] ?? []);
        $progressSignals = [];
        foreach ((array) ($setupLayer['progress_signals'] ?? []) as $signal) {
            if (is_array($signal) && isset($signal['key'])) {
                $progressSignals[(string) $signal['key']] = $signal;
            }
        }

        $this->assertContains('Complete Product pricing', (array) ($setupLayer['blockers'] ?? []));
        $this->assertSame(3, (int) ($setupLayer['metadata']['products_total'] ?? 0));
        $this->assertSame(0, (int) ($setupLayer['metadata']['priced_products'] ?? -1));
        $this->assertArrayHasKey('setup.product_pricing.progress', $progressSignals);
        $this->assertSame('0 of 3 active products have unit prices.', (string) ($progressSignals['setup.product_pricing.progress']['label'] ?? ''));
        $this->assertSame(2, (int) ($progressSignals['setup.product_pricing.progress']['target_value'] ?? 0));
    }

    public function testHistoryGapsRenderAsProgressSignalsNotActions(): void
    {
        $this->makeSetupReady();
        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'suggest_only',
        ]);

        $service = $this->batteryService();
        $status = $service->getStatus($this->userId);

        $commercialActionKeys = array_map(
            static fn(array $action): string => (string) ($action['key'] ?? ''),
            array_values(array_filter((array) ($status['layers']['commercial']['actions'] ?? []), 'is_array'))
        );
        $workflowActionKeys = array_map(
            static fn(array $action): string => (string) ($action['key'] ?? ''),
            array_values(array_filter((array) ($status['layers']['workflow_automation']['actions'] ?? []), 'is_array'))
        );
        $commercialProgressKeys = array_map(
            static fn(array $signal): string => (string) ($signal['key'] ?? ''),
            array_values(array_filter((array) ($status['layers']['commercial']['progress_signals'] ?? []), 'is_array'))
        );
        $workflowProgressKeys = array_map(
            static fn(array $signal): string => (string) ($signal['key'] ?? ''),
            array_values(array_filter((array) ($status['layers']['workflow_automation']['progress_signals'] ?? []), 'is_array'))
        );

        $this->assertNotContains('commercial.run_history', $commercialActionKeys);
        $this->assertContains('commercial.run_history_progress', $commercialProgressKeys);
        $this->assertNotContains('workflow.proposal_history', $workflowActionKeys);
        $this->assertContains('workflow.proposal_history_progress', $workflowProgressKeys);
    }

    public function testFulfilledSetupLayerIsHidden(): void
    {
        $context = new class extends AIOperatingContextService {
            public function __construct()
            {
            }

            public function buildForUser(int $userId, array $options = []): array
            {
                return [
                    'feature_state' => [
                        'company_profile_ready' => true,
                        'products_priced' => true,
                        'invoicing_ready' => true,
                        'workflow_graph_ready' => true,
                        'commercial_automation_ready' => false,
                        'products_total' => 2,
                        'priced_products' => 2,
                        'readiness_gaps' => [],
                    ],
                    'deal_automation_state' => [
                        'current_mode' => 'manual',
                        'current_mode_label' => 'Manual',
                        'readiness_status' => 'ready',
                        'is_managed_by_auto_admin' => false,
                        'blocking_reasons' => [],
                        'recent_audit_summary' => ['recent_total' => 5, 'recent_applied' => 5],
                    ],
                ];
            }
        };

        $service = $this->batteryService($context);
        $status = $service->getStatus($this->userId);

        $this->assertFalse((bool) ($status['layers']['setup']['is_visible'] ?? true));
        $this->assertSame('hidden', (string) ($status['layers']['setup']['display_mode'] ?? ''));
        $this->assertSame('fulfilled', (string) ($status['layers']['setup']['visibility_reason'] ?? ''));
    }

    public function testKnownAutomationBlockersMapToActionsOrExplicitNeutralChips(): void
    {
        $service = $this->batteryService();
        $method = new \ReflectionMethod(AutomationBatteryService::class, 'automationActionCatalog');
        $method->setAccessible(true);
        $catalog = (array) $method->invoke($service);
        $source = file_get_contents(__DIR__ . '/../../../services/AutomationBatteryService.php');
        $this->assertNotFalse($source);

        preg_match_all('/\$blockers\[\]\s*=\s*\'([^\']+)\'\s*;/', (string) $source, $matches);

        $knownDynamicBlockers = [
            'Complete Company profile',
            'Complete Product pricing',
            'Complete Invoicing',
            'Complete Workflow graph',
            'Complete Commercial automation',
            'Deal automation is disabled.',
            'No transition rules are configured.',
            'Dry run is still enabled.',
            'Not enough recent automation audit history yet.',
            'Recent audit history shows unstable automation outcomes.',
            'Terminal-stage safety settings are not strict enough.',
        ];
        $explicitNeutralChips = [];
        $knownBlockers = array_values(array_unique(array_merge($matches[1] ?? [], $knownDynamicBlockers)));
        sort($knownBlockers);

        $unmapped = array_values(array_diff($knownBlockers, array_keys($catalog), $explicitNeutralChips));

        $this->assertSame([], $unmapped, 'Automation blockers must map to actions or be explicitly allowed as neutral chips.');
    }

    public function testCustomerCareLayerImprovesWithProfilesPlansCheckInsAndLearning(): void
    {
        $this->makeSetupReady();
        $cache = $this->memoryCache();
        $service = $this->batteryService(cache: $cache);
        $baseline = $service->getStatus($this->userId);

        $this->createCustomerCareEvidence($this->userId);
        $cache->expirePayloads();
        $improved = $service->getStatus($this->userId);

        $this->assertGreaterThan(
            (int) ($baseline['layers']['customer_care']['score'] ?? 0),
            (int) ($improved['layers']['customer_care']['score'] ?? 0)
        );
        $this->assertContains('Paying customer care profiles detected', (array) ($improved['layers']['customer_care']['signals'] ?? []));
        $this->assertContains('Follow-up plans available', (array) ($improved['layers']['customer_care']['signals'] ?? []));
        $this->assertContains('Completed check-ins detected', (array) ($improved['layers']['customer_care']['signals'] ?? []));
        $this->assertSame('Customer Care', (string) ($improved['layers']['customer_care']['label'] ?? ''));
    }

    public function testCustomerCareCriticalIncidentDegradesReadiness(): void
    {
        $cache = $this->memoryCache();
        $service = $this->batteryService(cache: $cache);
        $this->createCustomerCareEvidence($this->userId);
        $ready = $service->getStatus($this->userId);

        Database::execute(
            "INSERT INTO ai_autonomy_incidents
                (workspace_id, tenant_key, domain_key, action_key, incident_key, severity, status, reason_codes_json, details_json, created_at, updated_at)
             VALUES
                (1, 'workspace:1', 'customer_care', 'record_check_in', 'unit_critical', 'critical', 'open', ?, '{}', NOW(), NOW())",
            [json_encode(['unit_test'])]
        );
        $cache->expirePayloads();
        $degraded = $service->getStatus($this->userId);

        $this->assertLessThan(
            (int) ($ready['layers']['customer_care']['score'] ?? 0),
            (int) ($degraded['layers']['customer_care']['score'] ?? 100)
        );
        $this->assertContains('Resolve Customer Care automation incidents', (array) ($degraded['layers']['customer_care']['blockers'] ?? []));
    }

    public function testStatusUsesCacheWhenFingerprintIsUnchanged(): void
    {
        $context = new class extends AIOperatingContextService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function buildForUser(int $userId, array $options = []): array
            {
                $this->calls++;

                return [
                    'feature_state' => [
                        'company_profile_ready' => false,
                        'products_priced' => false,
                        'invoicing_ready' => false,
                        'workflow_graph_ready' => true,
                        'commercial_automation_ready' => true,
                        'readiness_gaps' => ['company profile', 'product pricing', 'invoicing'],
                    ],
                    'deal_automation_state' => [
                        'current_mode' => 'manual',
                        'current_mode_label' => 'Manual',
                        'readiness_status' => 'not_ready',
                        'is_managed_by_auto_admin' => false,
                        'blocking_reasons' => ['Deal automation is disabled.'],
                        'recent_audit_summary' => ['recent_total' => 0, 'recent_applied' => 0],
                    ],
                ];
            }
        };
        $service = $this->batteryService($context);

        $first = $service->getStatus($this->userId);
        $second = $service->getStatus($this->userId);

        $this->assertSame(1, $context->calls);
        $this->assertSame($first, $second);
    }

    public function testFreshCachedStatusReturnsBeforeRecalculation(): void
    {
        $context = new class extends AIOperatingContextService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function buildForUser(int $userId, array $options = []): array
            {
                $this->calls++;
                throw new \RuntimeException('Fresh cache hit should not rebuild status.');
            }
        };
        $cache = $this->memoryCache();
        $cachedStatus = [
            'score' => 50,
            'bucket' => 'medium',
            'status_label' => 'Building automation',
            'headline_label' => 'Cached',
            'summary' => 'Cached battery status.',
            'top_blockers' => [],
            'top_boosters' => [],
            'top_actions' => [],
            'top_progress_signals' => [],
            'context_enrichments' => [],
            'mode_label' => 'Cached',
            'subject_user_id' => $this->userId,
            'setup_progress_label' => '0 of 0 foundations ready',
            'layers' => [],
            'job_health' => [],
        ];
        $cache->set(
            'automation_battery_status:v4:workspace:1:subject:' . $this->userId . ':viewer:' . $this->userId,
            [
                'fingerprint' => 'older-fingerprint',
                'status' => $cachedStatus,
                'cached_at' => gmdate('c'),
                'expires_at' => gmdate('c', time() + 300),
            ],
            300
        );
        $service = $this->batteryService($context, $cache);

        $status = $service->getStatus($this->userId);

        $this->assertSame('Cached', (string) ($status['headline_label'] ?? ''));
        $this->assertSame(50, (int) ($status['score'] ?? 0));
        $this->assertIsArray($status['top_progress_signals'] ?? null);
        $this->assertArrayHasKey('is_visible', $status);
        $this->assertSame(0, $context->calls);
    }

    public function testStoredSnapshotReturnsWithoutRecalculation(): void
    {
        $context = new class extends AIOperatingContextService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function buildForUser(int $userId, array $options = []): array
            {
                $this->calls++;
                throw new \RuntimeException('Stored snapshot should not rebuild status.');
            }
        };
        $status = [
            'score' => 67,
            'bucket' => 'medium',
            'status_label' => 'Building automation',
            'headline_label' => 'Cached snapshot',
            'summary' => 'Stored dashboard snapshot.',
            'top_blockers' => [],
            'top_boosters' => ['Snapshot ready'],
            'top_actions' => [],
            'top_progress_signals' => [],
            'context_enrichments' => [],
            'mode_label' => 'Cached snapshot',
            'subject_user_id' => $this->userId,
            'setup_progress_label' => '3 of 6 foundations ready',
            'layers' => [],
            'job_health' => [],
        ];
        Database::execute(
            "INSERT INTO automation_battery_snapshots
                (workspace_id, subject_user_id, score, bucket, status_label, headline_label, summary,
                 payload_json, fingerprint, calculation_source, calculated_at, expires_at, created_at, updated_at)
             VALUES (1, ?, 67, 'medium', 'Building automation', 'Cached snapshot', 'Stored dashboard snapshot.',
                     ?, ?, 'manual', ?, ?, NOW(), NOW())",
            [
                $this->userId,
                json_encode($status),
                str_repeat('a', 64),
                date('Y-m-d H:i:s', time() - 60),
                date('Y-m-d H:i:s', time() + 600),
            ]
        );
        $service = $this->batteryService($context);

        $stored = $service->getStoredStatus($this->userId);

        $this->assertSame(0, $context->calls);
        $this->assertSame(67, (int) ($stored['score'] ?? 0));
        $this->assertSame('Building automation', (string) ($stored['status_label'] ?? ''));
        $this->assertStringStartsWith('Updated ', (string) ($stored['updated_label'] ?? ''));
        $this->assertFalse((bool) ($stored['is_stale'] ?? true));
    }

    public function testStoredStaleSnapshotReturnsStaleRefreshLabel(): void
    {
        $this->insertBatterySnapshot($this->userId, 52, 'cron', 1800);
        $service = $this->batteryService();

        $stored = $service->getStoredStatus($this->userId);

        $this->assertNotNull($stored);
        $this->assertTrue((bool) ($stored['is_stale'] ?? false));
        $this->assertSame('Saved score is stale. Refresh for the latest automation health.', (string) ($stored['refresh_state_label'] ?? ''));
        $this->assertSame('Saved score is stale. Refresh for the latest automation health.', (string) ($stored['health_summary']['refresh_state_label'] ?? ''));
    }

    public function testRefreshStatusWritesStoredSnapshot(): void
    {
        $context = new class extends AIOperatingContextService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function buildForUser(int $userId, array $options = []): array
            {
                $this->calls++;

                return [
                    'feature_state' => [
                        'company_profile_ready' => false,
                        'products_priced' => false,
                        'invoicing_ready' => false,
                        'workflow_graph_ready' => true,
                        'commercial_automation_ready' => true,
                        'readiness_gaps' => ['company profile'],
                    ],
                    'deal_automation_state' => [
                        'current_mode' => 'manual',
                        'current_mode_label' => 'Manual',
                        'readiness_status' => 'not_ready',
                        'is_managed_by_auto_admin' => false,
                        'blocking_reasons' => ['Deal automation is disabled.'],
                        'recent_audit_summary' => ['recent_total' => 0, 'recent_applied' => 0],
                    ],
                ];
            }
        };
        $service = $this->batteryService($context);

        $refreshed = $service->refreshStatus($this->userId, $this->userId, 'manual');
        $stored = $service->getStoredStatus($this->userId);
        $row = Database::queryOne(
            "SELECT score, calculation_source FROM automation_battery_snapshots WHERE workspace_id = 1 AND subject_user_id = ? LIMIT 1",
            [$this->userId]
        );

        $this->assertSame(1, $context->calls);
        $this->assertNotNull($row);
        $this->assertSame((int) ($refreshed['score'] ?? 0), (int) ($row['score'] ?? -1));
        $this->assertSame('manual', (string) ($row['calculation_source'] ?? ''));
        $this->assertSame((int) ($refreshed['score'] ?? 0), (int) ($stored['score'] ?? -1));
        $this->assertFalse((bool) ($stored['is_stale'] ?? true));
    }

    public function testIdleRefreshSkipsFreshSnapshotBeforeCadence(): void
    {
        $context = new AutomationBatteryCountingContext(true);
        $this->insertBatterySnapshot($this->userId, 67, 'manual', 3600);
        $service = $this->batteryService($context);

        $status = $service->refreshStatus($this->userId, $this->userId, 'idle');

        $this->assertSame(0, $context->calls);
        $this->assertSame(67, (int) ($status['score'] ?? 0));
        $this->assertTrue((bool) ($status['refresh_skipped'] ?? false));
        $this->assertSame('not_due', (string) ($status['refresh_skip_reason'] ?? ''));
        $this->assertSame('six_hours', (string) ($status['refresh_policy']['cadence'] ?? ''));
        $this->assertNotEmpty($status['refresh_due_at'] ?? null);
        $this->assertSame('Already fresh. The next automatic check is scheduled.', (string) ($status['refresh_state_label'] ?? ''));
        $this->assertSame('Already fresh. The next automatic check is scheduled.', (string) ($status['health_summary']['refresh_state_label'] ?? ''));
    }

    public function testIdleRefreshRebuildsWhenSnapshotAgeExceedsCadence(): void
    {
        $context = new AutomationBatteryCountingContext();
        $this->insertBatterySnapshot($this->userId, 41, 'manual', 7 * 3600);
        $service = $this->batteryService($context);

        $status = $service->refreshStatus($this->userId, $this->userId, 'idle');
        $row = Database::queryOne(
            "SELECT calculation_source FROM automation_battery_snapshots WHERE workspace_id = 1 AND subject_user_id = ? LIMIT 1",
            [$this->userId]
        ) ?: [];

        $this->assertSame(1, $context->calls);
        $this->assertFalse((bool) ($status['refresh_skipped'] ?? true));
        $this->assertSame('idle', (string) ($row['calculation_source'] ?? ''));
    }

    public function testManualOnlyCadencePreventsIdleRebuildWithoutSnapshot(): void
    {
        $context = new AutomationBatteryCountingContext(true);
        (new WorkspaceAutomationReadinessSettingsService())->savePolicy(1, 'manual_only', $this->userId);
        $service = $this->batteryService($context);

        $status = $service->refreshStatus($this->userId, $this->userId, 'idle');

        $this->assertSame(0, $context->calls);
        $this->assertSame('Ready to check', (string) ($status['status_label'] ?? ''));
        $this->assertTrue((bool) ($status['refresh_skipped'] ?? false));
        $this->assertSame('manual_only', (string) ($status['refresh_skip_reason'] ?? ''));
        $this->assertSame('manual_only', (string) ($status['refresh_policy']['cadence'] ?? ''));
        $this->assertNull($status['refresh_due_at'] ?? null);
        $this->assertSame('Automatic checks are off. Manual refresh is available from the dashboard.', (string) ($status['refresh_state_label'] ?? ''));
    }

    public function testMissingSnapshotAllowsIdleRebuildWhenAutomaticRefreshIsEnabled(): void
    {
        $context = new AutomationBatteryCountingContext();
        $service = $this->batteryService($context);

        $status = $service->refreshStatus($this->userId, $this->userId, 'idle');

        $this->assertSame(1, $context->calls);
        $this->assertFalse((bool) ($status['refresh_skipped'] ?? true));
        $this->assertSame('six_hours', (string) ($status['refresh_policy']['cadence'] ?? ''));
    }

    public function testManualRefreshWithinThrottleReturnsStoredManualSnapshot(): void
    {
        $context = new AutomationBatteryCountingContext(true);
        $this->insertBatterySnapshot($this->userId, 73, 'manual', 30);
        $service = $this->batteryService($context);

        $status = $service->refreshStatus($this->userId, $this->userId, 'manual');

        $this->assertSame(0, $context->calls);
        $this->assertSame(73, (int) ($status['score'] ?? 0));
        $this->assertTrue((bool) ($status['refresh_skipped'] ?? false));
        $this->assertSame('manual_throttle', (string) ($status['refresh_skip_reason'] ?? ''));
        $this->assertSame('Already fresh. Manual refresh will be available again shortly.', (string) ($status['refresh_state_label'] ?? ''));
    }

    public function testManualRefreshAfterThrottleWindowRebuilds(): void
    {
        $context = new AutomationBatteryCountingContext();
        $this->insertBatterySnapshot($this->userId, 73, 'manual', 120);
        $service = $this->batteryService($context);

        $status = $service->refreshStatus($this->userId, $this->userId, 'manual');

        $this->assertSame(1, $context->calls);
        $this->assertFalse((bool) ($status['refresh_skipped'] ?? true));
    }

    public function testStatusRecomputesWhenSetupFingerprintChanges(): void
    {
        $context = new AutomationBatteryCountingContext();
        $cache = $this->memoryCache();
        $service = $this->batteryService($context, $cache);

        $service->getStatus($this->userId);
        $this->makeSetupReady();
        $cache->expirePayloads();
        $service->getStatus($this->userId);

        $this->assertSame(2, $context->calls);
    }

    public function testIncompleteSetupHardCapsOverallBattery(): void
    {
        $service = $this->batteryService();

        $status = $service->getStatus($this->userId);

        $this->assertFalse((bool) ($status['layers']['setup']['is_complete'] ?? true));
        $this->assertLessThanOrEqual(69, (int) ($status['score'] ?? 0));
        $this->assertSame('Finish Setup', (string) ($status['headline_label'] ?? ''));
        $this->assertSame('Complete the remaining foundations before automation can run reliably.', (string) ($status['summary'] ?? ''));
        $this->assertMatchesRegularExpression('/^\d+ of \d+ foundations ready$/', (string) ($status['setup_progress_label'] ?? ''));
    }

    public function testWorkflowAutomationLayerAddsBlockersAndImprovesWhenRecentAppliedProposalsExist(): void
    {
        $cache = $this->memoryCache();
        $service = $this->batteryService(cache: $cache);
        $this->makeSetupReady();

        $baseline = $service->getStatus($this->userId);

        Database::execute(
            "INSERT INTO ai_autonomy_domain_controls
                (workspace_id, tenant_key, domain_key, autonomy_mode, demonstration_capture_enabled, policy_learning_enabled,
                 review_ui_enabled, fast_promotion_enabled, promotion_status, min_precision_to_promote,
                 max_reversal_rate_to_promote, max_edit_rate_to_promote, metadata_json, updated_at, created_at)
             VALUES (1, 'workspace:1', 'workflow_execution', 'full_auto', 1, 1, 1, 1, 'full_auto', 0.9000, 0.0800, 0.1200, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE autonomy_mode = VALUES(autonomy_mode), promotion_status = VALUES(promotion_status), metadata_json = VALUES(metadata_json), updated_at = NOW()",
            [json_encode(['allowed_actions' => ['create_task'], 'seeded' => true])]
        );
        Database::execute(
            "INSERT INTO workflow_automation_proposals
                (workspace_id, workflow_id, applied_workflow_id, proposal_type, source_surface, status, requested_by_type, requested_by_id,
                 target_workflow_name, decision_mode, governance_decision, confidence_score, proposal_graph_json, current_graph_json,
                 legacy_payload_json, validation_issues_json, risk_summary_json, action_summary_json, diff_summary_json, decision_snapshot_json,
                 notes, created_at, updated_at)
             VALUES
                (1, NULL, 1, 'create', 'unit_test', 'applied', 'user', ?, 'Workflow A', 'full_auto', 'allow', 0.9600, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW()),
                (1, NULL, 2, 'create', 'unit_test', 'applied', 'user', ?, 'Workflow B', 'full_auto', 'allow', 0.9400, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW())",
            [$this->userId, $this->userId]
        );
        $cache->expirePayloads();

        $improved = $service->getStatus($this->userId);

        $this->assertContains('Workflow automation has not built enough recent proposal history', (array) ($baseline['layers']['workflow_automation']['blockers'] ?? []));
        $this->assertGreaterThan((int) ($baseline['score'] ?? 0), (int) ($improved['score'] ?? 0));
        $this->assertNotEmpty($improved['layers']['workflow_automation']['signals'] ?? []);
        $this->assertSame('Workflow Automation', (string) ($improved['layers']['workflow_automation']['label'] ?? ''));
    }

    public function testWorkflowAutomationCountsTowardHeadlineAndBoosters(): void
    {
        $this->makeSetupReady();
        Database::execute(
            "INSERT INTO ai_autonomy_domain_controls
                (workspace_id, tenant_key, domain_key, autonomy_mode, demonstration_capture_enabled, policy_learning_enabled,
                 review_ui_enabled, fast_promotion_enabled, promotion_status, min_precision_to_promote,
                 max_reversal_rate_to_promote, max_edit_rate_to_promote, metadata_json, updated_at, created_at)
             VALUES
                (1, 'workspace:1', 'commercial_mvp', 'full_auto', 1, 1, 1, 1, 'full_auto', 0.9000, 0.0800, 0.1200, ?, NOW(), NOW()),
                (1, 'workspace:1', 'workflow_execution', 'full_auto', 1, 1, 1, 1, 'full_auto', 0.9000, 0.0800, 0.1200, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE autonomy_mode = VALUES(autonomy_mode), promotion_status = VALUES(promotion_status), metadata_json = VALUES(metadata_json), updated_at = NOW()",
            [json_encode(['seeded' => true]), json_encode(['seeded' => true])]
        );
        Database::execute(
            "INSERT INTO workflow_automation_proposals
                (workspace_id, workflow_id, applied_workflow_id, proposal_type, source_surface, status, requested_by_type, requested_by_id,
                 target_workflow_name, decision_mode, governance_decision, confidence_score, proposal_graph_json, current_graph_json,
                 legacy_payload_json, validation_issues_json, risk_summary_json, action_summary_json, diff_summary_json, decision_snapshot_json,
                 notes, created_at, updated_at)
             VALUES
                (1, NULL, 11, 'create', 'unit_test', 'applied', 'user', ?, 'Workflow Ready 1', 'full_auto', 'allow', 0.9800, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW()),
                (1, NULL, 12, 'create', 'unit_test', 'applied', 'user', ?, 'Workflow Ready 2', 'full_auto', 'allow', 0.9700, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW()),
                (1, NULL, 13, 'create', 'unit_test', 'applied', 'user', ?, 'Workflow Ready 3', 'full_auto', 'allow', 0.9600, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW())",
            [$this->userId, $this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at, updated_at)
             VALUES (1, ?, 'Battery', 'Contact', 'battery-contact@example.com', ?, NOW(), NOW())",
            [uniqid('contact-', true), $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', 1000, NOW(), NOW())",
            ['Battery Deal', $contactId, $this->userId, $this->userId]
        );
        $dealId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO commercial_automation_runs (workspace_id, deal_id, contact_id, trigger_type, trigger_ref_id, decision, action_plan_json, evidence_json, policy_snapshot_json, created_at)
             VALUES
                (1, ?, ?, 'deal_stage_changed', 1, 'auto_apply', '{}', '{}', '{}', NOW()),
                (1, ?, ?, 'deal_stage_changed', 2, 'auto_apply', '{}', '{}', '{}', NOW()),
                (1, ?, ?, 'deal_stage_changed', 3, 'auto_apply', '{}', '{}', '{}', NOW())",
            [$dealId, $contactId, $dealId, $contactId, $dealId, $contactId]
        );
        Database::execute(
            "INSERT INTO ai_operator_demonstrations
                (workspace_id, tenant_key, domain_key, actor_user_id, actor_type, source_surface, entity_type, entity_id, action_key, demonstration_hash, observed_at, created_at)
             VALUES
                (1, 'workspace:1', 'workflow_execution', ?, 'user', 'unit_test', 'workflow', 1, 'create_task', 'demo-hash-001', NOW(), NOW()),
                (1, 'workspace:1', 'workflow_execution', ?, 'user', 'unit_test', 'workflow', 2, 'create_task', 'demo-hash-002', NOW(), NOW()),
                (1, 'workspace:1', 'commercial_mvp', ?, 'user', 'unit_test', 'deal', 1, 'send_document', 'demo-hash-003', NOW(), NOW()),
                (1, 'workspace:1', 'commercial_mvp', ?, 'user', 'unit_test', 'deal', 2, 'send_document', 'demo-hash-004', NOW(), NOW())",
            [$this->userId, $this->userId, $this->userId, $this->userId]
        );
        Database::execute(
            "INSERT INTO ai_decision_outcomes (workspace_id, user_id, surface, decision_type, action_type, outcome_label, outcome_score, measured_at)
             VALUES
                (1, ?, 'workflow', 'auto_apply', 'create_task', 'accepted', 1.0000, NOW()),
                (1, ?, 'workflow', 'auto_apply', 'create_task', 'accepted', 1.0000, NOW()),
                (1, ?, 'workflow', 'auto_apply', 'create_task', 'accepted', 1.0000, NOW()),
                (1, ?, 'commercial', 'auto_apply', 'send_document', 'accepted', 1.0000, NOW()),
                (1, ?, 'commercial', 'auto_apply', 'send_document', 'accepted', 1.0000, NOW())",
            [$this->userId, $this->userId, $this->userId, $this->userId, $this->userId]
        );

        $service = $this->batteryService();
        $status = $service->getStatus($this->userId);

        $this->assertContains('Workflow automation is beyond suggest-only', (array) ($status['layers']['workflow_automation']['signals'] ?? []));
        $this->assertSame('Workflow Automation', (string) ($status['layers']['workflow_automation']['label'] ?? ''));
        $this->assertContains((string) ($status['headline_label'] ?? ''), ['Finish Setup', 'Suggest To Auto', 'Auto Mode Building', 'Full Auto In Reach']);
    }

    public function testMobileStatusReturnsCompactPayload(): void
    {
        $service = $this->batteryService();

        $status = $service->getMobileStatus($this->userId);

        $this->assertSame(
            [
                'score',
                'bucket',
                'status_label',
                'headline_label',
                'summary',
                'setup_progress_label',
                'top_blockers',
                'top_boosters',
                'top_actions',
                'top_progress_signals',
                'attention_counts',
                'health_summary',
                'refresh_state_label',
                'refresh_policy',
                'refresh_due_at',
                'last_calculated_at',
                'age_seconds',
            ],
            array_keys($status)
        );
        $this->assertIsArray($status['top_blockers']);
        $this->assertIsArray($status['top_boosters']);
        $this->assertIsArray($status['top_actions']);
        $this->assertIsArray($status['top_progress_signals']);
        $this->assertIsArray($status['attention_counts']);
        $this->assertIsArray($status['health_summary']);
        $this->assertArrayHasKey('cadence', $status['refresh_policy']);
        $this->assertArrayNotHasKey('layers', $status);
        $this->assertArrayNotHasKey('job_health', $status);
    }

    public function testDisabledCommercialAutomationDoesNotCountTowardReadinessOrSetupProgress(): void
    {
        $this->makeSetupReady();
        $cache = $this->memoryCache();
        $config = new CommercialAutomationConfig();
        $service = $this->batteryService(cache: $cache);

        $config->save([
            'enabled' => true,
            'mode' => 'suggest_only',
        ]);
        $enabledStatus = $service->getStatus($this->userId);

        $config->save([
            'enabled' => false,
            'mode' => 'suggest_only',
        ]);
        $cache->expirePayloads();
        $disabledStatus = $service->getStatus($this->userId);

        $this->assertTrue((bool) ($enabledStatus['layers']['commercial']['counted_in_score'] ?? false));
        $this->assertFalse((bool) ($disabledStatus['layers']['commercial']['counted_in_score'] ?? true));
        $this->assertSame('Disabled in Admin', (string) ($disabledStatus['layers']['commercial']['status_label'] ?? ''));
        $this->assertSame(5, (int) ($enabledStatus['layers']['setup']['total_count'] ?? 0));
        $this->assertSame(4, (int) ($disabledStatus['layers']['setup']['total_count'] ?? 0));
        $this->assertContains('Commercial automation has not built enough recent run history', (array) ($enabledStatus['layers']['commercial']['blockers'] ?? []));
        $this->assertNotContains('Commercial automation has not built enough recent run history', (array) ($disabledStatus['top_blockers'] ?? []));
        $this->assertStringContainsString('not counted', strtolower((string) ($disabledStatus['layers']['commercial']['summary'] ?? '')));
        $this->assertStringNotContainsString('commercial is', strtolower((string) ($disabledStatus['summary'] ?? '')));
    }

    public function testStrategyAndIdeaValidationDoNotBlockAutomationReadiness(): void
    {
        $this->makeSetupReady($this->userId);
        Database::execute(
            "UPDATE invoice_settings SET enabled = 1, company_legal_name = ? WHERE id = 1",
            ['Battery Co LLC']
        );

        $service = $this->batteryService();

        $readyStatus = $service->getStatus($this->userId, $this->userId);
        $notReadyStatus = $service->getStatus($this->userId, $this->otherUserId);

        $this->assertSame(
            (int) ($readyStatus['layers']['setup']['score'] ?? 0),
            (int) ($notReadyStatus['layers']['setup']['score'] ?? -1)
        );
        $this->assertSame(
            (int) ($readyStatus['layers']['setup']['total_count'] ?? 0),
            (int) ($notReadyStatus['layers']['setup']['total_count'] ?? -1)
        );
        $this->assertNotContains('Complete user strategy profile', (array) ($notReadyStatus['layers']['setup']['blockers'] ?? []));
        $this->assertNotContains('Complete idea validation', (array) ($notReadyStatus['layers']['setup']['blockers'] ?? []));
        $this->assertNotContains('Complete user strategy profile', (array) ($notReadyStatus['top_blockers'] ?? []));
        $this->assertNotContains('Complete idea validation', (array) ($notReadyStatus['top_blockers'] ?? []));
        $this->assertSame($this->otherUserId, (int) ($notReadyStatus['subject_user_id'] ?? 0));
    }

    public function testOwnedAndActedEvidenceOnlyImprovesTheSubjectUser(): void
    {
        $this->makeSetupReady($this->userId);
        $this->makeSetupReady($this->otherUserId);
        (new CommercialAutomationConfig())->save([
            'enabled' => true,
            'mode' => 'suggest_only',
        ]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at, updated_at)
             VALUES (1, ?, 'Owner', 'One', 'owner-one@example.com', ?, NOW(), NOW())",
            [uniqid('contact-owned-', true), $this->userId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', 1200, NOW(), NOW())",
            ['Owned Deal', $contactId, $this->userId, $this->userId]
        );
        $dealId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO commercial_automation_runs (workspace_id, deal_id, contact_id, trigger_type, trigger_ref_id, decision, action_plan_json, evidence_json, policy_snapshot_json, created_at)
             VALUES (1, ?, ?, 'deal_stage_changed', 1, 'auto_apply', '{}', '{}', '{}', NOW())",
            [$dealId, $contactId]
        );
        Database::execute(
            "INSERT INTO workflow_automation_proposals
                (workspace_id, workflow_id, applied_workflow_id, proposal_type, source_surface, status, requested_by_type, requested_by_id,
                 target_workflow_name, decision_mode, governance_decision, confidence_score, proposal_graph_json, current_graph_json,
                 legacy_payload_json, validation_issues_json, risk_summary_json, action_summary_json, diff_summary_json, decision_snapshot_json,
                 notes, created_at, updated_at)
             VALUES
                (1, NULL, 21, 'create', 'unit_test', 'applied', 'user', ?, 'Owned Workflow', 'full_auto', 'allow', 0.9700, '{}', '{}', '{}', '[]', '{}', '{}', '{}', '{}', NULL, NOW(), NOW())",
            [$this->userId]
        );
        for ($i = 1; $i <= 10; $i++) {
            Database::execute(
                "INSERT INTO ai_operator_demonstrations
                    (workspace_id, tenant_key, domain_key, actor_user_id, actor_type, source_surface, entity_type, entity_id, action_key, demonstration_hash, observed_at, created_at)
                 VALUES
                    (1, 'workspace:1', 'workflow_execution', ?, 'user', 'unit_test', 'workflow', ?, 'create_task', ?, NOW(), NOW())",
                [$this->userId, 100 + $i, sprintf('demo-owned-%03d', $i)]
            );
        }
        for ($i = 1; $i <= 15; $i++) {
            Database::execute(
                "INSERT INTO ai_decision_outcomes (workspace_id, user_id, surface, decision_type, action_type, predicted_confidence, outcome_label, outcome_score, measured_at)
                 VALUES (1, ?, 'commercial', 'auto_apply', 'send_document', 0.9700, 'accepted', 1.0000, NOW())",
                [$this->userId]
            );
        }

        $service = $this->batteryService();
        $ownerStatus = $service->getStatus($this->userId, $this->userId);
        $otherStatus = $service->getStatus($this->userId, $this->otherUserId);

        $this->assertGreaterThan(
            (int) ($otherStatus['layers']['commercial']['score'] ?? 0),
            (int) ($ownerStatus['layers']['commercial']['score'] ?? 0)
        );
        $this->assertGreaterThan(
            (int) ($otherStatus['layers']['workflow_automation']['score'] ?? 0),
            (int) ($ownerStatus['layers']['workflow_automation']['score'] ?? 0)
        );
        $this->assertGreaterThan(
            (int) ($otherStatus['layers']['learning']['score'] ?? 0),
            (int) ($ownerStatus['layers']['learning']['score'] ?? 0)
        );
    }

    public function testAutoresponderEvidenceIsScopedToActiveWorkspace(): void
    {
        $this->makeSetupReady($this->userId);
        (new AIAutoResponderConfig())->save([
            'enabled' => true,
            'mode' => 'full_auto',
            'default_confidence_threshold' => 0.80,
        ]);

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, 'Battery Workspace Two', 'battery-workspace-two', 'active', 'active', ?)",
            [uniqid('workspace-', true), $this->userId]
        );
        $workspaceTwoId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceTwoId, $this->userId]
        );

        $workspaceTwoContactId = $this->createBatteryContact($workspaceTwoId, $this->userId, 'battery-ws2@example.test');
        $this->createAutoresponderLog($workspaceTwoId, $workspaceTwoContactId, 'auto_sent', 'sent', 0.95);

        WorkspaceContext::activateRuntimeWorkspace(1, $this->userId, 'owner');
        $cache = $this->memoryCache();
        $service = $this->batteryService(cache: $cache);
        $workspaceOneStatus = $service->getStatus($this->userId);

        $this->assertNotContains(
            'Recent auto-sent replies detected',
            (array) ($workspaceOneStatus['layers']['autoresponder']['signals'] ?? [])
        );

        $workspaceOneContactId = $this->createBatteryContact(1, $this->userId, 'battery-ws1@example.test');
        $this->createAutoresponderLog(1, $workspaceOneContactId, 'auto_sent', 'sent', 0.96);
        $cache->expirePayloads();
        $workspaceOneUpdated = $service->getStatus($this->userId);

        $this->assertContains(
            'Recent auto-sent replies detected',
            (array) ($workspaceOneUpdated['layers']['autoresponder']['signals'] ?? [])
        );
        $this->assertGreaterThanOrEqual(
            (int) ($workspaceOneStatus['layers']['autoresponder']['score'] ?? 0),
            (int) ($workspaceOneUpdated['layers']['autoresponder']['score'] ?? 0)
        );
    }

    private function batteryService(
        ?AIOperatingContextService $operatingContext = null,
        ?AutomationBatteryMemoryCache $cache = null
    ): AutomationBatteryService
    {
        return new AutomationBatteryService(
            operatingContext: $operatingContext,
            cache: $cache ?? $this->memoryCache(),
            fileCacheDirectory: $this->makeCacheDirectory()
        );
    }

    private function memoryCache(): AutomationBatteryMemoryCache
    {
        return new AutomationBatteryMemoryCache();
    }

    private function makeCacheDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_battery_cache_' . uniqid('', true);
        @mkdir($directory, 0755, true);
        $this->cacheDirectories[] = $directory;

        return $directory;
    }

    private function insertBatterySnapshot(int $subjectUserId, int $score, string $source, int $ageSeconds): void
    {
        $calculatedAt = date('Y-m-d H:i:s', time() - max(0, $ageSeconds));
        $expiresAt = date('Y-m-d H:i:s', strtotime($calculatedAt) + 600);
        $status = [
            'score' => $score,
            'bucket' => 'medium',
            'status_label' => 'Building automation',
            'headline_label' => 'Cached snapshot',
            'summary' => 'Stored dashboard snapshot.',
            'top_blockers' => [],
            'top_boosters' => ['Snapshot ready'],
            'top_actions' => [],
            'top_progress_signals' => [],
            'context_enrichments' => [],
            'mode_label' => 'Cached snapshot',
            'subject_user_id' => $subjectUserId,
            'setup_progress_label' => '3 of 6 foundations ready',
            'layers' => [],
            'job_health' => [],
        ];

        Database::execute(
            "INSERT INTO automation_battery_snapshots
                (workspace_id, subject_user_id, score, bucket, status_label, headline_label, summary,
                 payload_json, fingerprint, calculation_source, calculated_at, expires_at, created_at, updated_at)
             VALUES (1, ?, ?, 'medium', 'Building automation', 'Cached snapshot', 'Stored dashboard snapshot.',
                     ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                $subjectUserId,
                $score,
                json_encode($status),
                str_repeat('c', 64),
                $source,
                $calculatedAt,
                $expiresAt,
            ]
        );
    }

    private function createBatteryContact(int $workspaceId, int $assignedTo, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at, updated_at)
             VALUES (?, ?, 'Battery', 'Responder', ?, ?, NOW(), NOW())",
            [$workspaceId, uniqid('battery-contact-', true), $email, $assignedTo]
        );

        return (int) Database::lastInsertId();
    }

    private function createCustomerCareEvidence(int $userId): int
    {
        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, assigned_to, metadata_json, created_at, updated_at)
             VALUES
                (1, ?, 'Care', 'Customer', ?, ?, ?, NOW(), NOW())",
            [
                uniqid('battery-care-contact-', true),
                'battery-care-' . uniqid('', true) . '@example.test',
                $userId,
                json_encode([
                    'source' => 'default_workspace_owner_contact',
                    'default_workspace_contact_scope' => 'current_paying_customer',
                    'current_paying_customer' => true,
                ]),
            ]
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO nurture_profiles
                (workspace_id, contact_id, lifecycle_lane, nurture_status, temperature, cadence, owner_user_id,
                 entry_source, entry_at, purchase_summary_json, last_touch_at, next_touch_at, next_touch_reason,
                 health_score, health_signals_json, suggested_touch_json, created_at, updated_at)
             VALUES
                (1, ?, 'customer_success', 'active', 'warm', 'monthly', ?, 'deal_closed_won', NOW(), ?, DATE_SUB(NOW(), INTERVAL 7 DAY),
                 DATE_ADD(NOW(), INTERVAL 21 DAY), 'Check whether the customer is getting the promised outcome.', 82, '{}', '{}', NOW(), NOW())",
            [$contactId, $userId, json_encode(['label' => 'Closed-won deal'])]
        );
        $profileId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO nurture_programs
                (workspace_id, name, description, program_type, status, cadence, created_by, created_at, updated_at)
             VALUES
                (1, 'Battery Follow-up Plan', 'Customer care rhythm for automation battery tests.', 'customer_success', 'active', 'monthly', ?, NOW(), NOW())",
            [$userId]
        );
        $programId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO nurture_enrollments
                (workspace_id, profile_id, program_id, contact_id, status, current_step_label, next_touch_at, metadata_json, updated_at)
             VALUES
                (1, ?, ?, ?, 'active', 'Program start', DATE_ADD(NOW(), INTERVAL 21 DAY), '{}', NOW())",
            [$profileId, $programId, $contactId]
        );

        Database::execute(
            "INSERT INTO nurture_touchpoints
                (workspace_id, profile_id, contact_id, touch_type, channel, status, subject, notes, completed_at, created_by, metadata_json, created_at, updated_at)
             VALUES
                (1, ?, ?, 'check_in', 'note', 'completed', 'Customer check-in completed', 'Customer is on track.', NOW(), ?, '{}', NOW(), NOW())",
            [$profileId, $contactId, $userId]
        );

        for ($i = 1; $i <= 6; $i++) {
            Database::execute(
                "INSERT INTO ai_operator_demonstrations
                    (workspace_id, tenant_key, domain_key, actor_user_id, actor_type, source_surface, entity_type, entity_id,
                     action_key, action_payload_json, metadata_json, outcome_state_json, outcome_label, demonstration_hash,
                     was_successful, observed_at, created_at)
                 VALUES
                    (1, 'workspace:1', 'customer_care', ?, 'user', 'customer_care', 'contact', ?,
                     'record_check_in', '{}', '{}', '{}', 'completed', ?, 1, NOW(), NOW())",
                [$userId, $contactId, sprintf('battery-care-demo-%03d', $i)]
            );
        }

        return $contactId;
    }

    private function createAutoresponderLog(int $workspaceId, int $contactId, string $decision, string $status, float $confidence): void
    {
        Database::execute(
            "INSERT INTO ai_autoresponder_logs
                (workspace_id, contact_id, channel, decision, status, confidence, created_at, updated_at)
             VALUES (?, ?, 'email', ?, ?, ?, NOW(), NOW())",
            [$workspaceId, $contactId, $decision, $status, $confidence]
        );
    }
}

final class AutomationBatteryCountingContext extends AIOperatingContextService
{
    public int $calls = 0;

    public function __construct(private bool $shouldThrow = false)
    {
    }

    public function buildForUser(int $userId, array $options = []): array
    {
        $this->calls++;
        if ($this->shouldThrow) {
            throw new \RuntimeException('Automation readiness should not rebuild.');
        }

        return [
            'feature_state' => [
                'company_profile_ready' => false,
                'products_priced' => false,
                'invoicing_ready' => false,
                'workflow_graph_ready' => true,
                'commercial_automation_ready' => true,
                'readiness_gaps' => ['company profile'],
            ],
            'deal_automation_state' => [
                'current_mode' => 'manual',
                'current_mode_label' => 'Manual',
                'readiness_status' => 'not_ready',
                'is_managed_by_auto_admin' => false,
                'blocking_reasons' => ['Deal automation is disabled.'],
                'recent_audit_summary' => ['recent_total' => 0, 'recent_applied' => 0],
            ],
        ];
    }
}

final class AutomationBatteryMemoryCache extends CacheManager
{
    private array $items = [];

    public function __construct()
    {
    }

    public function get(string $key)
    {
        $item = $this->items[$key] ?? null;
        if (!is_array($item)) {
            return null;
        }
        if ((int) ($item['expires_at'] ?? 0) <= time()) {
            unset($this->items[$key]);
            return null;
        }
        return $item['value'] ?? null;
    }

    public function set(string $key, $value, int $ttl = 300): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function expirePayloads(): void
    {
        $past = gmdate('c', time() - 1);
        foreach ($this->items as &$item) {
            if (is_array($item['value'] ?? null)) {
                $item['value']['expires_at'] = $past;
                $item['expires_at'] = time() + 300;
            }
        }
        unset($item);
    }
}
