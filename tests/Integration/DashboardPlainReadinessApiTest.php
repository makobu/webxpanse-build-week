<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class DashboardPlainReadinessApiTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testPlainReadinessRequiresAuthentication(): void
    {
        $response = $this->runEndpointScript('api/dashboard/plain_readiness.php', [
            'method' => 'GET',
        ]);

        $this->assertSame(401, (int) ($response['status'] ?? 0));
    }

    public function testPlainReadinessReturnsStablePayloadShape(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('api/dashboard/plain_readiness.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $payload = json_decode((string) ($response['body'] ?? ''), true);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertIsArray($payload['readiness'] ?? null);
        $this->assertArrayHasKey('headline_label', $payload['readiness']);
        $this->assertArrayHasKey('progress_label', $payload['readiness']);
        $this->assertArrayHasKey('phase', $payload['readiness']);
        $this->assertArrayHasKey('primary_gap', $payload['readiness']);
        $this->assertArrayHasKey('milestones', $payload['readiness']);
        $this->assertContains((string) ($payload['readiness']['phase'] ?? ''), ['setup', 'revenue_momentum']);
        if (($payload['readiness']['phase'] ?? '') === 'setup') {
            $this->assertSame('deterministic', (string) ($payload['readiness']['source'] ?? ''));
            $this->assertSame('company_profile', (string) ($payload['readiness']['primary_gap']['key'] ?? ''));
            $this->assertSame('settings.php?tab=company', (string) ($payload['readiness']['primary_gap']['href'] ?? ''));
            $this->assertSame('Profile and products saved', (string) (($payload['readiness']['milestones'][0]['label'] ?? '')));
        } else {
            $this->assertSame('dashboard_momentum', (string) ($payload['readiness']['source'] ?? ''));
        }
        $this->assertStringNotContainsString('OpenAI', (string) ($response['body'] ?? ''));
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int}
     */
    private function seedWorkspace(): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Plain Readiness API ' . $suffix,
            'workspace_slug' => 'plain-readiness-api-' . $suffix,
            'first_name' => 'Plain',
            'last_name' => 'Owner',
            'email' => 'plain.readiness.api.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $workspace = Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
        ];
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'plain-readiness-api-user',
            'user_email' => 'plain.readiness.api@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-dashboard-plain-readiness',
        ];
    }
}
