<?php

declare(strict_types=1);

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\FounderCommandCenterService;
use CRM\Services\PresentationSeedPackService;
use CRM\Services\PresentationWorkspaceCapabilityService;
use CRM\Services\PresentationWorkspaceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class PresentationFounderWorkspaceServiceTest extends DatabaseTestCase
{
    public function testCapabilitiesCannotBeInstalledInANormalWorkspace(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('active presentation workspace');

        (new PresentationWorkspaceCapabilityService())->installForWorkspace(1, 1, 901);
    }

    public function testFounderPackCreatesAnExplainablePresentationWorkspace(): void
    {
        $workspaceId = (new WorkspaceProvisioningService())->provisionWorkspace([
            'workspace_name' => 'WebXpanse Build Week Test',
            'owner_user_id' => 1,
        ]);
        (new PresentationWorkspaceService())->applyPresentationFlags(
            $workspaceId,
            'solo_founders',
            'solo_founder',
            date('Y-m-d H:i:s', strtotime('+24 hours')),
            'WebXpanse Build Week',
            1
        );

        $capabilities = (new PresentationWorkspaceCapabilityService())->installForWorkspace($workspaceId, 1, 902);

        $this->assertSame(2, $capabilities['installed']);
        $this->assertSame([
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            WorkspaceSkillCatalogService::SKILL_AI_COACH,
        ], $capabilities['skills']);
        $this->assertTrue((new AICoachWorkspaceSetupService())->isWorkspaceEnabled($workspaceId));

        $aiCoachInstall = Database::queryOne(
            "SELECT status, config_json FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ?",
            [$workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH]
        ) ?: [];
        $aiCoachConfig = json_decode((string) ($aiCoachInstall['config_json'] ?? ''), true);
        $this->assertSame('installed', $aiCoachInstall['status'] ?? null);
        $this->assertTrue((bool) ($aiCoachConfig['demo_only'] ?? false));
        $this->assertTrue((bool) ($aiCoachConfig['simulation_only'] ?? false));
        $this->assertTrue((bool) ($aiCoachConfig['ai_coach']['enabled'] ?? false));

        $seed = (new PresentationSeedPackService())->seed($workspaceId, 1, 'solo_founder', null, false);

        $this->assertTrue((bool) ($seed['success'] ?? false));
        $this->assertGreaterThan(0, (int) ($seed['entity_count'] ?? 0));
        $this->assertSame(1, (int) ($seed['created']['company_profiles'] ?? 0));
        $this->assertSame(1, (int) ($seed['created']['strategy_profiles'] ?? 0));
        $this->assertSame(1, (int) ($seed['created']['startup_journeys'] ?? 0));
        $this->assertSame(8, (int) ($seed['created']['startup_journey_stages'] ?? 0));
        $this->assertSame(1, (int) ($seed['created']['founder_sprints'] ?? 0));
        $this->assertSame(1, (int) ($seed['created']['founder_reviews'] ?? 0));
        $this->assertSame(1, (int) ($seed['created']['founder_commitments'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage = 'closed_won'",
            [$workspaceId]
        )['c'] ?? 0));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ? AND stage = 'won'",
            [$workspaceId]
        )['c'] ?? 0));
        $this->assertGreaterThan(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ? AND LOWER(company) LIKE '%founder%'",
            [$workspaceId]
        )['c'] ?? 0));

        $journey = Database::queryOne(
            'SELECT id, status, current_stage_key FROM startup_journeys WHERE workspace_id = ? AND user_id = ?',
            [$workspaceId, 1]
        ) ?: [];
        $this->assertSame('completed', $journey['status'] ?? null);
        $this->assertSame('okrs', $journey['current_stage_key'] ?? null);
        $this->assertSame(8, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM startup_journey_stage_responses WHERE journey_id = ? AND status = \'completed\'',
            [(int) ($journey['id'] ?? 0)]
        )['c'] ?? 0));

        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
        $commandCenter = (new FounderCommandCenterService())->build($workspaceId, 1);

        $this->assertSame('ready', (string) ($commandCenter['context']['health']['status'] ?? ''));
        $this->assertSame(100, (int) ($commandCenter['context']['health']['strength'] ?? 0));
        $this->assertSame('1 item needs your judgment or founder work.', (string) ($commandCenter['summary'] ?? ''));
        $this->assertNotSame('business_context_incomplete', (string) ($commandCenter['primary_constraint']['constraint_key'] ?? ''));
        $this->assertSame('Send first 20 warm outreach messages', (string) ($commandCenter['current_commitment']['title'] ?? ''));
        $this->assertSame('active', (string) ($commandCenter['growth_experiment']['status'] ?? ''));
        $this->assertNotSame('', (string) ($commandCenter['growth_experiment']['title'] ?? ''));
        foreach ((array) ($commandCenter['needs_you'] ?? []) as $item) {
            $this->assertFalse(
                str_starts_with((string) ($item['constraint_key'] ?? ''), 'assumption_conflict:'),
                json_encode($commandCenter['needs_you'] ?? [], JSON_UNESCAPED_SLASHES)
            );
        }
    }
}
