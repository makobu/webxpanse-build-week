<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class OnboardingClarityDraftEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testOnboardingPageRendersInlineClarityAssistControls(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => ['step' => 4],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('data-clarity-draft-field="product_description"', $body);
        $this->assertStringContainsString('Let Clarity draft this', $body);
    }

    public function testDraftEndpointRejectsInvalidCsrf(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('api/onboarding/clarity_draft.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'bad',
                'step' => 1,
                'field' => 'company_description',
            ],
        ]);

        $this->assertSame(419, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
    }

    public function testDraftEndpointRejectsNonWorkspaceAdmin(): void
    {
        $seed = $this->seedWorkspace();
        $memberId = $this->createMember($seed['workspace_id']);

        $response = $this->runWebEndpoint('api/onboarding/clarity_draft.php', [
            'user_id' => $memberId,
            'user_role' => 'user',
            'csrf_token' => 'csrf-member',
            'active_workspace_id' => $seed['workspace_id'],
            'active_workspace_uuid' => $seed['workspace_uuid'],
            'active_workspace_slug' => $seed['workspace_slug'],
            'active_workspace_name' => $seed['workspace_name'],
            'active_workspace_role' => 'member',
        ], [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-member',
                'step' => 1,
                'field' => 'company_description',
                'form_context' => ['company_name' => 'Member Co'],
            ],
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
    }

    public function testDraftEndpointReturnsSuggestionForSupportedField(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('api/onboarding/clarity_draft.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-owner',
                'step' => 1,
                'field' => 'company_description',
                'current_value' => '',
                'form_context' => [
                    'company_name' => 'Draft Co',
                    'company_industry' => 'Services',
                    'company_website' => 'https://draft.example',
                ],
            ],
        ]);
        $payload = json_decode((string) ($response['body'] ?? '{}'), true) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('company_description', (string) ($payload['field'] ?? ''));
        $this->assertNotSame('', trim((string) ($payload['draft'] ?? '')));
        $this->assertStringContainsString('website', (string) ($payload['source_notes'] ?? ''));
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int}
     */
    private function seedWorkspace(): array
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Clarity Draft Workspace',
            'workspace_slug' => 'clarity-draft-workspace',
            'first_name' => 'Draft',
            'last_name' => 'Owner',
            'email' => 'clarity.draft.owner@example.test',
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

    private function createMember(int $workspaceId): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, 'clarity.draft.member@example.test', ?, 'user', NOW())",
            [uniqid('clarity-member-', true), password_hash('secret', PASSWORD_DEFAULT)]
        );
        $memberId = (int) Database::lastInsertId();
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $memberId, 'member', false, null);

        return $memberId;
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_role' => 'admin',
            'csrf_token' => 'csrf-owner',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $seed['membership_id'],
        ];
    }
}
