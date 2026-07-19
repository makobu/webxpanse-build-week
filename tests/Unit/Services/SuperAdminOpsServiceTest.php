<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\SuperAdminOpsService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class SuperAdminOpsServiceTest extends DatabaseTestCase
{
    public function testSuperAdminOpsSummarizesRiskSignals(): void
    {
        $seed = $this->seedRiskyWorkspace();
        $service = new SuperAdminOpsService();

        $result = $service->handleMessage('Show risky workspaces', ['id' => $seed['actor_user_id']]);

        $this->assertSame('superadmin_ops', (string) ($result['diagnostics']['surface'] ?? ''));
        $this->assertStringContainsString('Ops Risk Workspace', (string) ($result['answer'] ?? ''));
        $this->assertStringContainsString('Failed billing provider event', (string) ($result['answer'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($result['diagnostics']['risk_count'] ?? 0));
    }

    public function testTaskGenerationCreatesDedupedPlatformOpsTasks(): void
    {
        $seed = $this->seedRiskyWorkspace();
        $service = new SuperAdminOpsService();

        $first = $service->handleMessage('Create platform follow-up tasks', ['id' => $seed['actor_user_id']]);
        $second = $service->handleMessage('Create platform follow-up tasks', ['id' => $seed['actor_user_id']]);

        $this->assertGreaterThanOrEqual(1, (int) ($first['metadata']['task_result']['created_count'] ?? 0));
        $this->assertSame(0, (int) ($second['metadata']['task_result']['created_count'] ?? -1));
        $this->assertGreaterThanOrEqual(1, (int) ($second['metadata']['task_result']['skipped_count'] ?? 0));

        $task = Database::queryOne(
            "SELECT metadata_json
             FROM tasks
             WHERE assigned_to = ?
               AND metadata_json IS NOT NULL
             ORDER BY id DESC
             LIMIT 1",
            [$seed['actor_user_id']]
        );
        $metadata = json_decode((string) ($task['metadata_json'] ?? ''), true);

        $this->assertSame('superadmin_ops', (string) ($metadata['source_surface'] ?? ''));
        $this->assertNotEmpty($metadata['platform_task_key'] ?? '');
    }

    public function testNonSuperAdminCannotUseSuperAdminOpsSurface(): void
    {
        $userId = (int) Auth::createUser('ops-normal-user@example.com', 'P@ssword123!', 'user', 'Normal', 'User');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Super Admin access is required');

        (new SuperAdminOpsService())->handleMessage('What needs my attention today?', ['id' => $userId]);
    }

    /**
     * @return array{actor_user_id:int,workspace_id:int}
     */
    private function seedRiskyWorkspace(): array
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Risk Workspace',
            'workspace_slug' => 'ops-risk-workspace',
            'first_name' => 'Ops',
            'last_name' => 'Owner',
            'email' => 'ops-risk-owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        Session::set('active_workspace_id', $workspaceId);

        Database::execute(
            "UPDATE workspaces
             SET trial_ends_at = ?,
                 plan_status = 'trialing'
             WHERE id = ?",
            [date('Y-m-d H:i:s', strtotime('+2 days')), $workspaceId]
        );
        Database::execute(
            "INSERT INTO workspace_wallets (workspace_id, token_balance, reserved_tokens)
             VALUES (?, 250, 0)
             ON DUPLICATE KEY UPDATE token_balance = VALUES(token_balance), reserved_tokens = VALUES(reserved_tokens)",
            [$workspaceId]
        );
        Database::execute(
            "UPDATE workspace_subscriptions
             SET subscription_status = 'past_due'
             WHERE workspace_id = ?",
            [$workspaceId]
        );
        Database::execute(
            "INSERT INTO billing_provider_events
                (provider, workspace_id, event_name, event_reference, processing_status, processing_message)
             VALUES
                ('paystack', ?, 'charge.failed', 'ops-risk-ref', 'failed', 'Unit test failure')",
            [$workspaceId]
        );

        return [
            'actor_user_id' => $actorUserId,
            'workspace_id' => $workspaceId,
        ];
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }
}
