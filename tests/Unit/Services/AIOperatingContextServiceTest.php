<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIOperatingContextService;
use CRM\Services\UnifiedCommercialCatalogService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceRecommendationControlService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class AIOperatingContextServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->tableExists('company_profile')) {
            $this->markTestSkipped('company_profile table is required for AI operating context tests.');
        }
        if (!$this->columnExists('company_profile', 'owner_company_context')) {
            Database::execute('ALTER TABLE company_profile ADD COLUMN owner_company_context TEXT NULL');
        }

        Session::start();
        $_SESSION['user_id'] = null;
        $_SESSION['user_role'] = 'admin';

        Database::execute('DELETE FROM company_profile');
        if ($this->tableExists('user_strategy_profiles')) {
            Database::execute('DELETE FROM user_strategy_profiles');
        }
        if ($this->tableExists('user_strategy_snapshots')) {
            Database::execute('DELETE FROM user_strategy_snapshots');
        }
        if ($this->tableExists('idea_validation_context')) {
            Database::execute('DELETE FROM idea_validation_context');
        }
        if ($this->tableExists('beginner_budget')) {
            Database::execute('DELETE FROM beginner_budget');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tableExists('company_profile')) {
            Database::execute('DELETE FROM company_profile');
        }
        if ($this->tableExists('user_strategy_profiles')) {
            Database::execute('DELETE FROM user_strategy_profiles');
        }
        if ($this->tableExists('user_strategy_snapshots')) {
            Database::execute('DELETE FROM user_strategy_snapshots');
        }
        if ($this->tableExists('idea_validation_context')) {
            Database::execute('DELETE FROM idea_validation_context');
        }
        if ($this->tableExists('beginner_budget')) {
            Database::execute('DELETE FROM beginner_budget');
        }
        Session::destroy();
        parent::tearDown();
    }

    public function testBuildForSurfaceIncludesOptionalOwnerCompanyContext(): void
    {
        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_description, company_industry, owner_company_context, is_active)
             VALUES (1, 'Clarity CRM', 'AI-assisted CRM for founder-led teams.', 'SaaS', 'We sell through hands-on onboarding, promise practical AI, and avoid over-automation claims.', 1)"
        );

        $context = (new AIOperatingContextService())->buildForSurface(1, 'settings');

        $this->assertSame('Clarity CRM', $context['company_context']['name'] ?? null);
        $this->assertStringContainsString(
            'hands-on onboarding',
            (string) ($context['company_context']['owner_additional_context'] ?? '')
        );
    }

    public function testDefaultWorkspaceOperatingContextIncludesPlatformOpsMode(): void
    {
        $context = (new AIOperatingContextService())->buildForSurface(1, 'coach', ['skip_marketplace' => true]);

        $this->assertSame('platform_ops_hq', $context['workspace_operating_mode']['mode'] ?? null);
        $this->assertTrue((bool) ($context['workspace_operating_mode']['is_default_workspace'] ?? false));
        $this->assertStringContainsString('platform success', (string) ($context['platform_ops_context']['mission'] ?? ''));
        $this->assertContains('workspace owners', (array) ($context['platform_ops_context']['primary_subjects'] ?? []));
    }

    public function testDefaultWorkspaceAutomationReadinessKeepsUsingThePackageCatalog(): void
    {
        $packages = (new UnifiedCommercialCatalogService())->catalogForWorkspace(1)['packages'];
        $capabilityState = (new AIOperatingContextService())->getCapabilityState(1);

        $this->assertNotEmpty($packages);
        $this->assertSame(count($packages), (int) ($capabilityState['products_total'] ?? 0));
        $this->assertSame(count($packages), (int) ($capabilityState['priced_products'] ?? 0));
        $this->assertTrue((bool) ($capabilityState['products_priced'] ?? false));
    }

    public function testBuildForSurfaceIncludesResponseStyleContract(): void
    {
        if (!$this->tableExists('workspace_onboarding_state') || !$this->columnExists('workspace_onboarding_state', 'tone_json')) {
            $this->markTestSkipped('workspace_onboarding_state.tone_json is required for response style contract tests.');
        }

        Database::execute(
            "INSERT INTO workspace_onboarding_state (workspace_id, status, current_step, tone_json)
             VALUES (1, 'in_progress', 1, ?)
             ON DUPLICATE KEY UPDATE tone_json = VALUES(tone_json)",
            [json_encode(['draft_reading_level' => 'level_3'])]
        );

        $context = (new AIOperatingContextService())->buildForSurface(1, 'coach', ['skip_marketplace' => true]);
        $contract = (array) ($context['ai_settings']['response_style_contract'] ?? []);

        $this->assertSame('level_3', $contract['level'] ?? null);
        $this->assertSame('Level 3', $contract['label'] ?? null);
        $this->assertStringContainsString('executive operating language', (string) ($contract['prompt_instruction'] ?? ''));
        $this->assertStringContainsString('do not reduce nuance', (string) ($contract['preserve_rigor'] ?? ''));
    }

    public function testTenantWorkspaceOperatingContextDoesNotIncludePlatformOpsMode(): void
    {
        $workspaceId = $this->createTenantWorkspace();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, 1, 'owner');
        Session::set('active_workspace_id', $workspaceId);

        $context = (new AIOperatingContextService())->buildForSurface(1, 'coach', ['skip_marketplace' => true]);

        $this->assertArrayNotHasKey('workspace_operating_mode', $context);
        $this->assertArrayNotHasKey('platform_ops_context', $context);
    }

    public function testBuildForSurfaceIncludesPerUserStrategyContextWithoutChangingSharedCompanyContext(): void
    {
        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_description, company_industry, is_active)
             VALUES (1, 'Clarity CRM', 'Shared company profile', 'SaaS', 1)"
        );
        Database::execute(
            "INSERT INTO user_strategy_profiles (
                user_id, target_market_focus, ideal_customer_profile, offer_angle,
                segment_focus, sales_motion, deal_movement_strategy, outreach_posture, positioning_notes
             ) VALUES (
                7, 'East Africa SMBs', 'Founder-led B2B service firms', 'Done-with-you AI operating system',
                'Agencies and consultancies', 'Warm outbound plus referrals', 'Move deals with live audits and pilot offers', 'Consultative and direct', 'Lead with speed-to-value proof'
             )"
        );
        Database::execute(
            "INSERT INTO idea_validation_context (
                user_id, value_proposition, target_market, pain_points, assumptions_to_test, competitors, differentiator
             ) VALUES (
                7, 'AI-backed CRM operating system', 'Founder-led agencies', 'Pipeline inconsistency', 'Founders want guided automation', 'Spreadsheets', 'Hands-on GTM guidance'
             )"
        );
        Database::execute(
            "INSERT INTO beginner_budget (
                user_id, monthly_marketing_budget, monthly_fixed_costs, target_deal_value, target_cac, currency_code
             ) VALUES (7, 500.00, 300.00, 1200.00, 100.00, 'USD')"
        );
        (new WorkspaceSkillCatalogService())->syncDefinitions();
        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(1, 7, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, 'setup_opened', [
            'label' => 'Lean Canvas',
        ]);
        (new WorkspaceMarketplaceActivationBundleEventService())->recordEvent(1, 7, 'strategy_foundation', 'cta_clicked');
        (new WorkspaceMarketplaceRecommendationControlService())->setControl(1, 7, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, 'pinned', 'all', true, 'test');

        $context = (new AIOperatingContextService())->buildForSurface(7, 'coach');

        $this->assertSame('Clarity CRM', $context['company_context']['name'] ?? null);
        $this->assertArrayHasKey('workspace_marketplace', $context);
        $this->assertArrayHasKey('recommendations', $context['workspace_marketplace']);
        $this->assertArrayHasKey('activation_bundles', $context['workspace_marketplace']);
        $this->assertSame('East Africa SMBs', $context['user_strategy_context']['target_market_focus'] ?? null);
        $this->assertSame('Founder-led agencies', $context['user_strategy_context']['idea_validation']['target_market'] ?? null);
        $this->assertSame(1, (int) ($context['user_strategy_context']['active_strategy_snapshot']['version'] ?? 0));
        $this->assertSame(1200.00, (float) ($context['user_strategy_context']['budget_context']['target_deal_value'] ?? 0.0));
        $this->assertSame('Agencies and consultancies', $context['user_strategy_context']['summary']['primary_segment'] ?? null);
        $marketplaceRecommendations = (array) ($context['workspace_marketplace']['recommendations'] ?? []);
        $adaptiveItems = array_values(array_filter($marketplaceRecommendations, static fn(array $item): bool => array_key_exists('adaptive_score_delta', $item)));
        if ($adaptiveItems !== []) {
            $this->assertArrayHasKey('base_score', $adaptiveItems[0]);
            $this->assertArrayHasKey('adaptive_reason_codes', $adaptiveItems[0]);
        }
        $this->assertArrayHasKey('setup_journeys', $context['workspace_marketplace']);
        $controlledRecommendations = array_values(array_filter($marketplaceRecommendations, static fn(array $item): bool => (string) ($item['admin_control_type'] ?? '') !== ''));
        if ($marketplaceRecommendations !== []) {
            $this->assertNotEmpty($controlledRecommendations);
        }
        if (!empty($marketplaceRecommendations[0]['setup_journey'])) {
            $this->assertArrayHasKey('next_step', $marketplaceRecommendations[0]['setup_journey']);
        }
        $activationBundles = (array) ($context['workspace_marketplace']['activation_bundles'] ?? []);
        if ($activationBundles !== []) {
            $this->assertArrayHasKey('next_action', $activationBundles[0]);
            $this->assertArrayHasKey('progress', $activationBundles[0]);
            $this->assertArrayHasKey('adaptive_guidance', $activationBundles[0]);
            $this->assertArrayHasKey('insight_label', $activationBundles[0]);
            $this->assertArrayNotHasKey('adaptive_score_delta', $activationBundles[0]);
            $this->assertArrayNotHasKey('base_score', $activationBundles[0]);
            $this->assertArrayNotHasKey('adaptive_reason_codes', $activationBundles[0]);
            $this->assertArrayNotHasKey('setup_journeys', $activationBundles[0]);
        }
        $this->assertStringNotContainsString('secret', strtolower(json_encode($activationBundles) ?: ''));
        $this->assertStringNotContainsString('secret', strtolower(json_encode($marketplaceRecommendations) ?: ''));
    }

    private function tableExists(string $tableName): bool
    {
        $row = Database::queryOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $row = Database::queryOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$tableName, $columnName]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function createTenantWorkspace(): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Tenant AI Workspace', 'tenant-ai-workspace', 'active', 'active', NOW(), NOW())",
            ['00000000-0000-4000-8000-0000000000a2']
        );
        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, 1, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = VALUES(membership_status)",
            [$workspaceId]
        );

        return $workspaceId;
    }
}
