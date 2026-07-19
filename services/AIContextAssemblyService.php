<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WebsiteAssistantContext;
use CRM\Session;

class AIContextAssemblyService
{
    private const PRIORITY_WEIGHTS = [
        'required' => 1.00,
        'preferred' => 0.82,
        'optional' => 0.64,
        'discardable' => 0.42,
    ];

    private AIOperatingContextService $operatingContext;
    private WebsiteAssistantContext $websiteContext;
    private AIRetrievalQualityService $retrievalQuality;
    private AIRoleProfileService $roleProfiles;
    private AIUserWorkContextService $userWorkContext;
    private ClarityPageContextService $clarityPageContext;
    private WorkspaceLanguageLevelService $languageLevels;
    private SystemContextRegistryService $contextRegistry;

    public function __construct()
    {
        $this->operatingContext = new AIOperatingContextService();
        $this->websiteContext = new WebsiteAssistantContext();
        $this->retrievalQuality = new AIRetrievalQualityService();
        $this->roleProfiles = new AIRoleProfileService();
        $this->userWorkContext = new AIUserWorkContextService();
        $this->clarityPageContext = new ClarityPageContextService();
        $this->languageLevels = new WorkspaceLanguageLevelService();
        $this->contextRegistry = new SystemContextRegistryService();
    }

    public function buildContextBundle(string $surface, string $promptKey, array $inputs = []): array
    {
        $inputs['surface'] = $surface;
        $maxBlocks = max(1, (int) ($inputs['max_blocks'] ?? 12));
        $maxChars = max(500, (int) ($inputs['max_chars'] ?? 12000));
        $minScore = max(0.0, min(1.0, (float) ($inputs['min_relevance_score'] ?? 0.22)));
        $bundle = [
            'surface' => $surface,
            'prompt_key' => $promptKey,
            'version' => 2,
            'blocks' => [],
            'bundle_quality' => [
                'avg_relevance' => 0.0,
                'stale_block_count' => 0,
                'trimmed_block_count' => 0,
                'overload_risk' => 'low',
                'priority_mix' => [],
            ],
        ];

        $blocks = match ($surface) {
            'coach' => $this->buildCoachBlocks($inputs),
            'clarity_chat' => $this->buildClarityBlocks($inputs),
            'assistant' => $this->buildAssistantBlocks($inputs),
            'customer_thread' => $this->buildCustomerThreadBlocks($inputs),
            'commercial_assistant' => $this->buildCommercialBlocks($inputs),
            default => $this->buildGenericBlocks($inputs),
        };
        $responseStyleContract = $this->responseStyleContractFromBlocks($blocks);

        $pruned = $this->pruneContextDetailed($blocks, $maxBlocks, $maxChars, $minScore);
        $bundle['blocks'] = $pruned['blocks'];
        $bundle['response_style_contract'] = $responseStyleContract !== []
            ? $responseStyleContract
            : $this->languageLevels->currentResponseStyleContract((int) ($inputs['user_id'] ?? Session::get('user_id') ?? 0) ?: null);
        $bundle['bundle_quality']['trimmed_block_count'] = $pruned['trimmed_block_count'];
        $bundle['bundle_quality']['priority_mix'] = $pruned['priority_mix'];
        $bundle['bundle_quality']['discarded_required_count'] = $pruned['discarded_required_count'];
        $bundle['bundle_quality']['truncated_required_count'] = $pruned['truncated_required_count'];
        $bundle['bundle_quality'] = $this->retrievalQuality->scoreBundle($bundle);

        return $bundle;
    }

    public function scoreContextBlock(array $block, array $inputs = []): float
    {
        $content = $block['content'] ?? [];
        $label = strtolower((string) ($block['label'] ?? $block['type'] ?? ''));
        $type = strtolower((string) ($block['type'] ?? 'context'));
        $surface = strtolower((string) ($inputs['surface'] ?? ''));
        $serializedContent = strtolower((string) json_encode($content));

        if ($type === 'platform_ops_context') {
            return 1.0;
        }
        if ($type === 'response_style_contract') {
            return 1.0;
        }

        $priority = (string) ($block['block_priority'] ?? 'optional');
        $score = 0.18 + (($block['priority_weight'] ?? self::PRIORITY_WEIGHTS['optional']) * 0.18);

        if (!empty($content)) {
            $score += 0.10;
        }

        $contentWeight = $this->estimateContentWeight($content);
        if ($contentWeight >= 0.70) {
            $score += 0.10;
        } elseif ($contentWeight <= 0.15) {
            $score -= 0.08;
        }

        if (
            str_contains($label, 'goal')
            || str_contains($label, 'target')
            || str_contains($label, 'thread')
            || str_contains($label, 'deal')
            || str_contains($label, 'invoice')
        ) {
            $score += 0.12;
        }

        $score += $this->freshnessAdjustment((int) ($block['freshness_seconds'] ?? 0), $priority);
        $score += $this->surfaceRelevanceAdjustment($surface, $type, $priority);
        $score += $this->linkageAdjustment($inputs, $serializedContent, $type);
        $score += $this->goalAlignmentAdjustment($inputs, $serializedContent, $type);

        $needle = strtolower((string) ($inputs['question'] ?? $inputs['message'] ?? ''));
        if ($needle !== '') {
            if (str_contains($serializedContent, $needle) || str_contains($label, $needle)) {
                $score += 0.10;
            } else {
                foreach (array_filter(explode(' ', $needle)) as $term) {
                    if (strlen($term) < 4) {
                        continue;
                    }
                    if (str_contains($serializedContent, $term) || str_contains($label, $term)) {
                        $score += 0.02;
                    }
                }
            }
        }

        if ($priority === 'discardable' && $contentWeight < 0.30) {
            $score -= 0.10;
        }

        return round(max(0.0, min(1.0, $score)), 4);
    }

    public function pruneContext(array $blocks, int $maxBlocks = 12, int $maxChars = 12000): array
    {
        return $this->pruneContextDetailed($blocks, $maxBlocks, $maxChars)['blocks'];
    }

    public function summarizeBundle(array $bundle): array
    {
        $responseStyle = (array) (
            $bundle['response_style_contract']
            ?? $this->responseStyleContractFromBlocks((array) ($bundle['blocks'] ?? []))
        );

        return [
            'surface' => (string) ($bundle['surface'] ?? ''),
            'prompt_key' => (string) ($bundle['prompt_key'] ?? ''),
            'response_style' => [
                'level' => (string) ($responseStyle['level'] ?? ''),
                'label' => (string) ($responseStyle['label'] ?? ''),
                'description' => (string) ($responseStyle['description'] ?? ''),
            ],
            'block_count' => count((array) ($bundle['blocks'] ?? [])),
            'block_types' => array_values(array_unique(array_map(
                static fn(array $block): string => (string) ($block['type'] ?? 'context'),
                (array) ($bundle['blocks'] ?? [])
            ))),
            'block_priorities' => array_values(array_unique(array_map(
                static fn(array $block): string => (string) ($block['block_priority'] ?? 'optional'),
                (array) ($bundle['blocks'] ?? [])
            ))),
            'bundle_quality' => (array) ($bundle['bundle_quality'] ?? []),
        ];
    }

    private function buildCoachBlocks(array $inputs): array
    {
        $userId = (int) ($inputs['user_id'] ?? 0);
        $operating = $userId > 0 ? $this->operatingContext->buildForSurface($userId, 'coach') : [];
        $operatingBlockContent = $this->operatingContextBlockContent($operating);
        $roleProfile = $userId > 0 ? $this->buildRoleProfileBlock($userId, 'coach', $operating) : null;
        $userWorkContext = $userId > 0 ? $this->buildUserWorkContextBlock($userId, 'coach', $operating) : null;

        $blocks = [
            ['type' => 'operating_context', 'source' => 'AIOperatingContextService', 'label' => 'Operating context', 'freshness_seconds' => 30, 'content' => $operatingBlockContent],
            $this->buildResponseStyleContractBlock($inputs, $operating),
            $this->buildPlatformOpsContextBlock($inputs, $operating),
            ['type' => 'feature_usage', 'source' => 'AICoach', 'label' => 'Feature usage', 'freshness_seconds' => 60, 'content' => $inputs['feature_usage'] ?? []],
            ['type' => 'analytics_summary', 'source' => 'AICoach', 'label' => 'Analytics summary', 'freshness_seconds' => 60, 'content' => $inputs['metrics'] ?? []],
            ['type' => 'active_targets', 'source' => 'AICoach', 'label' => 'Active targets', 'freshness_seconds' => 60, 'content' => $inputs['active_targets'] ?? []],
            ['type' => 'readiness_gaps', 'source' => 'AIOperatingContextService', 'label' => 'Readiness gaps', 'freshness_seconds' => 60, 'content' => $operating['feature_state']['readiness_gaps'] ?? []],
            ['type' => 'capability_flags', 'source' => 'AIOperatingContextService', 'label' => 'Capability flags', 'freshness_seconds' => 60, 'content' => $operating['feature_state'] ?? []],
            ['type' => 'user_strategy_context', 'source' => 'AIOperatingContextService', 'label' => 'User GTM strategy', 'freshness_seconds' => 60, 'content' => $operating['user_strategy_context'] ?? []],
            ['type' => 'installed_skill_contracts', 'source' => 'WorkspaceSkillInstallService', 'label' => 'Installed skill contracts', 'freshness_seconds' => 60, 'content' => $operating['installed_skill_contracts'] ?? []],
            ['type' => 'workspace_skills', 'source' => 'WorkspaceSkillInstallService', 'label' => 'Installed Workspace Skills', 'freshness_seconds' => 60, 'content' => $operating['workspace_skills'] ?? []],
            ['type' => 'workspace_marketplace', 'source' => 'WorkspaceMarketplaceRecommendationService', 'label' => 'Workspace Marketplace Recommendations', 'freshness_seconds' => 60, 'content' => $operating['workspace_marketplace'] ?? []],
            ['type' => 'lean_canvas_context', 'source' => 'AIOperatingContextService', 'label' => 'Lean Canvas context', 'freshness_seconds' => 60, 'content' => ($operating['user_strategy_context']['lean_canvas'] ?? [])],
            ['type' => 'lean_canvas_status', 'source' => 'AIOperatingContextService', 'label' => 'Lean Canvas status', 'freshness_seconds' => 60, 'content' => $operating['lean_canvas_status'] ?? []],
            ['type' => 'startup_journey_context', 'source' => 'StartupJourneyService', 'label' => 'Clarity Journey context', 'freshness_seconds' => 60, 'content' => $operating['startup_journey_context'] ?? ($operating['user_strategy_context']['startup_journey'] ?? [])],
            ['type' => 'financial_assumptions', 'source' => 'StartupJourneyService', 'label' => 'Journey financial assumptions', 'freshness_seconds' => 60, 'content' => $operating['financial_assumptions'] ?? ($operating['startup_journey_context']['financial_assumptions'] ?? [])],
            ['type' => 'finance_context', 'source' => 'FounderFinanceService', 'label' => 'Finance context', 'freshness_seconds' => 60, 'content' => $operating['finance_context'] ?? ($operating['user_strategy_context']['finance_context'] ?? [])],
            ['type' => 'finance_accounting_context', 'source' => 'FinanceStatementService', 'label' => 'Finance accounting context', 'freshness_seconds' => 60, 'content' => $operating['finance_accounting_context'] ?? ($operating['finance_context']['finance_accounting_context'] ?? [])],
            ['type' => 'founder_operating_loop_context', 'source' => 'FounderOperatingLoopService', 'label' => 'Founder Operating Loop context', 'freshness_seconds' => 60, 'content' => $operating['founder_operating_loop_context'] ?? ($operating['user_strategy_context']['founder_operating_loop'] ?? [])],
            ['type' => 'operating_maturity', 'source' => 'AICoachOperatingMaturityService', 'label' => 'Operating maturity', 'freshness_seconds' => 60, 'content' => $operating['operating_maturity'] ?? []],
            ['type' => 'assumption_conflicts', 'source' => 'AICoachAssumptionConflictService', 'label' => 'Journey assumption conflicts', 'freshness_seconds' => 60, 'content' => $operating['assumption_conflicts'] ?? []],
        ];
        if ($roleProfile !== null) {
            $blocks[] = $roleProfile;
        }
        if ($userWorkContext !== null) {
            $blocks[] = $userWorkContext;
        }

        return $this->decorateBlocks($blocks, $inputs);
    }

    private function buildClarityBlocks(array $inputs): array
    {
        $userId = (int) ($inputs['user_id'] ?? 0);
        $currentPage = (string) ($inputs['current_page'] ?? '');
        $providedWebsite = (array) ($inputs['website_context'] ?? []);
        $website = $providedWebsite !== []
            ? $providedWebsite
            : ($userId > 0 ? $this->websiteContext->build($userId, $currentPage) : []);
        $websiteOperating = (array) ($website['operating_context'] ?? []);
        $providedOperating = (array) ($inputs['operating_context'] ?? []);
        $operating = $providedOperating !== []
            ? array_replace_recursive($websiteOperating, $providedOperating)
            : $websiteOperating;
        if ($operating !== []) {
            $website['operating_context'] = $operating;
        }
        $clarityPageContext = (array) (
            $inputs['clarity_page_context']
            ?? $website['clarity_page_context']
            ?? $operating['clarity_page_context']
            ?? []
        );
        if ($clarityPageContext === [] && $userId > 0) {
            $workspaceId = (int) ($operating['identity']['workspace_id'] ?? 0);
            $clarityPageContext = $this->clarityPageContext->build($workspaceId, $userId, $currentPage, $operating);
        }
        if ($clarityPageContext !== []) {
            $website['clarity_page_context'] = $clarityPageContext;
            $operating['clarity_page_context'] = $clarityPageContext;
        }
        $operatingBlockContent = $this->operatingContextBlockContent($operating);
        $roleProfile = $userId > 0 ? $this->buildRoleProfileBlock($userId, 'clarity_chat', $operating) : null;
        $userWorkContext = $userId > 0 ? $this->buildUserWorkContextBlock($userId, 'clarity_chat', $operating) : null;
        $organizationIntelligenceContext = (array) (
            $inputs['organization_intelligence_context']
            ?? $website['organization_intelligence']
            ?? $operating['organization_intelligence_page_context']
            ?? []
        );
        $organizationIntelligenceContext = $this->organizationIntelligencePromptContext($organizationIntelligenceContext);
        $explanationContext = (array) ($inputs['explanation_context'] ?? []);
        $conversationContext = (array) ($inputs['conversation_context'] ?? []);

        $blocks = [
            ['type' => 'current_page', 'source' => 'chat', 'label' => 'Current page', 'freshness_seconds' => 5, 'content' => ['current_page' => $currentPage]],
            ['type' => 'clarity_explanation_context', 'source' => 'ClarityExplanationContextService', 'label' => 'Question-specific explanation evidence', 'freshness_seconds' => 5, 'content' => $explanationContext],
            ['type' => 'organization_intelligence_context', 'source' => 'OrganizationIntelligenceContextService', 'label' => 'Organization Intelligence evidence', 'freshness_seconds' => 5, 'content' => $organizationIntelligenceContext],
            ['type' => 'conversation_context', 'source' => 'ClarityConversationService', 'label' => 'Recent Clarity conversation', 'freshness_seconds' => 10, 'content' => $conversationContext],
            ['type' => 'clarity_page_context', 'source' => 'ClarityPageContextService', 'label' => 'Clarity page context', 'freshness_seconds' => 10, 'content' => $clarityPageContext],
            ['type' => 'website_assistant_context', 'source' => 'WebsiteAssistantContext', 'label' => 'Website assistant context', 'freshness_seconds' => 10, 'content' => $website],
            ['type' => 'marketing_page_context', 'source' => 'MarketingPageContextService', 'label' => 'Marketing page context', 'freshness_seconds' => 10, 'content' => $website['marketing_page_context'] ?? []],
            ['type' => 'operating_context', 'source' => 'AIOperatingContextService', 'label' => 'Operating context', 'freshness_seconds' => 30, 'content' => $operatingBlockContent],
            $this->buildResponseStyleContractBlock($inputs, $operating),
            $this->buildPlatformOpsContextBlock($inputs, $operating),
            ['type' => 'onboarding_state', 'source' => 'WorkspaceOnboardingService', 'label' => 'Onboarding and setup state', 'freshness_seconds' => 30, 'content' => $operating['onboarding_state'] ?? []],
            ['type' => 'top_goals', 'source' => 'WebsiteAssistantContext', 'label' => 'Top goals', 'freshness_seconds' => 60, 'content' => $website['top_goals'] ?? []],
            ['type' => 'feature_access', 'source' => 'WebsiteAssistantContext', 'label' => 'Feature access', 'freshness_seconds' => 60, 'content' => $website['features'] ?? []],
            ['type' => 'missing_context_flags', 'source' => 'WebsiteAssistantContext', 'label' => 'Missing context flags', 'freshness_seconds' => 60, 'content' => $website['missing_context_flags'] ?? []],
            ['type' => 'installed_skill_contracts', 'source' => 'WorkspaceSkillInstallService', 'label' => 'Installed skill contracts', 'freshness_seconds' => 60, 'content' => $operating['installed_skill_contracts'] ?? []],
            ['type' => 'workspace_skills', 'source' => 'WorkspaceSkillInstallService', 'label' => 'Installed Workspace Skills', 'freshness_seconds' => 60, 'content' => $operating['workspace_skills'] ?? []],
            ['type' => 'workspace_marketplace', 'source' => 'WorkspaceMarketplaceRecommendationService', 'label' => 'Workspace Marketplace Recommendations', 'freshness_seconds' => 60, 'content' => $operating['workspace_marketplace'] ?? []],
            ['type' => 'lean_canvas_context', 'source' => 'AIOperatingContextService', 'label' => 'Lean Canvas context', 'freshness_seconds' => 60, 'content' => ($operating['user_strategy_context']['lean_canvas'] ?? [])],
            ['type' => 'lean_canvas_status', 'source' => 'AIOperatingContextService', 'label' => 'Lean Canvas status', 'freshness_seconds' => 60, 'content' => $operating['lean_canvas_status'] ?? []],
            ['type' => 'startup_journey_context', 'source' => 'StartupJourneyService', 'label' => 'Clarity Journey context', 'freshness_seconds' => 60, 'content' => $operating['startup_journey_context'] ?? ($operating['user_strategy_context']['startup_journey'] ?? [])],
            ['type' => 'financial_assumptions', 'source' => 'StartupJourneyService', 'label' => 'Journey financial assumptions', 'freshness_seconds' => 60, 'content' => $operating['financial_assumptions'] ?? ($operating['startup_journey_context']['financial_assumptions'] ?? [])],
            ['type' => 'finance_context', 'source' => 'FounderFinanceService', 'label' => 'Finance context', 'freshness_seconds' => 60, 'content' => $operating['finance_context'] ?? ($operating['user_strategy_context']['finance_context'] ?? [])],
            ['type' => 'finance_accounting_context', 'source' => 'FinanceStatementService', 'label' => 'Finance accounting context', 'freshness_seconds' => 60, 'content' => $operating['finance_accounting_context'] ?? ($operating['finance_context']['finance_accounting_context'] ?? [])],
            ['type' => 'founder_operating_loop_context', 'source' => 'FounderOperatingLoopService', 'label' => 'Founder Operating Loop context', 'freshness_seconds' => 60, 'content' => $operating['founder_operating_loop_context'] ?? ($operating['user_strategy_context']['founder_operating_loop'] ?? [])],
            ['type' => 'operating_maturity', 'source' => 'AICoachOperatingMaturityService', 'label' => 'Operating maturity', 'freshness_seconds' => 60, 'content' => $operating['operating_maturity'] ?? []],
            ['type' => 'assumption_conflicts', 'source' => 'AICoachAssumptionConflictService', 'label' => 'Journey assumption conflicts', 'freshness_seconds' => 60, 'content' => $operating['assumption_conflicts'] ?? []],
        ];
        if ($roleProfile !== null) {
            $blocks[] = $roleProfile;
        }
        if ($userWorkContext !== null) {
            $blocks[] = $userWorkContext;
        }

        return $this->decorateBlocks($blocks, $inputs);
    }

    /**
     * Keep explanation evidence ahead of room-wide dashboard payloads so a tight
     * context budget cannot cut away the factors that answer the user's question.
     */
    private function organizationIntelligencePromptContext(array $context): array
    {
        if ($context === []) {
            return [];
        }

        $selected = [];
        foreach ([
            'score_explanation',
            'source',
            'room',
            'sub_room',
            'scope',
            'data_quality',
            'health',
            'risks',
            'people',
            'signals',
        ] as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== [] && $context[$key] !== null) {
                $selected[$key] = $context[$key];
            }
        }

        return $selected !== [] ? $selected : $context;
    }

    private function buildAssistantBlocks(array $inputs): array
    {
        $roleProfile = $this->buildOptionalRoleProfileBlock($inputs, 'assistant');
        $userWorkContext = $this->buildOptionalUserWorkContextBlock($inputs, 'assistant');
        $marketplacePerformance = $this->buildOptionalMarketplacePerformanceBlock($inputs);
        $blocks = [
            ['type' => 'resolved_entities', 'source' => 'assistant', 'label' => 'Resolved entities', 'freshness_seconds' => 15, 'content' => $inputs['resolved_entities'] ?? []],
            ['type' => 'thread_context', 'source' => 'assistant', 'label' => 'Thread context', 'freshness_seconds' => 15, 'content' => $inputs['thread_context'] ?? []],
            ['type' => 'operating_context', 'source' => 'assistant', 'label' => 'Operating context', 'freshness_seconds' => 30, 'content' => $inputs['operating_context'] ?? []],
            $this->buildResponseStyleContractBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            $this->buildPlatformOpsContextBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            ['type' => 'policy_context', 'source' => 'assistant', 'label' => 'Policy context', 'freshness_seconds' => 30, 'content' => $inputs['policy_context'] ?? []],
            ['type' => 'commercial_context', 'source' => 'assistant', 'label' => 'Commercial context', 'freshness_seconds' => 30, 'content' => $inputs['commercial_context'] ?? []],
        ];
        $voiceContext = $this->buildOptionalVoiceContextBlock($inputs);
        if ($voiceContext !== null) {
            $blocks[] = $voiceContext;
        }
        if ($roleProfile !== null) {
            $blocks[] = $roleProfile;
        }
        if ($userWorkContext !== null) {
            $blocks[] = $userWorkContext;
        }
        if ($marketplacePerformance !== null) {
            $blocks[] = $marketplacePerformance;
        }

        return $this->decorateBlocks($blocks, $inputs);
    }

    private function buildOptionalMarketplacePerformanceBlock(array $inputs): ?array
    {
        $workspaceId = (int) ($inputs['workspace_id'] ?? $inputs['active_workspace_id'] ?? Session::get('active_workspace_id') ?? 0);
        $userId = (int) ($inputs['user_id'] ?? Session::get('user_id') ?? 0);
        if ($workspaceId <= 0 || $userId <= 0 || !Authorization::isSuperAdmin(['id' => $userId])) {
            return null;
        }

        return [
            'type' => 'workspace_marketplace_performance',
            'source' => 'WorkspaceMarketplacePerformanceService',
            'label' => 'Workspace Marketplace Performance',
            'freshness_seconds' => 60,
            'content' => (new WorkspaceMarketplacePerformanceService())->buildWorkspaceSummary($workspaceId, 30),
        ];
    }

    private function buildCustomerThreadBlocks(array $inputs): array
    {
        $roleProfile = $this->buildOptionalRoleProfileBlock($inputs, 'customer_thread');
        $userWorkContext = $this->buildOptionalUserWorkContextBlock($inputs, 'customer_thread');
        $blocks = [
            ['type' => 'thread_summary', 'source' => 'customer_thread', 'label' => 'Thread summary', 'freshness_seconds' => 15, 'content' => $inputs['thread_summary'] ?? []],
            $this->buildResponseStyleContractBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            $this->buildPlatformOpsContextBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            ['type' => 'latest_inbound_message', 'source' => 'customer_thread', 'label' => 'Latest inbound message', 'freshness_seconds' => 15, 'content' => $inputs['latest_inbound_message'] ?? []],
            ['type' => 'contact', 'source' => 'customer_thread', 'label' => 'Contact', 'freshness_seconds' => 30, 'content' => $inputs['contact'] ?? []],
            ['type' => 'deal', 'source' => 'customer_thread', 'label' => 'Deal', 'freshness_seconds' => 30, 'content' => $inputs['deal'] ?? []],
            ['type' => 'invoice', 'source' => 'customer_thread', 'label' => 'Invoice', 'freshness_seconds' => 30, 'content' => $inputs['invoice'] ?? []],
            ['type' => 'policy_context', 'source' => 'customer_thread', 'label' => 'Policy context', 'freshness_seconds' => 30, 'content' => $inputs['policy_context'] ?? []],
        ];
        $voiceContext = $this->buildOptionalVoiceContextBlock($inputs);
        if ($voiceContext !== null) {
            $blocks[] = $voiceContext;
        }
        if ($roleProfile !== null) {
            $blocks[] = $roleProfile;
        }
        if ($userWorkContext !== null) {
            $blocks[] = $userWorkContext;
        }

        return $this->decorateBlocks($blocks, $inputs);
    }

    private function buildCommercialBlocks(array $inputs): array
    {
        $roleProfile = $this->buildOptionalRoleProfileBlock($inputs, 'commercial_assistant');
        $userWorkContext = $this->buildOptionalUserWorkContextBlock($inputs, 'commercial_assistant');
        $blocks = [
            $this->buildResponseStyleContractBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            $this->buildPlatformOpsContextBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            ['type' => 'deal', 'source' => 'commercial', 'label' => 'Deal', 'freshness_seconds' => 30, 'content' => $inputs['deal'] ?? []],
            ['type' => 'invoice', 'source' => 'commercial', 'label' => 'Invoice', 'freshness_seconds' => 30, 'content' => $inputs['invoice'] ?? []],
            ['type' => 'approval_context', 'source' => 'commercial', 'label' => 'Approval context', 'freshness_seconds' => 30, 'content' => $inputs['approval_context'] ?? []],
            ['type' => 'commercial_policy_snapshot', 'source' => 'commercial', 'label' => 'Commercial policy snapshot', 'freshness_seconds' => 30, 'content' => $inputs['commercial_policy_snapshot'] ?? []],
            ['type' => 'pricing_settings', 'source' => 'commercial', 'label' => 'Pricing and invoice settings', 'freshness_seconds' => 60, 'content' => $inputs['pricing_settings'] ?? []],
        ];
        $voiceContext = $this->buildOptionalVoiceContextBlock($inputs);
        if ($voiceContext !== null) {
            $blocks[] = $voiceContext;
        }
        if ($roleProfile !== null) {
            $blocks[] = $roleProfile;
        }
        if ($userWorkContext !== null) {
            $blocks[] = $userWorkContext;
        }

        return $this->decorateBlocks($blocks, $inputs);
    }

    private function buildGenericBlocks(array $inputs): array
    {
        return $this->decorateBlocks([
            $this->buildResponseStyleContractBlock($inputs, (array) ($inputs['operating_context'] ?? [])),
            ['type' => 'inputs', 'source' => 'generic', 'label' => 'Input context', 'freshness_seconds' => 30, 'content' => $inputs],
        ], $inputs);
    }

    private function buildOptionalVoiceContextBlock(array $inputs): ?array
    {
        $workspaceId = (int) ($inputs['workspace_id'] ?? $inputs['active_workspace_id'] ?? Session::get('active_workspace_id') ?? 0);
        $contact = (array) ($inputs['contact'] ?? []);
        $contactId = (int) ($inputs['contact_id'] ?? $contact['id'] ?? 0);
        if ($workspaceId <= 0 || $contactId <= 0 || !Database::tableExists('voice_call_insights')) {
            return null;
        }
        $rows = Database::query(
            "SELECT c.id AS call_id, c.completed_at, i.summary, i.sentiment, i.intent, i.next_step, i.confidence,
                    i.review_status, i.pains_json, i.goals_json, i.objections_json, i.commitments_json
             FROM voice_calls c INNER JOIN voice_call_insights i ON i.workspace_id = c.workspace_id AND i.call_id = c.id
             WHERE c.workspace_id = ? AND c.contact_id = ? AND c.state = 'completed' AND COALESCE(i.confidence, 0) >= 0.50
             ORDER BY c.completed_at DESC, c.id DESC LIMIT 5",
            [$workspaceId, $contactId]
        );
        if ($rows === []) {
            return null;
        }
        $voiceConfig = (new WorkspaceVoiceConfigService())->get($workspaceId, false);
        $policyService = new VoicePolicyDecisionService();
        $summaryAllowed = $voiceConfig === []
            || !empty($policyService->decide($voiceConfig, 'call_summary', false, 1.0)['apply']);
        $decode = static function ($value, int $limit = 4): array {
            $list = $value ? json_decode((string) $value, true) : [];
            if (!is_array($list)) return [];
            return array_slice(array_map(static fn($item): string => mb_substr(trim((string) $item), 0, 240), array_values($list)), 0, $limit);
        };
        $content = array_values(array_filter(array_map(static function (array $row) use ($decode, $voiceConfig, $policyService, $summaryAllowed): array {
            $confidence = (float) ($row['confidence'] ?? 0);
            $structuredAllowed = $voiceConfig === []
                ? (string) ($row['review_status'] ?? '') === 'applied'
                : $policyService->structuredContextMayBeUsed($voiceConfig, (string) ($row['review_status'] ?? ''), $confidence);
            if (!$summaryAllowed && !$structuredAllowed) {
                return [];
            }
            $context = [
                'record_id' => 'voice_call:' . (int) $row['call_id'],
                'observed_at' => (string) ($row['completed_at'] ?? ''),
                'summary' => $summaryAllowed ? mb_substr(trim((string) ($row['summary'] ?? '')), 0, 900) : '',
                'confidence' => $confidence,
                'context_application' => $structuredAllowed ? 'applied' : 'summary_only',
            ];
            if ($structuredAllowed) {
                $context['sentiment_inferred'] = (string) ($row['sentiment'] ?? '');
                $context['intent_inferred'] = (string) ($row['intent'] ?? '');
                $context['pains_inferred'] = $decode($row['pains_json'] ?? null);
                $context['goals_observed_or_inferred'] = $decode($row['goals_json'] ?? null);
                $context['objections_inferred'] = $decode($row['objections_json'] ?? null);
                $context['commitments_observed'] = $decode($row['commitments_json'] ?? null);
                $context['next_step'] = mb_substr(trim((string) ($row['next_step'] ?? '')), 0, 500);
            }
            return $context;
        }, $rows), static fn(array $row): bool => $row !== []));
        if ($content === []) {
            return null;
        }
        return [
            'type' => 'voice_context', 'source' => 'VoiceCallCenter', 'label' => 'Recent voice context',
            'freshness_seconds' => 30, 'block_priority' => 'preferred', 'content' => $content,
        ];
    }

    private function buildResponseStyleContractBlock(array $inputs, array $operating = []): array
    {
        $contract = (array) (
            $operating['ai_settings']['response_style_contract']
            ?? $inputs['response_style_contract']
            ?? []
        );
        if ($contract === []) {
            $userId = (int) ($inputs['user_id'] ?? Session::get('user_id') ?? 0);
            $contract = $this->languageLevels->currentResponseStyleContract($userId > 0 ? $userId : null);
        }

        return [
            'type' => 'response_style_contract',
            'source' => 'WorkspaceLanguageLevelService',
            'label' => 'Workspace language level',
            'freshness_seconds' => 60,
            'block_priority' => 'required',
            'content' => $contract,
        ];
    }

    private function responseStyleContractFromBlocks(array $blocks): array
    {
        foreach ($blocks as $block) {
            if ((string) ($block['type'] ?? '') !== 'response_style_contract') {
                continue;
            }
            $content = (array) ($block['content'] ?? []);
            if ($content !== []) {
                return $content;
            }
        }

        return [];
    }

    private function buildPlatformOpsContextBlock(array $inputs, array $operating = []): array
    {
        $workspaceId = (int) (
            $operating['identity']['workspace_id']
            ?? $inputs['workspace_id']
            ?? $inputs['active_workspace_id']
            ?? Session::get('active_workspace_id')
            ?? 0
        );

        $workspaceContext = $workspaceId > 0 ? $this->contextRegistry->workspaceContext($workspaceId) : [];
        if ($workspaceId <= 0 || empty($workspaceContext['is_default_workspace'])) {
            return [
                'type' => 'platform_ops_context',
                'source' => 'SystemContextRegistryService',
                'label' => 'Default workspace Platform Ops context',
                'freshness_seconds' => 30,
                'block_priority' => 'required',
                'content' => [],
            ];
        }

        $content = (array) ($operating['platform_ops_context'] ?? []);
        if ($content === []) {
            $content = $this->contextRegistry->platformOpsContext($workspaceId);
        }

        return [
            'type' => 'platform_ops_context',
            'source' => 'SystemContextRegistryService',
            'label' => 'Default workspace Platform Ops context',
            'freshness_seconds' => 30,
            'block_priority' => 'required',
            'content' => $content,
        ];
    }

    private function operatingContextBlockContent(array $operating): array
    {
        unset($operating['platform_ops_context']);
        return $operating;
    }

    private function decorateBlocks(array $blocks, array $inputs): array
    {
        foreach ($blocks as &$block) {
            $explicitPriority = (string) ($block['block_priority'] ?? '');
            $block['block_priority'] = array_key_exists($explicitPriority, self::PRIORITY_WEIGHTS)
                ? $explicitPriority
                : $this->resolveBlockPriority((string) ($inputs['surface'] ?? ''), $block);
            $block['priority_weight'] = self::PRIORITY_WEIGHTS[$block['block_priority']] ?? self::PRIORITY_WEIGHTS['optional'];
            $block['relevance_score'] = $this->scoreContextBlock($block, $inputs);
            $block['content_chars'] = strlen((string) json_encode($block['content'] ?? []));
        }
        unset($block);

        return array_values(array_filter($blocks, static fn(array $block): bool => !empty($block['content'])));
    }

    private function pruneContextDetailed(array $blocks, int $maxBlocks, int $maxChars, float $minScore = 0.22): array
    {
        $trimmed = 0;
        $discardedRequired = 0;
        $truncatedRequired = 0;
        $priorityMix = [
            'required' => 0,
            'preferred' => 0,
            'optional' => 0,
            'discardable' => 0,
        ];

        $requiredCount = count(array_filter(
            $blocks,
            static fn(array $block): bool => (string) ($block['block_priority'] ?? 'optional') === 'required'
        ));
        if ($requiredCount > 0) {
            $requiredBudget = max(160, intdiv($maxChars, $requiredCount));
            foreach ($blocks as &$requiredBlock) {
                if ((string) ($requiredBlock['block_priority'] ?? 'optional') !== 'required') {
                    continue;
                }
                $contentChars = max(1, (int) ($requiredBlock['content_chars'] ?? strlen((string) json_encode($requiredBlock['content'] ?? []))));
                if ($contentChars <= $requiredBudget) {
                    continue;
                }
                $requiredBlock['content'] = $this->compactContentToBudget($requiredBlock['content'] ?? [], $requiredBudget);
                $requiredBlock['content_chars'] = strlen((string) json_encode($requiredBlock['content'] ?? []));
                $requiredBlock['content_truncated'] = true;
                $trimmed++;
                $truncatedRequired++;
            }
            unset($requiredBlock);
        }

        usort($blocks, static function (array $left, array $right): int {
            $leftPriority = self::PRIORITY_WEIGHTS[(string) ($left['block_priority'] ?? 'optional')] ?? 0.0;
            $rightPriority = self::PRIORITY_WEIGHTS[(string) ($right['block_priority'] ?? 'optional')] ?? 0.0;
            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }
            $leftScore = (float) ($left['relevance_score'] ?? 0.0);
            $rightScore = (float) ($right['relevance_score'] ?? 0.0);
            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }
            return ((int) ($left['content_chars'] ?? 0)) <=> ((int) ($right['content_chars'] ?? 0));
        });

        $kept = [];
        $chars = 0;
        foreach ($blocks as $block) {
            $priority = (string) ($block['block_priority'] ?? 'optional');
            $score = (float) ($block['relevance_score'] ?? 0.0);
            $blockChars = max(1, (int) ($block['content_chars'] ?? strlen((string) json_encode($block['content'] ?? []))));
            $mustKeep = $priority === 'required';

            if ($score < $minScore && !$mustKeep) {
                $trimmed++;
                continue;
            }

            if (count($kept) >= $maxBlocks) {
                $trimmed++;
                if ($mustKeep) {
                    $discardedRequired++;
                }
                continue;
            }

            if ($chars + $blockChars > $maxChars && !empty($kept)) {
                if ($mustKeep) {
                    $replaceIndex = $this->findReplaceableBlockIndex($kept, $score, $blockChars, $maxChars, $chars);
                    if ($replaceIndex !== null) {
                        $removed = $kept[$replaceIndex];
                        $chars -= max(1, (int) ($removed['content_chars'] ?? 0));
                        $priorityMix[(string) ($removed['block_priority'] ?? 'optional')]--;
                        $kept[$replaceIndex] = $block;
                        $chars += $blockChars;
                        $priorityMix[$priority]++;
                        $trimmed++;
                        continue;
                    }
                    $discardedRequired++;
                }
                $trimmed++;
                continue;
            }

            $kept[] = $block;
            $chars += $blockChars;
            $priorityMix[$priority]++;
        }

        return [
            'blocks' => array_values($kept),
            'trimmed_block_count' => $trimmed,
            'priority_mix' => array_filter($priorityMix, static fn(int $count): bool => $count > 0),
            'discarded_required_count' => $discardedRequired,
            'truncated_required_count' => $truncatedRequired,
        ];
    }

    private function resolveBlockPriority(string $surface, array $block): string
    {
        $type = (string) ($block['type'] ?? 'context');
        if ($type === 'platform_ops_context') {
            return 'required';
        }
        if ($type === 'response_style_contract') {
            return 'required';
        }
        if ($surface === 'clarity_chat' && $type === 'clarity_page_context') {
            $family = (string) ($block['content']['page']['family'] ?? '');
            return in_array($family, ['workspace', ''], true) ? 'preferred' : 'required';
        }
        if ($surface === 'clarity_chat' && in_array($type, ['clarity_explanation_context', 'organization_intelligence_context'], true)) {
            return 'required';
        }
        if ($surface === 'clarity_chat' && $type === 'conversation_context') {
            return 'preferred';
        }
        if ($surface === 'clarity_chat' && $type === 'onboarding_state') {
            return empty($block['content']['is_operational']) ? 'required' : 'preferred';
        }

        return match ($surface . ':' . $type) {
            'coach:operating_context',
            'coach:readiness_gaps',
            'clarity_chat:current_page',
            'clarity_chat:operating_context',
            'assistant:resolved_entities',
            'assistant:thread_context',
            'assistant:policy_context',
            'customer_thread:thread_summary',
            'customer_thread:latest_inbound_message',
            'commercial_assistant:deal',
            'commercial_assistant:approval_context',
            'commercial_assistant:commercial_policy_snapshot' => 'required',

            'coach:active_targets',
            'coach:feature_usage',
            'coach:analytics_summary',
            'coach:user_strategy_context',
            'coach:installed_skill_contracts',
            'coach:workspace_skills',
            'coach:workspace_marketplace',
            'coach:lean_canvas_context',
            'coach:lean_canvas_status',
            'coach:startup_journey_context',
            'coach:financial_assumptions',
            'coach:finance_context',
            'coach:finance_accounting_context',
            'coach:founder_operating_loop_context',
            'coach:operating_maturity',
            'coach:assumption_conflicts',
            'coach:role_profile',
            'coach:user_work_context',
            'clarity_chat:website_assistant_context',
            'clarity_chat:top_goals',
            'clarity_chat:missing_context_flags',
            'clarity_chat:installed_skill_contracts',
            'clarity_chat:workspace_skills',
            'clarity_chat:workspace_marketplace',
            'clarity_chat:lean_canvas_context',
            'clarity_chat:lean_canvas_status',
            'clarity_chat:startup_journey_context',
            'clarity_chat:financial_assumptions',
            'clarity_chat:finance_context',
            'clarity_chat:finance_accounting_context',
            'clarity_chat:founder_operating_loop_context',
            'clarity_chat:operating_maturity',
            'clarity_chat:assumption_conflicts',
            'clarity_chat:role_profile',
            'clarity_chat:user_work_context',
            'assistant:operating_context',
            'assistant:commercial_context',
            'assistant:workspace_marketplace_performance',
            'assistant:role_profile',
            'assistant:user_work_context',
            'customer_thread:contact',
            'customer_thread:deal',
            'customer_thread:policy_context',
            'customer_thread:role_profile',
            'customer_thread:user_work_context',
            'commercial_assistant:invoice',
            'commercial_assistant:pricing_settings',
            'commercial_assistant:role_profile',
            'commercial_assistant:user_work_context' => 'preferred',

            'clarity_chat:feature_access',
            'customer_thread:invoice',
            'coach:capability_flags' => 'optional',

            default => 'discardable',
        };
    }

    private function freshnessAdjustment(int $freshnessSeconds, string $priority): float
    {
        if ($freshnessSeconds <= 0) {
            return 0.0;
        }
        if ($freshnessSeconds <= 300) {
            return 0.12;
        }
        if ($freshnessSeconds <= 3600) {
            return 0.08;
        }
        if ($freshnessSeconds <= 21600) {
            return 0.03;
        }
        if ($freshnessSeconds > 86400) {
            return $priority === 'required' ? -0.04 : -0.12;
        }
        return 0.0;
    }

    private function surfaceRelevanceAdjustment(string $surface, string $type, string $priority): float
    {
        $map = [
            'coach' => ['active_targets', 'readiness_gaps', 'analytics_summary', 'feature_usage', 'role_profile', 'user_work_context'],
            'clarity_chat' => ['current_page', 'clarity_explanation_context', 'organization_intelligence_context', 'conversation_context', 'clarity_page_context', 'website_assistant_context', 'operating_context', 'onboarding_state', 'top_goals', 'role_profile', 'user_work_context'],
            'assistant' => ['resolved_entities', 'thread_context', 'policy_context', 'commercial_context', 'role_profile', 'user_work_context'],
            'customer_thread' => ['thread_summary', 'latest_inbound_message', 'deal', 'invoice', 'role_profile', 'user_work_context'],
            'commercial_assistant' => ['deal', 'invoice', 'approval_context', 'commercial_policy_snapshot', 'role_profile', 'user_work_context'],
        ];

        $boost = in_array($type, $map[$surface] ?? [], true) ? 0.08 : 0.0;
        if ($priority === 'required') {
            $boost += 0.04;
        }
        return $boost;
    }

    private function linkageAdjustment(array $inputs, string $serializedContent, string $type): float
    {
        $score = 0.0;
        foreach (['deal', 'invoice', 'contact', 'approval_context', 'thread_context', 'resolved_entities'] as $key) {
            if (empty($inputs[$key])) {
                continue;
            }
            $encoded = strtolower((string) json_encode($inputs[$key]));
            if ($encoded !== '' && $encoded !== '[]' && $encoded !== '{}' && $encoded === $serializedContent) {
                $score += 0.12;
            } elseif ($encoded !== '' && str_contains($serializedContent, substr($encoded, 0, min(strlen($encoded), 80)))) {
                $score += 0.07;
            }
        }

        if (in_array($type, ['deal', 'invoice', 'contact', 'resolved_entities'], true)) {
            $score += 0.04;
        }

        return min(0.18, $score);
    }

    private function goalAlignmentAdjustment(array $inputs, string $serializedContent, string $type): float
    {
        $goals = (array) ($inputs['active_targets'] ?? $inputs['top_goals'] ?? []);
        if ($goals === []) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($goals as $goal) {
            $title = strtolower((string) ($goal['title'] ?? $goal['name'] ?? ''));
            if ($title !== '' && str_contains($serializedContent, $title)) {
                $score += 0.05;
            }
        }

        if (in_array($type, ['active_targets', 'top_goals', 'readiness_gaps'], true)) {
            $score += 0.05;
        }

        return min(0.12, $score);
    }

    private function estimateContentWeight(mixed $content): float
    {
        $encoded = trim((string) json_encode($content));
        if ($encoded === '' || $encoded === '[]' || $encoded === '{}') {
            return 0.0;
        }

        $length = strlen($encoded);
        if ($length >= 1200) {
            return 1.0;
        }
        return round($length / 1200, 4);
    }

    private function compactContentToBudget(mixed $content, int $maxChars): mixed
    {
        $maxChars = max(80, $maxChars);
        if (is_string($content)) {
            if (strlen($content) <= $maxChars) {
                return $content;
            }
            return rtrim(substr($content, 0, max(1, $maxChars - 16))) . ' [truncated]';
        }
        if (!is_array($content)) {
            return $content;
        }

        $result = [];
        $truncated = false;
        foreach ($content as $key => $value) {
            $used = strlen((string) json_encode($result));
            $remaining = $maxChars - $used - strlen((string) $key) - 12;
            if ($remaining < 40) {
                $truncated = true;
                break;
            }

            $candidate = is_array($value) || is_string($value)
                ? $this->compactContentToBudget($value, $remaining)
                : $value;
            $result[$key] = $candidate;
            if (strlen((string) json_encode($result)) > $maxChars) {
                unset($result[$key]);
                $truncated = true;
                break;
            }
            if ($candidate !== $value) {
                $truncated = true;
            }
        }

        return $result;
    }

    private function findReplaceableBlockIndex(array $kept, float $incomingScore, int $incomingChars, int $maxChars, int $currentChars): ?int
    {
        $candidateIndex = null;
        $candidateScore = 2.0;
        foreach ($kept as $index => $block) {
            $priority = (string) ($block['block_priority'] ?? 'optional');
            if ($priority === 'required') {
                continue;
            }
            $blockChars = max(1, (int) ($block['content_chars'] ?? 0));
            if (($currentChars - $blockChars + $incomingChars) > $maxChars) {
                continue;
            }
            $blockScore = (float) ($block['relevance_score'] ?? 0.0);
            if ($blockScore < $candidateScore && $blockScore < $incomingScore) {
                $candidateIndex = $index;
                $candidateScore = $blockScore;
            }
        }

        return $candidateIndex;
    }

    private function buildOptionalRoleProfileBlock(array $inputs, string $surface): ?array
    {
        $userId = (int) ($inputs['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $operatingContext = (array) ($inputs['operating_context'] ?? []);
        return $this->buildRoleProfileBlock($userId, $surface, $operatingContext);
    }

    private function buildOptionalUserWorkContextBlock(array $inputs, string $surface): ?array
    {
        $userId = (int) ($inputs['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $operatingContext = (array) ($inputs['operating_context'] ?? []);
        return $this->buildUserWorkContextBlock($userId, $surface, $operatingContext);
    }

    private function buildRoleProfileBlock(int $userId, string $surface, array $operatingContext): array
    {
        return [
            'type' => 'role_profile',
            'source' => 'AIRoleProfileService',
            'label' => 'Role profile',
            'freshness_seconds' => 60,
            'content' => $this->roleProfiles->buildProfile($userId, $operatingContext),
        ];
    }

    private function buildUserWorkContextBlock(int $userId, string $surface, array $operatingContext): array
    {
        return [
            'type' => 'user_work_context',
            'source' => 'AIUserWorkContextService',
            'label' => 'Logged-in user work context',
            'freshness_seconds' => 30,
            'content' => $this->userWorkContext->buildContext($userId, $surface, $operatingContext),
        ];
    }
}
