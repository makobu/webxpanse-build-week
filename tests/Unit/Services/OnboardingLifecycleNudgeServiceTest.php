<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\OnboardingLifecycleNudgeService;
use CRM\Services\OnboardingNudgeDraftService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class OnboardingLifecycleNudgeServiceTest extends DatabaseTestCase
{
    public function testStuckWorkspaceDetectionAndDraftCreation(): void
    {
        $seed = $this->provisionWorkspace('nudge-owner@example.test');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE workspace_id = ?",
            [$workspaceId]
        );

        $service = new OnboardingLifecycleNudgeService(
            null,
            null,
            new OnboardingNudgeDraftService($this->fakeAi())
        );
        $summary = $service->summarizeWorkspace($workspaceId, $userId);

        $this->assertTrue((bool) ($summary['is_stuck'] ?? false));
        $this->assertSame('in_progress', (string) ($summary['status'] ?? ''));
        $this->assertNotEmpty($summary['next_action']['title'] ?? '');

        $draft = $service->createDraft($workspaceId, $userId, 'email', 'Testing lifecycle draft');
        $this->assertSame('draft', (string) ($draft['status'] ?? ''));
        $this->assertSame('email', (string) ($draft['channel'] ?? ''));
        $this->assertStringContainsString('Finish setup', (string) ($draft['subject'] ?? ''));

        $audit = Database::queryOne(
            "SELECT action_type FROM operator_audit_log WHERE target_workspace_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId]
        );
        $this->assertSame('onboarding_nudge_drafted', (string) ($audit['action_type'] ?? ''));
    }

    public function testWhatsAppSendKeepsDraftWhenOwnerNumberIsMissing(): void
    {
        $seed = $this->provisionWorkspace('whatsapp-draft-owner@example.test');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new OnboardingLifecycleNudgeService(
            null,
            null,
            new OnboardingNudgeDraftService($this->fakeAi())
        );
        $draft = $service->createDraft($workspaceId, $userId, 'whatsapp', 'Testing WhatsApp draft');
        $sent = $service->sendNudge((int) $draft['id'], $userId, [], 'Testing WhatsApp send fallback');

        $this->assertSame('skipped', (string) ($sent['status'] ?? ''));
        $this->assertStringContainsString('manual', strtolower((string) ($sent['delivery_error'] ?? '')));
    }

    public function testInvalidChannelNormalizesToEmailForDrafts(): void
    {
        $seed = $this->provisionWorkspace('normalize-channel-owner@example.test');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');

        $service = new OnboardingLifecycleNudgeService(
            null,
            null,
            new OnboardingNudgeDraftService($this->fakeAi())
        );
        $draft = $service->createDraft($workspaceId, $userId, 'unsupported_channel', 'Testing channel normalization');

        $this->assertSame('email', (string) ($draft['channel'] ?? ''));
    }

    public function testQueuedDraftCooldownPreventsDuplicateNudges(): void
    {
        $seed = $this->provisionWorkspace('queued-cooldown-owner@example.test');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE workspace_id = ?",
            [$workspaceId]
        );

        $service = new OnboardingLifecycleNudgeService(
            null,
            null,
            new OnboardingNudgeDraftService($this->fakeAi())
        );
        $queued = $service->createQueuedDraft($workspaceId, $userId, 'in_app', null, 'Testing queue');
        $duplicate = $service->createQueuedDraft($workspaceId, $userId, 'in_app', null, 'Testing cooldown');

        $this->assertSame('queued', (string) ($queued['status'] ?? ''));
        $this->assertSame('skipped', (string) ($duplicate['status'] ?? ''));
        $this->assertSame('cooldown', (string) ($duplicate['reason'] ?? ''));
    }

    public function testProcessDueQueuedInAppNudgeCreatesNotification(): void
    {
        $seed = $this->provisionWorkspace('queued-send-owner@example.test');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        Database::execute(
            "UPDATE workspace_onboarding_state SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE workspace_id = ?",
            [$workspaceId]
        );

        $service = new OnboardingLifecycleNudgeService(
            null,
            null,
            new OnboardingNudgeDraftService($this->fakeAi())
        );
        $queued = $service->createQueuedDraft($workspaceId, $userId, 'in_app', date('Y-m-d H:i:s', strtotime('-5 minutes')), 'Testing due send', false);
        $summary = $service->processDueQueuedNudges(10, $userId, true);

        $this->assertSame(1, (int) ($summary['processed'] ?? 0));
        $this->assertSame(1, (int) ($summary['sent'] ?? 0));

        $stored = Database::queryOne("SELECT status, sent_at FROM workspace_onboarding_nudges WHERE id = ?", [(int) $queued['id']]);
        $this->assertSame('sent', (string) ($stored['status'] ?? ''));
        $this->assertNotEmpty($stored['sent_at'] ?? null);

        $notification = Database::queryOne(
            "SELECT id FROM notifications WHERE workspace_id = ? AND user_id = ? AND type = 'onboarding_nudge' ORDER BY id DESC LIMIT 1",
            [$workspaceId, $userId]
        );
        $this->assertNotEmpty($notification);
    }

    public function testResetAndMarkCompleteUpdateOnboardingStateWithAudit(): void
    {
        $seed = $this->provisionWorkspace('recover-owner@example.test');
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        $onboarding = new WorkspaceOnboardingService();
        $onboarding->completeQuickStart($workspaceId, $userId, [
            'company_name' => 'Recovery Co',
            'company_description' => 'Recovery setup test.',
            'communication_channel' => 'email',
            'product_name' => 'Recovery service',
            'product_description' => 'Recovery offer.',
            'ideal_customer_profile' => 'Teams recovering onboarding.',
            'relationship_style' => 'trusted_advisor',
        ]);

        $service = new OnboardingLifecycleNudgeService();
        $reset = $service->resetOnboarding($workspaceId, $userId, 2, 'Testing reset');
        $this->assertSame('in_progress', (string) ($reset['status'] ?? ''));
        $this->assertSame(2, (int) ($reset['current_step'] ?? 0));

        $complete = $service->markOnboardingComplete($workspaceId, $userId, 'Testing mark complete');
        $this->assertSame('completed', (string) ($complete['status'] ?? ''));

        $actions = Database::query(
            "SELECT action_type FROM operator_audit_log WHERE target_workspace_id = ? ORDER BY id DESC LIMIT 2",
            [$workspaceId]
        );
        $this->assertSame(['onboarding_state_marked_complete', 'onboarding_state_reset'], array_column($actions, 'action_type'));
    }

    private function provisionWorkspace(string $email): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Nudge ' . uniqid('', true),
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    private function fakeAi(): AIService
    {
        return new class extends AIService {
            public function buildPromptFromRegistry(string $surface, string $promptKey, array $bundle, array $inputs = [], ?int $version = null): array
            {
                return ['rendered_prompt' => (string) ($inputs['legacy_prompt'] ?? '')];
            }

            public function processWithPrompt(string $task, array $resolvedPrompt, array $context = []): string
            {
                return json_encode([
                    'subject' => 'Finish setup for your workspace',
                    'body' => 'Finish your channel setup to start capturing conversations. Open setup and complete the next step.',
                ]);
            }
        };
    }
}
