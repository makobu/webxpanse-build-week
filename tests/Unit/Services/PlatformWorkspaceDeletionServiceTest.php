<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\DemoWorkspaceService;
use CRM\Services\PlatformWorkspaceDeletionService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class PlatformWorkspaceDeletionServiceTest extends DatabaseTestCase
{
    public function testDeletesWorkspaceScopedDataAndPreservesAuditHistory(): void
    {
        [$actorUserId, $workspaceId] = $this->provisionSuperAdminWorkspace('Delete Service Workspace', 'delete.service.owner@example.com');

        Database::execute(
            "INSERT INTO operator_audit_log (actor_user_id, target_workspace_id, action_type, reason)
             VALUES (?, ?, 'test_existing_audit', 'Keep this audit row')",
            [$actorUserId, $workspaceId]
        );

        $result = (new PlatformWorkspaceDeletionService())->deleteWorkspaces(
            [$workspaceId],
            $actorUserId,
            1,
            'Delete test workspace'
        );

        $workspaceCount = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces WHERE id = ?", [$workspaceId])['c'] ?? 0);
        $walletCount = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId])['c'] ?? 0);
        $auditRows = Database::query(
            "SELECT action_type, metadata_json
             FROM operator_audit_log
             WHERE action_type IN ('test_existing_audit', 'workspace_delete_completed')
             ORDER BY id ASC"
        );

        $this->assertSame(0, $workspaceCount);
        $this->assertSame(0, $walletCount);
        $this->assertContains($workspaceId, $result['deleted_workspace_ids']);
        $this->assertGreaterThanOrEqual(1, (int) ($result['row_counts']['workspaces'] ?? 0));
        $this->assertNotEmpty($auditRows);
        $this->assertSame('test_existing_audit', (string) ($auditRows[0]['action_type'] ?? ''));
        $this->assertSame('workspace_delete_completed', (string) ($auditRows[count($auditRows) - 1]['action_type'] ?? ''));
        $this->assertStringContainsString((string) $workspaceId, (string) ($auditRows[count($auditRows) - 1]['metadata_json'] ?? ''));
    }

    public function testBlocksDefaultCurrentAndLastWorkspaceDeletion(): void
    {
        [$actorUserId, $workspaceId] = $this->provisionSuperAdminWorkspace('Delete Guard Workspace', 'delete.guard.owner@example.com');
        $service = new PlatformWorkspaceDeletionService();

        try {
            $service->deleteWorkspaces([1], $actorUserId, $workspaceId, 'Try default delete');
            $this->fail('Default workspace deletion should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('default workspace', strtolower($e->getMessage()));
        }

        try {
            $service->deleteWorkspaces([$workspaceId], $actorUserId, $workspaceId, 'Try current delete');
            $this->fail('Current workspace deletion should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('current active workspace', strtolower($e->getMessage()));
        }
    }

    public function testBlocksProtectedDemoWorkspaceDeletion(): void
    {
        [$actorUserId] = $this->provisionSuperAdminWorkspace('Delete Demo Guard Workspace', 'delete.demo.guard@example.com');
        $demoWorkspaceId = (new DemoWorkspaceService())->id();

        try {
            (new PlatformWorkspaceDeletionService())->deleteWorkspaces([$demoWorkspaceId], $actorUserId, 1, 'Try demo delete');
            $this->fail('Protected demo workspace deletion should be blocked.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('protected demo workspace', strtolower($e->getMessage()));
        }
    }

    public function testHandlesBulkWorkspaceDeletion(): void
    {
        [$actorUserId, $firstWorkspaceId] = $this->provisionSuperAdminWorkspace('Bulk Delete One', 'bulk.delete.one@example.com');
        [, $secondWorkspaceId] = $this->provisionSuperAdminWorkspace('Bulk Delete Two', 'bulk.delete.two@example.com');

        $result = (new PlatformWorkspaceDeletionService())->deleteWorkspaces(
            [$firstWorkspaceId, $secondWorkspaceId],
            $actorUserId,
            1,
            'Bulk delete test'
        );

        $remaining = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspaces WHERE id IN (?, ?)",
            [$firstWorkspaceId, $secondWorkspaceId]
        )['c'] ?? 0);

        $actualIds = $result['deleted_workspace_ids'];
        sort($actualIds);
        $expectedIds = [$firstWorkspaceId, $secondWorkspaceId];
        sort($expectedIds);

        $this->assertSame(0, $remaining);
        $this->assertSame($expectedIds, $actualIds);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function provisionSuperAdminWorkspace(string $workspaceName, string $email): array
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => $workspaceName,
            'first_name' => 'Delete',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        Database::execute("UPDATE users SET role = 'admin' WHERE id = ?", [$actorUserId]);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        return [$actorUserId, $workspaceId];
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }
}
