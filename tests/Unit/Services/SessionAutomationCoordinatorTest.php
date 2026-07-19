<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AITaskAutomationService;
use CRM\Services\SessionAutomationCoordinator;
use CRM\Services\SessionAutomationStateService;
use CRM\Services\TaskCompletionScanQueueService;
use CRM\Tests\DatabaseTestCase;

class SessionAutomationCoordinatorTest extends DatabaseTestCase
{
    public function testCoordinatorRespectsCooldownsAcrossRuns(): void
    {
        $userId = $this->createUser('cooldown@example.com');
        $state = new SessionAutomationStateService();

        $taskQueue = new class extends TaskCompletionScanQueueService {
            public int $calls = 0;
            public int $processCalls = 0;

            public function countOpenAutoCompletableTasks(int $userId, ?int $workspaceId = null): int
            {
                return 1;
            }

            public function enqueueForUser(int $userId, ?int $requestedBy = null, string $source = 'manual', bool $force = false, ?int $workspaceId = null): array
            {
                $this->calls++;
                return [
                    'status' => 'queued',
                    'completed_tasks' => 0,
                    'matched_tasks' => 0,
                    'refresh_recommended' => false,
                ];
            }

            public function processQueuedScansForUser(int $userId, int $limit = 10, ?int $workspaceId = null): array
            {
                $this->processCalls++;
                return [
                    'processed_jobs' => 1,
                    'completed_jobs' => 1,
                    'failed_jobs' => 0,
                    'completed_tasks' => 0,
                    'results' => [],
                ];
            }

            public function getUserScanStatus(int $userId, ?int $workspaceId = null): array
            {
                return [
                    'status' => 'completed',
                    'completed_tasks' => 0,
                    'matched_tasks' => 0,
                    'refresh_recommended' => false,
                    'show_status_bar' => true,
                    'last_scan_age_seconds' => 0,
                ];
            }
        };

        $taskAutomation = new class extends AITaskAutomationService {
            public int $shouldSeedCalls = 0;
            public int $seedCalls = 0;

            public function __construct()
            {
            }

            public function shouldAutoSeedToday(int $userId): bool
            {
                $this->shouldSeedCalls++;
                return false;
            }

            public function autoSeedDailyTasks(int $userId): array
            {
                $this->seedCalls++;
                return [
                    'mode' => '1',
                    'created_count' => 0,
                    'skipped' => 0,
                    'created_titles' => [],
                ];
            }
        };

        $coordinator = new SessionAutomationCoordinator($state, $taskQueue, $taskAutomation);

        $first = $coordinator->runForUser($userId, ['current_page' => 'contacts.php']);
        $second = $coordinator->runForUser($userId, ['current_page' => 'contacts.php']);

        $this->assertSame(1, $taskQueue->calls);
        $this->assertSame(0, $taskQueue->processCalls);
        $this->assertSame(1, $taskAutomation->shouldSeedCalls);
        $this->assertSame(0, $taskAutomation->seedCalls);

        $firstTask = $this->findFeature($first['features'], 'task_auto_complete');
        $secondTask = $this->findFeature($second['features'], 'task_auto_complete');
        $this->assertSame('ok', $firstTask['status']);
        $this->assertSame(0, $firstTask['processed_jobs']);
        $this->assertTrue($firstTask['background_worker_required']);
        $this->assertSame('skipped', $secondTask['status']);
        $this->assertSame('cooldown', $secondTask['skipped_reason']);
    }

    public function testCoordinatorReportsAiSeededTaskChanges(): void
    {
        $userId = $this->createUser('seed@example.com');
        $state = new SessionAutomationStateService();

        $taskQueue = new class extends TaskCompletionScanQueueService {
            public function countOpenAutoCompletableTasks(int $userId, ?int $workspaceId = null): int
            {
                return 0;
            }

            public function getUserScanStatus(int $userId, ?int $workspaceId = null): array
            {
                return [
                    'status' => 'idle',
                    'completed_tasks' => 0,
                    'matched_tasks' => 0,
                    'refresh_recommended' => false,
                    'show_status_bar' => false,
                    'last_scan_age_seconds' => null,
                ];
            }
        };

        $taskAutomation = new class extends AITaskAutomationService {
            public function __construct()
            {
            }

            public function shouldAutoSeedToday(int $userId): bool
            {
                return true;
            }

            public function autoSeedDailyTasks(int $userId): array
            {
                return [
                    'mode' => '1',
                    'created_count' => 2,
                    'skipped' => 1,
                    'retired_count' => 1,
                    'blocked_by_gate' => 0,
                    'blocked_by_plan' => 1,
                    'gate_redirected' => 0,
                    'created_titles' => ['Task A', 'Task B'],
                ];
            }
        };

        $coordinator = new SessionAutomationCoordinator($state, $taskQueue, $taskAutomation);
        $summary = $coordinator->runForUser($userId, ['current_page' => 'dashboard.php']);

        $taskFeature = $this->findFeature($summary['features'], 'task_auto_complete');
        $seedFeature = $this->findFeature($summary['features'], 'ai_task_auto_seed');

        $this->assertSame('skipped', $taskFeature['status']);
        $this->assertSame('no_eligible_tasks', $taskFeature['skipped_reason']);
        $this->assertSame('ok', $seedFeature['status']);
        $this->assertSame(2, $seedFeature['created_count']);
        $this->assertSame(1, $seedFeature['retired_count']);
        $this->assertSame(1, $seedFeature['blocked_by_plan']);
        $this->assertTrue($seedFeature['changed']);
        $this->assertTrue($seedFeature['retired']);
        $this->assertSame(['new_ai_tasks_created', 'stale_ai_tasks_retired'], $seedFeature['user_visible_changes']);
    }

    public function testCoordinatorReportsRetirementOnlyAiSeedChanges(): void
    {
        $userId = $this->createUser('retired-only@example.com');
        $state = new SessionAutomationStateService();

        $taskQueue = new class extends TaskCompletionScanQueueService {
            public function countOpenAutoCompletableTasks(int $userId, ?int $workspaceId = null): int
            {
                return 0;
            }

            public function getUserScanStatus(int $userId, ?int $workspaceId = null): array
            {
                return [
                    'status' => 'idle',
                    'completed_tasks' => 0,
                    'matched_tasks' => 0,
                    'refresh_recommended' => false,
                    'show_status_bar' => false,
                    'last_scan_age_seconds' => null,
                ];
            }
        };

        $taskAutomation = new class extends AITaskAutomationService {
            public function __construct()
            {
            }

            public function shouldAutoSeedToday(int $userId): bool
            {
                return true;
            }

            public function autoSeedDailyTasks(int $userId): array
            {
                return [
                    'mode' => '2',
                    'created_count' => 0,
                    'skipped' => 0,
                    'retired_count' => 2,
                    'blocked_by_gate' => 1,
                    'blocked_by_plan' => 1,
                    'gate_redirected' => 1,
                    'created_titles' => [],
                ];
            }
        };

        $coordinator = new SessionAutomationCoordinator($state, $taskQueue, $taskAutomation);
        $summary = $coordinator->runForUser($userId, ['current_page' => 'dashboard.php']);
        $seedFeature = $this->findFeature($summary['features'], 'ai_task_auto_seed');

        $this->assertSame('ok', $seedFeature['status']);
        $this->assertSame(0, $seedFeature['created_count']);
        $this->assertSame(2, $seedFeature['retired_count']);
        $this->assertTrue($seedFeature['changed']);
        $this->assertTrue($seedFeature['retired']);
        $this->assertSame(['stale_ai_tasks_retired'], $seedFeature['user_visible_changes']);
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('user_', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function findFeature(array $features, string $featureKey): array
    {
        foreach ($features as $feature) {
            if (($feature['feature'] ?? '') === $featureKey) {
                return $feature;
            }
        }

        $this->fail('Missing feature result for ' . $featureKey);
    }
}
