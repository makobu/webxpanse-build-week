<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Authorization;
use CRM\Modules\AIGuidanceEvaluator;
use CRM\Modules\BeginnerBudget;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Targets;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Modules\UserPreferences;

class AIOperatingContextService
{
    private UserPreferences $preferences;
    private AIGoalRelevanceService $goalRelevance;
    private AIThresholdUpdateService $thresholds;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    private AIWorkspaceScopeService $workspaceScope;
    private SystemContextRegistryService $contextRegistry;

    public function __construct()
    {
        $this->preferences = new UserPreferences();
        $this->goalRelevance = new AIGoalRelevanceService();
        $this->thresholds = new AIThresholdUpdateService();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
        $this->workspaceScope = new AIWorkspaceScopeService();
        $this->contextRegistry = new SystemContextRegistryService();
    }

    public function buildForUser(int $userId, array $options = []): array
    {
        return $this->buildForSurface($userId, (string) ($options['surface'] ?? 'system'), $options);
    }

    public function buildForSurface(int $userId, string $surface, array $options = []): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $workspaceRegistryContext = $this->contextRegistry->workspaceContext($workspaceId);
        $isDefaultWorkspace = !empty($workspaceRegistryContext['is_default_workspace']);
        $page = (string) ($options['current_page'] ?? basename($_SERVER['PHP_SELF'] ?? ''));
        $featureState = $this->getCapabilityState($userId);
        $goalState = $this->getGoalRelevanceState($userId);
        $qualification = $this->getQualificationState($userId);

        $workspaceSkills = $this->getWorkspaceSkillsContext($workspaceId, $userId);
        $workspaceMarketplace = !empty($options['skip_marketplace'])
            ? [
                'recommendations' => [],
                'setup_journeys' => [],
                'activation_bundles' => [],
                'top_recommendation_keys' => [],
                'visibility' => 'skipped_for_fast_setup',
                'generated_at' => gmdate('c'),
            ]
            : $this->getWorkspaceMarketplaceContext($workspaceId, $userId, $surface);

        $context = [
            'identity' => [
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'role' => (string) ((\CRM\Auth::user()['role'] ?? 'user')),
            ],
            'surface' => [
                'name' => $surface,
                'current_page' => $page,
            ],
            'feature_state' => $featureState,
            'workspace_context' => $workspaceRegistryContext,
            'goal_state' => $goalState,
            'pipeline_state' => $this->getPipelineState($workspaceId),
            'deal_automation_state' => $this->getDealAutomationState(),
            'commercial_state' => $this->getCommercialState($workspaceId),
            'task_state' => $this->getTaskState($userId, $workspaceId),
            'target_state' => $this->getTargetState($userId),
            'inbox_state' => $this->getInboxState($userId, $workspaceId),
            'ai_settings' => $this->getAiSettings($userId),
            'qualification_state' => $qualification,
            'company_context' => $this->getCompanyContext(),
            'user_strategy_context' => $this->getUserStrategyContext($userId),
            'startup_journey_context' => $this->getStartupJourneyContext($workspaceId, $userId),
            'finance_context' => $this->getFinanceContext($workspaceId, $userId),
            'finance_accounting_context' => $this->getFinanceAccountingContext($workspaceId, $userId),
            'founder_operating_loop_context' => $this->getFounderOperatingLoopContext($workspaceId, $userId),
            'workspace_skills' => $workspaceSkills,
            'workspace_modules' => $workspaceSkills,
            'installed_skill_contracts' => (array) ($workspaceSkills['installed_skill_contracts'] ?? []),
            'workspace_marketplace' => $workspaceMarketplace,
            'onboarding_state' => $surface === 'clarity_chat'
                ? (new WorkspaceOperatingBriefService())->buildOnboardingState($workspaceId, $userId)
                : null,
            'lean_canvas_status' => $this->getLeanCanvasStatus($userId),
            'missing_context_flags' => $featureState['missing_context_flags'],
            'recommended_mode' => $qualification['effective_mode'],
        ];

        $context['financial_assumptions'] = (array) ($context['startup_journey_context']['financial_assumptions'] ?? []);
        if ($isDefaultWorkspace) {
            $context['workspace_operating_mode'] = (array) ($workspaceRegistryContext['operating_mode'] ?? $this->contextRegistry->defaultWorkspaceOperatingMode());
            $context['platform_ops_context'] = $this->contextRegistry->platformOpsContext($workspaceId);
            $context['platform_ops_context']['internal_capabilities'] = (new DefaultWorkspaceCapabilityCatalogService())->all();
        }

        $context['operating_maturity'] = (new AICoachOperatingMaturityService())->determine($workspaceId, $userId, $context);
        $context['assumption_conflicts'] = (new AICoachAssumptionConflictService())->detect($workspaceId, $userId, $context);

        return $context;
    }

    public function getCapabilityState(int $userId): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $profile = (new CompanyProfile())->get();
        $invoiceSettings = class_exists(InvoiceSettings::class) ? (new InvoiceSettings())->get() : [];
        $workflowReady = $this->tableExists('workflows') && $this->columnExists('workflows', 'graph_json');
        $productsTotal = $this->countScopedProducts($workspaceId, false);
        $pricedProducts = $this->countScopedProducts($workspaceId, true);
        $workspaceRegistryContext = $this->contextRegistry->workspaceContext($workspaceId);
        if (!empty($workspaceRegistryContext['is_default_workspace'])) {
            $catalog = (new UnifiedCommercialCatalogService())->catalogForWorkspace($workspaceId);
            $productsTotal = count($catalog['packages']);
            $pricedProducts = count(array_filter(
                $catalog['packages'],
                static fn(array $package): bool => (bool) array_filter(
                    (array) ($package['price_variants'] ?? []),
                    static fn(array $variant): bool => (float) ($variant['amount'] ?? 0) >= 0
                )
            ));
        }
        $missingFlags = [];

        foreach ([
            'idea_validation_context' => 'idea_validation_context_missing',
            'beginner_budget' => 'beginner_budget_missing',
            'onboarding_progress' => 'onboarding_progress_missing',
        ] as $table => $flag) {
            if (!$this->tableExists($table)) {
                $missingFlags[] = $flag;
                $this->logCapabilityState($userId, 'system', $table, 'missing', 'Optional AI context table is not installed.');
            }
        }

        $companyProfileReady = !empty(trim((string) ($profile['company_description'] ?? '')))
            && !empty(trim((string) ($profile['company_name'] ?? '')));
        $productsPriced = $productsTotal === 0 ? false : $pricedProducts >= max(1, (int) ceil($productsTotal / 2));
        $invoicingReady = !empty($invoiceSettings['enabled']) && !empty($invoiceSettings['company_legal_name']);
        $commercialAutomationReady = $this->tableExists('commercial_automation_config');

        $readinessGaps = [];
        if (!$companyProfileReady) {
            $readinessGaps[] = 'company profile';
        }
        if (!$productsPriced) {
            $readinessGaps[] = 'product pricing';
        }
        if (!$invoicingReady) {
            $readinessGaps[] = 'invoicing';
        }
        if (!$workflowReady) {
            $readinessGaps[] = 'workflow graph';
        }
        if (!$commercialAutomationReady) {
            $readinessGaps[] = 'commercial automation';
        }

        return [
            'company_profile_ready' => $companyProfileReady,
            'products_priced' => $productsPriced,
            'invoicing_ready' => $invoicingReady,
            'workflow_graph_ready' => $workflowReady,
            'commercial_automation_ready' => $commercialAutomationReady,
            'products_total' => $productsTotal,
            'priced_products' => $pricedProducts,
            'readiness_gaps' => $readinessGaps,
            'missing_context_flags' => $missingFlags,
        ];
    }

    public function getGoalRelevanceState(int $userId): array
    {
        $goals = $this->goalRelevance->getPrimaryGoals($userId);
        $nearestDeadline = null;
        foreach ($goals as $goal) {
            $date = (string) ($goal['target_date'] ?? '');
            if ($date !== '' && ($nearestDeadline === null || strtotime($date) < strtotime($nearestDeadline))) {
                $nearestDeadline = $date;
            }
        }

        $goalScore = empty($goals) ? 0.0 : 0.85;
        return [
            'active_goals' => $goals,
            'top_goal' => $goals[0] ?? null,
            'nearest_deadline' => $nearestDeadline,
            'goal_relevance_score' => $goalScore,
        ];
    }

    public function getQualificationState(int $userId): array
    {
        $mode = $this->preferences->getEffectiveAIGuidanceMode($userId);
        $evaluation = (new AIGuidanceEvaluator())->evaluateDetailedMode($userId);
        return [
            'effective_mode' => $mode,
            'reason_codes' => (array) ($evaluation['reason_codes'] ?? []),
            'scores' => (array) ($evaluation['scores'] ?? []),
        ];
    }

    private function getPipelineState(int $workspaceId): array
    {
        return [
            'open_deals' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage NOT IN ('closed_won', 'closed_lost')", [$workspaceId])['c'] ?? 0),
            'proposal_stage_deals' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage = 'proposal'", [$workspaceId])['c'] ?? 0),
            'negotiation_stage_deals' => (int) (Database::queryOne("SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage = 'negotiation'", [$workspaceId])['c'] ?? 0),
        ];
    }

    private function getWorkspaceSkillsContext(int $workspaceId, int $userId): array
    {
        try {
            return (new WorkspaceSkillInstallService())->buildContextForWorkspace($workspaceId, $userId);
        } catch (\Throwable $e) {
            return [
                'installed' => [],
                'installed_keys' => [],
                'context' => [],
                'error' => 'Workspace skills context unavailable.',
            ];
        }
    }

    private function getWorkspaceMarketplaceContext(int $workspaceId, int $userId, string $surface): array
    {
        try {
            $user = \CRM\Auth::user();
            $canViewMarketplace = Authorization::isSuperAdmin($user)
                || Authorization::can('workspace.skills.view', $user)
                || Authorization::can('workspace.skills.manage', $user);
            $canManageMarketplace = Authorization::isSuperAdmin($user)
                || Authorization::can('workspace.skills.manage', $user);
            if (!$canViewMarketplace) {
                return [
                    'recommendations' => [],
                    'setup_journeys' => [],
                    'activation_bundles' => [],
                    'top_recommendation_keys' => [],
                    'visibility' => 'hidden_by_access_profile',
                    'generated_at' => gmdate('c'),
                ];
            }

            $recommendations = (new WorkspaceMarketplaceRecommendationService())->recommendationsForWorkspace($workspaceId, $userId, 5, $surface);
            $setupJourneys = $canManageMarketplace
                ? (new WorkspaceMarketplaceSetupJourneyService())->continuityBySkill($workspaceId, $userId, $recommendations)
                : [];
            foreach ($recommendations as &$recommendation) {
                $skillKey = (string) ($recommendation['skill_key'] ?? '');
                if ($skillKey !== '' && isset($setupJourneys[$skillKey])) {
                    $recommendation['setup_journey'] = $setupJourneys[$skillKey];
                }
            }
            unset($recommendation);

            $context = [
                'recommendations' => $recommendations,
                'setup_journeys' => $setupJourneys,
                'activation_bundles' => $canManageMarketplace
                    ? (new WorkspaceMarketplaceActivationBundleService())->contextBundlesForSurface($workspaceId, $userId, $surface)
                    : [],
                'top_recommendation_keys' => array_values(array_map(
                    static fn(array $item): string => (string) ($item['skill_key'] ?? ''),
                    array_slice($recommendations, 0, 3)
                )),
                'generated_at' => gmdate('c'),
            ];
            if (Authorization::isSuperAdmin(\CRM\Auth::user())) {
                $context['performance'] = (new WorkspaceMarketplacePerformanceService())->buildWorkspaceSummary($workspaceId, 30);
            }

            return $context;
        } catch (\Throwable $e) {
            return [
                'recommendations' => [],
                'setup_journeys' => [],
                'activation_bundles' => [],
                'top_recommendation_keys' => [],
                'error' => 'Workspace marketplace recommendations unavailable.',
            ];
        }
    }

    private function getCommercialState(int $workspaceId): array
    {
        $approvals = 0;
        if ($this->tableExists('commercial_automation_approvals')) {
            $approvals = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM commercial_automation_approvals caa
                 WHERE caa.status = 'pending'
                   AND (
                        EXISTS (SELECT 1 FROM deals d WHERE d.id = caa.deal_id AND d.workspace_id = ?)
                        OR EXISTS (SELECT 1 FROM invoices i WHERE i.id = caa.invoice_id AND i.workspace_id = ?)
                   )",
                [$workspaceId, $workspaceId]
            )['c'] ?? 0);
        }

        return [
            'pending_invoices' => $this->tableExists('invoices') ? (int) (Database::queryOne("SELECT COUNT(*) AS c FROM invoices WHERE workspace_id = ? AND status IN ('draft', 'sent', 'revised', 'overdue')", [$workspaceId])['c'] ?? 0) : 0,
            'pending_commercial_approvals' => $approvals,
            'quotes_pending' => $this->tableExists('invoices') ? (int) (Database::queryOne("SELECT COUNT(*) AS c FROM invoices WHERE workspace_id = ? AND document_type IN ('quote','proforma') AND status NOT IN ('paid','cancelled')", [$workspaceId])['c'] ?? 0) : 0,
        ];
    }

    private function getDealAutomationState(): array
    {
        try {
            $state = (new DealAutomationReadinessService())->getState();
            return [
                'current_mode' => (string) ($state['current_mode'] ?? 'manual'),
                'readiness_status' => (string) ($state['readiness_status'] ?? 'unknown'),
                'is_managed_by_auto_admin' => !empty($state['is_managed_by_auto_admin']),
                'blocking_reasons' => array_values((array) ($state['blocking_reasons'] ?? [])),
            ];
        } catch (\Throwable $e) {
            return [
                'current_mode' => 'manual',
                'readiness_status' => 'unknown',
                'is_managed_by_auto_admin' => false,
                'blocking_reasons' => [],
            ];
        }
    }

    private function getTaskState(int $userId, int $workspaceId): array
    {
        $openTasks = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = ? AND (assigned_to = ? OR created_by = ?) AND status NOT IN ('completed', 'cancelled')", [$workspaceId, $userId, $userId])['c'] ?? 0);
        $overdueTasks = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = ? AND (assigned_to = ? OR created_by = ?) AND due_date < NOW() AND status NOT IN ('completed', 'cancelled')", [$workspaceId, $userId, $userId])['c'] ?? 0);
        $autoCompletable = $this->columnExists('tasks', 'metadata_json')
            ? (int) (Database::queryOne("SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = ? AND (assigned_to = ? OR created_by = ?) AND JSON_EXTRACT(metadata_json, '$.auto_complete_allowed') = true AND status NOT IN ('completed', 'cancelled')", [$workspaceId, $userId, $userId])['c'] ?? 0)
            : 0;

        return [
            'open_tasks' => $openTasks,
            'overdue_tasks' => $overdueTasks,
            'auto_completable_tasks' => $autoCompletable,
            'explicit_evidence_available' => false,
        ];
    }

    private function getTargetState(int $userId): array
    {
        try {
            $targets = new Targets();
            $activeTargets = $targets->getAll(['user_id' => $userId, 'status' => 'active'], 25, 0);
        } catch (\Throwable $e) {
            return [
                'active_targets' => 0,
                'at_risk_targets' => 0,
                'behind_targets' => 0,
                'top_targets' => [],
            ];
        }

        $atRisk = 0;
        $behind = 0;
        $topTargets = [];
        foreach ($activeTargets as $target) {
            $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
            if (in_array($band, ['at_risk', 'blocked'], true)) {
                $atRisk++;
            }
            if (in_array($band, ['behind', 'missed'], true)) {
                $behind++;
            }
            if (count($topTargets) < 5) {
                $topTargets[] = [
                    'id' => (int) ($target['id'] ?? 0),
                    'title' => (string) ($target['title'] ?? ''),
                    'scope' => (string) ($target['scope'] ?? 'personal'),
                    'status_band' => $band,
                    'forecast_score' => (float) ($target['forecast_score'] ?? 0.0),
                    'pace_summary' => (string) ($target['pace_summary'] ?? ''),
                ];
            }
        }

        return [
            'active_targets' => count($activeTargets),
            'at_risk_targets' => $atRisk,
            'behind_targets' => $behind,
            'top_targets' => $topTargets,
        ];
    }

    private function getInboxState(int $userId, int $workspaceId): array
    {
        $threadStatusColumn = $this->columnExists('conversation_threads', 'status');
        $unread = $this->tableExists('communications') && $this->columnExists('communications', 'read_at')
            ? (int) (Database::queryOne("SELECT COUNT(*) AS c FROM communications WHERE workspace_id = ? AND read_at IS NULL", [$workspaceId])['c'] ?? 0)
            : 0;
        $openThreads = $this->tableExists('conversation_threads')
            ? (int) (Database::queryOne(
                $threadStatusColumn
                    ? "SELECT COUNT(*) AS c FROM conversation_threads WHERE workspace_id = ? AND status <> 'resolved'"
                    : "SELECT COUNT(*) AS c FROM conversation_threads WHERE workspace_id = ?",
                [$workspaceId]
            )['c'] ?? 0)
            : 0;
        $overdueThreads = $this->tableExists('conversation_threads')
            && $this->columnExists('conversation_threads', 'response_due_at')
            ? (int) (Database::queryOne(
                $threadStatusColumn
                    ? "SELECT COUNT(*) AS c FROM conversation_threads WHERE workspace_id = ? AND status IN ('open','waiting_on_us') AND response_due_at IS NOT NULL AND response_due_at < NOW()"
                    : "SELECT COUNT(*) AS c FROM conversation_threads WHERE workspace_id = ? AND response_due_at IS NOT NULL AND response_due_at < NOW()",
                [$workspaceId]
            )['c'] ?? 0)
            : 0;
        $unassignedThreads = $this->tableExists('conversation_threads') && $this->columnExists('conversation_threads', 'current_owner_id')
            ? (int) (Database::queryOne(
                $threadStatusColumn
                    ? "SELECT COUNT(*) AS c FROM conversation_threads WHERE workspace_id = ? AND status <> 'resolved' AND (current_owner_id IS NULL OR current_owner_id = 0)"
                    : "SELECT COUNT(*) AS c FROM conversation_threads WHERE workspace_id = ? AND (current_owner_id IS NULL OR current_owner_id = 0)",
                [$workspaceId]
            )['c'] ?? 0)
            : 0;
        return [
            'unread_priority_items' => $unread,
            'open_threads' => $openThreads,
            'overdue_threads' => $overdueThreads,
            'unassigned_threads' => $unassignedThreads,
            'triage_opt_out' => $this->preferences->isInboxTriageOptOut($userId),
        ];
    }

    private function getAiSettings(int $userId): array
    {
        $preferenceUserId = $this->workspaceScope->resolvePreferenceUserId(null, $userId > 0 ? $userId : null);
        $languageLevel = new WorkspaceLanguageLevelService();

        return [
            'context_strictness' => $this->preferences->getAIContextStrictness($userId) ?? 'strict',
            'response_style_contract' => $languageLevel->currentResponseStyleContract($userId > 0 ? $userId : null),
            'advice_min_confidence' => $this->thresholds->getCurrentThreshold('ai_advice_min_confidence', 'coach', 'advice', $userId) ?? 0.88,
            'action_min_confidence' => $this->thresholds->getCurrentThreshold('ai_action_min_confidence', 'assistant', 'action', $userId) ?? 0.92,
            'goal_relevance_min_score' => $this->thresholds->getCurrentThreshold('ai_goal_relevance_min_score', 'coach', 'advice', $userId) ?? ($this->preferences->getAIGoalRelevanceMinScore($userId) ?? 0.70),
            'auto_task_completion_enabled' => $this->preferences->isAIAutoTaskCompletionEnabled($userId),
            'auto_task_completion_min_confidence' => $this->thresholds->getCurrentThreshold('ai_auto_task_completion_min_confidence', 'task_automation', 'task', $userId) ?? 0.95,
            'missing_context_behavior' => $this->preferences->getAIMissingContextBehavior($userId) ?? 'warn',
            'mode_lock' => $this->preferences->getAIModeLock($userId) ?? 'auto',
            'autonomous_threshold_tuning_enabled' => $this->preferences->isAIAutonomousThresholdTuningEnabled($preferenceUserId),
            'calibration_last_run_at' => $this->preferences->getAICalibrationLastRunAt($preferenceUserId),
            'calibration_min_sample_size' => $this->preferences->getAICalibrationMinSampleSize($preferenceUserId),
            'calibration_daily_change_cap' => $this->preferences->getAICalibrationDailyChangeCap($preferenceUserId),
            'calibration_rolling_change_cap' => $this->preferences->getAICalibrationRollingChangeCap($preferenceUserId),
            'lean_canvas_mode_enabled' => $this->preferences->isLeanCanvasModeEnabled($userId),
        ];
    }

    private function getCompanyContext(): array
    {
        $profile = (new CompanyProfile())->get() ?? [];

        return [
            'name' => (string) ($profile['company_name'] ?? ''),
            'tagline' => (string) ($profile['company_tagline'] ?? ''),
            'description' => (string) ($profile['company_description'] ?? ''),
            'mission' => (string) ($profile['company_mission'] ?? ''),
            'values' => (string) ($profile['company_values'] ?? ''),
            'industry' => (string) ($profile['company_industry'] ?? ''),
            'location' => (string) ($profile['company_location'] ?? ''),
            'website' => (string) ($profile['company_website'] ?? ''),
            'owner_additional_context' => (string) ($profile['owner_company_context'] ?? ''),
        ];
    }

    private function getUserStrategyContext(int $userId): array
    {
        $strategyProfile = [];
        $ideaValidation = [];
        $budget = [];

        try {
            $strategyProfile = (new UserStrategyProfile())->get($userId) ?? [];
        } catch (\Throwable $e) {
            $strategyProfile = [];
        }
        $strategyProfileModule = new UserStrategyProfile();
        $strategySnapshotModule = new UserStrategySnapshot();
        $activeStrategySnapshot = null;
        try {
            $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
            $brief = $strategySnapshotModule->getCurrentBrief($workspaceId, $userId, true);
            $activeStrategySnapshot = $brief['active_strategy_snapshot'] ?? null;
        } catch (\Throwable $e) {
            $activeStrategySnapshot = null;
        }

        try {
            $ideaValidation = (new IdeaValidationContext())->get($userId) ?? [];
        } catch (\Throwable $e) {
            $ideaValidation = [];
        }

        try {
            $budget = (new BeginnerBudget())->get($userId) ?? [];
        } catch (\Throwable $e) {
            $budget = [];
        }

        $segments = array_values(array_filter([
            trim((string) ($strategyProfile['segment_focus'] ?? '')),
            trim((string) ($ideaValidation['target_market'] ?? '')),
        ]));

        return [
            'has_explicit_profile' => !empty($strategyProfile),
            'target_market_focus' => (string) ($strategyProfile['target_market_focus'] ?? ''),
            'ideal_customer_profile' => (string) ($strategyProfile['ideal_customer_profile'] ?? ''),
            'offer_angle' => (string) ($strategyProfile['offer_angle'] ?? ''),
            'segment_focus' => (string) ($strategyProfile['segment_focus'] ?? ''),
            'sales_motion' => (string) ($strategyProfile['sales_motion'] ?? ''),
            'deal_movement_strategy' => (string) ($strategyProfile['deal_movement_strategy'] ?? ''),
            'outreach_posture' => (string) ($strategyProfile['outreach_posture'] ?? ''),
            'positioning_notes' => (string) ($strategyProfile['positioning_notes'] ?? ''),
            'market_view' => (string) ($strategyProfile['market_view'] ?? ''),
            'strategy_hypothesis' => (string) ($strategyProfile['strategy_hypothesis'] ?? ''),
            'active_strategy_snapshot' => $activeStrategySnapshot,
            'lean_canvas_mode_enabled' => $this->preferences->isLeanCanvasModeEnabled($userId),
            'lean_canvas' => $strategyProfileModule->getLeanCanvas($userId),
            'lean_canvas_context' => $strategyProfileModule->getLeanCanvasContextForPrompt($userId),
            'lean_canvas_status' => $strategyProfileModule->getLeanCanvasStatus($userId),
            'startup_journey' => $this->getStartupJourneyContext((int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0), $userId),
            'finance_context' => $this->getFinanceContext((int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0), $userId),
            'founder_operating_loop' => $this->getFounderOperatingLoopContext((int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0), $userId),
            'idea_validation' => [
                'value_proposition' => (string) ($ideaValidation['value_proposition'] ?? ''),
                'target_market' => (string) ($ideaValidation['target_market'] ?? ''),
                'pain_points' => (string) ($ideaValidation['pain_points'] ?? ''),
                'assumptions_to_test' => (string) ($ideaValidation['assumptions_to_test'] ?? ''),
                'competitors' => (string) ($ideaValidation['competitors'] ?? ''),
                'differentiator' => (string) ($ideaValidation['differentiator'] ?? ''),
            ],
            'budget_context' => [
                'monthly_marketing_budget' => isset($budget['monthly_marketing_budget']) ? (float) $budget['monthly_marketing_budget'] : 0.0,
                'monthly_fixed_costs' => isset($budget['monthly_fixed_costs']) ? (float) $budget['monthly_fixed_costs'] : 0.0,
                'target_deal_value' => isset($budget['target_deal_value']) ? (float) $budget['target_deal_value'] : 0.0,
                'target_cac' => isset($budget['target_cac']) && $budget['target_cac'] !== null ? (float) $budget['target_cac'] : null,
                'currency_code' => (string) ($budget['currency_code'] ?? 'USD'),
            ],
            'summary' => [
                'primary_segment' => (string) ($strategyProfile['segment_focus'] ?? $ideaValidation['target_market'] ?? ''),
                'offer_angle' => (string) ($strategyProfile['offer_angle'] ?? ''),
                'sales_motion' => (string) ($strategyProfile['sales_motion'] ?? ''),
                'deal_strategy' => (string) ($strategyProfile['deal_movement_strategy'] ?? ''),
                'market_view' => (string) ($strategyProfile['market_view'] ?? ''),
                'strategy_hypothesis' => (string) ($strategyProfile['strategy_hypothesis'] ?? ''),
                'active_snapshot_version' => isset($activeStrategySnapshot['version']) ? (int) $activeStrategySnapshot['version'] : 0,
                'segments_in_play' => $segments,
            ],
        ];
    }

    private function getLeanCanvasStatus(int $userId): array
    {
        $strategyProfile = new UserStrategyProfile();
        return $strategyProfile->getLeanCanvasStatus($userId);
    }

    private function getStartupJourneyContext(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('startup_journeys')) {
            return [];
        }

        try {
            return (new StartupJourneyService())->getContextForAI($workspaceId, $userId);
        } catch (\Throwable $e) {
            return ['error' => 'startup_journey_context_unavailable'];
        }
    }

    private function getFinanceContext(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('finance_expenses')) {
            return [];
        }

        try {
            return (new FounderFinanceService())->aiContext($workspaceId, $userId);
        } catch (\Throwable $e) {
            return ['error' => 'finance_context_unavailable'];
        }
    }

    private function getFinanceAccountingContext(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('finance_expenses')) {
            return [];
        }

        try {
            return (new FounderFinanceService())->accountingContext($workspaceId, $userId);
        } catch (\Throwable $e) {
            return ['error' => 'finance_accounting_context_unavailable'];
        }
    }

    private function getFounderOperatingLoopContext(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('founder_weekly_reviews')) {
            return [];
        }

        try {
            return (new FounderOperatingLoopService())->contextForAI($workspaceId, $userId);
        } catch (\Throwable $e) {
            return ['error' => 'founder_operating_loop_context_unavailable'];
        }
    }

    public function buildPlatformOpsContext(int $workspaceId): array
    {
        return $this->contextRegistry->platformOpsContext($workspaceId);
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function countScopedProducts(int $workspaceId, bool $pricedOnly): int
    {
        if (!$this->tableExists('products') || !$this->columnExists('products', 'workspace_id')) {
            return 0;
        }

        $priceFilter = $pricedOnly ? " AND COALESCE(unit_price, 0) > 0" : '';

        try {
            return (int) (Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM products
                 WHERE workspace_id = ?
                   AND is_active = TRUE{$priceFilter}",
                [$workspaceId]
            )['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function logCapabilityState(int $userId, string $surface, string $capabilityKey, string $status, string $reason): void
    {
        if (!$this->tableExists('ai_capability_state_log')) {
            return;
        }
        try {
            $workspaceId = $this->workspaceScope->requireWorkspaceId();
            Database::execute(
                "INSERT INTO ai_capability_state_log (workspace_id, user_id, surface, capability_key, status, reason, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $userId, $surface, $capabilityKey, $status, $reason, json_encode([])]
            );
        } catch (\Throwable $e) {
        }
    }
}
