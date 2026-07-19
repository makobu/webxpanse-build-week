<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Services\AICoachOperatingMaturityService;
use CRM\Services\FounderOperatingLoopService;
use CRM\Services\StartupJourneyService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class AICoachOperatingMaturityServiceTest extends DatabaseTestCase
{
    public function testIncompleteJourneyResolvesPreClarityJourney(): void
    {
        $seed = $this->seedWorkspaceUser('coach-maturity-pre');

        $maturity = (new AICoachOperatingMaturityService())->determine($seed['workspace_id'], $seed['user_id']);

        $this->assertSame(AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY, $maturity['stage'] ?? null);
        $this->assertFalse((bool) ($maturity['journey_complete'] ?? true));
        $this->assertFalse((bool) ($maturity['founder_loop_active'] ?? true));
    }

    public function testCompletedJourneyWithoutFounderLoopResolvesActivationNext(): void
    {
        $seed = $this->seedWorkspaceUser('coach-maturity-journey');
        $this->completeJourney($seed['workspace_id'], $seed['user_id']);

        $maturity = (new AICoachOperatingMaturityService())->determine($seed['workspace_id'], $seed['user_id']);

        $this->assertSame(AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT, $maturity['stage'] ?? null);
        $this->assertTrue((bool) ($maturity['journey_complete'] ?? false));
        $this->assertFalse((bool) ($maturity['founder_loop_active'] ?? true));
    }

    public function testDraftFounderLoopCommitmentResolvesFounderLoopActive(): void
    {
        $seed = $this->seedWorkspaceUser('coach-maturity-loop');
        $this->completeJourney($seed['workspace_id'], $seed['user_id']);
        (new FounderOperatingLoopService())->saveWeeklyReview($seed['workspace_id'], $seed['user_id'], [
            'week_start' => date('Y-m-d'),
            'wins' => 'Set up first-customer motion',
            'blockers' => '',
            'customer_conversations' => 1,
            'next_week_focus' => 'Follow up one named prospect',
            'commitment_title' => ['Follow up named prospect'],
            'commitment_description' => ['Send the first customer follow-up and record the reply.'],
            'commitment_due_date' => [date('Y-m-d', strtotime('+7 days'))],
            'commitment_status' => ['pending'],
        ], false);

        $maturity = (new AICoachOperatingMaturityService())->determine($seed['workspace_id'], $seed['user_id']);

        $this->assertSame(AICoachOperatingMaturityService::FOUNDER_LOOP_ACTIVE, $maturity['stage'] ?? null);
        $this->assertTrue((bool) ($maturity['founder_loop_active'] ?? false));
        $this->assertFalse((bool) ($maturity['operating_system_active'] ?? true));
    }

    public function testFounderLoopPlusOperatingEvidenceResolvesOperatingSystemActive(): void
    {
        $seed = $this->seedWorkspaceUser('coach-maturity-os');
        $this->completeJourney($seed['workspace_id'], $seed['user_id']);
        (new FounderOperatingLoopService())->saveWeeklyReview($seed['workspace_id'], $seed['user_id'], [
            'week_start' => date('Y-m-d'),
            'wins' => 'Started Founder Loop',
            'blockers' => '',
            'customer_conversations' => 2,
            'next_week_focus' => 'Move pipeline',
            'commitment_title' => ['Advance the warm opportunity'],
            'commitment_description' => ['Follow up the prospect and update the deal stage.'],
            'commitment_due_date' => [date('Y-m-d', strtotime('+7 days'))],
            'commitment_status' => ['pending'],
        ], false);
        $contactId = $this->insertContact($seed['workspace_id'], $seed['user_id'], 'Operating', 'Buyer', 'Agency Pipeline');
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, created_by, stage, value, probability, currency, created_at)
             VALUES (?, 'Operating system deal', ?, ?, 'proposal', 2500, 60, 'USD', '2026-05-20 10:00:00')",
            [$seed['workspace_id'], $contactId, $seed['user_id']]
        );

        $maturity = (new AICoachOperatingMaturityService())->determine($seed['workspace_id'], $seed['user_id']);

        $this->assertSame(AICoachOperatingMaturityService::OPERATING_SYSTEM_ACTIVE, $maturity['stage'] ?? null);
        $this->assertTrue((bool) ($maturity['founder_loop_active'] ?? false));
        $this->assertTrue((bool) ($maturity['operating_system_active'] ?? false));
        $this->assertContains('open_deals', (array) ($maturity['later_stage_activity']['signals'] ?? []));
    }

    /**
     * @return array{workspace_id:int,user_id:int}
     */
    private function seedWorkspaceUser(string $prefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $userId = (int) Auth::createUser(
            $prefix . '.' . $suffix . '@example.test',
            'P@ssword123!',
            'admin',
            'Maturity',
            'Owner'
        );
        $workspaceId = (new WorkspaceService())->createWorkspace('Coach Maturity ' . $suffix, $prefix . '-' . $suffix, $userId);
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'owner', true, $userId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        Session::set('user_id', $userId);

        return ['workspace_id' => $workspaceId, 'user_id' => $userId];
    }

    private function completeJourney(int $workspaceId, int $userId): void
    {
        $journey = new StartupJourneyService();
        foreach ($journey->stageDefinitions() as $stageKey => $definition) {
            $responses = [];
            foreach ((array) ($definition['fields'] ?? []) as $fieldKey => $label) {
                $responses[(string) $fieldKey] = match ((string) $fieldKey) {
                    'target_customer', 'customer_segments', 'beachhead_segment' => 'Founder-led service firms',
                    'channels' => 'LinkedIn outbound',
                    'unique_value_proposition', 'message' => 'AI-guided follow-up system for first deals',
                    'mvp_hypothesis' => 'Weekly recommendations will move first deals faster.',
                    'objective' => 'Close three paid pilots from the first cohort.',
                    default => (string) $label . ' answer with clear customer evidence.',
                };
            }
            $journey->saveStage($workspaceId, $userId, (string) $stageKey, $responses, '', true);
        }
    }

    private function insertContact(int $workspaceId, int $userId, string $firstName, string $lastName, string $company): int
    {
        Database::execute(
            "INSERT INTO contacts (uuid, workspace_id, first_name, last_name, email, company, lead_source, created_by, created_at)
             VALUES (UUID(), ?, ?, ?, ?, ?, 'referral', ?, '2026-05-20 09:00:00')",
            [$workspaceId, $firstName, $lastName, strtolower($firstName) . '.' . strtolower($lastName) . '@example.test', $company, $userId]
        );

        return (int) Database::lastInsertId();
    }
}
