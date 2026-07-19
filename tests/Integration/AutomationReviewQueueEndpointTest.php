<?php

namespace CRM\Tests\Integration;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\AutomationCatalogService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class AutomationReviewQueueEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testDashboardEndpointRequiresSuperAdmin(): void
    {
        $regularUserId = $this->createUser('automation-review-admin@example.test', 'admin');
        $response = $this->runWebEndpoint('api/automation_review_queue.php', $this->webSession($regularUserId, 'admin'), [
            'method' => 'GET',
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
    }

    public function testSuperAdminCanReadAutomationReviewDashboard(): void
    {
        $superAdminId = $this->createUser('automation-review-superadmin@example.test', 'superadmin');
        Authorization::assignUserRoleBySlug($superAdminId, 'superadmin', $superAdminId);

        $response = $this->runWebEndpoint('api/automation_review_queue.php', $this->webSession($superAdminId, 'superadmin'), [
            'method' => 'GET',
            'query' => ['limit' => 5],
        ]);

        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertTrue((bool) ($payload['dashboard']['schema_ready'] ?? false));
        $this->assertArrayHasKey('summary', (array) ($payload['dashboard'] ?? []));
    }

    public function testSuperAdminCanApplyEscalationPolicy(): void
    {
        $superAdminId = $this->createUser('automation-review-escalation@example.test', 'superadmin');
        Authorization::assignUserRoleBySlug($superAdminId, 'superadmin', $superAdminId);

        $runId = (new AutomationCatalogService())->recordRun('failed_migration_detector', [
            'run_status' => 'suggested',
            'severity' => 'critical',
            'evidence' => ['pending_count' => 1],
            'recommendation' => ['action' => 'run_database_migrations'],
            'source' => 'phpunit',
        ]);
        Database::execute(
            "UPDATE automation_catalog_runs SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?",
            [$runId]
        );

        $response = $this->runWebEndpoint('api/automation_review_queue.php', $this->webSession($superAdminId, 'superadmin'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-automation-review',
                'action' => 'apply_escalation_policy',
            ],
        ]);

        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame(1, (int) ($payload['result']['newly_escalated'] ?? 0));
        $this->assertSame(1, (int) ($payload['notifications']['notifications_created'] ?? 0));

        $run = Database::queryOne('SELECT escalation_status, escalation_notified_at FROM automation_catalog_runs WHERE id = ? LIMIT 1', [$runId]);
        $this->assertSame('escalated', (string) ($run['escalation_status'] ?? ''));
        $this->assertNotEmpty($run['escalation_notified_at'] ?? null);
    }

    private function createUser(string $email, string $role): int
    {
        Database::execute(
            'INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, ?, NOW())',
            [$email, password_hash('secret', PASSWORD_DEFAULT), $role]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(int $userId, string $role): array
    {
        return [
            'user_id' => $userId,
            'user_uuid' => '00000000-0000-4000-8000-' . str_pad((string) $userId, 12, '0', STR_PAD_LEFT),
            'user_email' => 'automation-review-' . $userId . '@example.test',
            'user_role' => $role,
            'active_workspace_id' => 1,
            'active_workspace_uuid' => '00000000-0000-4000-8000-000000000001',
            'active_workspace_slug' => 'default',
            'active_workspace_name' => 'Default Workspace',
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => 1,
            'csrf_token' => 'csrf-automation-review',
            '__remember_restore_attempted' => true,
        ];
    }
}
