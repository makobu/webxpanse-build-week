<?php
/**
 * AI Coach Module
 *
 * Generates actionable marketing recommendations based on metrics and feature usage.
 * Uses AIService for recommendation generation.
 */

namespace CRM\Modules;

use CRM\Auth;
use CRM\Authorization;
use CRM\CacheManager;
use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\AICoachRelevanceScorer;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AIContextAssemblyService;
use CRM\Services\AIPromptRegistryService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\AIRetrievalQualityService;
use CRM\Services\AIRoleProfileService;
use CRM\Services\AIQualificationPolicyService;
use CRM\Services\AIThresholdUpdateService;
use CRM\Services\AIUserWorkContextService;
use CRM\Services\AIAdviceFeedbackService;
use CRM\Services\AICoachRecommendationControlService;
use CRM\Services\AICoachRecommendationRankingService;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceMarketplaceRecommendationService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyService;
use CRM\Services\SkillTaskTemplateService;
use CRM\Services\AICoachAssumptionConflictService;
use CRM\Services\AICoachOperatingMaturityService;
use CRM\Services\FounderFinanceService;
use CRM\Services\FounderOperatingLoopService;
use CRM\Services\StartupJourneyService;

class AICoach
{
    private const CACHE_KEY_PREFIX = 'ai_coach_recommendations_';
    private const CACHE_TTL = 3600; // 1 hour

    private AIService $aiService;
    private AnalyticsDashboard $analytics;
    private UserPreferences $preferences;
    private CacheManager $cache;
    private AIOperatingContextService $operatingContext;
    private AICoachRelevanceScorer $relevanceScorer;
    private AIQualificationPolicyService $qualificationPolicy;
    private AIContextAssemblyService $contextAssembly;
    private AIPromptRegistryService $promptRegistry;
    private AIRetrievalQualityService $retrievalQuality;
    private AIRuntimeControlService $runtimeControls;
    private AIRoleProfileService $roleProfiles;
    private AIUserWorkContextService $userWorkContext;
    private AIThresholdUpdateService $thresholds;
    private AIAdviceFeedbackService $feedback;
    private AICoachRecommendationControlService $recommendationControls;
    private AICoachRecommendationRankingService $recommendationRanking;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    private WorkspaceMarketplaceRecommendationService $marketplaceRecommendations;
    private WorkspaceMarketplaceSetupJourneyService $marketplaceSetupJourneys;
    private SkillTaskTemplateService $skillTaskTemplates;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->analytics = new AnalyticsDashboard();
        $this->preferences = new UserPreferences();
        $this->cache = new CacheManager();
        $this->operatingContext = new AIOperatingContextService();
        $this->relevanceScorer = new AICoachRelevanceScorer();
        $this->qualificationPolicy = new AIQualificationPolicyService();
        $this->contextAssembly = new AIContextAssemblyService();
        $this->promptRegistry = new AIPromptRegistryService();
        $this->retrievalQuality = new AIRetrievalQualityService();
        $this->runtimeControls = new AIRuntimeControlService();
        $this->roleProfiles = new AIRoleProfileService();
        $this->userWorkContext = new AIUserWorkContextService();
        $this->thresholds = new AIThresholdUpdateService();
        $this->feedback = new AIAdviceFeedbackService();
        $this->recommendationControls = new AICoachRecommendationControlService();
        $this->recommendationRanking = new AICoachRecommendationRankingService();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
        $this->marketplaceRecommendations = new WorkspaceMarketplaceRecommendationService();
        $this->marketplaceSetupJourneys = new WorkspaceMarketplaceSetupJourneyService();
        $this->skillTaskTemplates = new SkillTaskTemplateService();
    }

    /**
     * Generate recommendations for the given user.
     * Returns structured array: priorities, quick_wins, missing_features, with each item having
     * title, impact, effort, reason, category, suggested_subtasks (optional).
     *
     * @param int $userId User ID
     * @param string $mode '1' Foundation, '2' Operations, '3' Guardian
     */
    public function generateRecommendations(int $userId, string $mode = '2', array $options = []): array
    {
        $liveAi = !empty($options['live_ai']);
        $forceRefresh = !empty($options['force_refresh']);
        $generationStatus = $this->buildGenerationStatus('deterministic_fallback');
        $operatingContext = $this->buildMinimalSkillOperatingContext($userId);
        $leanCanvasEnabled = $this->hasReadySkillContract($operatingContext, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
        $strategyProfile = new UserStrategyProfile();
        $leanCanvasCompleteness = $strategyProfile->getLeanCanvasCompleteness($userId);
        $leanCanvasMissingBlocks = $strategyProfile->getLeanCanvasMissingBlocks($userId);
        $modeVariant = $leanCanvasEnabled ? 'lean_canvas' : 'standard';
        $promptKey = $leanCanvasEnabled ? 'coach_recommendations_lean_canvas' : 'coach_recommendations';
        $control = $this->runtimeControls->getEffectiveControl('coach');
        if (in_array((string) ($control['control_mode'] ?? 'normal'), ['paused', 'diagnostics_only'], true)) {
            return $this->attachGenerationStatus($this->applyLeanCanvasMetadata(
                $this->buildRuntimeControlResponse($mode, $control),
                $modeVariant,
                $leanCanvasEnabled,
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            ), $this->buildGenerationStatus('runtime_blocked', [
                'message' => (string) ($control['reason'] ?? 'AI Coach is temporarily constrained by an administrator.'),
                'fallback_used' => true,
            ]));
        }

        // Guardian mode: no daily coaching, minimal response
        if ($mode === '3') {
            return $this->attachGenerationStatus($this->applyLeanCanvasMetadata([
                'why_this_matters' => "Guardian Mode: No daily coaching. You'll be alerted only for critical issues.",
                'priorities' => [],
                'quick_wins' => [],
                'missing_features' => [],
                'foundation_gaps' => [],
            ], $modeVariant, $leanCanvasEnabled, $leanCanvasCompleteness, $leanCanvasMissingBlocks), $generationStatus);
        }

        $metrics = ['summary' => []];
        $featureUsage = [
            'tasks_used' => false,
            'task_count' => 0,
            'contact_count' => 0,
            'workflows_used' => false,
            'deals_used' => false,
            'email_templates_count' => 0,
        ];
        $allowedTargetIds = [];
        if (!$this->skillTaskTemplates->hasReadyAdviceSkill($operatingContext)) {
            $setupRecommendations = $this->attachEvidenceSignals(
                $this->attachRecommendationKeys($this->buildSkillSetupOnlyRecommendations($operatingContext), 0),
                $metrics,
                $featureUsage,
                [],
                $operatingContext,
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            );
            return $this->attachGenerationStatus($this->applyLeanCanvasMetadata(
                $setupRecommendations,
                $modeVariant,
                $leanCanvasEnabled,
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            ), $generationStatus);
        }

        try {
            $operatingContext = $this->operatingContext->buildForSurface($userId, 'coach');
            $leanCanvasEnabled = $this->hasReadySkillContract($operatingContext, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
            $modeVariant = $leanCanvasEnabled ? 'lean_canvas' : 'standard';
            $promptKey = $leanCanvasEnabled ? 'coach_recommendations_lean_canvas' : 'coach_recommendations';
            $roleProfile = $this->roleProfiles->buildProfile($userId, $operatingContext);
            $userWorkContext = $this->userWorkContext->buildContext($userId, 'coach', $operatingContext);
            $operatingContext['role_profile'] = $roleProfile;
            $operatingContext['user_work_context'] = $userWorkContext;
            $metrics = $this->getMetricsContext();
            $featureUsage = $this->getFeatureUsageContext($userId);
            $activeTargets = $this->getActiveTargetsContext($userId);
            $allowedTargetIds = array_map(static fn($t) => (int) ($t['id'] ?? 0), $activeTargets);
            $targetTitlesById = [];
            foreach ($activeTargets as $t) {
                $tid = (int) ($t['id'] ?? 0);
                if ($tid > 0) {
                    $targetTitlesById[$tid] = (string) ($t['title'] ?? '');
                }
            }
            $bundle = $this->contextAssembly->buildContextBundle('coach', $promptKey, [
                'user_id' => $userId,
                'mode' => $mode,
                'metrics' => $metrics,
                'feature_usage' => $featureUsage,
                'active_targets' => $activeTargets,
                'operating_context' => $operatingContext,
            ]);
            $resolvedPrompt = $this->aiService->buildPromptFromRegistry('coach', $promptKey, $bundle, [
                'mode' => $mode,
                'legacy_prompt' => $leanCanvasEnabled
                    ? $this->buildLeanCanvasRecommendationPrompt($metrics, $featureUsage, $activeTargets, $mode, $userId)
                    : $this->buildRecommendationPrompt($metrics, $featureUsage, $activeTargets, $mode, $userId),
            ]);
            $contextBundleQuality = $this->retrievalQuality->scoreBundle($bundle);
            $aiContext = [
                'fast_fallback' => true,
                'cache_only' => !$liveAi,
            ];
            if ($forceRefresh) {
                $aiContext['force_refresh'] = true;
            }
            $response = $this->aiService->processWithPrompt('ai_coach_recommendations', $resolvedPrompt, $aiContext);
            $providerStatus = $this->aiService->getLastProviderStatus();
            $recommendations = $this->parseRecommendationsResponse($response, $allowedTargetIds, $targetTitlesById, $mode);
            $usedDeterministicFallback = false;
            if (!$this->hasRecommendationItems($recommendations)) {
                error_log('AICoach::generateRecommendations received an empty AI response; using deterministic fallback recommendations.');
                $recommendations = $this->buildDeterministicFallbackRecommendations($metrics, $featureUsage, $allowedTargetIds, $mode, $operatingContext);
                $usedDeterministicFallback = true;
            }
            $recommendations = $this->applyRoleAwarePrioritization($recommendations, $roleProfile);
            $recommendations = $this->relevanceScorer->filterIrrelevantRecommendations($recommendations, $operatingContext);
            $recommendations = $this->skillTaskTemplates->applyTaskTemplates($recommendations, $operatingContext);
            $recommendations = $this->restoreFoundationSetupRecommendations($recommendations, $operatingContext, $mode);
            $recommendations = $this->appendMarketplaceRecommendations($recommendations, $operatingContext);
            if (!$this->hasRecommendationItems($recommendations)) {
                error_log('AICoach::generateRecommendations filters removed all recommendation items; using deterministic fallback recommendations.');
                $recommendations = $this->buildDeterministicFallbackRecommendations($metrics, $featureUsage, $allowedTargetIds, $mode, $operatingContext);
                $usedDeterministicFallback = true;
            }
            $generationStatus = $this->buildGenerationStatus(
                $usedDeterministicFallback
                    ? 'deterministic_fallback'
                    : (!empty($providerStatus['cache_hit']) ? 'cached_ai' : 'live_ai'),
                $providerStatus
            );
            $recommendations = $this->attachEvidenceSignals(
                $recommendations,
                $metrics,
                $featureUsage,
                $activeTargets,
                $operatingContext,
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            );
            $operatingContext['goal_state']['goal_relevance_score'] = $this->deriveGoalRelevanceScore($recommendations);
            $decision = $this->qualificationPolicy->evaluateAdviceEligibility($operatingContext);
            $guidanceRunId = $this->qualificationPolicy->logGuidanceRun(
                $userId,
                'coach',
                $mode,
                $decision,
                array_merge($operatingContext, [
                    'prompt_key' => $promptKey,
                    'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
                    'context_bundle_summary' => $this->contextAssembly->summarizeBundle($bundle),
                    'context_bundle_quality' => $contextBundleQuality,
                    'role_profile' => $roleProfile,
                    'user_work_context' => $userWorkContext,
                ]),
                array_merge($recommendations, [
                    'prompt_key' => $promptKey,
                    'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
                    'context_bundle_quality' => $contextBundleQuality,
                    'role_profile' => $roleProfile,
                    'role_summary' => $roleProfile['summary'] ?? '',
                    'user_work_context_summary' => $this->userWorkContext->summarize($userWorkContext),
                    'mode_variant' => $modeVariant,
                    'lean_canvas_enabled' => $leanCanvasEnabled,
                    'lean_canvas_completeness' => $leanCanvasCompleteness,
                    'lean_canvas_missing_blocks' => $leanCanvasMissingBlocks,
                ])
            );
            $recommendations = $this->attachRecommendationKeys($recommendations, $guidanceRunId);
            $recommendations = $this->applyLeanCanvasMetadata(
                $recommendations,
                $modeVariant,
                $leanCanvasEnabled,
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            );
            $recommendations = $this->attachTrustAndExplanationSignals(
                $recommendations,
                $decision,
                $modeVariant,
                $leanCanvasEnabled
            );
            [$recommendations, $feedbackSummary] = $this->applyFeedbackAwareRanking($userId, $recommendations);
            $recommendations['diagnostics'] = [
                'mode' => $mode,
                'confidence_score' => (float) ($decision['confidence_score'] ?? 1.0),
                'context_quality_score' => $decision['context_quality_score'],
                'goal_relevance_score' => (float) ($operatingContext['goal_state']['goal_relevance_score'] ?? 0.0),
                'decision' => $decision['decision'],
                'missing_context_flags' => $operatingContext['missing_context_flags'],
                'using_goals' => array_values(array_filter(array_map(static fn(array $goal): string => (string) ($goal['title'] ?? ''), (array) $operatingContext['goal_state']['active_goals']))),
                'suppressed_count' => count((array) ($recommendations['suppressed_recommendations'] ?? [])),
                'capability_gaps' => (array) ($operatingContext['feature_state']['readiness_gaps'] ?? []),
                'guidance_run_id' => $guidanceRunId,
                'prompt_key' => $promptKey,
                'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
                'context_bundle_quality' => $contextBundleQuality,
                'role_profile' => $roleProfile,
                'role_summary' => $roleProfile['summary'] ?? '',
                'user_work_context_summary' => $this->userWorkContext->summarize($userWorkContext),
                'mode_variant' => $modeVariant,
                'lean_canvas_enabled' => $leanCanvasEnabled,
                'lean_canvas_completeness' => $leanCanvasCompleteness,
                'lean_canvas_missing_blocks' => $leanCanvasMissingBlocks,
                'operating_maturity' => (string) ($recommendations['operating_maturity'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY),
                'operating_maturity_context' => (array) ($recommendations['operating_maturity_context'] ?? []),
                'assumption_conflicts' => (array) ($recommendations['assumption_conflicts'] ?? []),
                'role_priority_focus' => (array) ($roleProfile['priority_focus'] ?? []),
                'role_prompt_bias' => (array) ($roleProfile['prompt_bias'] ?? []),
                'role_threshold_recommendations' => $decision['role_threshold_recommendations']
                    ?? $this->thresholds->getRoleAwareThresholdRecommendation('coach', 'advice', $userId, $roleProfile),
                'role_decision_effects' => [
                    'ranking_strategy' => 'role_weighted_keyword_sort',
                    'top_ranked_titles' => array_values(array_map(
                        static fn(array $item): string => (string) ($item['title'] ?? ''),
                        array_slice((array) ($recommendations['priorities'] ?? []), 0, 3)
                    )),
                ],
                'feedback_summary' => $feedbackSummary,
            ];
            $recommendations = $this->attachGenerationStatus($recommendations, $generationStatus);
            return $recommendations;
        } catch (\Throwable $e) {
            error_log('AICoach::generateRecommendations error: ' . $e->getMessage());
            $fallback = $this->buildDeterministicFallbackRecommendations($metrics, $featureUsage, $allowedTargetIds, $mode, $operatingContext ?? []);
            $fallback = $this->attachEvidenceSignals(
                $fallback,
                $metrics,
                $featureUsage,
                [],
                $operatingContext ?? [],
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            );
            return $this->attachGenerationStatus($this->applyLeanCanvasMetadata(
                $this->attachRecommendationKeys($fallback, 0),
                $modeVariant,
                $leanCanvasEnabled,
                $leanCanvasCompleteness,
                $leanCanvasMissingBlocks
            ), $this->buildGenerationStatus('deterministic_fallback', [
                'message' => 'AI Coach fell back after an internal generation error.',
                'fallback_used' => true,
            ]));
        }
    }

    /**
     * Generate deterministic recommendations for automatic starter-task seeding.
     * This intentionally avoids remote AI calls so the daily seed path stays fast.
     */
    public function generateStarterTaskRecommendations(int $userId, string $mode = '1'): array
    {
        $metrics = ['summary' => []];
        $featureUsage = [
            'tasks_used' => false,
            'task_count' => 0,
            'contact_count' => 0,
            'workflows_used' => false,
            'deals_used' => false,
            'email_templates_count' => 0,
        ];

        try {
            $metrics = $this->getMetricsContext();
            $featureUsage = $this->getFeatureUsageContext($userId);
            $operatingContext = $this->operatingContext->buildForSurface($userId, 'coach');
            $strategyProfile = new UserStrategyProfile();
            $leanCanvasCompleteness = $strategyProfile->getLeanCanvasCompleteness($userId);
            $leanCanvasMissingBlocks = $strategyProfile->getLeanCanvasMissingBlocks($userId);
            if (!$this->skillTaskTemplates->hasReadyAdviceSkill($operatingContext)) {
                $setupRecommendations = $this->attachEvidenceSignals(
                    $this->buildSkillSetupOnlyRecommendations($operatingContext),
                    $metrics,
                    $featureUsage,
                    [],
                    $operatingContext,
                    $leanCanvasCompleteness,
                    $leanCanvasMissingBlocks
                );
                return $this->attachRecommendationKeys($setupRecommendations, 0);
            }
            $recommendations = $this->getFallbackRecommendations($metrics, $featureUsage, [], $mode);
            $recommendations = $this->applyFoundationStarterRecommendations($recommendations, $operatingContext, $mode);
            $recommendations = $this->skillTaskTemplates->applyTaskTemplates($recommendations, $operatingContext);
            $recommendations = $this->appendMarketplaceRecommendations($recommendations, $operatingContext);
            $recommendations = $this->attachEvidenceSignals($recommendations, $metrics, $featureUsage, [], $operatingContext, $leanCanvasCompleteness, $leanCanvasMissingBlocks);

            return $this->attachRecommendationKeys($recommendations, 0);
        } catch (\Throwable $e) {
            error_log('AICoach::generateStarterTaskRecommendations error: ' . $e->getMessage());
            return $this->attachRecommendationKeys($this->getFallbackRecommendations($metrics, $featureUsage, [], $mode), 0);
        }
    }

    /**
     * Gather metrics from AnalyticsDashboard for context
     */
    private function getMetricsContext(): array
    {
        $today = $this->analytics->getRealTimeMetrics('today');
        $week = $this->analytics->getRealTimeMetrics('week');
        $month = $this->analytics->getRealTimeMetrics('month');
        return [
            'today' => $today,
            'week' => $week,
            'month' => $month,
            'summary' => [
                'leads_today' => $today['leads'] ?? 0,
                'emails_sent_week' => $week['emails_sent'] ?? 0,
                'emails_opened' => $today['emails_opened'] ?? 0,
                'form_submissions_month' => $month['form_submissions'] ?? 0,
            ]
        ];
    }

    /**
     * Get feature usage context (from task counts, activities, etc.)
     */
    private function getFeatureUsageContext(int $userId): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $taskCount = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM tasks
             WHERE workspace_id = ?
               AND (created_by = ? OR assigned_to = ?)",
            [$workspaceId, $userId, $userId]
        )['count'] ?? 0;

        $contactCount = (int) (Database::queryOne(
            "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ?",
            [$workspaceId]
        )['count'] ?? 0);
        $hasWorkflows = (int) (Database::queryOne(
            "SELECT COUNT(*) as count FROM workflows WHERE workspace_id = ?",
            [$workspaceId]
        )['count'] ?? 0) > 0;
        $hasDeals = (int) (Database::queryOne(
            "SELECT COUNT(*) as count FROM deals WHERE workspace_id = ?",
            [$workspaceId]
        )['count'] ?? 0) > 0;
        $emailTemplates = (int) (Database::queryOne(
            "SELECT COUNT(*) as count
             FROM email_templates et
             WHERE EXISTS (
                 SELECT 1
                 FROM workspace_memberships wm
                 WHERE wm.workspace_id = ?
                   AND wm.user_id = et.created_by
                   AND wm.membership_status = 'active'
             )",
            [$workspaceId]
        )['count'] ?? 0);

        return [
            'tasks_used' => $taskCount > 0,
            'task_count' => $taskCount,
            'contact_count' => $contactCount,
            'workflows_used' => $hasWorkflows,
            'deals_used' => $hasDeals,
            'email_templates_count' => $emailTemplates,
        ];
    }

    private function getActiveTargetsContext(int $userId): array
    {
        try {
            $targets = new Targets();
            // Only active targets matter for day-to-day recommendations
            return $targets->getAll(['user_id' => $userId, 'status' => 'active'], 10, 0);
        } catch (\Throwable $e) {
            error_log('AICoach::getActiveTargetsContext error: ' . $e->getMessage());
            return [];
        }
    }

    private function buildRecommendationPrompt(array $metrics, array $featureUsage, array $activeTargets, string $mode = '2', int $userId = 0): string
    {
        $operatingContext = $userId > 0 ? $this->operatingContext->buildForSurface($userId, 'coach') : [];
        $readinessBlock = $this->buildReadinessBlock($operatingContext);
        if ($mode === '1') {
            return $this->buildFoundationModePrompt($metrics, $featureUsage, $activeTargets, $userId) . "\n\n" . $readinessBlock;
        }
        return $this->buildOperationsModePrompt($metrics, $featureUsage, $activeTargets) . "\n\n" . $readinessBlock;
    }

    private function buildLeanCanvasRecommendationPrompt(array $metrics, array $featureUsage, array $activeTargets, string $mode = '2', int $userId = 0): string
    {
        $basePrompt = $this->buildRecommendationPrompt($metrics, $featureUsage, $activeTargets, $mode, $userId);
        $strategyProfile = new UserStrategyProfile();
        $leanCanvasContext = $userId > 0 ? $strategyProfile->getLeanCanvasContextForPrompt($userId) : '';
        $missingBlocks = $userId > 0 ? $strategyProfile->getLeanCanvasMissingBlocks($userId) : [];
        $completeness = $userId > 0 ? $strategyProfile->getLeanCanvasCompleteness($userId) : 0;
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $startupJourneyContext = [];
        $financeContext = [];
        $founderLoopContext = [];
        if ($workspaceId > 0 && $userId > 0) {
            try {
                if (Database::tableExists('startup_journeys')) {
                    $startupJourneyContext = (new StartupJourneyService())->getContextForAI($workspaceId, $userId);
                }
            } catch (\Throwable $e) {
                error_log('AICoach Clarity Journey context unavailable: ' . $e->getMessage());
            }

            try {
                if (Database::tableExists('finance_expenses')) {
                    $financeContext = (new FounderFinanceService())->aiContext($workspaceId, $userId);
                }
            } catch (\Throwable $e) {
                error_log('AICoach finance context unavailable: ' . $e->getMessage());
            }

            try {
                if (Database::tableExists('founder_weekly_reviews')) {
                    $founderLoopContext = (new FounderOperatingLoopService())->contextForAI($workspaceId, $userId);
                }
            } catch (\Throwable $e) {
                error_log('AICoach founder operating loop context unavailable: ' . $e->getMessage());
            }
        }

        return $basePrompt . "\n\n" .
            "CLARITY JOURNEY COACHING RULES:\n" .
            "- Use Clarity Journey as the primary strategy-development frame; Lean Canvas is one compatibility stage inside it.\n" .
            "- Prioritize the next incomplete journey stage before advanced optimization.\n" .
            "- Call out contradictions between problem, segments, UVP, solution, channels, metrics, and economics.\n" .
            "- Recommend validation experiments, MVP tests, channel tests, metric definitions, and CRM execution tasks tied to a journey gap.\n" .
            "- Use Finance context to flag runway, budget, CAC, margin, break-even, receivables, payables, and accounting-report quality before recommending spend.\n" .
            "- Prefer recommendations that move the owner through the Founder Operating Loop: setup, business model, budget, runway, pricing, first customers, weekly review.\n" .
            "- Keep the same JSON output shape as the standard coach response.\n\n" .
            ($startupJourneyContext !== [] ? "Clarity Journey context: " . json_encode($startupJourneyContext, JSON_UNESCAPED_SLASHES) . "\n\n" : '') .
            ($financeContext !== [] ? "Finance context: " . json_encode($financeContext, JSON_UNESCAPED_SLASHES) . "\n\n" : '') .
            ($founderLoopContext !== [] ? "Founder Operating Loop context: " . json_encode($founderLoopContext, JSON_UNESCAPED_SLASHES) . "\n\n" : '') .
            ($leanCanvasContext !== '' ? $leanCanvasContext . "\n\n" : '') .
            "Lean Canvas missing blocks: " . ($missingBlocks === [] ? 'none' : implode(', ', $missingBlocks)) . "\n" .
            "Lean Canvas completeness: " . $completeness . "%";
    }

    /**
     * Foundation Mode: Business mentor + operations auditor. Covers business fundamentals.
     */
    private function buildFoundationModePrompt(array $metrics, array $featureUsage, array $activeTargets, int $userId = 0): string
    {
        $businessContext = $this->getBusinessContext();
        $ideaValidationBlock = '';
        $budgetBlock = '';
        $strategyBlock = '';
        if ($userId > 0) {
            try {
                $ideaCtx = (new IdeaValidationContext())->getContextForPrompt($userId);
                if ($ideaCtx !== '') {
                    $ideaValidationBlock = "\n\n" . $ideaCtx;
                }
            } catch (\Throwable $e) {
                $ideaValidationBlock .= "\n\nIdea validation context: unavailable (missing setup).";
            }
            try {
                $budgetCtx = (new BeginnerBudget())->getContextForPrompt($userId);
                if ($budgetCtx !== '') {
                    $budgetBlock = "\n\n" . $budgetCtx;
                }
            } catch (\Throwable $e) {
                $budgetBlock .= "\n\nBudget context: unavailable (missing setup).";
            }
            try {
                $onboardingCtx = (new OnboardingProgress())->getContextForPrompt($userId);
                if ($onboardingCtx !== '') {
                    $budgetBlock .= "\n\n" . $onboardingCtx;
                }
            } catch (\Throwable $e) {
                $budgetBlock .= "\n\nOnboarding progress: unavailable (missing setup).";
            }
            $hoursPerWeek = $this->preferences->getHoursPerWeekSales($userId);
            if ($hoursPerWeek !== null && $hoursPerWeek !== '') {
                $budgetBlock .= "\n\nTime capacity: " . $hoursPerWeek . " hours per week for sales/marketing. Suggest realistic targets (e.g. fewer outreach tasks for 1-5 hrs/week).";
            }
            try {
                $strategyCtx = (new UserStrategyProfile())->getContextForPrompt($userId);
                if ($strategyCtx !== '') {
                    $strategyBlock = "\n\n" . $strategyCtx;
                }
            } catch (\Throwable $e) {
                $strategyBlock .= "\n\nUser GTM strategy: unavailable (missing setup).";
            }
        }
        $summary = $metrics['summary'] ?? [];
        $m = [
            'leads_today' => $summary['leads_today'] ?? 0,
            'emails_sent_week' => $summary['emails_sent_week'] ?? 0,
            'emails_opened' => $summary['emails_opened'] ?? 0,
            'form_submissions_month' => $summary['form_submissions_month'] ?? 0,
        ];
        $fu = $featureUsage;

        $targetsLines = [];
        foreach ($activeTargets as $t) {
            $targetsLines[] = '- [id ' . ((int) ($t['id'] ?? 0)) . '] ' .
                ($t['title'] ?? 'Untitled') .
                ' (' . ($t['target_type'] ?? 'custom') . '): ' .
                ((float) ($t['current_value'] ?? 0)) . '/' . ((float) ($t['target_value'] ?? 0)) .
                ($t['unit'] ? (' ' . $t['unit']) : '') .
                ', progress ' . ((float) ($t['progress_percentage'] ?? 0)) . '%' .
                ', deadline ' . ($t['target_date'] ?? '') .
                ', days_remaining ' . (($t['days_remaining'] ?? '') === null ? 'n/a' : (string) $t['days_remaining']) .
                ', status ' . ($t['status_category'] ?? 'on_track');
        }
        $targetsBlock = empty($targetsLines) ? '- none' : implode("\n", $targetsLines);

        return 'You are Clarity, the AI co-founder inside an AI Business Incubator. Act proactively, structured, and slightly firm. Do not allow foundational gaps to go unaddressed.

Check: business model clarity, offer definition, pricing logic, sales process structure, customer segmentation, marketing channels, follow-up discipline, revenue forecasting, KPI tracking, pipeline hygiene, lead quality. When idea validation context is provided, use it to validate assumptions before suggesting scaling (e.g. "Validate your value proposition before scaling ads"). When budget is provided, suggest budget-aware recommendations (e.g. "Set a monthly marketing budget", "Your break-even is X deals - focus on high-intent leads first").

Deliverability guardrail for outbound email:
- For early stage users, set worry level using volume/risk context: low concern under ~30 emails/day and mostly warm contacts; moderate concern around 50-150/day or aggressive automation.
- Always prioritize SPF, DKIM, DMARC before scaling outreach.
- Recommend gradual ramp-up (example: week1 10/day, week2 20/day, week3 30/day) instead of sudden volume spikes.
- Prefer a separate sending domain/subdomain for campaigns to protect the primary brand domain reputation.
- If tradeoffs exist, prioritize activation, conversion, retention, and ICP clarity before high-volume cold outreach.

BUSINESS CONTEXT:
' . $businessContext . $ideaValidationBlock . $budgetBlock . $strategyBlock . '

METRICS:
- Leads today: ' . ($m['leads_today'] ?? 0) . '
- Emails sent (this week): ' . ($m['emails_sent_week'] ?? 0) . '
- Emails opened (recent): ' . ($m['emails_opened'] ?? 0) . '
- Form submissions (this month): ' . ($m['form_submissions_month'] ?? 0) . '

FEATURE USAGE:
- Tasks used: ' . ($fu['tasks_used'] ? 'yes' : 'no') . ' (count: ' . $fu['task_count'] . ')
- Contacts in growth system: ' . $fu['contact_count'] . '
- Workflows: ' . ($fu['workflows_used'] ? 'yes' : 'no') . '
- Deals pipeline: ' . ($fu['deals_used'] ? 'yes' : 'no') . '
- Email templates: ' . $fu['email_templates_count'] . '

ACTIVE TARGETS (use when relevant; set target_id to one of the ids below if applicable):
' . $targetsBlock . '

Respond with ONLY a valid JSON object (no markdown, no code block wrapper) with this exact structure:
{
  "why_this_matters": "string",
  "foundation_gaps": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "suggested_subtasks": ["string"] }
  ],
  "priorities": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "target_id": 0, "suggested_subtasks": ["string"] }
  ],
  "quick_wins": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "target_id": 0, "suggested_subtasks": ["string"] }
  ],
  "missing_features": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "target_id": 0, "suggested_subtasks": ["string"] }
  ]
}

Rules:
- foundation_gaps: business fundamentals missing or weak (offer, pricing, sales process, segmentation, etc.). Max 3. Flag gaps first.
- priorities: top 3 high-impact items to focus on today.
- quick_wins: low effort, high impact (max 3).
- missing_features: growth system features not yet used (max 3).
- why_this_matters: 1-2 short sentences. No fluff.
- reason: 1 line, max ~120 characters.
- suggested_subtasks: provide 3-5 concrete CRM actions whenever the recommendation is actionable. Each item must mention what to update and what should be visible after saving; avoid generic items like "Configure", "Review", or repeating the task title.
- Pricing/product tasks: make subtasks explicitly cover defining pricing fields or structure, saving the product/offer configuration, and verifying the saved pricing is visible on the CRM record or preview.
- target_id: 0 if not tied to a target; otherwise use listed active target id.
- Use only the impact/effort values given.
- Workflows: If workflows_used is no, suggest "Try automation with Workflows" in missing_features with subtasks like "Use Welcome New Contacts template". If workflows_used is yes and they have deals, suggest "Add Deal Follow-up workflow" in quick_wins.
- First-goal scaffolding: When ACTIVE TARGETS is empty (- none), include in foundation_gaps or quick_wins a "Set your first goal" item. Suggest creating a target (e.g. "5 qualified leads this month" or "1 closed deal"). Use suggested_subtasks like "Create a target in Targets", "Start with 5 qualified leads this month".
- Communication policy: Prefer Now/Next/Later prioritization, avoid hype, avoid competitor framing unless explicitly requested, and guide calm strategic execution.';
    }

    /**
     * Operations Mode: Execution-focused guidance. Suggestive, not authoritative.
     */
    private function buildOperationsModePrompt(array $metrics, array $featureUsage, array $activeTargets): string
    {
        $summary = $metrics['summary'] ?? [];
        $m = [
            'leads_today' => $summary['leads_today'] ?? 0,
            'emails_sent_week' => $summary['emails_sent_week'] ?? 0,
            'emails_opened' => $summary['emails_opened'] ?? 0,
            'form_submissions_month' => $summary['form_submissions_month'] ?? 0,
        ];
        $fu = $featureUsage;

        $targetsLines = [];
        foreach ($activeTargets as $t) {
            $targetsLines[] = '- [id ' . ((int) ($t['id'] ?? 0)) . '] ' .
                ($t['title'] ?? 'Untitled') .
                ' (' . ($t['target_type'] ?? 'custom') . '): ' .
                ((float) ($t['current_value'] ?? 0)) . '/' . ((float) ($t['target_value'] ?? 0)) .
                ($t['unit'] ? (' ' . $t['unit']) : '') .
                ', progress ' . ((float) ($t['progress_percentage'] ?? 0)) . '%,' .
                ' deadline ' . ($t['target_date'] ?? '') .
                ', days_remaining ' . (($t['days_remaining'] ?? '') === null ? 'n/a' : (string) $t['days_remaining']) .
                ', status ' . ($t['status_category'] ?? 'on_track');
        }
        $targetsBlock = empty($targetsLines) ? '- none' : implode("\n", $targetsLines);

        return 'You are Clarity, the AI co-founder inside an AI Business Incubator. Focus strictly on execution inside the growth system: activities, follow-ups, automations, tasks, deal progression, feature adoption. Do NOT question business model, pricing, or market positioning. Assume those are correct. Be suggestive and helpful, not authoritative.

METRICS:
- Leads today: ' . ($m['leads_today'] ?? 0) . '
- Emails sent (this week): ' . ($m['emails_sent_week'] ?? 0) . '
- Emails opened (recent): ' . ($m['emails_opened'] ?? 0) . '
- Form submissions (this month): ' . ($m['form_submissions_month'] ?? 0) . '

FEATURE USAGE:
- Tasks used: ' . ($fu['tasks_used'] ? 'yes' : 'no') . ' (count: ' . $fu['task_count'] . ')
- Contacts in growth system: ' . $fu['contact_count'] . '
- Workflows: ' . ($fu['workflows_used'] ? 'yes' : 'no') . '
- Deals pipeline: ' . ($fu['deals_used'] ? 'yes' : 'no') . '
- Email templates: ' . $fu['email_templates_count'] . '

ACTIVE TARGETS (use these when relevant; if a recommendation supports a target, set target_id to one of the ids below):
' . $targetsBlock . '

Respond with ONLY a valid JSON object (no markdown, no code block wrapper) with this exact structure:
{
  "why_this_matters": "string",
  "priorities": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "target_id": 0, "suggested_subtasks": ["string"] }
  ],
  "quick_wins": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "target_id": 0, "suggested_subtasks": ["string"] }
  ],
  "missing_features": [
    { "title": "string", "impact": "High|Medium|Low", "effort": "Low|Medium|High", "reason": "string", "target_id": 0, "suggested_subtasks": ["string"] }
  ]
}

Rules:
- priorities: top 3 high-impact items to focus on today.
- quick_wins: low effort, high impact (max 3).
- missing_features: growth system features not yet used that could help (max 3).
- why_this_matters: 1-2 short sentences max. No fluff.
- reason: 1 line, max ~120 characters. No essays.
- suggested_subtasks: provide 3-5 concrete CRM actions whenever the recommendation is actionable. Each item must mention the object being changed and the visible result after saving; avoid generic items like "Configure", "Review", or repeating the task title. Use empty array only when no meaningful CRM checklist exists.
- Pricing/product tasks: include subtasks for defining pricing fields or structure, saving the product/offer setup, and verifying the saved configuration is visible on the CRM record or preview.
- target_id: use 0 if not tied to a target; otherwise use one of the listed active target ids.
- Use only the impact/effort values given.
- Workflows: If workflows_used is no, include "Try automation with Workflows" in missing_features. If workflows_used is yes and they have deals, suggest "Add Deal Follow-up workflow" in quick_wins. If many contacts, suggest "Add Lead Nurturing workflow".
- Communication policy: Prefer Now/Next/Later prioritization, avoid hype, avoid competitor framing unless explicitly requested, and guide calm strategic execution.';
    }

    /**
     * Gather business context for Foundation mode (company profile, products).
     */
    private function getBusinessContext(): string
    {
        $lines = [];
        try {
            $profile = (new CompanyProfile())->get();
            if ($profile) {
                $lines[] = 'Company: ' . ($profile['company_name'] ?? 'Unknown');
                $lines[] = 'Description: ' . (trim($profile['company_description'] ?? '') ?: '(empty)');
                $lines[] = 'Mission: ' . (trim($profile['company_mission'] ?? '') ?: '(empty)');
                $ownerContext = trim($profile['owner_company_context'] ?? '');
                if ($ownerContext !== '') {
                    $lines[] = 'Owner context: ' . $ownerContext;
                }
                $lines[] = 'Industry: ' . ($profile['company_industry'] ?? '(not set)');
                $lines[] = 'Size: ' . ($profile['company_size'] ?? '(not set)');
                $icpJobTitles = trim($profile['icp_job_titles'] ?? '');
                $icpIndustries = trim($profile['icp_industries'] ?? '');
                $icpPainPoints = trim($profile['icp_pain_points'] ?? '');
                $icpChannels = trim($profile['icp_channels'] ?? '');
                if ($icpJobTitles !== '' || $icpIndustries !== '' || $icpPainPoints !== '' || $icpChannels !== '') {
                    $lines[] = 'ICP: job_titles=' . ($icpJobTitles ?: '(empty)') . ', industries=' . ($icpIndustries ?: '(empty)') . ', pain_points=' . ($icpPainPoints ?: '(empty)') . ', channels=' . ($icpChannels ?: '(empty)');
                }
            } else {
                $lines[] = 'Company profile: not configured';
            }
        } catch (\Throwable $e) {
            $lines[] = 'Company profile: unavailable';
        }

        try {
            $products = (new Products())->list();
            if (!empty($products)) {
                foreach ($products as $p) {
                    $lines[] = 'Product: ' . ($p['name'] ?? '') . ' | Pricing: ' . (trim($p['pricing_info'] ?? '') ?: '(empty)') . ' | Target: ' . (trim($p['target_audience'] ?? '') ?: '(empty)');
                }
            } else {
                $lines[] = 'Products: none defined';
            }
        } catch (\Throwable $e) {
            $lines[] = 'Products: unavailable';
        }

        try {
            $pipelineStages = Database::query(
                "SELECT stage, COUNT(*) as cnt FROM deals GROUP BY stage ORDER BY cnt DESC"
            );
            if (!empty($pipelineStages)) {
                $lines[] = 'Pipeline stages: ' . implode(', ', array_map(fn($r) => ($r['stage'] ?? '') . ' (' . ($r['cnt'] ?? 0) . ')', $pipelineStages));
            } else {
                $lines[] = 'Pipeline stages: no deals';
            }
        } catch (\Throwable $e) {
            $lines[] = 'Pipeline stages: unavailable';
        }

        return implode("\n", $lines);
    }

    private function parseRecommendationsResponse(string $response, array $allowedTargetIds = [], array $targetTitlesById = [], string $mode = '2'): array
    {
        $response = trim($response);
        // Strip markdown code block if present
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)```\s*$/m', $response, $m)) {
            $response = trim($m[1]);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'why_this_matters' => '',
                'priorities' => [],
                'quick_wins' => [],
                'missing_features' => [],
                'foundation_gaps' => [],
            ];
        }
        $out = [
            'why_this_matters' => (string) ($decoded['why_this_matters'] ?? ''),
            'priorities' => $this->normalizeRecommendationItems($decoded['priorities'] ?? [], $allowedTargetIds, $targetTitlesById),
            'quick_wins' => $this->normalizeRecommendationItems($decoded['quick_wins'] ?? [], $allowedTargetIds, $targetTitlesById),
            'missing_features' => $this->normalizeRecommendationItems($decoded['missing_features'] ?? [], $allowedTargetIds, $targetTitlesById),
            'foundation_gaps' => [],
        ];
        if ($mode === '1' && !empty($decoded['foundation_gaps'])) {
            $out['foundation_gaps'] = $this->normalizeFoundationGaps($decoded['foundation_gaps']);
        }
        return $out;
    }

    public function getDiagnostics(int $userId): array
    {
        $context = $this->operatingContext->buildForSurface($userId, 'coach');
        $decision = $this->qualificationPolicy->evaluateAdviceEligibility($context);
        return [
            'context' => $context,
            'qualification' => $decision,
            'runtime_control' => $this->runtimeControls->getEffectiveControl('coach'),
            'role_profile' => $this->roleProfiles->buildProfile($userId, $context),
            'user_work_context' => $this->userWorkContext->buildContext($userId, 'coach', $context),
        ];
    }

    private function buildRuntimeControlResponse(string $mode, array $control): array
    {
        $reason = trim((string) ($control['reason'] ?? ''));
        $message = $reason !== '' ? $reason : 'AI Coach is temporarily constrained by an administrator.';

        return [
            'why_this_matters' => $message,
            'priorities' => [],
            'quick_wins' => [],
            'missing_features' => [],
            'foundation_gaps' => [],
            'diagnostics' => [
                'mode' => $mode,
                'confidence_score' => 0.0,
                'context_quality_score' => 0.0,
                'goal_relevance_score' => 0.0,
                'decision' => (string) ($control['control_mode'] ?? 'paused') === 'diagnostics_only' ? 'suggest_only' : 'blocked',
                'missing_context_flags' => [],
                'using_goals' => [],
                'suppressed_count' => 0,
                'capability_gaps' => [],
                'guidance_run_id' => 0,
                'prompt_key' => null,
                'prompt_version' => null,
                'context_bundle_quality' => null,
                'runtime_control' => $control,
            ],
        ];
    }

    private function buildGenerationStatus(string $source, array $providerStatus = []): array
    {
        $allowedSources = ['live_ai', 'cached_ai', 'deterministic_fallback', 'readiness_blocked', 'runtime_blocked'];
        if (!in_array($source, $allowedSources, true)) {
            $source = 'deterministic_fallback';
        }

        $fallback = $source === 'deterministic_fallback' || $source === 'runtime_blocked' || !empty($providerStatus['fallback_used']);
        return [
            'source' => $source,
            'generated_at' => gmdate('c'),
            'cache_hit' => !empty($providerStatus['cache_hit']) || $source === 'cached_ai',
            'provider_message' => (string) ($providerStatus['message'] ?? ''),
            'fallback_used' => $fallback,
            'ai_status' => (new AIExecutionStatusService())->present($providerStatus, [
                'surface' => 'coach',
                'fallback' => $fallback,
                'source' => $source,
            ]),
        ];
    }

    private function attachGenerationStatus(array $recommendations, array $generationStatus): array
    {
        $recommendations['generation_status'] = $generationStatus;
        return $recommendations;
    }

    private function buildReadinessBlock(array $operatingContext): string
    {
        if (empty($operatingContext)) {
            return '';
        }
        $goals = array_map(static fn(array $goal): string => (string) ($goal['title'] ?? ''), (array) ($operatingContext['goal_state']['active_goals'] ?? []));
        $skillContracts = json_encode($operatingContext['installed_skill_contracts'] ?? [], JSON_PRETTY_PRINT);
        $startupJourney = (array) ($operatingContext['startup_journey_context'] ?? []);
        $journeyProgress = (array) ($startupJourney['progress'] ?? []);
        $journeyCompleted = (int) ($journeyProgress['completed'] ?? 0);
        $journeyTotal = (int) ($journeyProgress['total'] ?? 0);
        $journeyReady = $journeyTotal > 0 && $journeyCompleted >= $journeyTotal;
        $founderLoop = (array) ($operatingContext['founder_operating_loop_context'] ?? []);
        $founderLoopActive = !empty($founderLoop['latest_commitments'])
            || !empty($founderLoop['active_week']['has_saved_review'])
            || !in_array((string) ($founderLoop['weekly_review_status'] ?? 'not_started'), ['', 'not_started'], true);
        $currentJourneyStage = (string) ($startupJourney['current_stage_key'] ?? '');
        $currentLoopStep = (string) ($founderLoop['current_loop_step'] ?? '');
        $operatingMaturityContext = (array) ($operatingContext['operating_maturity'] ?? []);
        $operatingMaturity = (string) ($operatingMaturityContext['stage'] ?? (!$journeyReady
            ? 'pre_clarity_journey'
            : ($founderLoopActive ? 'founder_loop_active' : 'journey_complete_founder_loop_next')));
        $assumptionConflicts = (array) ($operatingContext['assumption_conflicts'] ?? []);
        $conflictLines = [];
        foreach (array_slice($assumptionConflicts, 0, 3) as $conflict) {
            $conflictLines[] = '- ' . (string) ($conflict['severity'] ?? 'medium') . ': ' . (string) ($conflict['operating_evidence'] ?? '') . ' Next: ' . (string) ($conflict['suggested_next_action'] ?? '');
        }
        return 'FEATURE READINESS MATRIX:
- Company profile ready: ' . (!empty($operatingContext['feature_state']['company_profile_ready']) ? 'yes' : 'no') . '
- Products priced: ' . (!empty($operatingContext['feature_state']['products_priced']) ? 'yes' : 'no') . '
- Invoicing configured: ' . (!empty($operatingContext['feature_state']['invoicing_ready']) ? 'yes' : 'no') . '
- Workflow graph ready: ' . (!empty($operatingContext['feature_state']['workflow_graph_ready']) ? 'yes' : 'no') . '
- Commercial automation ready: ' . (!empty($operatingContext['feature_state']['commercial_automation_ready']) ? 'yes' : 'no') . '
- Clarity Journey complete: ' . ($journeyReady ? 'yes' : 'no') . ($journeyTotal > 0 ? ' (' . $journeyCompleted . '/' . $journeyTotal . ')' : '') . '
- Current Clarity Journey stage: ' . ($currentJourneyStage !== '' ? $currentJourneyStage : 'unknown') . '
- Founder Loop active: ' . ($founderLoopActive ? 'yes' : 'no') . '
- Current Founder Loop step: ' . ($currentLoopStep !== '' ? $currentLoopStep : 'unknown') . '
- Operating maturity: ' . $operatingMaturity . '
- Assumption conflicts: ' . ($conflictLines === [] ? 'none' : "\n" . implode("\n", $conflictLines)) . '
- Missing context flags: ' . (empty($operatingContext['missing_context_flags']) ? 'none' : implode(', ', $operatingContext['missing_context_flags'])) . '
- Active goals: ' . (empty($goals) ? 'none' : implode(', ', $goals)) . '
- Installed skill contracts: ' . ($skillContracts ?: '[]') . '

Rules:
- Treat Clarity Journey as the canonical starting context; Lean Canvas is only one compatibility stage inside it.
- Before Clarity Journey is complete, send the user back to the next missing Journey stage instead of optimizing later operations.
- When Journey is complete and Founder Loop is not active, prioritize first-customer, first-deal, pricing, outreach, and learning-loop actions.
- When Founder Loop is active, prioritize weekly commitments, follow-up, first deals, pricing evidence, and review cadence before broad operating advice.
- When later CRM, finance, inbox, automation, marketing, or team evidence appears, broaden recommendations while flagging contradictions with the original Journey assumptions.
- AI Coach is an orchestrator. Only recommend business advice or business tasks sourced from ready installed skill contracts or explicit CRM operational evidence.
- Do not recommend features as if they are available when readiness says no.
- If a feature is unready but relevant, recommend configuring it first.
- If no active goals exist, include defining a concrete goal before advanced optimization.
- Do not overstate certainty when missing context flags are present.';
    }

    private function buildMinimalSkillOperatingContext(int $userId): array
    {
        $workspaceId = 0;
        try {
            $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        } catch (\Throwable $e) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }

        $workspaceSkills = [
            'installed' => [],
            'installed_keys' => [],
            'installed_skill_contracts' => [],
        ];
        if ($workspaceId > 0) {
            try {
                $workspaceSkills = (new WorkspaceSkillInstallService())->buildContextForWorkspace($workspaceId, $userId);
            } catch (\Throwable $e) {
                $workspaceSkills['error'] = 'Workspace skills context unavailable.';
            }
        }

        return [
            'identity' => [
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'role' => (string) ((Auth::user()['role'] ?? 'user')),
            ],
            'surface' => [
                'name' => 'coach',
                'current_page' => basename($_SERVER['PHP_SELF'] ?? ''),
            ],
            'workspace_skills' => $workspaceSkills,
            'workspace_modules' => $workspaceSkills,
            'installed_skill_contracts' => (array) ($workspaceSkills['installed_skill_contracts'] ?? []),
            'workspace_marketplace' => [
                'recommendations' => [],
                'setup_journeys' => [],
                'activation_bundles' => [],
                'top_recommendation_keys' => [],
                'visibility' => 'skipped_for_fast_setup',
                'generated_at' => gmdate('c'),
            ],
            'feature_state' => [
                'readiness_gaps' => [],
            ],
            'goal_state' => [
                'active_goals' => [],
            ],
            'missing_context_flags' => [],
        ];
    }

    private function hasReadySkillContract(array $operatingContext, string $skillKey): bool
    {
        foreach ((array) ($operatingContext['installed_skill_contracts'] ?? []) as $contract) {
            if ((string) ($contract['key'] ?? '') === $skillKey && !empty($contract['readiness']['ready'])) {
                return true;
            }
        }

        return false;
    }

    private function buildSkillSetupOnlyRecommendations(array $operatingContext): array
    {
        $recommendations = [
            'why_this_matters' => 'AI Coach is installed as an orchestrator. Add and complete at least one strategy, marketing, or custom workspace skill before it can provide business-growth recommendations.',
            'priorities' => [],
            'quick_wins' => [],
            'missing_features' => [
                [
                    'title' => 'Complete one advice skill for AI Coach',
                    'impact' => 'High',
                    'effort' => 'Medium',
                    'reason' => 'Coach needs a ready strategy or marketing skill before it can create business-growth recommendations.',
                    'target_id' => 0,
                    'target_title' => '',
                    'suggested_subtasks' => [
                        'Open Workspace Marketplace and choose Lean Canvas, Campaign Manager, or a custom advice skill',
                        'Complete the required setup fields for that skill',
                        'Confirm the skill status shows ready',
                        'Return to AI Coach and refresh recommendations',
                    ],
                    'marketplace_skill_key' => WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    'marketplace_setup_url' => 'workspace_skills.php?source=coach&marketplace_skill=' . WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                    'marketplace_cta_label' => 'Open Marketplace',
                    'marketplace_feedback_enabled' => false,
                    'source_recommendation_type' => 'coach_setup_required',
                ],
            ],
            'foundation_gaps' => [],
        ];

        return $this->appendMarketplaceRecommendations($recommendations, $operatingContext);
    }

    private function deriveGoalRelevanceScore(array $recommendations): float
    {
        $scores = [];
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $bucket) {
            foreach ((array) ($recommendations[$bucket] ?? []) as $item) {
                if (isset($item['goal_relevance_score'])) {
                    $scores[] = (float) $item['goal_relevance_score'];
                }
            }
        }
        if ($scores === []) {
            return 0.0;
        }
        return array_sum($scores) / count($scores);
    }

    private function hasRecommendationItems(array $recommendations): bool
    {
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $bucket) {
            if (!empty($recommendations[$bucket]) && is_array($recommendations[$bucket])) {
                return true;
            }
        }

        return false;
    }

    private function buildDeterministicFallbackRecommendations(
        array $metrics,
        array $featureUsage,
        array $allowedTargetIds,
        string $mode,
        array $operatingContext
    ): array {
        $fallback = $this->getFallbackRecommendations($metrics, $featureUsage, $allowedTargetIds, $mode);
        $fallback = $this->skillTaskTemplates->applyTaskTemplates($fallback, $operatingContext);
        $fallback = $this->restoreFoundationSetupRecommendations($fallback, $operatingContext, $mode);

        return $this->appendMarketplaceRecommendations($fallback, $operatingContext);
    }

    private function attachEvidenceSignals(
        array $recommendations,
        array $metrics,
        array $featureUsage,
        array $activeTargets,
        array $operatingContext,
        int $leanCanvasCompleteness,
        array $leanCanvasMissingBlocks
    ): array {
        $maturityContext = $this->maturityContext($operatingContext);
        $assumptionConflicts = $this->assumptionConflicts($operatingContext);
        $recommendations = $this->applyOperatingMaturityRecommendations($recommendations, $operatingContext, $maturityContext, $assumptionConflicts);
        $summary = (array) ($metrics['summary'] ?? []);
        $sourceContext = [
            'leads_today' => (int) ($summary['leads_today'] ?? 0),
            'emails_sent_week' => (int) ($summary['emails_sent_week'] ?? 0),
            'form_submissions_month' => (int) ($summary['form_submissions_month'] ?? 0),
            'task_count' => (int) ($featureUsage['task_count'] ?? 0),
            'contact_count' => (int) ($featureUsage['contact_count'] ?? 0),
            'workflows_used' => !empty($featureUsage['workflows_used']),
            'deals_used' => !empty($featureUsage['deals_used']),
            'email_templates_count' => (int) ($featureUsage['email_templates_count'] ?? 0),
            'active_target_count' => count($activeTargets),
            'readiness_gaps' => array_values((array) ($operatingContext['feature_state']['readiness_gaps'] ?? [])),
            'lean_canvas_completeness' => $leanCanvasCompleteness,
            'lean_canvas_missing_blocks' => array_values($leanCanvasMissingBlocks),
        ];

        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            $recommendations[$section] = array_map(function (array $item) use ($section, $sourceContext, $leanCanvasMissingBlocks): array {
                $text = strtolower(trim((string) ($item['title'] ?? '') . ' ' . (string) ($item['reason'] ?? '')));
                $evidence = [];

                if ((int) ($item['target_id'] ?? 0) > 0 && trim((string) ($item['target_title'] ?? '')) !== '') {
                    $evidence[] = ['label' => 'Target', 'value' => (string) $item['target_title']];
                }
                if (str_contains($text, 'lead') || str_contains($text, 'contact') || str_contains($text, 'prospect')) {
                    $evidence[] = ['label' => 'Contacts', 'value' => (string) $sourceContext['contact_count']];
                    $evidence[] = ['label' => 'Leads today', 'value' => (string) $sourceContext['leads_today']];
                }
                if (str_contains($text, 'email') || str_contains($text, 'outreach') || str_contains($text, 'follow')) {
                    $evidence[] = ['label' => 'Emails this week', 'value' => (string) $sourceContext['emails_sent_week']];
                    $evidence[] = ['label' => 'Email templates', 'value' => (string) $sourceContext['email_templates_count']];
                }
                if (str_contains($text, 'workflow') || str_contains($text, 'automation') || str_contains($text, 'template')) {
                    $evidence[] = ['label' => 'Workflows', 'value' => !empty($sourceContext['workflows_used']) ? 'Configured' : 'Not configured'];
                }
                if (str_contains($text, 'deal') || str_contains($text, 'pipeline') || str_contains($text, 'close')) {
                    $evidence[] = ['label' => 'Deals', 'value' => !empty($sourceContext['deals_used']) ? 'In use' : 'Not in use'];
                    $evidence[] = ['label' => 'Active targets', 'value' => (string) $sourceContext['active_target_count']];
                }
                if (!empty($item['marketplace_skill_key'])) {
                    $evidence[] = ['label' => 'Marketplace skill', 'value' => (string) $item['marketplace_skill_key']];
                    $progress = (array) ($item['marketplace_setup_progress'] ?? []);
                    if (!empty($progress['total'])) {
                        $evidence[] = ['label' => 'Setup progress', 'value' => (int) ($progress['completed'] ?? 0) . '/' . (int) $progress['total']];
                    }
                }
                if (str_contains($text, 'lean') || str_contains($text, 'canvas') || str_contains($text, 'strategy') || str_contains($text, 'validation')) {
                    $evidence[] = ['label' => 'Lean Canvas', 'value' => (string) $sourceContext['lean_canvas_completeness'] . '% complete'];
                    if ($leanCanvasMissingBlocks !== []) {
                        $evidence[] = ['label' => 'Missing blocks', 'value' => implode(', ', array_slice($leanCanvasMissingBlocks, 0, 3))];
                    }
                }

                if ($evidence === []) {
                    $evidence[] = ['label' => 'Tasks', 'value' => (string) $sourceContext['task_count']];
                    $evidence[] = ['label' => 'Contacts', 'value' => (string) $sourceContext['contact_count']];
                    if (!empty($sourceContext['readiness_gaps'])) {
                        $evidence[] = ['label' => 'Setup gaps', 'value' => implode(', ', array_slice((array) $sourceContext['readiness_gaps'], 0, 2))];
                    }
                }

                $item['evidence'] = array_slice($evidence, 0, 4);
                $item['source_context'] = array_merge($sourceContext, [
                    'source_section' => $section,
                    'source_recommendation_type' => (string) ($item['source_recommendation_type'] ?? $section),
                ]);
                return $item;
            }, array_values((array) ($recommendations[$section] ?? [])));
        }

        $recommendations['operating_maturity'] = (string) ($maturityContext['stage'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY);
        $recommendations['operating_maturity_context'] = $maturityContext;
        $recommendations['assumption_conflicts'] = $assumptionConflicts;
        $recommendations['financial_evidence'] = $this->financialEvidenceSummary($operatingContext, $assumptionConflicts);

        return $recommendations;
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @return array<string,mixed>
     */
    private function maturityContext(array $operatingContext): array
    {
        $existing = (array) ($operatingContext['operating_maturity'] ?? []);
        if ($existing !== []) {
            return $existing;
        }
        $workspaceId = (int) ($operatingContext['identity']['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        $userId = (int) ($operatingContext['identity']['user_id'] ?? 0);
        return (new AICoachOperatingMaturityService())->determine($workspaceId, $userId, $operatingContext);
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @return list<array<string,mixed>>
     */
    private function assumptionConflicts(array $operatingContext): array
    {
        $existing = (array) ($operatingContext['assumption_conflicts'] ?? []);
        if ($existing !== []) {
            return array_values($existing);
        }
        $workspaceId = (int) ($operatingContext['identity']['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        $userId = (int) ($operatingContext['identity']['user_id'] ?? 0);
        return (new AICoachAssumptionConflictService())->detect($workspaceId, $userId, $operatingContext);
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @param list<array<string,mixed>> $assumptionConflicts
     * @return array<string,mixed>
     */
    private function financialEvidenceSummary(array $operatingContext, array $assumptionConflicts): array
    {
        $finance = (array) ($operatingContext['finance_context'] ?? []);
        $founderLoop = (array) ($operatingContext['founder_operating_loop_context'] ?? []);
        $pricing = (array) ($founderLoop['pricing_evidence'] ?? []);
        $financeConflicts = array_values(array_filter($assumptionConflicts, static function (array $conflict): bool {
            return in_array((string) ($conflict['type'] ?? ''), ['pricing', 'revenue_model', 'cost_structure', 'experiment_budget', 'runway', 'cac'], true);
        }));

        $summary = [
            'period' => (array) ($finance['period'] ?? []),
            'currency' => (string) ($finance['currency'] ?? 'USD'),
            'revenue_this_month' => (float) ($finance['money_in'] ?? 0),
            'expenses_this_month' => (float) ($finance['money_out'] ?? 0),
            'runway_months' => array_key_exists('runway_months', $finance) && $finance['runway_months'] !== null ? (float) $finance['runway_months'] : null,
            'target_deal_value' => (float) ($finance['target_deal_value'] ?? ($pricing['target_deal_value'] ?? 0)),
            'avg_paid_invoice' => array_key_exists('avg_paid_invoice', $finance) && $finance['avg_paid_invoice'] !== null
                ? (float) $finance['avg_paid_invoice']
                : (array_key_exists('average_paid_invoice', $pricing) && $pricing['average_paid_invoice'] !== null ? (float) $pricing['average_paid_invoice'] : null),
            'paid_invoice_count' => (int) ($finance['paid_invoice_count'] ?? 0),
            'paid_customer_count' => (int) ($finance['paid_customer_count'] ?? 0),
            'guidance_flags' => array_values((array) ($finance['guidance_flags'] ?? [])),
            'pricing_warnings' => array_values((array) ($founderLoop['pricing_warnings'] ?? [])),
            'financial_next_actions' => array_slice(array_values((array) ($founderLoop['financial_next_actions'] ?? [])), 0, 3),
            'top_conflict' => (array) ($financeConflicts[0] ?? []),
        ];

        return array_filter($summary, static function ($value): bool {
            if (is_array($value)) {
                return $value !== [];
            }
            return $value !== null && $value !== '';
        });
    }

    /**
     * @param array<string,mixed> $recommendations
     * @param array<string,mixed> $operatingContext
     * @param array<string,mixed> $maturityContext
     * @param list<array<string,mixed>> $assumptionConflicts
     * @return array<string,mixed>
     */
    private function applyOperatingMaturityRecommendations(array $recommendations, array $operatingContext, array $maturityContext, array $assumptionConflicts): array
    {
        if ($this->hasCoachSetupRequiredRecommendation($recommendations)) {
            return $recommendations;
        }

        $stage = (string) ($maturityContext['stage'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY);
        $item = null;
        $founderLoop = (array) ($operatingContext['founder_operating_loop_context'] ?? []);
        if ($stage === AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY) {
            $journey = (array) ($operatingContext['startup_journey_context'] ?? []);
            $currentStage = (string) ($journey['current_stage_key'] ?? '');
            $stageLabel = $currentStage !== '' ? ucwords(str_replace('_', ' ', $currentStage)) : 'next';
            $item = [
                'title' => 'Complete the ' . $stageLabel . ' Clarity Journey stage',
                'impact' => 'High',
                'effort' => 'Medium',
                'reason' => 'AI Coach needs the Journey foundation before optimizing later operations.',
                'target_id' => 0,
                'suggested_subtasks' => [
                    'Open Clarity Journey and finish the highlighted stage',
                    'Save customer, problem, offer, GTM, MVP, metrics, and OKR details',
                    'Return to AI Coach after the Journey progress reaches 100%',
                ],
                'source_recommendation_type' => 'clarity_journey_gate',
            ];
        } elseif ($stage === AICoachOperatingMaturityService::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT) {
            $item = [
                'title' => 'Start Founder Loop first-deal commitments',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'The Journey foundation is ready; the next move is first-customer execution.',
                'target_id' => 0,
                'suggested_subtasks' => [
                    'Open Founder Loop from the Journey completion panel or dashboard',
                    'Review the generated weekly first-customer commitments',
                    'Check target deal value, budget, and runway before approving the first follow-up',
                ],
                'source_recommendation_type' => 'founder_loop_activation',
            ];
        } elseif ($stage === AICoachOperatingMaturityService::FOUNDER_LOOP_ACTIVE) {
            $commitment = (array) ($founderLoop['current_commitment'] ?? []);
            $title = trim((string) ($commitment['title'] ?? ''));
            $item = [
                'title' => $title !== '' ? 'Work Founder Loop commitment: ' . $title : 'Work this week\'s Founder Loop commitment',
                'impact' => 'High',
                'effort' => 'Medium',
                'reason' => 'Founder Loop is active, so deal movement should follow the current weekly commitment.',
                'target_id' => 0,
                'suggested_subtasks' => [
                    'Open the current Founder Loop commitment',
                    'Create or update the named follow-up task in CRM',
                    'Record the customer response, pricing signal, CAC/runway signal, or deal-stage movement',
                ],
                'source_recommendation_type' => 'founder_loop_commitment',
            ];
        } elseif ($stage === AICoachOperatingMaturityService::OPERATING_SYSTEM_ACTIVE) {
            $conflict = (array) ($assumptionConflicts[0] ?? []);
            $item = [
                'title' => $conflict !== [] ? 'Resolve the strongest Journey/evidence conflict' : 'Review operating evidence against Journey assumptions',
                'impact' => $conflict !== [] && (string) ($conflict['severity'] ?? '') === 'high' ? 'High' : 'Medium',
                'effort' => 'Medium',
                'reason' => $conflict !== []
                    ? (string) ($conflict['operating_evidence'] ?? 'New operating evidence differs from Journey assumptions.')
                    : 'CRM, finance, and Founder Loop evidence are now broad enough to challenge original assumptions.',
                'target_id' => 0,
                'suggested_subtasks' => [
                    (string) (($conflict['suggested_next_action'] ?? '') ?: 'Compare the latest CRM and finance evidence with the Journey foundation'),
                    'Decide whether to adjust the Journey assumption, GTM motion, or weekly commitment',
                    'Record the decision in the next Founder Loop review',
                ],
                'source_recommendation_type' => 'assumption_conflict',
            ];
        }

        if ($item !== null) {
            $recommendations['priorities'] = $this->prependUniqueRecommendation((array) ($recommendations['priorities'] ?? []), $item);
        }

        return $recommendations;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $item
     * @return list<array<string,mixed>>
     */
    private function prependUniqueRecommendation(array $items, array $item): array
    {
        $newTitle = strtolower(trim((string) ($item['title'] ?? '')));
        foreach ($items as $existing) {
            $existingTitle = strtolower(trim((string) ($existing['title'] ?? '')));
            if ($newTitle !== '' && ($existingTitle === $newTitle || str_contains($existingTitle, substr($newTitle, 0, min(32, strlen($newTitle)))))) {
                return $items;
            }
        }
        array_unshift($items, $item);
        return array_slice(array_values($items), 0, 4);
    }

    /**
     * @param array<string,mixed> $recommendations
     */
    private function hasCoachSetupRequiredRecommendation(array $recommendations): bool
    {
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            foreach ((array) ($recommendations[$section] ?? []) as $item) {
                if ((string) ($item['source_recommendation_type'] ?? '') === 'coach_setup_required') {
                    return true;
                }
            }
        }
        return false;
    }

    private function restoreFoundationSetupRecommendations(array $recommendations, array $operatingContext, string $mode): array
    {
        if ($mode !== '1') {
            return $recommendations;
        }

        $activeGoals = (array) ($operatingContext['goal_state']['active_goals'] ?? []);
        $readinessGaps = (array) ($operatingContext['feature_state']['readiness_gaps'] ?? []);
        $visibleCount = count((array) ($recommendations['priorities'] ?? []))
            + count((array) ($recommendations['foundation_gaps'] ?? []));

        if ($visibleCount > 0) {
            return $recommendations;
        }

        if ($activeGoals !== [] && $readinessGaps === []) {
            return $recommendations;
        }

        $suppressed = array_values((array) ($recommendations['suppressed_recommendations'] ?? []));
        if ($suppressed === []) {
            return $recommendations;
        }

        $promoted = [];
        foreach ($suppressed as $item) {
            $title = strtolower(trim((string) ($item['title'] ?? '')));
            $reason = strtolower(trim((string) ($item['reason'] ?? '')));
            $matchesReadiness = false;
            foreach ($readinessGaps as $gap) {
                $gapText = strtolower(trim((string) $gap));
                if ($gapText !== '' && (str_contains($title, $gapText) || str_contains($reason, $gapText))) {
                    $matchesReadiness = true;
                    break;
                }
            }

            if ($matchesReadiness || $activeGoals === []) {
                $item['goal_relevance_score'] = max(0.75, (float) ($item['goal_relevance_score'] ?? 0.0));
                $item['promoted_from_suppressed'] = true;
                $promoted[] = $item;
            }
        }

        if ($promoted === []) {
            return $recommendations;
        }

        $recommendations['priorities'] = array_slice($promoted, 0, 3);
        $recommendations['suppressed_recommendations'] = array_values(array_filter(
            $suppressed,
            static fn(array $item): bool => empty($item['promoted_from_suppressed'])
        ));

        return $recommendations;
    }

    private function applyRoleAwarePrioritization(array $recommendations, array $roleProfile): array
    {
        $signals = $this->roleProfiles->getRoleRankingSignals($roleProfile);
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $bucket) {
            if (empty($recommendations[$bucket]) || !is_array($recommendations[$bucket])) {
                continue;
            }

            foreach ($recommendations[$bucket] as &$item) {
                $item['role_fit_score'] = $this->scoreRecommendationForRole($item, $roleProfile, $bucket, $signals);
            }
            unset($item);

            usort($recommendations[$bucket], function (array $left, array $right): int {
                $leftScore = (int) ($left['role_fit_score'] ?? 0);
                $rightScore = (int) ($right['role_fit_score'] ?? 0);
                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }
                return strcmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
            });
        }

        return $recommendations;
    }

    private function attachTrustAndExplanationSignals(
        array $recommendations,
        array $decision,
        string $modeVariant,
        bool $leanCanvasEnabled
    ): array {
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            $recommendations[$section] = array_map(function (array $item) use ($section, $decision, $modeVariant, $leanCanvasEnabled): array {
                $item['feedback_signature'] = $this->buildFeedbackSignature($section, $item);
                $item['why_signals'] = $this->buildWhySignals($item, $modeVariant, $leanCanvasEnabled);
                $item['trust_signals'] = $this->buildTrustSignals($item, $decision);
                return $item;
            }, array_values((array) ($recommendations[$section] ?? [])));
        }

        return $recommendations;
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function applyFeedbackAwareRanking(int $userId, array $recommendations): array
    {
        $summary = [
            'accepted_count' => 0,
            'rejected_count' => 0,
            'dismissed_count' => 0,
            'adjusted_count' => 0,
            'control_counts' => [
                'boosted' => 0,
                'muted' => 0,
                'reset_learning' => 0,
            ],
        ];
        $signatures = [];
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            foreach ((array) ($recommendations[$section] ?? []) as $item) {
                $signature = trim((string) ($item['feedback_signature'] ?? ''));
                if ($signature !== '') {
                    $signatures[] = $signature;
                }
            }
        }
        if ($signatures === []) {
            return [$recommendations, $summary];
        }

        $controlsBySignature = [];
        $resetCutoffs = [];
        try {
            $controlsBySignature = $this->recommendationControls->controlsForRecommendations($recommendations);
            $resetCutoffs = $this->recommendationControls->resetCutoffsBySignature($recommendations, $controlsBySignature);
        } catch (\Throwable $e) {
            $controlsBySignature = [];
            $resetCutoffs = [];
        }

        try {
            $feedbackBySignature = $this->feedback->getRecentCoachFeedbackBySignature($userId, $signatures, 90, $resetCutoffs);
        } catch (\Throwable $e) {
            return [$recommendations, $summary];
        }
        if ($feedbackBySignature === [] && $controlsBySignature === []) {
            return [$recommendations, $summary];
        }

        return $this->recommendationRanking->rank($recommendations, $feedbackBySignature, $controlsBySignature);
    }

    private function buildFeedbackSignature(string $section, array $item): string
    {
        $seed = implode('|', [
            $section,
            strtolower(trim((string) ($item['title'] ?? ''))),
            (string) ((int) ($item['target_id'] ?? 0)),
            strtolower(trim((string) ($item['marketplace_skill_key'] ?? ''))),
            strtolower(trim((string) ($item['source_recommendation_type'] ?? ''))),
        ]);

        return 'coach_sig_' . substr(sha1($seed), 0, 20);
    }

    /**
     * @return list<string>
     */
    private function buildWhySignals(array $item, string $modeVariant, bool $leanCanvasEnabled): array
    {
        $signals = [];
        $text = strtolower(trim(((string) ($item['title'] ?? '')) . ' ' . ((string) ($item['reason'] ?? ''))));
        $sourceType = strtolower((string) ($item['source_recommendation_type'] ?? ''));
        if ((int) ($item['target_id'] ?? 0) > 0) {
            $signals[] = 'Target linked';
        }
        if (!empty($item['marketplace_skill_key']) || $sourceType === 'marketplace_module') {
            $signals[] = 'Marketplace';
            $signals[] = 'Setup gap';
        }
        if (str_contains($text, 'setup') || str_contains($text, 'install') || str_contains($text, 'complete') || str_contains($text, 'missing') || str_contains($text, 'enable')) {
            $signals[] = 'Setup gap';
        }
        if ($leanCanvasEnabled || $modeVariant === 'lean_canvas' || str_contains($text, 'lean canvas')) {
            $signals[] = 'Lean Canvas';
        }
        if (!empty($item['suggested_subtasks'])) {
            $signals[] = 'Ready skill';
        }
        if (trim((string) ($item['reason'] ?? '')) !== '') {
            $signals[] = 'CRM activity';
        }
        if ($signals === []) {
            $signals[] = 'Workspace context';
        }

        return array_slice(array_values(array_unique($signals)), 0, 4);
    }

    /**
     * @return list<array{label:string,value:string,tone:string}>
     */
    private function buildTrustSignals(array $item, array $decision): array
    {
        $signals = [];
        if (isset($decision['context_quality_score']) && is_numeric($decision['context_quality_score'])) {
            $score = (float) $decision['context_quality_score'];
            $signals[] = [
                'label' => 'Context',
                'value' => $this->formatPercent($score),
                'tone' => $this->scoreTone($score, 0.85, 0.65),
            ];
        }
        if (isset($item['goal_relevance_score']) && is_numeric($item['goal_relevance_score'])) {
            $score = (float) $item['goal_relevance_score'];
            $signals[] = [
                'label' => 'Goal match',
                'value' => $this->formatPercent($score),
                'tone' => $this->scoreTone($score, 0.80, 0.60),
            ];
        }
        if (isset($decision['confidence_score']) && is_numeric($decision['confidence_score'])) {
            $score = (float) $decision['confidence_score'];
            $signals[] = [
                'label' => 'Confidence',
                'value' => $this->formatPercent($score),
                'tone' => $this->scoreTone($score, 0.85, 0.65),
            ];
        }

        $source = $this->sourceLabel($item);
        if ($source !== '') {
            $signals[] = [
                'label' => 'Source',
                'value' => $source,
                'tone' => 'neutral',
            ];
        }

        return $signals;
    }

    private function sourceLabel(array $item): string
    {
        if (!empty($item['marketplace_skill_key'])) {
            return 'Marketplace: ' . ucwords(str_replace('_', ' ', (string) $item['marketplace_skill_key']));
        }
        $sourceType = trim((string) ($item['source_recommendation_type'] ?? ''));
        if ($sourceType !== '') {
            return ucwords(str_replace('_', ' ', $sourceType));
        }
        return 'Coach';
    }

    private function formatPercent(float $score): string
    {
        return (string) max(0, min(100, (int) round($score * 100))) . '%';
    }

    private function scoreTone(float $score, float $good, float $warning): string
    {
        if ($score >= $good) {
            return 'success';
        }
        if ($score >= $warning) {
            return 'warning';
        }
        return 'danger';
    }

    private function appendMarketplaceRecommendations(array $recommendations, array $operatingContext): array
    {
        $workspaceId = (int) ($operatingContext['identity']['workspace_id'] ?? 0);
        $userId = (int) ($operatingContext['identity']['user_id'] ?? 0);
        if ($workspaceId <= 0 || $userId <= 0) {
            return $recommendations;
        }

        if (!$this->canViewMarketplaceRecommendations()) {
            return $recommendations;
        }

        $marketplaceContext = (array) ($operatingContext['workspace_marketplace'] ?? []);
        if (($marketplaceContext['visibility'] ?? '') === 'skipped_for_fast_setup') {
            return $recommendations;
        }

        $canManageMarketplace = $this->canManageMarketplaceRecommendations();
        $activationBundles = $canManageMarketplace
            ? (array) ($marketplaceContext['activation_bundles'] ?? [])
            : [];
        if ($activationBundles !== []) {
            $recommendations['activation_bundle_guidance'] = $this->shapeActivationBundleGuidance((array) $activationBundles[0]);
        }

        $marketplace = (array) ($marketplaceContext['recommendations'] ?? []);
        if ($marketplace === []) {
            try {
                $marketplace = $this->marketplaceRecommendations->recommendationsForWorkspace($workspaceId, $userId, 3, 'coach');
            } catch (\Throwable $e) {
                return $recommendations;
            }
        }

        if ($canManageMarketplace) {
            $journeysBySkill = (array) ($marketplaceContext['setup_journeys'] ?? []);
            if ($journeysBySkill === []) {
                try {
                    $journeysBySkill = $this->marketplaceSetupJourneys->continuityBySkill($workspaceId, $userId, $marketplace);
                } catch (\Throwable $e) {
                    $journeysBySkill = [];
                }
            }
            foreach ($marketplace as &$marketplaceItem) {
                if (!is_array($marketplaceItem)) {
                    continue;
                }
                $skillKey = (string) ($marketplaceItem['skill_key'] ?? '');
                if ($skillKey !== '' && isset($journeysBySkill[$skillKey])) {
                    $marketplaceItem['setup_journey'] = $journeysBySkill[$skillKey];
                }
            }
            unset($marketplaceItem);
        }

        $existingTitles = [];
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $bucket) {
            foreach ((array) ($recommendations[$bucket] ?? []) as $item) {
                $existingTitles[strtolower(trim((string) ($item['title'] ?? '')))] = true;
            }
        }

        foreach (array_slice($marketplace, 0, 3) as $item) {
            $label = (string) ($item['label'] ?? $item['skill_key'] ?? 'Marketplace module');
            $setupUrl = trim((string) ($item['setup_url'] ?? 'workspace_skills.php'));
            $isInstalled = !empty($item['is_installed']);
            $setupJourney = $canManageMarketplace ? (array) ($item['setup_journey'] ?? []) : [];
            $nextSetupStep = (array) ($setupJourney['next_step'] ?? []);
            $setupProgress = (array) ($setupJourney['progress'] ?? []);
            $title = !empty($item['is_installed'])
                ? 'Finish ' . $label . ' setup'
                : 'Add ' . $label . ' from the Marketplace';
            $titleKey = strtolower(trim($title));
            if ($titleKey === '' || isset($existingTitles[$titleKey])) {
                continue;
            }

            $bucket = (string) ($item['coach_bucket'] ?? 'missing_features');
            $bucket = $bucket === 'foundation_gaps' ? 'foundation_gaps' : 'missing_features';
            $matchingBundle = $canManageMarketplace
                ? $this->activationBundleForSkill((string) ($item['skill_key'] ?? ''), $activationBundles)
                : [];
            $recommendations[$bucket][] = [
                'title' => $title,
                'impact' => ((int) ($item['score'] ?? 0)) >= 75 ? 'High' : 'Medium',
                'effort' => !empty($item['setup_blockers']) ? 'Medium' : 'Low',
                'reason' => trim((string) ($item['why_now'] ?? '') . ' ' . (string) ($item['expected_benefit'] ?? '')),
                'target_id' => 0,
                'target_title' => '',
                'suggested_subtasks' => array_values(array_filter(array_merge(
                    (array) ($item['setup_blockers'] ?? []),
                    !empty($nextSetupStep['label']) ? [(string) $nextSetupStep['label']] : [],
                    ['Open Workspace Marketplace']
                ), 'is_string')),
                'marketplace_skill_key' => (string) ($item['skill_key'] ?? ''),
                'marketplace_setup_url' => $setupUrl !== '' ? $setupUrl : 'workspace_skills.php',
                'marketplace_cta_label' => $isInstalled ? 'Open setup' : 'Open Marketplace',
                'marketplace_feedback_enabled' => $canManageMarketplace,
                'marketplace_setup_journey' => $setupJourney,
                'marketplace_next_setup_step' => $nextSetupStep,
                'marketplace_setup_progress' => $setupProgress,
                'marketplace_activation_bundle' => $matchingBundle !== [] ? $matchingBundle : null,
                'marketplace_score' => (int) ($item['score'] ?? 0),
                'source_recommendation_type' => 'marketplace_module',
            ];
            $existingTitles[$titleKey] = true;
        }

        return $recommendations;
    }

    private function shapeActivationBundleGuidance(array $bundle): array
    {
        return [
            'bundle_key' => (string) ($bundle['bundle_key'] ?? ''),
            'label' => (string) ($bundle['label'] ?? 'Activation path'),
            'summary' => (string) ($bundle['summary'] ?? ''),
            'priority' => (string) ($bundle['priority'] ?? 'medium'),
            'status' => (string) ($bundle['status'] ?? 'suggested'),
            'progress' => (array) ($bundle['progress'] ?? []),
            'next_action' => (array) ($bundle['next_action'] ?? []),
            'recommended_skill_keys' => array_values(array_filter(array_map('strval', (array) ($bundle['recommended_skill_keys'] ?? [])))),
            'installed_skill_keys' => array_values(array_filter(array_map('strval', (array) ($bundle['installed_skill_keys'] ?? [])))),
            'adaptive_guidance' => (string) ($bundle['adaptive_guidance'] ?? ''),
            'insight_label' => (string) ($bundle['insight_label'] ?? ''),
        ];
    }

    private function activationBundleForSkill(string $skillKey, array $activationBundles): array
    {
        $skillKey = strtolower(trim($skillKey));
        if ($skillKey === '') {
            return [];
        }

        foreach ($activationBundles as $bundle) {
            if (!is_array($bundle)) {
                continue;
            }
            $keys = array_merge(
                (array) ($bundle['recommended_skill_keys'] ?? []),
                (array) ($bundle['installed_skill_keys'] ?? [])
            );
            if (in_array($skillKey, array_map(static fn($key): string => strtolower(trim((string) $key)), $keys), true)) {
                return $this->shapeActivationBundleGuidance($bundle);
            }
        }

        return [];
    }

    private function canManageMarketplaceRecommendations(): bool
    {
        $user = Auth::user() ?: [];

        return Authorization::isSuperAdmin($user)
            || Authorization::can('workspace.skills.manage', $user);
    }

    private function canViewMarketplaceRecommendations(): bool
    {
        $user = Auth::user() ?: [];

        return Authorization::isSuperAdmin($user)
            || Authorization::can('workspace.skills.view', $user)
            || Authorization::can('workspace.skills.manage', $user);
    }

    private function scoreRecommendationForRole(array $item, array $roleProfile, string $bucket, array $signals): int
    {
        $haystack = strtolower(trim(((string) ($item['title'] ?? '')) . ' ' . ((string) ($item['reason'] ?? ''))));
        if ($haystack === '') {
            return 0;
        }

        $score = (int) (($signals['bucket_weights'][$bucket] ?? 0) * 2);
        foreach ((array) ($signals['keyword_weights'] ?? []) as $keyword => $weight) {
            if (str_contains($haystack, $keyword)) {
                $score += (int) $weight;
            }
        }

        foreach ((array) ($roleProfile['emphasis_areas'] ?? []) as $area) {
            foreach (array_filter(explode(' ', strtolower((string) $area))) as $term) {
                if (strlen($term) >= 4 && str_contains($haystack, $term)) {
                    $score++;
                }
            }
        }

        if (strtolower((string) ($item['impact'] ?? '')) === 'high') {
            $score += 2;
        }
        if (strtolower((string) ($item['effort'] ?? '')) === 'low') {
            $score++;
        }

        return $score;
    }

    private function attachRecommendationKeys(array $recommendations, int $guidanceRunId): array
    {
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $section) {
            $recommendations[$section] = array_map(function (array $item) use ($guidanceRunId, $section): array {
                $item['guidance_run_id'] = $guidanceRunId;
                $item['recommendation_key'] = $this->buildRecommendationKey($guidanceRunId, $section, $item);
                return $item;
            }, array_values((array) ($recommendations[$section] ?? [])));
        }

        return $recommendations;
    }

    private function buildRecommendationKey(int $guidanceRunId, string $section, array $item): string
    {
        $seed = implode('|', [
            $guidanceRunId,
            $section,
            (string) ($item['title'] ?? ''),
            (string) ($item['target_id'] ?? 0),
        ]);

        return 'coach_' . $guidanceRunId . '_' . substr(sha1($seed), 0, 12);
    }

    private function normalizeFoundationGaps(array $items): array
    {
        $normalized = [];
        foreach (array_slice($items, 0, 5) as $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }
            $normalized[] = [
                'title' => (string) $item['title'],
                'impact' => in_array($item['impact'] ?? '', ['High', 'Medium', 'Low']) ? $item['impact'] : 'Medium',
                'effort' => in_array($item['effort'] ?? '', ['Low', 'Medium', 'High']) ? $item['effort'] : 'Medium',
                'reason' => (string) ($item['reason'] ?? ''),
                'target_id' => 0,
                'target_title' => '',
                'suggested_subtasks' => array_values(array_filter((array) ($item['suggested_subtasks'] ?? []), 'is_string')),
            ];
        }
        return $normalized;
    }

    private function normalizeRecommendationItems(array $items, array $allowedTargetIds = [], array $targetTitlesById = []): array
    {
        $normalized = [];
        foreach (array_slice($items, 0, 5) as $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }

            $targetId = (int) ($item['target_id'] ?? 0);
            if ($targetId !== 0 && !in_array($targetId, $allowedTargetIds, true)) {
                $targetId = 0;
            }
            $targetTitle = $targetId > 0 ? (string) ($targetTitlesById[$targetId] ?? '') : '';

            $entry = [
                'title' => (string) $item['title'],
                'impact' => in_array($item['impact'] ?? '', ['High', 'Medium', 'Low']) ? $item['impact'] : 'Medium',
                'effort' => in_array($item['effort'] ?? '', ['Low', 'Medium', 'High']) ? $item['effort'] : 'Medium',
                'reason' => (string) ($item['reason'] ?? ''),
                'target_id' => $targetId,
                'target_title' => $targetTitle,
                'suggested_subtasks' => array_values(array_filter((array) ($item['suggested_subtasks'] ?? []), 'is_string')),
            ];
            if (!empty($item['workflow_template_id'])) {
                $entry['workflow_template_id'] = (int) $item['workflow_template_id'];
            }
            if (!empty($item['workflow_create_url'])) {
                $entry['workflow_create_url'] = (string) $item['workflow_create_url'];
            }
            $normalized[] = $entry;
        }
        return $normalized;
    }

    private function getFallbackRecommendations(array $metrics, array $featureUsage, array $allowedTargetIds = [], string $mode = '2'): array
    {
        $priorities = [];
        $quickWins = [];
        $missingFeatures = [];
        $foundationGaps = [];

        $summary = $metrics['summary'] ?? [];
        if (($summary['emails_sent_week'] ?? 0) < 5) {
            $priorities[] = [
                'title' => 'Send more outreach emails this week',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Email volume is low; consistent outreach improves pipeline.',
                'target_id' => 0,
                'suggested_subtasks' => ['Draft 3–5 personalized emails', 'Schedule sends', 'Track opens and replies'],
            ];
        }
        if (($summary['leads_today'] ?? 0) === 0) {
            $priorities[] = [
                'title' => 'Add or import new leads today',
                'impact' => 'High',
                'effort' => 'Medium',
                'reason' => 'No new leads today; pipeline needs fresh contacts.',
                'target_id' => 0,
                'suggested_subtasks' => ['Import list or add contacts manually', 'Assign lead source', 'Set follow-up tasks'],
            ];
        }
        $quickWins[] = [
            'title' => 'Review and update contact stages',
            'impact' => 'Medium',
            'effort' => 'Low',
            'reason' => 'Keeping stages accurate improves reporting and next steps.',
            'target_id' => 0,
            'suggested_subtasks' => [],
        ];
        if (!$featureUsage['tasks_used']) {
            $missingFeatures[] = [
                'title' => 'Use Tasks to track follow-ups',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Tasks help you never miss a follow-up.',
                'target_id' => 0,
                'suggested_subtasks' => ['Create your first task', 'Set a due date', 'Complete and check off'],
            ];
        }
        if (!$featureUsage['workflows_used']) {
            $missingFeatures[] = [
                'title' => 'Try automation with Workflows',
                'impact' => 'High',
                'effort' => 'Medium',
                'reason' => 'Automate repetitive steps and save time. Start with the Welcome New Contacts template.',
                'target_id' => 0,
                'suggested_subtasks' => ['Open Workflow Templates', 'Use "Welcome New Contacts" template', 'Customize and activate'],
                'workflow_template_id' => 1,
                'workflow_create_url' => publicUrl('workflow_create.php?template_id=1'),
            ];
        } else {
            $dealCount = (int) Database::queryOne("SELECT COUNT(*) as count FROM deals WHERE stage NOT IN ('closed_won', 'closed_lost')")['count'] ?? 0;
            $contactCount = (int) ($featureUsage['contact_count'] ?? 0);
            if ($dealCount > 0) {
                $quickWins[] = [
                    'title' => 'Add Deal Follow-up workflow',
                    'impact' => 'High',
                    'effort' => 'Low',
                    'reason' => 'Automate follow-ups when deals move to proposal stage.',
                    'target_id' => 0,
                    'suggested_subtasks' => ['Open Workflow Templates', 'Use "Deal Follow-up" template', 'Activate'],
                    'workflow_template_id' => 4,
                    'workflow_create_url' => publicUrl('workflow_create.php?template_id=4'),
                ];
            }
            if ($contactCount > 10) {
                $quickWins[] = [
                    'title' => 'Add Lead Nurturing workflow',
                    'impact' => 'High',
                    'effort' => 'Low',
                    'reason' => 'Nurture your contacts with a 3-email sequence automatically.',
                    'target_id' => 0,
                    'suggested_subtasks' => ['Open Workflow Templates', 'Use "Lead Nurturing Sequence" template', 'Activate'],
                    'workflow_template_id' => 2,
                    'workflow_create_url' => publicUrl('workflow_create.php?template_id=2'),
                ];
            }
        }

        if ($mode === '1') {
            $foundationGaps[] = [
                'title' => 'Complete company profile and product definitions',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Business fundamentals drive better execution in your growth system.',
                'suggested_subtasks' => ['Add company description', 'Define products with pricing', 'Set target audience'],
            ];
            $quickWins[] = [
                'title' => 'Set email deliverability basics before scaling outreach',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Sender reputation is easier to protect than recover later.',
                'target_id' => 0,
                'suggested_subtasks' => ['Set SPF, DKIM, and DMARC', 'Use a separate sending subdomain', 'Ramp sends gradually: 10/day then 20/day then 30/day'],
            ];
        }

        return [
            'why_this_matters' => 'Small daily improvements compound: tighter follow-up and better setup increase conversions over time.',
            'priorities' => array_slice($priorities, 0, 3),
            'quick_wins' => array_slice($quickWins, 0, 3),
            'missing_features' => array_slice($missingFeatures, 0, 3),
            'foundation_gaps' => array_slice($foundationGaps, 0, 3),
        ];
    }

    private function applyLeanCanvasMetadata(
        array $recommendations,
        string $modeVariant,
        bool $leanCanvasEnabled,
        int $leanCanvasCompleteness,
        array $leanCanvasMissingBlocks
    ): array {
        $recommendations['mode_variant'] = $modeVariant;
        $recommendations['lean_canvas_enabled'] = $leanCanvasEnabled;
        $recommendations['lean_canvas_completeness'] = $leanCanvasCompleteness;
        $recommendations['lean_canvas_missing_blocks'] = array_values($leanCanvasMissingBlocks);

        return $recommendations;
    }

    private function applyFoundationStarterRecommendations(array $recommendations, array $operatingContext, string $mode): array
    {
        if ($mode !== '1') {
            return $recommendations;
        }

        $featureState = (array) ($operatingContext['feature_state'] ?? []);
        $starterGaps = [];

        if (empty($featureState['company_profile_ready'])) {
            $starterGaps[] = [
                'title' => 'Company Profile Configuration',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Complete your company profile so AI guidance, documents, and customer-facing details have the right business identity.',
                'suggested_subtasks' => [
                    'Add company name, description, and primary contact details',
                    'Save branding and business identity fields',
                    'Verify the profile appears correctly in the CRM',
                ],
            ];
        }

        if (empty($featureState['products_priced'])) {
            $starterGaps[] = [
                'title' => 'Product Pricing Setup',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Pricing must be configured before quotes, invoices, and commercial automation can operate cleanly.',
                'suggested_subtasks' => [
                    'Create or review active products',
                    'Set unit prices and billing terms',
                    'Verify pricing is visible on product records',
                ],
            ];
        }

        if (empty($featureState['invoicing_ready'])) {
            $starterGaps[] = [
                'title' => 'Invoicing Configuration',
                'impact' => 'High',
                'effort' => 'Low',
                'reason' => 'Complete invoice settings so quotes, proformas, and invoices can be generated with valid business details.',
                'suggested_subtasks' => [
                    'Enable invoicing and legal company details',
                    'Review numbering, tax, and payment defaults',
                    'Save and verify invoice settings',
                ],
            ];
        }

        if ($starterGaps !== []) {
            $recommendations['foundation_gaps'] = $this->normalizeFoundationGaps($starterGaps);
        }

        return $recommendations;
    }
}
