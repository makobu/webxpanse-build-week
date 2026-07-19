<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\AITaskCreationEligibilityService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class AITaskCreationEligibilityServiceTest extends DatabaseTestCase
{
    public function testPlanLockedAiApiCandidateIsSkipped(): void
    {
        $seed = $this->seedWorkspace('ai-task-plan-lock');
        $this->activateSession($seed);

        $result = (new AITaskCreationEligibilityService())->filterCandidates(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            [[
                'title' => 'Add AI API from the Marketplace',
                'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_AI_API,
                'source_recommendation_type' => 'marketplace_module',
            ]]
        );

        $this->assertSame([], $result['candidates']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['blocked_by_plan']);
        $this->assertSame(0, $result['gate_redirected']);
    }

    public function testExistingPlanLockedAiApiStarterTaskIsRetired(): void
    {
        $seed = $this->seedWorkspace('ai-task-retire-plan');
        $this->activateSession($seed);

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, description, assigned_to, created_by, status, priority, metadata_json, created_at, updated_at)
             VALUES (?, 'Add AI API from the Marketplace', '', ?, ?, 'pending', 'medium', ?, NOW(), NOW())",
            [
                (int) $seed['workspace_id'],
                (int) $seed['user_id'],
                (int) $seed['user_id'],
                json_encode([
                    'source_surface' => 'ai_coach',
                    'source_recommendation_type' => 'marketplace_module',
                    'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_AI_API,
                ], JSON_UNESCAPED_SLASHES),
            ]
        );
        $taskId = (int) Database::lastInsertId();

        $service = new AITaskCreationEligibilityService();
        $this->assertSame(1, $service->countIneligibleOpenStarterTasks((int) $seed['workspace_id'], (int) $seed['user_id']));

        $result = $service->retireIneligibleOpenStarterTasks((int) $seed['workspace_id'], (int) $seed['user_id']);
        $task = Database::queryOne("SELECT status, metadata_json FROM tasks WHERE id = ?", [$taskId]) ?: [];
        $metadata = json_decode((string) ($task['metadata_json'] ?? '{}'), true) ?: [];

        $this->assertSame(1, $result['retired_count']);
        $this->assertSame(1, $result['blocked_by_plan']);
        $this->assertSame('cancelled', (string) ($task['status'] ?? ''));
        $this->assertSame('plan_locked', (string) ($metadata['auto_retired_reason'] ?? ''));
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_AI_API, (string) ($metadata['blocked_marketplace_skill_key'] ?? ''));
        $this->assertNotEmpty((string) ($metadata['retired_at'] ?? ''));
    }

    public function testClarityJourneyCandidateRemainsEligibleWithoutFinanceSetup(): void
    {
        $seed = $this->seedWorkspace('ai-task-clarity-eligible');
        $this->activateSession($seed);

        $result = (new AITaskCreationEligibilityService())->filterCandidates(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            [[
                'title' => 'Add Clarity Journey from the Marketplace',
                'marketplace_skill_key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                'source_recommendation_type' => 'marketplace_module',
            ]]
        );

        $this->assertCount(1, $result['candidates']);
        $candidate = $result['candidates'][0];
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, (string) ($candidate['marketplace_skill_key'] ?? ''));
        $this->assertSame('', (string) ($candidate['gate_redirected_from_skill_key'] ?? ''));
        $this->assertStringContainsString('Clarity Journey', (string) ($candidate['title'] ?? ''));
        $this->assertSame(0, $result['blocked_by_gate']);
        $this->assertSame(0, $result['gate_redirected']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testMarketingAssistantsCandidateRedirectsToRootGate(): void
    {
        $seed = $this->seedWorkspace('ai-task-marketer-root');
        $this->activateSession($seed);

        $result = (new AITaskCreationEligibilityService())->filterCandidates(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            [[
                'title' => 'Add Marketing Assistants from the Marketplace',
                'marketplace_skill_key' => WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
                'source_recommendation_type' => 'marketplace_module',
            ]]
        );

        $this->assertCount(1, $result['candidates']);
        $candidate = $result['candidates'][0];
        $this->assertNotSame(WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, (string) ($candidate['marketplace_skill_key'] ?? ''));
        $this->assertSame(WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, (string) ($candidate['gate_redirected_from_skill_key'] ?? ''));
        $this->assertSame(1, $result['blocked_by_gate']);
        $this->assertSame(1, $result['gate_redirected']);
    }

    public function testAvailableMarketplaceCandidateAndOperationalCandidateRemainEligible(): void
    {
        $seed = $this->seedWorkspace('ai-task-eligible');
        $this->activateSession($seed);

        $result = (new AITaskCreationEligibilityService())->filterCandidates(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            [
                [
                    'title' => 'Add Finance from the Marketplace',
                    'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                    'source_recommendation_type' => 'marketplace_module',
                ],
                [
                    'title' => 'Follow up with Ada',
                    'source_recommendation_type' => 'crm_operational',
                    'target_id' => 123,
                ],
            ]
        );

        $this->assertCount(2, $result['candidates']);
        $this->assertSame(WorkspaceSkillCatalogService::PLUGIN_FINANCE, (string) ($result['candidates'][0]['marketplace_skill_key'] ?? ''));
        $this->assertSame('Follow up with Ada', (string) ($result['candidates'][1]['title'] ?? ''));
        $this->assertSame(0, $result['skipped']);
    }

    public function testReadyMarketplaceSetupCandidateIsSkipped(): void
    {
        $seed = $this->seedWorkspace('ai-task-ready-skip');
        $this->activateSession($seed);
        $this->completeFinanceSetup($seed);

        $result = (new AITaskCreationEligibilityService())->filterCandidates(
            (int) $seed['workspace_id'],
            (int) $seed['user_id'],
            [[
                'title' => 'Finish Finance setup',
                'marketplace_skill_key' => WorkspaceSkillCatalogService::PLUGIN_FINANCE,
                'source_recommendation_type' => 'marketplace_module',
            ]]
        );

        $this->assertSame([], $result['candidates']);
        $this->assertSame(1, $result['skipped']);
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function seedWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $email = $slugPrefix . '.' . $suffix . '@example.test';
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'AI Task Gate ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Task',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $this->grantRolePermissions('owner', ['workspace.skills.view', 'workspace.skills.manage', 'finance.manage']);
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
            'email' => $email,
        ];
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function activateSession(array $seed): void
    {
        Session::set('user_id', (int) $seed['user_id']);
        Session::set('user_email', (string) $seed['email']);
        Session::set('active_workspace_id', (int) $seed['workspace_id']);
        Session::set('active_workspace_uuid', (string) $seed['workspace_uuid']);
        Session::set('active_workspace_slug', (string) $seed['workspace_slug']);
        Session::set('active_workspace_name', (string) $seed['workspace_name']);
        Session::set('active_workspace_role', 'owner');
        Session::set('active_workspace_membership_id', (int) $seed['membership_id']);
        WorkspaceContext::activateRuntimeWorkspace((int) $seed['workspace_id'], (int) $seed['user_id'], 'owner');
    }

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     */
    private function completeFinanceSetup(array $seed): void
    {
        (new WorkspaceFinanceGateService())->saveInitialSetup((int) $seed['workspace_id'], [
            'currency' => 'USD',
            'opening_date' => date('Y-m-d'),
            'opening_cash' => '1000.00',
            'opening_receivables' => '0.00',
            'opening_payables' => '0.00',
            'opening_loan_balance' => '0.00',
            'opening_assets' => '0.00',
            'opening_equity' => '1000.00',
            'owner_equity' => [
                (int) $seed['user_id'] => [
                    'user_id' => (int) $seed['user_id'],
                    'ownership_percent' => '100',
                    'opening_owner_capital' => '1000.00',
                    'opening_owner_draws' => '0.00',
                ],
            ],
        ], (int) $seed['user_id']);
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function grantRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 SELECT r.id, p.id, 1
                 FROM roles r
                 JOIN permissions p ON p.permission_key = ?
                 WHERE r.slug = ?
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$permissionKey, $roleSlug]
            );
        }
    }
}
