<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class OnboardingOutcomePreviewTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testOnboardingSectionsRenderWithoutPersistentBriefPreviewColumn(): void
    {
        $seed = $this->seedWorkspaceForOnboarding();

        foreach ([1, 2, 3, 4, 5] as $step) {
            $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
                'method' => 'GET',
                'query' => ['step' => $step],
            ]);
            $body = (string) ($response['body'] ?? '');

            $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
            $this->assertStringNotContainsString('Brief preview', $body);
            $this->assertStringNotContainsString('onboarding-brief-preview', $body);
        }

        $stepOne = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => ['step' => 1],
        ]);
        $stepTwo = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => ['step' => 2],
        ]);

        $this->assertStringContainsString('Preview Co', (string) ($stepOne['body'] ?? ''));
        $this->assertStringContainsString('Outcome Engine', (string) ($stepTwo['body'] ?? ''));
    }

    public function testReviewStepRendersWorkspaceOperatingBriefPreview(): void
    {
        $seed = $this->seedWorkspaceForOnboarding();
        $this->completeCoreLaunchReadiness($seed);

        $response = $this->runWebEndpoint('public/onboarding.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => ['step' => 5],
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Review the operating brief', $body);
        $this->assertStringContainsString('Operating brief', $body);
        $this->assertStringContainsString('Clarity understands', $body);
        $this->assertStringContainsString('Later setup', $body);
        $this->assertStringNotContainsString('Clarity has optional follow-up questions for later', $body);
        $this->assertStringContainsString('Preview Co', $body);
        $this->assertStringContainsString('Outcome Engine', $body);
        $this->assertStringContainsString('Growing service teams with inconsistent follow-up.', $body);
        $this->assertStringContainsString('Work with me', $body);
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int}
     */
    private function seedWorkspaceForOnboarding(): array
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Outcome Preview Workspace',
            'workspace_slug' => 'outcome-preview-workspace',
            'first_name' => 'Outcome',
            'last_name' => 'Owner',
            'email' => 'outcome.preview.owner@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $service->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Preview Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Preview Co helps service teams understand their pipeline and follow up faster.',
            'success_outcome' => 'Fewer missed leads and clearer next actions.',
        ]);
        $service->saveStep($workspaceId, $userId, 2, [
            'product_name' => 'Outcome Engine',
            'product_description' => 'A practical CRM implementation service.',
            'pricing_info' => 'Starts at 500 USD',
            'target_audience' => 'Growing service teams',
            'ideal_customer_profile' => 'Growing service teams with inconsistent follow-up.',
            'offer_angle' => 'Make every lead easier to move.',
        ]);
        $service->saveStep($workspaceId, $userId, 3, [
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'warm',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
            'words_to_avoid' => 'unapproved discounts',
            'escalation_preference' => 'Refunds and complaints',
        ]);
        $service->saveStep($workspaceId, $userId, 4, [
            'technical_level' => 'work_with_me',
            'ai_best_practices_enabled' => '1',
        ]);

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
     * @param array{workspace_id:int,user_id:int} $seed
     */
    private function completeCoreLaunchReadiness(array $seed): void
    {
        $service = new WorkspaceOnboardingService();
        $service->saveStep((int) $seed['workspace_id'], (int) $seed['user_id'], 4, [
            'technical_level' => 'work_with_me',
            'ai_best_practices_enabled' => '1',
            'deal_automation_enabled' => '1',
        ]);
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'outcome-preview-user',
            'user_email' => 'outcome.preview.owner@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-outcome-preview',
            '__remember_restore_attempted' => true,
        ];
    }
}
