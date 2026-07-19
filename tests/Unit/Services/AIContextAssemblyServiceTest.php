<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Authorization;
use CRM\Session;
use CRM\Services\AIContextAssemblyService;
use CRM\Services\AIService;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Tests\DatabaseTestCase;

class AIContextAssemblyServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'sales', NOW())",
            ['context-role@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE membership_status = VALUES(membership_status), role_slug = VALUES(role_slug)",
            [$this->userId]
        );
    }

    public function testBuildsClarityBundleAndPrunesToBudget(): void
    {
        $service = new AIContextAssemblyService();

        $bundle = $service->buildContextBundle('customer_thread', 'assistant_customer_reply_goal', [
            'thread_summary' => ['summary' => 'Customer asked for pricing details'],
            'latest_inbound_message' => 'Please resend the quote.',
            'contact' => ['first_name' => 'Test', 'email' => 'test@example.com'],
            'deal' => ['title' => 'Enterprise renewal', 'stage' => 'proposal'],
            'invoice' => ['invoice_number' => 'Q-123'],
        ]);

        $this->assertSame('customer_thread', $bundle['surface']);
        $this->assertSame('assistant_customer_reply_goal', $bundle['prompt_key']);
        $this->assertNotEmpty($bundle['blocks']);
        $this->assertArrayHasKey('bundle_quality', $bundle);
        $this->assertLessThanOrEqual(12, count($bundle['blocks']));
        $this->assertContains('required', array_column($bundle['blocks'], 'block_priority'));
    }

    public function testBuildsRoleProfileBlockForCoachSurface(): void
    {
        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, 'setup_opened', [
            'label' => 'Lean Canvas',
        ]);
        (new WorkspaceMarketplaceActivationBundleEventService())->recordEvent(1, $this->userId, 'strategy_foundation', 'cta_clicked');
        $service = new AIContextAssemblyService();

        $bundle = $service->buildContextBundle('coach', 'coach_recommendations', [
            'user_id' => $this->userId,
            'metrics' => ['summary' => ['leads_today' => 1]],
            'feature_usage' => ['tasks_used' => true],
            'active_targets' => [],
            'max_blocks' => 20,
            'max_chars' => 1000000,
        ]);

        $roleBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'role_profile'));
        $workContextBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'user_work_context'));
        $marketplaceBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'workspace_marketplace'));
        $this->assertCount(1, $roleBlocks);
        $this->assertCount(1, $workContextBlocks);
        $this->assertCount(1, $marketplaceBlocks);
        $this->assertSame('sales_rep', $roleBlocks[0]['content']['role_profile'] ?? null);
        $this->assertSame('preferred', $roleBlocks[0]['block_priority'] ?? null);
        $this->assertSame($this->userId, $workContextBlocks[0]['content']['identity']['user_id'] ?? null);
        $this->assertSame('preferred', $workContextBlocks[0]['block_priority'] ?? null);
        $this->assertSame('preferred', $marketplaceBlocks[0]['block_priority'] ?? null);
        $this->assertArrayHasKey('recommendations', $marketplaceBlocks[0]['content']);
        $recommendations = (array) ($marketplaceBlocks[0]['content']['recommendations'] ?? []);
        $adaptiveItems = array_values(array_filter($recommendations, static fn(array $item): bool => array_key_exists('adaptive_score_delta', $item)));
        if ($adaptiveItems !== []) {
            $this->assertArrayHasKey('base_score', $adaptiveItems[0]);
        }
        $activationBundles = (array) ($marketplaceBlocks[0]['content']['activation_bundles'] ?? []);
        if ($activationBundles !== []) {
            $this->assertArrayHasKey('next_action', $activationBundles[0]);
            $this->assertArrayHasKey('progress', $activationBundles[0]);
            $this->assertArrayNotHasKey('adaptive_score_delta', $activationBundles[0]);
            $this->assertArrayNotHasKey('setup_journeys', $activationBundles[0]);
        }
    }

    public function testDefaultWorkspaceBundlesIncludePlatformOpsContextForOpsSurfaces(): void
    {
        $service = new AIContextAssemblyService();
        $surfaces = [
            ['coach', 'coach_recommendations', ['user_id' => $this->userId, 'max_blocks' => 20, 'max_chars' => 100000]],
            ['clarity_chat', 'clarity_question_answer', ['user_id' => $this->userId, 'current_page' => 'dashboard', 'max_blocks' => 20, 'max_chars' => 100000]],
            ['assistant', 'assistant_question', ['user_id' => $this->userId, 'workspace_id' => 1, 'thread_context' => ['question' => 'What needs attention?'], 'max_blocks' => 20, 'max_chars' => 100000]],
            ['customer_thread', 'assistant_customer_reply_goal', ['workspace_id' => 1, 'thread_summary' => ['summary' => 'Owner needs setup help'], 'max_blocks' => 20, 'max_chars' => 100000]],
            ['commercial_assistant', 'assistant_commercial_reply', ['workspace_id' => 1, 'invoice' => ['status' => 'past_due'], 'max_blocks' => 20, 'max_chars' => 100000]],
        ];

        foreach ($surfaces as [$surface, $promptKey, $inputs]) {
            $bundle = $service->buildContextBundle($surface, $promptKey, $inputs);
            $styleBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'response_style_contract'));
            $this->assertCount(1, $styleBlocks, $surface . ' should include workspace language level.');
            $this->assertSame('required', $styleBlocks[0]['block_priority'] ?? null);
            $this->assertSame('Level 2', $styleBlocks[0]['content']['label'] ?? null);
            $this->assertStringContainsString('LANGUAGE LEVEL: Level 2', (string) ($styleBlocks[0]['content']['prompt_instruction'] ?? ''));
            $this->assertSame('Level 2', $bundle['response_style_contract']['label'] ?? null);
            $this->assertSame('Level 2', $service->summarizeBundle($bundle)['response_style']['label'] ?? null);

            $blocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'platform_ops_context'));
            $this->assertCount(1, $blocks, $surface . ' should include Platform Ops context.');
            $this->assertSame('required', $blocks[0]['block_priority'] ?? null);
            $this->assertStringContainsString('platform success', (string) ($blocks[0]['content']['mission'] ?? ''));
        }
    }

    public function testGenericPluginBundleIncludesResponseStyleContract(): void
    {
        $bundle = (new AIContextAssemblyService())->buildContextBundle('plugin_surface', 'plugin_prompt', [
            'user_id' => $this->userId,
            'message' => 'Draft a recommendation.',
            'max_blocks' => 5,
            'max_chars' => 5000,
        ]);

        $styleBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'response_style_contract'));
        $this->assertCount(1, $styleBlocks);
        $this->assertSame('required', $styleBlocks[0]['block_priority'] ?? null);
        $this->assertSame('level_2', $styleBlocks[0]['content']['level'] ?? null);
    }

    public function testClarityBundleHonorsProvidedOperatingContextResponseStyle(): void
    {
        $bundle = (new AIContextAssemblyService())->buildContextBundle('clarity_chat', 'clarity_question_answer', [
            'user_id' => $this->userId,
            'current_page' => 'dashboard',
            'operating_context' => [
                'identity' => ['workspace_id' => 1],
                'ai_settings' => [
                    'response_style_contract' => (new WorkspaceLanguageLevelService())->responseStyleContract('level_1'),
                ],
            ],
            'max_blocks' => 20,
            'max_chars' => 100000,
        ]);

        $styleBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'response_style_contract'));
        $this->assertCount(1, $styleBlocks);
        $this->assertSame('level_1', $styleBlocks[0]['content']['level'] ?? null);
        $this->assertSame('Level 1', $bundle['response_style_contract']['label'] ?? null);
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 1', (string) ($styleBlocks[0]['content']['prompt_instruction'] ?? ''));
    }

    public function testClarityBundleIncludesPageContextForNormalPages(): void
    {
        $service = new AIContextAssemblyService();

        foreach (['contacts.php', 'deals.php', 'tasks.php', 'settings.php', 'workspace_skills.php'] as $page) {
            $bundle = $service->buildContextBundle('clarity_chat', 'clarity_question_answer', [
                'user_id' => $this->userId,
                'current_page' => $page,
                'question' => 'What should I pay attention to on this page?',
                'max_blocks' => 24,
                'max_chars' => 200000,
            ]);

            $blocks = array_values(array_filter(
                $bundle['blocks'],
                static fn(array $block): bool => ($block['type'] ?? '') === 'clarity_page_context'
            ));

            $this->assertCount(1, $blocks, $page . ' should include Clarity page context.');
            $this->assertSame('clarity_page_context', $blocks[0]['content']['source'] ?? null);
            $this->assertSame($page, $blocks[0]['content']['page']['filename'] ?? null);
            $this->assertNotEmpty($blocks[0]['content']['page']['purpose'] ?? '');
            $this->assertNotEmpty($blocks[0]['content']['opening_insight']['kind'] ?? '');
            $this->assertContains($blocks[0]['block_priority'] ?? '', ['required', 'preferred']);
        }
    }

    public function testClarityResolvedPromptIncludesPageContextBlock(): void
    {
        $bundle = (new AIContextAssemblyService())->buildContextBundle('clarity_chat', 'clarity_question_answer', [
            'user_id' => $this->userId,
            'current_page' => 'contacts.php',
            'question' => 'What is this contacts page telling me?',
            'max_blocks' => 24,
            'max_chars' => 200000,
        ]);

        $resolvedPrompt = (new AIService())->buildPromptFromRegistry('clarity_chat', 'clarity_question_answer', $bundle, [
            'question' => 'What is this contacts page telling me?',
            'legacy_prompt' => 'Legacy fallback prompt.',
        ]);

        $this->assertStringContainsString('clarity_page_context', (string) ($resolvedPrompt['rendered_prompt'] ?? ''));
        $this->assertStringContainsString('contact usefulness and lead quality', (string) ($resolvedPrompt['rendered_prompt'] ?? ''));
    }

    public function testTenantWorkspaceBundleDoesNotIncludePlatformOpsContext(): void
    {
        $workspaceId = $this->createTenantWorkspace();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $this->userId, 'owner');
        Session::set('active_workspace_id', $workspaceId);

        $bundle = (new AIContextAssemblyService())->buildContextBundle('assistant', 'assistant_question', [
            'user_id' => $this->userId,
            'workspace_id' => $workspaceId,
            'thread_context' => ['question' => 'What should I do next?'],
            'max_blocks' => 20,
            'max_chars' => 100000,
        ]);

        $blocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'platform_ops_context'));
        $this->assertCount(0, $blocks);
    }

    public function testSuperadminContextIncludesMarketplacePerformance(): void
    {
        Authorization::assignUserRoleBySlug($this->userId, 'superadmin', $this->userId);
        Session::set('user_id', $this->userId);
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'marketplace', 'impression');
        (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'marketplace', 'cta_clicked');
        (new WorkspaceMarketplaceSetupJourneyEventService())->recordEvent(1, $this->userId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, 'setup_opened', [
            'label' => 'Email Assistant',
        ]);
        (new WorkspaceSkillInstallService())->install(1, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT, $this->userId);

        $bundle = (new AIContextAssemblyService())->buildContextBundle('coach', 'coach_recommendations', [
            'user_id' => $this->userId,
            'max_blocks' => 20,
            'max_chars' => 1000000,
        ]);

        $marketplaceBlocks = array_values(array_filter($bundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'workspace_marketplace'));
        $this->assertCount(1, $marketplaceBlocks);
        $performance = (array) ($marketplaceBlocks[0]['content']['performance'] ?? []);
        $this->assertArrayHasKey('metrics', $performance);
        $this->assertGreaterThanOrEqual(1, (int) ($performance['metrics']['impressions'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($performance['metrics']['clicks'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($performance['metrics']['installs'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($performance['metrics']['active_users'] ?? 0));
        $this->assertNotEmpty((array) ($performance['top_modules'] ?? []));

        $assistantBundle = (new AIContextAssemblyService())->buildContextBundle('assistant', 'superadmin_marketplace_assistant', [
            'user_id' => $this->userId,
            'workspace_id' => 1,
            'thread_context' => ['question' => 'How is the marketplace performing?'],
            'max_blocks' => 20,
            'max_chars' => 100000,
        ]);
        $assistantPerformanceBlocks = array_values(array_filter($assistantBundle['blocks'], static fn(array $block): bool => ($block['type'] ?? '') === 'workspace_marketplace_performance'));
        $this->assertCount(1, $assistantPerformanceBlocks);
        $this->assertSame('preferred', $assistantPerformanceBlocks[0]['block_priority'] ?? null);
        $assistantMetrics = (array) ($assistantPerformanceBlocks[0]['content']['metrics'] ?? []);
        $this->assertGreaterThanOrEqual(1, (int) ($assistantMetrics['impressions'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($assistantMetrics['clicks'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($assistantMetrics['installs'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($assistantMetrics['active_users'] ?? 0));
    }

    public function testScoreContextBlockRewardsFreshRelevantBlocks(): void
    {
        $service = new AIContextAssemblyService();

        $score = $service->scoreContextBlock([
            'type' => 'thread_context',
            'label' => 'Thread context',
            'freshness_seconds' => 20,
            'content' => ['message' => 'Please send the invoice'],
            'block_priority' => 'required',
            'priority_weight' => 1.0,
        ], [
            'surface' => 'customer_thread',
            'question' => 'send the invoice',
        ]);

        $this->assertGreaterThan(0.7, $score);
    }

    private function createTenantWorkspace(): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Tenant Bundle Workspace', 'tenant-bundle-workspace', 'active', 'active', NOW(), NOW())",
            ['00000000-0000-4000-8000-0000000000b2']
        );
        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'active', 1, NOW())",
            [$workspaceId, $this->userId]
        );

        return $workspaceId;
    }

    public function testPruneContextKeepsRequiredBlocksBeforeDiscardableOnes(): void
    {
        $service = new AIContextAssemblyService();

        $blocks = [
            [
                'type' => 'required_thread',
                'label' => 'Thread summary',
                'block_priority' => 'required',
                'relevance_score' => 0.62,
                'content_chars' => 380,
                'content' => ['summary' => str_repeat('thread ', 20)],
            ],
            [
                'type' => 'discardable_noise',
                'label' => 'Feature access',
                'block_priority' => 'discardable',
                'relevance_score' => 0.65,
                'content_chars' => 380,
                'content' => ['access' => str_repeat('noise ', 20)],
            ],
            [
                'type' => 'preferred_contact',
                'label' => 'Contact',
                'block_priority' => 'preferred',
                'relevance_score' => 0.70,
                'content_chars' => 380,
                'content' => ['email' => 'test@example.com'],
            ],
        ];

        $kept = $service->pruneContext($blocks, 2, 800);

        $this->assertCount(2, $kept);
        $this->assertContains('required', array_column($kept, 'block_priority'));
        $this->assertNotContains('discardable', array_column($kept, 'block_priority'));
    }

    public function testExplanationEvidenceSurvivesAConstrainedClarityBundle(): void
    {
        $bundle = (new AIContextAssemblyService())->buildContextBundle('clarity_chat', 'clarity_question_answer', [
            'user_id' => $this->userId,
            'current_page' => 'hr_analytics.php',
            'question' => 'Why is my risk score high?',
            'operating_context' => ['identity' => ['workspace_id' => 1]],
            'explanation_context' => [
                'source' => 'server_owned_clarity_explanation',
                'evidence' => ['score_explanation' => ['score' => 42, 'risk_reason' => 'Low task completion.']],
            ],
            'organization_intelligence_context' => [
                'large_unrelated_payload' => str_repeat('noise', 3000),
                'score_explanation' => ['score' => 42, 'risk_reason' => 'Low task completion.'],
                'data_quality' => ['confidence' => 'medium'],
            ],
            'max_blocks' => 8,
            'max_chars' => 5000,
        ]);

        $byType = [];
        foreach ($bundle['blocks'] as $block) {
            $byType[(string) ($block['type'] ?? '')] = $block;
        }

        $this->assertArrayHasKey('clarity_explanation_context', $byType);
        $this->assertSame(42, $byType['clarity_explanation_context']['content']['evidence']['score_explanation']['score']);
        $this->assertArrayHasKey('organization_intelligence_context', $byType);
        $this->assertArrayNotHasKey('large_unrelated_payload', $byType['organization_intelligence_context']['content']);
        $this->assertSame('required', $byType['clarity_explanation_context']['block_priority']);
    }

    public function testPruneContextCompactsOversizedRequiredBlocksWithinHardBudget(): void
    {
        $service = new AIContextAssemblyService();
        $blocks = [
            [
                'type' => 'response_style_contract',
                'block_priority' => 'required',
                'relevance_score' => 1.0,
                'content_chars' => 5000,
                'content' => ['prompt_instruction' => str_repeat('plain language ', 400)],
            ],
            [
                'type' => 'platform_ops_context',
                'block_priority' => 'required',
                'relevance_score' => 1.0,
                'content_chars' => 5000,
                'content' => ['mission' => 'Protect platform success. ' . str_repeat('operator context ', 400)],
            ],
        ];

        $kept = $service->pruneContext($blocks, 4, 900);
        $totalChars = array_sum(array_map(
            static fn(array $block): int => strlen((string) json_encode($block['content'] ?? [])),
            $kept
        ));

        $this->assertCount(2, $kept);
        $this->assertLessThanOrEqual(900, $totalChars);
        $this->assertSame(['required'], array_values(array_unique(array_column($kept, 'block_priority'))));
        $platform = array_values(array_filter($kept, static fn(array $block): bool => ($block['type'] ?? '') === 'platform_ops_context'))[0];
        $this->assertStringContainsString('Protect platform success', (string) ($platform['content']['mission'] ?? ''));
    }
}
