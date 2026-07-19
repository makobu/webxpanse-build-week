<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class QuickStartOnboardingTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testOnboardingRendersCalmCompanyBasicsForNewWorkspace(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Start with the basics', $body);
        $this->assertStringContainsString('Workspace setup', $body);
        $this->assertStringContainsString('Only the company name is required.', $body);
        $this->assertStringContainsString('name="action" value="quick_start_complete"', $body);
        $this->assertStringContainsString('Enter your new workspace', $body);
        $this->assertStringNotContainsString('Work Ownership', $body);
        $this->assertStringNotContainsString('Later in Settings', $body);
        $this->assertStringNotContainsString('Connect Gmail', $body);
        $this->assertStringNotContainsString('Connect WhatsApp', $body);
        $this->assertStringNotContainsString('name="product_name[]"', $body);
        $this->assertStringNotContainsString('name="draft_tone_preset"', $body);
        $this->assertStringNotContainsString('name="technical_level"', $body);
    }

    public function testSignupSuccessRendersWorkspaceCreatedCelebrationWithQuickStartForm(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => ['signup_success' => '1'],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace created', $body);
        $this->assertStringContainsString('Your new workspace is ready', $body);
        $this->assertStringContainsString('Business setup', $body);
        $this->assertStringContainsString('Workspace created. Start by giving Clarity the business context.', $body);
        $this->assertStringContainsString('Only the company name is required.', $body);
        $this->assertStringContainsString('name="action" value="quick_start_complete"', $body);
        $this->assertStringContainsString('Enter your new workspace', $body);
        $this->assertStringNotContainsString('Work Ownership', $body);
        $this->assertStringNotContainsString('Later in Settings', $body);
        $this->assertStringNotContainsString('name="product_name[]"', $body);
        $this->assertStringNotContainsString('name="technical_level"', $body);
    }

    public function testRequestedLaterStepStillRendersCalmSingleScreen(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => ['step' => '2'],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Start with the basics', $body);
        $this->assertStringContainsString('Enter your new workspace', $body);
        $this->assertStringNotContainsString('Products and services', $body);
        $this->assertStringNotContainsString('Work Ownership', $body);
        $this->assertStringNotContainsString('Later in Settings', $body);
        $this->assertStringNotContainsString('name="product_name[]"', $body);
        $this->assertStringNotContainsString('name="ideal_customer_profile"', $body);
        $this->assertStringNotContainsString('name="communication_channel"', $body);
        $this->assertStringNotContainsString('name="lean_problem"', $body);
        $this->assertStringNotContainsString('name="bank_instructions"', $body);
    }

    public function testQuickStartRejectsInvalidCsrf(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => array_merge($this->quickStartPost(), [
                'csrf_token' => 'bad',
            ]),
        ]);

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertStringContainsString('Invalid security token', (string) ($response['body'] ?? ''));
    }

    public function testQuickStartPostCompletesAndRedirectsToDashboardWelcome(): void
    {
        $seed = $this->seedWorkspace();

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => $this->quickStartPost(),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));

        $row = Database::queryOne("SELECT status, communication_channel, skipped_optional_json FROM workspace_onboarding_state WHERE workspace_id = ?", [(int) $seed['workspace_id']]);
        $subscription = Database::queryOne(
            "SELECT subscription_status
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             LIMIT 1",
            [(int) $seed['workspace_id']]
        );
        $this->assertSame('completed', (string) ($row['status'] ?? ''));
        $this->assertSame('', (string) ($row['communication_channel'] ?? ''));
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertContains('quick_start', json_decode((string) ($row['skipped_optional_json'] ?? '[]'), true));
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int}
     */
    private function seedWorkspace(): array
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Quick Start Workspace',
            'workspace_slug' => 'quick-start-workspace-' . strtolower(bin2hex(random_bytes(3))),
            'first_name' => 'Quick',
            'last_name' => 'Owner',
            'email' => 'quick.start.' . strtolower(bin2hex(random_bytes(3))) . '@example.test',
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
            'user_uuid' => 'quick-start-user',
            'user_email' => 'quick.start.owner@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-quick-start',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function quickStartPost(): array
    {
        return [
            'csrf_token' => 'csrf-quick-start',
            'action' => 'quick_start_complete',
            'company_name' => 'Quick Co',
            'company_industry' => 'Home services',
            'company_description' => 'Quick Co helps homeowners book reliable service visits.',
        ];
    }
}
