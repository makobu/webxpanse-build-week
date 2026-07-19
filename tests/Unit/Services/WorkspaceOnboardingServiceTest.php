<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Services\WorkspaceLaunchChecklistService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceOnboardingServiceTest extends DatabaseTestCase
{
    public function testProvisionedWorkspaceStartsOnboardingAndGateAllowsSettings(): void
    {
        $provisioned = $this->provisionWorkspace('gate-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $state = $service->getState($workspaceId, $userId);

        $this->assertSame('in_progress', $state['status']);
        $this->assertTrue($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'dashboard.php'));
        $this->assertFalse($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'settings.php'));
        $this->assertFalse($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'owner_support.php'));
        $this->assertFalse($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'onboarding.php'));

        $impact = $service->getDashboardSetupImpact($workspaceId, $userId);
        $actionKeys = array_column((array) ($impact['actions'] ?? []), 'key');
        $this->assertTrue((bool) ($impact['visible'] ?? false));
        $this->assertStringContainsString('Your workspace is ', (string) ($impact['headline'] ?? ''));
        $this->assertContains('add_invoice_settings', $actionKeys);
        $this->assertNotContains('invite_team', $actionKeys);
        $this->assertContains('enable_automation', $actionKeys);
        $this->assertContains('team', (array) ($impact['completed_actions'] ?? []));
    }

    public function testOnboardingCanBeCompletedWithoutChannelsAndAutomationPreferencesDoNotEnableRuntime(): void
    {
        $provisioned = $this->provisionWorkspace('full-auto-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $dealConfigBefore = Database::queryOne("SELECT enabled, mode, config_json FROM deal_automation_config WHERE id = 1") ?: [];
        $service->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Full Auto Co',
            'company_industry' => 'Professional services',
            'company_description' => 'A services company that needs fast follow-up automation.',
            'success_outcome' => 'Friendly, concise, specific follow-up.',
        ]);
        $earlyState = $service->getState($workspaceId, $userId);
        $this->assertNotContains('review', $earlyState['completed_steps']);

        $service->saveStep($workspaceId, $userId, 2, [
            'product_name' => 'Automation setup',
            'pricing_info' => 'Starts at 500 USD',
            'product_description' => 'CRM automation setup for growing service businesses.',
            'target_audience' => 'Growing service businesses',
            'ideal_customer_profile' => 'Growing service businesses that need faster follow-up.',
            'offer_angle' => 'Install a safer follow-up operating system.',
        ]);
        $service->saveStep($workspaceId, $userId, 3, [
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'professional',
            'words_to_avoid' => 'unapproved refunds',
            'escalation_preference' => 'Complaints and payment disputes',
        ]);
        $service->saveStep($workspaceId, $userId, 4, [
            'technical_level' => 'run_quietly',
            'ai_best_practices_enabled' => '1',
            'commercial_layer_enabled' => '0',
            'deal_automation_enabled' => '1',
        ]);
        $state = $service->saveStep($workspaceId, $userId, 5, []);

        $this->assertSame('completed', $state['status']);
        $this->assertTrue($state['is_completed']);
        $this->assertFalse($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'dashboard.php'));

        $storedState = Database::queryOne("SELECT readiness_score FROM workspace_onboarding_state WHERE workspace_id = ?", [$workspaceId]);
        $this->assertSame(100, (int) ($storedState['readiness_score'] ?? 0));

        $dealConfig = Database::queryOne("SELECT enabled, mode, config_json FROM deal_automation_config WHERE id = 1");
        $this->assertSame((int) ($dealConfigBefore['enabled'] ?? 0), (int) ($dealConfig['enabled'] ?? 0));
        $this->assertSame((string) ($dealConfigBefore['mode'] ?? ''), (string) ($dealConfig['mode'] ?? ''));
        $this->assertSame((string) ($dealConfigBefore['config_json'] ?? ''), (string) ($dealConfig['config_json'] ?? ''));

        $impact = $service->getDashboardSetupImpact($workspaceId, $userId);
        $actionKeys = array_column((array) ($impact['actions'] ?? []), 'key');
        $this->assertGreaterThanOrEqual(60, (int) ($impact['score'] ?? 0));
        $this->assertContains('connect_channel', $actionKeys);
        $this->assertContains('enable_automation', $actionKeys);

        $launchTasks = Database::query(
            "SELECT id FROM tasks WHERE workspace_id = ? AND metadata_json LIKE ?",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%']
        );
        $this->assertCount(0, $launchTasks);
    }

    public function testCreateInProgressDoesNotRewindCurrentStep(): void
    {
        $provisioned = $this->provisionWorkspace('progress-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $state = $service->saveStep($workspaceId, $userId, 1, [
            'company_name' => 'Progress Co',
            'company_industry' => 'Professional services',
            'company_description' => 'Progress Co helps teams avoid onboarding regressions.',
            'success_outcome' => 'Keep users on the next unfinished chapter.',
        ]);

        $this->assertSame(2, (int) ($state['current_step'] ?? 0));

        $service->createInProgress($workspaceId);
        $stateAfterReload = $service->getState($workspaceId, $userId);

        $this->assertSame(2, (int) ($stateAfterReload['current_step'] ?? 0));
        $this->assertContains('company', (array) ($stateAfterReload['completed_steps'] ?? []));
    }

    public function testVoiceDraftNormalizesLanguageLevelValues(): void
    {
        $provisioned = $this->provisionWorkspace('voice-level-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $service->saveStepDraft($workspaceId, $userId, 3, [
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'junior_high',
        ]);

        $strategy = (new UserStrategyProfile())->get($userId) ?: [];
        $this->assertSame('level_1', $strategy['draft_reading_level'] ?? null);

        $row = Database::queryOne("SELECT tone_json FROM workspace_onboarding_state WHERE workspace_id = ?", [$workspaceId]) ?: [];
        $tone = json_decode((string) ($row['tone_json'] ?? '{}'), true);
        $this->assertSame('level_1', $tone['draft_reading_level'] ?? null);
        $this->assertSame('level_1', (new WorkspaceLanguageLevelService())->currentContext($userId)['level']);

        $service->saveStepDraft($workspaceId, $userId, 3, [
            'relationship_style' => 'trusted_advisor',
            'draft_tone_preset' => 'consultative',
            'draft_cta_style' => 'clear',
            'draft_formality_level' => 'balanced',
            'draft_reading_level' => 'level_3',
        ]);

        $strategy = (new UserStrategyProfile())->get($userId) ?: [];
        $this->assertSame('level_3', $strategy['draft_reading_level'] ?? null);
        $this->assertSame('level_3', (new WorkspaceLanguageLevelService())->currentContext($userId)['level']);
    }

    public function testQuickStartCompletesGateAndLeavesDeferredSetupActions(): void
    {
        $provisioned = $this->provisionWorkspace('quick-start-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $state = $service->completeQuickStart($workspaceId, $userId, [
            'company_name' => 'Quick Co',
            'company_industry' => 'Home services',
            'company_description' => 'Quick Co helps homeowners book reliable service visits.',
            'product_name' => 'Service visit',
            'product_description' => 'A fast booking and service coordination offer.',
            'target_audience' => 'Busy homeowners',
            'ideal_customer_profile' => 'Busy homeowners who need trusted service providers.',
            'relationship_style' => 'friendly_operator',
            'draft_tone_preset' => 'warm',
            'escalation_preference' => 'Complaints and urgent scheduling issues',
            'technical_level' => 'guide_me',
        ]);

        $this->assertSame('completed', $state['status']);
        $this->assertTrue($state['is_completed']);
        $this->assertTrue($state['all_complete']);
        $this->assertFalse($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'dashboard.php'));

        $row = Database::queryOne("SELECT skipped_optional_json, launch_summary_json FROM workspace_onboarding_state WHERE workspace_id = ?", [$workspaceId]);
        $this->assertContains('quick_start', json_decode((string) ($row['skipped_optional_json'] ?? '[]'), true));
        $this->assertTrue((bool) ((json_decode((string) ($row['launch_summary_json'] ?? '{}'), true)['quick_start'] ?? false)));

        $impact = $service->getDashboardSetupImpact($workspaceId, $userId);
        $actionKeys = array_column((array) ($impact['actions'] ?? []), 'key');
        $this->assertTrue((bool) ($impact['quick_start'] ?? false));
        $this->assertStringContainsString('business setup is done', strtolower((string) ($impact['summary'] ?? '')));
        $this->assertContains('connect_channel', $actionKeys);
        $this->assertContains('add_invoice_settings', $actionKeys);
        $this->assertNotContains('invite_team', $actionKeys);
        $this->assertContains('enable_automation', $actionKeys);
        $this->assertContains('team', (array) ($impact['completed_actions'] ?? []));

        $launchTasks = Database::query(
            "SELECT id FROM tasks WHERE workspace_id = ? AND metadata_json LIKE ?",
            [$workspaceId, '%' . WorkspaceLaunchChecklistService::SOURCE . '%']
        );
        $this->assertCount(0, $launchTasks);
    }

    public function testQuickStartCanCompleteWithOnlyCompanyBasics(): void
    {
        $provisioned = $this->provisionWorkspace('minimal-quick-start-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $state = $service->completeQuickStart($workspaceId, $userId, [
            'company_name' => 'Minimal Co',
            'company_location' => 'Kenya',
            'company_website' => 'https://minimal.example.test',
        ]);

        $this->assertSame('completed', $state['status']);
        $this->assertTrue($state['is_completed']);
        $this->assertTrue($state['all_complete']);
        $this->assertTrue((bool) ($state['readiness']['profile_ready'] ?? false));
        $this->assertFalse((bool) ($state['readiness']['product_ready'] ?? true));
        $this->assertFalse((bool) ($state['readiness']['voice_ready'] ?? true));
        $this->assertFalse((bool) ($state['readiness']['autopilot_ready'] ?? true));
        $this->assertSame(100, (int) ($state['readiness']['readiness_score'] ?? 0));
        $this->assertFalse($service->shouldGateWorkspace($workspaceId, ['id' => $userId], 'dashboard.php'));
    }

    public function testMultiSeatPackageRequiresTeamInviteUntilTeammateIsActive(): void
    {
        $provisioned = $this->provisionWorkspace('multi-seat-team-owner@example.test');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');

        $service = new WorkspaceOnboardingService();
        $impact = $service->getDashboardSetupImpact($workspaceId, $userId);
        $actionKeys = array_column((array) ($impact['actions'] ?? []), 'key');

        $this->assertContains('invite_team', $actionKeys);
        $this->assertNotContains('team', (array) ($impact['completed_actions'] ?? []));
        $inviteAction = current(array_filter((array) ($impact['actions'] ?? []), static fn(array $action): bool => ($action['key'] ?? '') === 'invite_team'));
        $this->assertSame('settings.php?tab=workspace_governance#workspace-team', (string) ($inviteAction['url'] ?? ''));

        $this->addActiveWorkspaceMember($workspaceId, $userId);

        $impactAfterMember = $service->getDashboardSetupImpact($workspaceId, $userId);
        $actionKeysAfterMember = array_column((array) ($impactAfterMember['actions'] ?? []), 'key');

        $this->assertNotContains('invite_team', $actionKeysAfterMember);
        $this->assertContains('team', (array) ($impactAfterMember['completed_actions'] ?? []));
    }

    public function testDefaultWorkspacePackageExemptionMakesTeamSetupApplicable(): void
    {
        Database::execute("UPDATE workspace_memberships SET membership_status = 'left' WHERE workspace_id = 1");
        $userId = (int) Auth::createUser('default-onboarding-team@example.test', 'P@ssword123!', 'owner', 'Default', 'Owner');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $userId, 'owner', true, $userId);
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $service = new WorkspaceOnboardingService();
        $impact = $service->getDashboardSetupImpact(1, $userId);
        $actionKeys = array_column((array) ($impact['actions'] ?? []), 'key');

        $this->assertContains('invite_team', $actionKeys);
        $this->assertNotContains('team', (array) ($impact['completed_actions'] ?? []));

        $this->addActiveWorkspaceMember(1, $userId);

        $impactAfterMember = $service->getDashboardSetupImpact(1, $userId);
        $actionKeysAfterMember = array_column((array) ($impactAfterMember['actions'] ?? []), 'key');

        $this->assertNotContains('invite_team', $actionKeysAfterMember);
        $this->assertContains('team', (array) ($impactAfterMember['completed_actions'] ?? []));
    }

    private function provisionWorkspace(string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Onboarding ' . uniqid('', true),
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    private function setWorkspacePlan(int $workspaceId, string $priceCode): void
    {
        $price = (new WorkspacePlanEntitlementService())->priceByCode($priceCode);
        $this->assertIsArray($price);
        $this->assertGreaterThan(0, (int) ($price['id'] ?? 0));

        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 subscription_status = 'active'
             WHERE workspace_id = ?",
            [(int) $price['id'], $workspaceId]
        );
    }

    private function addActiveWorkspaceMember(int $workspaceId, int $ownerUserId): void
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), ?, ?, 'sales', NOW())",
            [
                'workspace.onboarding.teammate.' . $suffix . '@example.test',
                password_hash('P@ssword123!', PASSWORD_DEFAULT),
            ]
        );
        $teammateId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (?, ?, 'member', 'active', 0, NOW(), ?)",
            [$workspaceId, $teammateId, $ownerUserId]
        );
    }
}
