<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AITaskAutomationService;
use CRM\Services\AITaskWorkspaceAutoSeedWorkerService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AITaskWorkspaceAutoSeedWorkerServiceTest extends DatabaseTestCase
{
    public function testWorkerActivatesEachWorkspaceMembershipBeforeSeeding(): void
    {
        $userId = $this->createWorkerUser('seed-worker@example.test');
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, 'Seed Worker Workspace Two', 'seed-worker-workspace-two', 'active', 'active', ?)",
            [uniqid('workspace-', true), $userId]
        );
        $workspaceTwoId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'admin', 'active', 0, NOW())",
            [$workspaceTwoId, $userId]
        );

        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');
        $taskAutomation = new class extends AITaskAutomationService {
            public array $shouldContexts = [];
            public array $seedContexts = [];

            public function __construct()
            {
            }

            public function shouldAutoSeedToday(int $userId): bool
            {
                $this->shouldContexts[] = [
                    'user_id' => $userId,
                    'workspace_id' => (int) WorkspaceContext::currentWorkspaceId(),
                ];
                return true;
            }

            public function autoSeedDailyTasks(int $userId): array
            {
                $workspaceId = (int) WorkspaceContext::currentWorkspaceId();
                $this->seedContexts[] = [
                    'user_id' => $userId,
                    'workspace_id' => $workspaceId,
                ];

                return [
                    'mode' => '1',
                    'created_count' => $workspaceId === 1 ? 1 : 0,
                    'skipped' => 0,
                    'retired_count' => $workspaceId === 1 ? 0 : 2,
                    'blocked_by_gate' => $workspaceId === 1 ? 0 : 1,
                    'blocked_by_plan' => $workspaceId === 1 ? 0 : 1,
                    'gate_redirected' => $workspaceId === 1 ? 0 : 1,
                    'created_titles' => [],
                ];
            }
        };

        $summary = (new AITaskWorkspaceAutoSeedWorkerService($taskAutomation))->run(20);

        $this->assertContains(1, array_column($taskAutomation->shouldContexts, 'workspace_id'));
        $this->assertContains($workspaceTwoId, array_column($taskAutomation->shouldContexts, 'workspace_id'));
        $this->assertContains(1, array_column($taskAutomation->seedContexts, 'workspace_id'));
        $this->assertContains($workspaceTwoId, array_column($taskAutomation->seedContexts, 'workspace_id'));
        $this->assertSame(1, (int) WorkspaceContext::currentWorkspaceId());
        $this->assertSame(1, $summary['seeded_users']);
        $this->assertSame(1, $summary['created_tasks']);
        $this->assertSame(2, $summary['retired_tasks']);
        $this->assertSame(1, $summary['blocked_by_gate']);
        $this->assertSame(1, $summary['blocked_by_plan']);
        $this->assertSame(1, $summary['gate_redirected']);
    }

    private function createWorkerUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('seed-worker-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }
}
