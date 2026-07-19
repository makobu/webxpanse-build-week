<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Modules\UserStrategySnapshot;
use CRM\Modules\Tasks;

class HRAnalyticsService
{
    private AnalyticsWorkspaceService $analyticsWorkspace;
    private UserStrategySnapshot $strategySnapshots;
    private OrganizationFunctionService $organizationFunctions;
    private PluginRuntimeEventService $runtimeEvents;

    public function __construct(
        private ?HRAnalyticsSettings $settingsModule = null,
        private ?HRAnalyticsAiService $ai = null,
        ?PluginRuntimeEventService $runtimeEvents = null,
    ) {
        $this->settingsModule = $this->settingsModule ?? new HRAnalyticsSettings();
        $this->ai = $this->ai ?? new HRAnalyticsAiService();
        $this->runtimeEvents = $runtimeEvents ?? new PluginRuntimeEventService();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
        $this->strategySnapshots = new UserStrategySnapshot();
        $this->organizationFunctions = new OrganizationFunctionService();
    }

    public function buildDashboard(array $filters = [], bool $allowAi = true, array $options = []): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $startedAt = microtime(true);
        $capability = trim((string) ($options['capability'] ?? 'dashboard'));
        $includeSwot = array_key_exists('swot', $options) ? !empty($options['swot']) : true;
        $includeManagerTips = array_key_exists('manager_tips', $options) ? !empty($options['manager_tips']) : true;
        $includeStrategicPointers = array_key_exists('strategic_pointers', $options) ? !empty($options['strategic_pointers']) : true;

        try {
        $this->organizationFunctions->ensureDefaults($workspaceId);
        $settings = $this->settingsModule->get($workspaceId);
        $window = $this->resolveWindow((string) ($filters['timeframe'] ?? 'month'));
        $profile = (new OrganizationIntelligenceProfileService())->refreshInference($workspaceId);
        $employees = $this->buildEmployeeSnapshots($filters, $window, $settings);

        usort($employees, static function (array $left, array $right): int {
            $leftEligible = (string) ($left['score_status'] ?? '') === 'eligible';
            $rightEligible = (string) ($right['score_status'] ?? '') === 'eligible';
            if ($leftEligible !== $rightEligible) {
                return $rightEligible <=> $leftEligible;
            }
            return (($right['score'] ?? -1) <=> ($left['score'] ?? -1)) ?: strcmp((string) $left['name'], (string) $right['name']);
        });

        $summary = $this->buildSummary($employees, $settings['thresholds']);
        $departmentSummaries = $this->buildDepartmentSummaries($employees, $settings['thresholds']);
        $roleSummaries = $this->buildRoleSummaries($employees);
        $functionCatalog = $this->organizationFunctions->listActiveFunctions($workspaceId);
        $userFunctionProfiles = $this->buildUserFunctionProfiles($employees);
        $functionCoverage = $this->buildFunctionCoverage($functionCatalog, $employees, $summary);
        $functionPerformance = $this->buildFunctionPerformance($functionCoverage);
        $founderLoad = $this->buildFounderLoad($employees, $functionCoverage, $profile);
        $organizationStage = $this->deriveOrganizationStage($summary, $departmentSummaries, $functionCoverage, $founderLoad, $profile);
        $functionDependencyRisks = $this->buildFunctionDependencyRisks($functionCoverage, $founderLoad);
        $departmentReadiness = $this->buildDepartmentReadiness($departmentSummaries, $functionCoverage);
        $nextFunctionToFormalize = $this->buildNextFunctionToFormalize($functionCoverage, $founderLoad);
        $contextualRecommendations = $this->buildContextualRecommendations($organizationStage, $functionCoverage, $founderLoad, $departmentReadiness, $nextFunctionToFormalize);
        $bestStrategies = $this->buildBestStrategies($employees, $summary);
        $strategyLeaderboard = $this->buildStrategyLeaderboard($employees, $window, $settings);
        $gaps = $this->buildGapAnalysis($employees, $departmentSummaries, $settings['thresholds']);
        $evidenceConfidence = $this->buildEvidenceConfidence($employees, $summary, $window);
        $peopleRisks = $this->buildPeopleRisks($employees, $departmentSummaries, $gaps, $settings['thresholds'], $evidenceConfidence, $functionDependencyRisks);
        $departmentIntelligence = $this->buildDepartmentIntelligence($departmentSummaries, $employees, $gaps, $bestStrategies, $peopleRisks);
        $structuralReadiness = $this->buildStructuralReadiness($functionCoverage, $departmentSummaries, $profile);
        $dataQuality = $this->buildDataQuality($employees, $window);
        $organizationHealth = $this->buildOrganizationHealth($summary, $departmentSummaries, $peopleRisks, $bestStrategies, $evidenceConfidence, $functionCoverage, $founderLoad, $structuralReadiness);
        $leadershipAttention = $this->buildLeadershipAttention($summary, $departmentSummaries, $gaps, $peopleRisks, $departmentIntelligence, $organizationHealth, $evidenceConfidence, $functionCoverage, $founderLoad, $nextFunctionToFormalize);
        $founderBrief = $this->buildFounderBrief($summary, $organizationHealth, $leadershipAttention, $departmentIntelligence, $bestStrategies, $evidenceConfidence, $organizationStage, $founderLoad, $nextFunctionToFormalize);
        $operatingTrends = $this->buildOperatingTrends($filters, $window, $settings, $employees, $organizationHealth, $functionCoverage);

        $organizationSwotSummary = array_merge((array) $summary, [
            'organization_stage' => $organizationStage,
            'function_coverage' => $functionCoverage,
            'function_performance' => $functionPerformance,
            'founder_load' => $founderLoad,
            'function_dependency_risks' => $functionDependencyRisks,
            'department_readiness' => $departmentReadiness,
            'next_function_to_formalize' => $nextFunctionToFormalize,
            'evidence_confidence' => $evidenceConfidence,
        ]);

        $swot = ['org' => [], 'departments' => []];
        if ($includeSwot) {
            $swot['org'] = $this->ai->buildSwot('Organization', $organizationSwotSummary, $bestStrategies, $gaps, $allowAi);
            foreach ($departmentSummaries as $department => $departmentSummary) {
                $departmentGaps = array_values(array_filter($gaps, static fn(array $gap): bool => (($gap['department'] ?? '') === $department) || (($gap['scope_type'] ?? '') === 'department' && ($gap['scope_label'] ?? '') === $department)));
                $departmentStrategies = array_values(array_filter($bestStrategies, static fn(array $strategy): bool => in_array($department, (array) ($strategy['departments'] ?? []), true)));
                $departmentMembers = array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['department'] ?? '') === (string) $department));
                $departmentSwotSummary = array_merge((array) $departmentSummary, [
                    'evidence_confidence' => $this->buildEvidenceConfidence($departmentMembers, (array) $departmentSummary, $window),
                    'small_sample_caveat' => count($departmentMembers) < 3 ? 'Small department sample; treat patterns as directional and validate with managers.' : '',
                ]);
                $swot['departments'][$department] = $this->ai->buildSwot($department, $departmentSwotSummary, $departmentStrategies, $departmentGaps, $allowAi);
            }
        }

        $dashboard = [
            'filters' => [
                'timeframe' => $window['label'],
                'role' => $filters['role'] ?? '',
                'department' => $filters['department'] ?? '',
                'user_id' => !empty($filters['user_id']) ? (int) $filters['user_id'] : null,
            ],
            'settings' => $settings,
            'summary' => $summary,
            'employees' => $employees,
            'department_summaries' => $departmentSummaries,
            'role_summaries' => $roleSummaries,
            'best_performers' => array_slice(array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible')), 0, 5),
            'struggling_performers' => array_slice(array_values(array_reverse(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible'))), 0, min(5, count($employees))),
            'best_strategies' => $bestStrategies,
            'strategy_leaderboard' => $strategyLeaderboard,
            'gaps' => $gaps,
            'manager_tips' => $includeManagerTips ? $this->ai->buildManagerTips($summary, $gaps, $bestStrategies, 'All Teams', $allowAi) : [],
            'strategic_pointers' => $includeStrategicPointers ? $this->ai->buildStrategicPointers($roleSummaries, $bestStrategies, $gaps, $allowAi) : [],
            'founder_brief' => $founderBrief,
            'schema_version' => 2,
            'calculation_version' => OrganizationIntelligenceSnapshotService::CALCULATION_VERSION,
            'organization_profile' => $profile,
            'data_quality' => $dataQuality,
            'structural_readiness' => $structuralReadiness,
            'organization_stage' => $organizationStage,
            'function_coverage' => $functionCoverage,
            'function_performance' => $functionPerformance,
            'user_function_profiles' => $userFunctionProfiles,
            'founder_load' => $founderLoad,
            'function_dependency_risks' => $functionDependencyRisks,
            'department_readiness' => $departmentReadiness,
            'next_function_to_formalize' => $nextFunctionToFormalize,
            'contextual_recommendations' => $contextualRecommendations,
            'organization_health' => $organizationHealth,
            'leadership_attention' => $leadershipAttention,
            'people_risks' => $peopleRisks,
            'department_intelligence' => $departmentIntelligence,
            'evidence_confidence' => $evidenceConfidence,
            'operating_trends' => $operatingTrends,
            'swot' => $swot,
        ];
        $this->recordRuntimeEvent($workspaceId, $capability, 'capability_succeeded', 'success', $startedAt, [
            'allow_ai' => $allowAi,
            'include_swot' => $includeSwot,
            'include_manager_tips' => $includeManagerTips,
            'include_strategic_pointers' => $includeStrategicPointers,
            'timeframe' => (string) ($window['label'] ?? ''),
            'employee_count' => count($employees),
            'engine_version' => 'v2',
            'calculation_version' => OrganizationIntelligenceSnapshotService::CALCULATION_VERSION,
            'eligible_people_count' => (int) ($dataQuality['eligible_people_count'] ?? 0),
            'total_people_count' => (int) ($dataQuality['total_people_count'] ?? 0),
            'evidence_coverage' => (float) ($dataQuality['average_evidence_coverage'] ?? 0),
            'snapshot_age_hours' => $operatingTrends['snapshot_diagnostics']['age_hours'] ?? null,
            'snapshot_status' => (string) ($operatingTrends['snapshot_diagnostics']['status'] ?? 'missing'),
            'organization_model' => (string) ($profile['effective_model'] ?? ''),
            'mobile_schema_version' => $capability === 'mobile_dashboard' ? 2 : null,
        ]);

        return $dashboard;
        } catch (\Throwable $e) {
            $this->recordRuntimeEvent($workspaceId, $capability, 'capability_failed', 'failed', $startedAt, [
                'allow_ai' => $allowAi,
                'filters' => $this->safeRuntimeMetadata($filters),
            ], 'dashboard_build_failed', $e->getMessage());
            throw $e;
        }
    }

    public function buildSummaryPayload(array $filters = []): array
    {
        $dashboard = $this->buildDashboard($filters, false, [
            'capability' => 'summary',
            'swot' => false,
            'manager_tips' => false,
            'strategic_pointers' => false,
        ]);
        return [
            'summary' => $dashboard['summary'],
            'best_performers' => $dashboard['best_performers'],
            'struggling_performers' => $dashboard['struggling_performers'],
            'best_strategies' => $dashboard['best_strategies'],
            'strategy_leaderboard' => $dashboard['strategy_leaderboard'],
            'department_summaries' => $dashboard['department_summaries'],
            'role_summaries' => $dashboard['role_summaries'],
            'filters' => $dashboard['filters'],
        ];
    }

    public function buildSwotPayload(array $filters = []): array
    {
        $dashboard = $this->buildDashboard($filters, true, [
            'capability' => 'swot',
            'manager_tips' => false,
            'strategic_pointers' => false,
        ]);
        return [
            'summary' => $dashboard['summary'],
            'swot' => $dashboard['swot'],
            'evidence_confidence' => $dashboard['evidence_confidence'],
        ];
    }

    public function buildTipsPayload(array $filters = []): array
    {
        $dashboard = $this->buildDashboard($filters, true, [
            'capability' => 'tips',
            'swot' => false,
        ]);
        return [
            'summary' => $dashboard['summary'],
            'manager_tips' => $dashboard['manager_tips'],
            'gaps' => $dashboard['gaps'],
            'strategic_pointers' => $dashboard['strategic_pointers'],
        ];
    }

    public function buildActionPlanDraft(array $filters = [], ?int $targetUserId = null, ?string $department = null, bool $allowAi = true): array
    {
        $dashboard = $this->buildDashboard($filters, $allowAi, [
            'capability' => 'action_plan',
            'swot' => false,
            'manager_tips' => false,
            'strategic_pointers' => false,
        ]);
        return $this->buildActionPlanDraftFromDashboard($dashboard, $targetUserId, $department, $allowAi);
    }

    public function buildActionPlanDraftFromDashboard(array $dashboard, ?int $targetUserId = null, ?string $department = null, bool $allowAi = true): array
    {
        if ($targetUserId !== null && $targetUserId > 0) {
            foreach ($dashboard['employees'] as $employee) {
                if ((int) ($employee['id'] ?? 0) === $targetUserId) {
                    $gaps = array_values(array_filter($dashboard['gaps'], static fn(array $gap): bool => (int) ($gap['user_id'] ?? 0) === $targetUserId));
                    $scopeLabel = (string) ($employee['name'] ?? 'Staff member');
                    return $this->withActionPlanTaskCandidates(
                        $this->ai->buildActionPlanDraft($scopeLabel, $employee, $gaps, $allowAi),
                        $scopeLabel,
                        $employee,
                        $gaps
                    );
                }
            }
        }

        if ($department !== null && $department !== '') {
            $departmentSummary = $dashboard['department_summaries'][$department] ?? ['department' => $department];
            $gaps = array_values(array_filter($dashboard['gaps'], static fn(array $gap): bool => (($gap['department'] ?? '') === $department) || (($gap['scope_type'] ?? '') === 'department' && ($gap['scope_label'] ?? '') === $department)));
            return $this->withActionPlanTaskCandidates(
                $this->ai->buildActionPlanDraft($department, $departmentSummary, $gaps, $allowAi),
                $department,
                $departmentSummary,
                $gaps
            );
        }

        $organizationSummary = array_merge((array) $dashboard['summary'], [
            'organization_stage' => $dashboard['organization_stage'] ?? [],
            'function_coverage' => $dashboard['function_coverage'] ?? [],
            'function_performance' => $dashboard['function_performance'] ?? [],
            'founder_load' => $dashboard['founder_load'] ?? [],
            'function_dependency_risks' => $dashboard['function_dependency_risks'] ?? [],
            'department_readiness' => $dashboard['department_readiness'] ?? [],
            'next_function_to_formalize' => $dashboard['next_function_to_formalize'] ?? [],
            'evidence_confidence' => $dashboard['evidence_confidence'] ?? [],
        ]);

        return $this->withActionPlanTaskCandidates(
            $this->ai->buildActionPlanDraft('Organization', $organizationSummary, $dashboard['gaps'], $allowAi),
            'Organization',
            $organizationSummary,
            (array) ($dashboard['gaps'] ?? [])
        );
    }

    public function createCoachingTask(int $actorUserId, int $targetUserId, string $title, string $description = '', ?string $dueDate = null, string $priority = 'high'): int
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $targetUserId = $this->analyticsWorkspace->ensureScopedUserId($targetUserId, $workspaceId) ?? 0;
        if ($targetUserId <= 0) {
            throw new \RuntimeException('Target user is not part of the active workspace.');
        }

        $tasks = new Tasks();
        return $tasks->create([
            'title' => $title,
            'description' => $description,
            'assigned_to' => $targetUserId,
            'created_by' => $actorUserId,
            'actor_user_id' => $actorUserId,
            'status' => 'pending',
            'priority' => $priority,
            'due_date' => $dueDate,
            'metadata_json' => [
                'source_surface' => 'hr_analytics',
                'coaching_task' => true,
            ],
        ]);
    }

    public function createActionPlanTasks(int $actorUserId, array $taskCandidates, ?int $defaultAssigneeUserId = null): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $assigneeUserId = null;
        if ($defaultAssigneeUserId !== null && $defaultAssigneeUserId > 0) {
            $assigneeUserId = $this->analyticsWorkspace->ensureScopedUserId($defaultAssigneeUserId, $workspaceId);
            if (!$assigneeUserId) {
                throw new \RuntimeException('Task assignee is not part of the active workspace.');
            }
        } elseif ($actorUserId > 0) {
            $assigneeUserId = $this->analyticsWorkspace->ensureScopedUserId($actorUserId, $workspaceId);
        }

        $normalizedCandidates = [];
        foreach ($taskCandidates as $candidate) {
            if (is_string($candidate)) {
                $candidate = ['title' => $candidate];
            }
            if (!is_array($candidate)) {
                continue;
            }
            $normalized = $this->normalizeActionPlanTaskCandidate($candidate);
            if ($normalized['title'] === '') {
                continue;
            }
            $normalizedCandidates[] = $normalized;
        }

        if ($normalizedCandidates === []) {
            throw new \RuntimeException('Select at least one action-plan task candidate.');
        }

        $tasks = new Tasks();
        $taskIds = [];
        $createdTasks = [];
        $startedAt = microtime(true);

        foreach ($normalizedCandidates as $candidate) {
            $dueDate = (new \DateTimeImmutable('now'))
                ->modify('+' . (int) $candidate['due_date_offset'] . ' days')
                ->setTime(17, 0)
                ->format('Y-m-d H:i:s');
            $metadata = [
                'source_surface' => 'hr_analytics',
                'source_skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                'source_capability_key' => 'hr_analytics.action_plan',
                'action_plan_task' => true,
                'task_candidate_key' => $candidate['key'],
                'source_scope' => $candidate['source_scope'],
                'source_risk_type' => $candidate['source_risk_type'],
                'due_date_offset' => $candidate['due_date_offset'],
            ];

            $taskId = $tasks->create([
                'title' => $candidate['title'],
                'description' => $candidate['description'],
                'assigned_to' => $assigneeUserId,
                'created_by' => $actorUserId,
                'actor_user_id' => $actorUserId,
                'status' => 'pending',
                'priority' => $candidate['priority'],
                'due_date' => $dueDate,
                'metadata_json' => $metadata,
                'source_skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                'source_plugin_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
                'source_surface' => 'hr_analytics',
                'source_capability_key' => 'hr_analytics.action_plan',
            ]);
            $taskIds[] = $taskId;
            $createdTasks[] = [
                'id' => $taskId,
                'title' => $candidate['title'],
                'assigned_to' => $assigneeUserId,
                'due_date' => $dueDate,
                'priority' => $candidate['priority'],
                'source_scope' => $candidate['source_scope'],
                'source_risk_type' => $candidate['source_risk_type'],
            ];
        }

        $this->runtimeEvents->record([
            'workspace_id' => $workspaceId,
            'user_id' => $actorUserId > 0 ? $actorUserId : null,
            'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
            'capability_key' => 'hr_analytics.action_plan',
            'entity_type' => 'task',
            'entity_id' => $taskIds[0] ?? null,
            'event_type' => 'workflow_action_executed',
            'status' => 'success',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'metadata' => [
                'source' => 'hr_analytics',
                'action' => 'create_action_plan_tasks',
                'task_ids' => $taskIds,
                'task_count' => count($taskIds),
                'assignee_user_id' => $assigneeUserId,
            ],
        ]);

        return [
            'created_count' => count($taskIds),
            'task_ids' => $taskIds,
            'tasks' => $createdTasks,
        ];
    }

    private function withActionPlanTaskCandidates(array $draft, string $scopeLabel, array $subjectSummary, array $gaps): array
    {
        $draft['task_candidates'] = $this->buildActionPlanTaskCandidates($draft, $scopeLabel, $subjectSummary, $gaps);
        return $draft;
    }

    private function buildActionPlanTaskCandidates(array $draft, string $scopeLabel, array $subjectSummary, array $gaps): array
    {
        $sourceRiskType = $this->primaryActionPlanRiskType($gaps, $subjectSummary);
        $sourceEvidence = $this->primaryActionPlanEvidence($gaps, $subjectSummary);
        $candidateSources = [
            'seven_day_actions' => ['offset' => 7, 'priority' => 'high'],
            'actions' => ['offset' => 7, 'priority' => 'high'],
            'thirty_day_actions' => ['offset' => 30, 'priority' => 'medium'],
        ];
        $candidates = [];
        $seen = [];

        foreach ($candidateSources as $planKey => $meta) {
            foreach (array_values((array) ($draft[$planKey] ?? [])) as $index => $item) {
                $action = $this->actionPlanItemText($item);
                if ($action === '') {
                    continue;
                }
                $dedupeKey = strtolower($action);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $candidate = $this->normalizeActionPlanTaskCandidate([
                    'key' => $this->actionPlanTaskKey($scopeLabel, $sourceRiskType, $planKey, $index, $action),
                    'title' => $this->actionPlanTaskTitle($action),
                    'description' => implode("\n", array_filter([
                        'Scope: ' . $scopeLabel,
                        'Recommended action: ' . $action,
                        $sourceEvidence !== '' ? 'Evidence: ' . $sourceEvidence : '',
                        'Source risk: ' . str_replace('_', ' ', $sourceRiskType),
                    ])),
                    'priority' => $meta['priority'],
                    'due_date_offset' => $meta['offset'],
                    'source_scope' => $scopeLabel,
                    'source_risk_type' => $sourceRiskType,
                ]);
                $candidates[] = $candidate;

                if (count($candidates) >= 6) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    private function normalizeActionPlanTaskCandidate(array $candidate): array
    {
        $title = trim((string) ($candidate['title'] ?? ''));
        $description = trim((string) ($candidate['description'] ?? ''));
        $priority = strtolower(trim((string) ($candidate['priority'] ?? 'medium')));
        if (!in_array($priority, ['urgent', 'high', 'medium', 'low'], true)) {
            $priority = 'medium';
        }
        $offset = (int) ($candidate['due_date_offset'] ?? 7);
        if ($offset <= 0) {
            $offset = 7;
        }
        $offset = min(90, $offset);
        $sourceScope = trim((string) ($candidate['source_scope'] ?? 'Organization'));
        if ($sourceScope === '') {
            $sourceScope = 'Organization';
        }
        $sourceRiskType = strtolower(trim((string) ($candidate['source_risk_type'] ?? 'organization_action_plan')));
        $sourceRiskType = preg_replace('/[^a-z0-9_]+/', '_', $sourceRiskType) ?: 'organization_action_plan';
        $key = strtolower(trim((string) ($candidate['key'] ?? '')));
        if ($key === '') {
            $key = $this->actionPlanTaskKey($sourceScope, $sourceRiskType, 'task', 0, $title);
        }
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?: 'action_plan_task';

        return [
            'key' => substr($key, 0, 120),
            'title' => substr($title, 0, 180),
            'description' => $description,
            'priority' => $priority,
            'due_date_offset' => $offset,
            'source_scope' => substr($sourceScope, 0, 120),
            'source_risk_type' => substr($sourceRiskType, 0, 120),
        ];
    }

    private function actionPlanTaskKey(string $scopeLabel, string $riskType, string $planKey, int $index, string $action): string
    {
        $base = strtolower($scopeLabel . '-' . $riskType . '-' . $planKey . '-' . ($index + 1) . '-' . substr($action, 0, 42));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'action-plan-task';
        return trim($base, '-');
    }

    private function actionPlanTaskTitle(string $action): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $action) ?? $action);
        if (strlen($title) > 88) {
            $title = rtrim(substr($title, 0, 85), " \t\n\r\0\x0B.,;:") . '...';
        }
        return $title;
    }

    private function actionPlanItemText(mixed $item): string
    {
        if (is_array($item)) {
            foreach (['title', 'action', 'description', 'text'] as $key) {
                $value = trim((string) ($item[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        }
        if (is_object($item)) {
            return '';
        }
        return trim((string) $item);
    }

    private function primaryActionPlanRiskType(array $gaps, array $subjectSummary): string
    {
        foreach ($gaps as $gap) {
            $riskType = trim((string) ($gap['risk_type'] ?? ''));
            if ($riskType !== '') {
                return preg_replace('/[^a-z0-9_]+/', '_', strtolower($riskType)) ?: 'organization_action_plan';
            }
        }
        if (!empty($subjectSummary['next_function_to_formalize'])) {
            return 'function_formalization';
        }
        if ((int) ($subjectSummary['overdue_open_tasks'] ?? 0) > 0) {
            return 'workload_follow_through';
        }
        return 'organization_action_plan';
    }

    private function primaryActionPlanEvidence(array $gaps, array $subjectSummary): string
    {
        foreach ($gaps as $gap) {
            $evidence = trim((string) ($gap['evidence'] ?? $gap['summary'] ?? ''));
            if ($evidence !== '') {
                return $evidence;
            }
        }
        if (!empty($subjectSummary['evidence_confidence']['summary'])) {
            return (string) $subjectSummary['evidence_confidence']['summary'];
        }
        if (!empty($subjectSummary['organization_stage']['summary'])) {
            return (string) $subjectSummary['organization_stage']['summary'];
        }
        return '';
    }

    public function assignAccessRole(int $actorUserId, int $targetUserId, int $roleId): void
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $targetUserId = $this->analyticsWorkspace->ensureScopedUserId($targetUserId, $workspaceId) ?? 0;
        if ($targetUserId <= 0) {
            throw new \RuntimeException('Target user is not part of the active workspace.');
        }

        $actor = Database::queryOne("SELECT * FROM users WHERE id = ?", [$actorUserId]);
        if (!$actor || !Authorization::can('admin.users.manage', $actor) || !Authorization::can('hr.analytics.manage', $actor)) {
            throw new \RuntimeException('Insufficient permissions to update access roles.');
        }
        if (!Authorization::canAssignRole($roleId, $actor)) {
            throw new \RuntimeException('You cannot assign that access role.');
        }

        $role = Authorization::getRoleById($roleId);
        if (!$role || (int) ($role['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Access profile not found.');
        }

        Authorization::assignWorkspaceUserRole($workspaceId, $targetUserId, $roleId, $actorUserId);

        $membershipRoleSlug = trim((string) ($role['slug'] ?? ''));
        if ($membershipRoleSlug === '' || in_array($membershipRoleSlug, ['superadmin', 'admin_ops'], true)) {
            $membershipRoleSlug = 'viewer';
        }

        Database::execute(
            "UPDATE workspace_memberships
             SET role_slug = ?,
                 is_owner = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'",
            [
                $membershipRoleSlug,
                $membershipRoleSlug === 'owner' ? 1 : 0,
                $workspaceId,
                $targetUserId,
            ]
        );
    }

    private function buildEmployeeSnapshots(array $filters, array $window, array $settings): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $where = [];
        $params = [$workspaceId];

        $where[] = 'wm.workspace_id = ?';
        $where[] = "wm.membership_status = 'active'";

        if (!empty($filters['user_id'])) {
            $where[] = 'u.id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['role'])) {
            $roleFamily = strtolower((string) $filters['role']);
            $roleExpression = "LOWER(COALESCE(wr.slug, r.slug, wm.role_slug, u.role, ''))";
            if ($roleFamily === 'marketing') {
                $where[] = "{$roleExpression} LIKE '%marketing%'";
            } elseif ($roleFamily === 'sales') {
                $where[] = "{$roleExpression} LIKE '%sales%'";
            } elseif ($roleFamily === 'general') {
                $where[] = "{$roleExpression} NOT LIKE '%marketing%' AND {$roleExpression} NOT LIKE '%sales%'";
            }
        }
        if (!empty($filters['department'])) {
            $where[] = '(LOWER(COALESCE(wd.slug, legacy_d.slug, \'\')) = ? OR LOWER(COALESCE(wd.name, legacy_d.name, \'\')) = ?)';
            $params[] = strtolower((string) $filters['department']);
            $params[] = strtolower((string) $filters['department']);
        }

        $sql = "SELECT u.id, u.email, u.role, u.first_name, u.last_name, wm.is_owner,
                       COALESCE(wd.name, legacy_d.name) AS department_name,
                       COALESCE(wd.slug, legacy_d.slug) AS department_slug,
                       COALESCE(wr.slug, r.slug, wm.role_slug, u.role, 'general') AS access_role_slug,
                       COALESCE(wr.name, r.name, wm.role_slug, u.role, 'General') AS access_role_name,
                       u.last_login
                FROM users u
                INNER JOIN workspace_memberships wm ON wm.user_id = u.id
                LEFT JOIN departments wd ON wd.id = wm.department_id AND wd.workspace_id = wm.workspace_id
                LEFT JOIN departments legacy_d ON legacy_d.id = u.department_id
                LEFT JOIN workspace_user_roles wur ON wur.user_id = u.id AND wur.workspace_id = wm.workspace_id
                LEFT JOIN roles wr ON wr.id = wur.role_id
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY COALESCE(NULLIF(u.first_name, \'\'), u.email) ASC';

        $users = Database::query($sql, $params);
        $taskStats = $this->queryTaskStats($window, $workspaceId);
        $activityStats = $this->queryActivityStats($window, $workspaceId);
        $dealStats = $this->queryDealStats($window, $workspaceId);
        $campaignStats = $this->queryCampaignStats($window, $workspaceId);
        $outcomeStats = $this->queryOutcomeStats($window, $workspaceId);
        $systemTimeStats = $this->querySystemTimeStats($window, $workspaceId);
        $evidenceActiveDays = $this->queryEvidenceActiveDays($window, $workspaceId);
        $functionAssignments = $this->organizationFunctions->assignmentsForWorkspace($workspaceId, true);

        $employees = [];
        $fallbackAssignmentsByRole = [];
        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $roleBucket = $this->roleBucket((string) ($user['access_role_slug'] ?? $user['role'] ?? 'general'));
            $hasExplicitDepartment = trim((string) ($user['department_name'] ?? '')) !== '';
            $department = $hasExplicitDepartment ? (string) $user['department_name'] : $this->departmentForRole((string) ($user['access_role_slug'] ?? $user['role'] ?? 'general'), $settings['department_mappings']);

            $tasks = $taskStats[$userId] ?? [];
            $activities = $activityStats[$userId] ?? [];
            $deals = $dealStats[$userId] ?? [];
            $campaigns = $campaignStats[$userId] ?? [];
            $outcomes = $outcomeStats[$userId] ?? [];
            $systemTime = $systemTimeStats[$userId] ?? [];
            $metrics = $this->buildNormalizedMetrics($tasks, $activities, $deals, $campaigns, $outcomes, $settings['thresholds'], $window, (int) ($evidenceActiveDays[$userId] ?? 0));
            $weights = $settings['scoring_weights'][$roleBucket] ?? $settings['scoring_weights']['general'];
            $eligibility = $this->assessEvidenceEligibility($metrics, $weights, $window);
            $roleScore = !empty($eligibility['eligible']) ? $this->calculateScore($metrics, $weights) : null;
            $assignments = $functionAssignments[$userId] ?? [];
            if ($assignments === []) {
                $fallbackKey = strtolower((string) ($user['access_role_slug'] ?? $user['role'] ?? 'general')) . ':' . (!empty($user['is_owner']) ? 'owner' : 'member');
                if (!array_key_exists($fallbackKey, $fallbackAssignmentsByRole)) {
                    $fallbackAssignmentsByRole[$fallbackKey] = $this->fallbackFunctionAssignments(
                        $workspaceId,
                        (string) ($user['access_role_slug'] ?? $user['role'] ?? 'general'),
                        !empty($user['is_owner'])
                    );
                }
                $assignments = $fallbackAssignmentsByRole[$fallbackKey];
            }
            $functionProfiles = $this->buildFunctionProfilesForEmployee($assignments, $metrics, $settings);
            $score = !empty($eligibility['eligible']) ? $this->blendFunctionScore($functionProfiles, $roleScore) : null;
            $explicitFunctionCount = count(array_filter($assignments, static fn(array $assignment): bool => empty($assignment['is_inferred'])));
            $inferredFunctionCount = count($assignments) - $explicitFunctionCount;

            $name = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
            $employees[] = [
                'id' => $userId,
                'name' => $name !== '' ? $name : (string) ($user['email'] ?? 'User #' . $userId),
                'email' => (string) ($user['email'] ?? ''),
                'role' => $roleBucket,
                'role_label' => (string) ($user['access_role_name'] ?? ucfirst($roleBucket)),
                'is_owner' => !empty($user['is_owner']),
                'department' => $department,
                'department_source' => $hasExplicitDepartment ? 'explicit' : 'inferred_lane',
                'score' => $score,
                'legacy_role_score' => $roleScore,
                'score_status' => !empty($eligibility['eligible']) ? 'eligible' : 'insufficient_evidence',
                'score_eligibility_reason' => (string) ($eligibility['reason'] ?? ''),
                'evidence_families' => (array) ($eligibility['families'] ?? []),
                'evidence_coverage' => (float) ($eligibility['weight_coverage'] ?? 0),
                'evidence_event_count' => (int) ($eligibility['event_count'] ?? 0),
                'band' => $score !== null ? $this->scoreBand($score, $settings['thresholds']) : 'insufficient_evidence',
                'last_login' => $user['last_login'] ?? null,
                'tasks_completed' => (int) ($tasks['completed_tasks'] ?? 0),
                'open_tasks' => (int) ($tasks['open_tasks'] ?? 0),
                'overdue_open_tasks' => (int) ($tasks['overdue_open_tasks'] ?? 0),
                'deal_wins' => (int) ($deals['won_deals'] ?? 0),
                'deals_advanced' => (int) ($deals['active_deals'] ?? 0),
                'campaigns_run' => (int) ($campaigns['campaigns_created'] ?? 0),
                'touchpoints' => (int) ($campaigns['touchpoints_created'] ?? 0),
                'activity_count' => (int) ($activities['activity_count'] ?? 0),
                'active_days' => (int) ($metrics['active_days'] ?? 0),
                'system_session_count' => (int) ($systemTime['session_count'] ?? 0),
                'system_active_seconds' => (int) ($systemTime['active_seconds'] ?? 0),
                'system_duration_seconds' => (int) ($systemTime['duration_seconds'] ?? 0),
                'system_active_minutes' => round(((int) ($systemTime['active_seconds'] ?? 0)) / 60, 1),
                'system_active_hours' => round(((int) ($systemTime['active_seconds'] ?? 0)) / 3600, 1),
                'outcome_events' => (int) ($outcomes['outcome_events'] ?? 0),
                'metrics' => $metrics,
                'functions' => $assignments,
                'function_profiles' => $functionProfiles,
                'primary_function' => $this->primaryFunctionLabel($assignments),
                'function_load_count' => $explicitFunctionCount,
                'inferred_function_count' => $inferredFunctionCount,
            ];
        }

        return $employees;
    }

    private function queryTaskStats(array $window, int $workspaceId): array
    {
        $rows = Database::query(
            "SELECT assigned_to AS user_id,
                    COUNT(*) AS task_count,
                    SUM(CASE WHEN status = 'completed' AND completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed_tasks,
                    SUM(CASE WHEN status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS open_tasks,
                    SUM(CASE WHEN status IN ('pending','in_progress') AND due_date IS NOT NULL AND due_date < NOW() THEN 1 ELSE 0 END) AS overdue_open_tasks,
                    SUM(CASE WHEN priority IN ('high','urgent') AND status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS high_priority_open_tasks,
                    SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS tasks_created_window,
                    SUM(CASE WHEN created_at BETWEEN ? AND ? AND status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS open_tasks_window,
                    GREATEST(
                        COUNT(DISTINCT CASE WHEN created_at BETWEEN ? AND ? THEN DATE(created_at) END),
                        COUNT(DISTINCT CASE WHEN completed_at BETWEEN ? AND ? THEN DATE(completed_at) END)
                    ) AS task_active_days
             FROM tasks
             WHERE workspace_id = ?
               AND assigned_to IS NOT NULL
             GROUP BY assigned_to",
            [
                $window['start'], $window['end'],
                $window['start'], $window['end'],
                $window['start'], $window['end'],
                $window['start'], $window['end'],
                $window['start'], $window['end'],
                $workspaceId,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = $row;
        }
        return $result;
    }

    private function queryActivityStats(array $window, int $workspaceId): array
    {
        $rows = Database::query(
            "SELECT user_id,
                    COUNT(*) AS activity_count,
                    COUNT(DISTINCT DATE(created_at)) AS active_days
             FROM activities
             WHERE workspace_id = ?
               AND user_id IS NOT NULL
               AND created_at BETWEEN ? AND ?
             GROUP BY user_id",
            [$workspaceId, $window['start'], $window['end']]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = $row;
        }
        return $result;
    }

    private function queryDealStats(array $window, int $workspaceId): array
    {
        $rows = Database::query(
            "SELECT assigned_to AS user_id,
                    SUM(CASE WHEN stage = 'closed_won' AND actual_close_date BETWEEN DATE(?) AND DATE(?) THEN 1 ELSE 0 END) AS won_deals,
                    SUM(CASE WHEN stage NOT IN ('closed_won', 'closed_lost') THEN 1 ELSE 0 END) AS active_deals,
                    SUM(CASE WHEN updated_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS touched_deals
             FROM deals
             WHERE workspace_id = ?
               AND assigned_to IS NOT NULL
             GROUP BY assigned_to",
            [$window['start'], $window['end'], $window['start'], $window['end'], $workspaceId]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = $row;
        }
        return $result;
    }

    private function queryCampaignStats(array $window, int $workspaceId): array
    {
        $result = [];
        try {
            $rows = Database::query(
                "SELECT u.id AS user_id,
                        COUNT(DISTINCT CASE WHEN c.created_at BETWEEN ? AND ? THEN c.id END) AS campaigns_created,
                        COUNT(DISTINCT CASE WHEN tp.occurred_at BETWEEN ? AND ? THEN tp.id END) AS touchpoints_created
                 FROM users u
                 INNER JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'
                 LEFT JOIN campaigns c ON c.created_by = u.id AND c.workspace_id = wm.workspace_id
                 LEFT JOIN touchpoints tp ON tp.source_table = 'campaigns' AND tp.campaign_id = c.id
                 GROUP BY u.id",
                [$window['start'], $window['end'], $window['start'], $window['end'], $workspaceId]
            );
            foreach ($rows as $row) {
                $result[(int) $row['user_id']] = $row;
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $result;
    }

    private function queryOutcomeStats(array $window, int $workspaceId): array
    {
        if (!$this->columnExists('outcome_events', 'workspace_id')) {
            return [];
        }

        $rows = Database::query(
            "SELECT user_id,
                    COUNT(*) AS outcome_events,
                    SUM(CASE WHEN event_key IN ('task.followup.completed','deal.created','deal.advanced','deal.closed_won') THEN 1 ELSE 0 END) AS revenue_linked_events
             FROM outcome_events
             WHERE workspace_id = ?
               AND user_id IS NOT NULL
               AND event_at BETWEEN ? AND ?
             GROUP BY user_id",
            [$workspaceId, $window['start'], $window['end']]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = $row;
        }
        return $result;
    }

    private function querySystemTimeStats(array $window, int $workspaceId): array
    {
        if (!Database::tableExists('user_system_sessions')) {
            return [];
        }

        $rows = Database::query(
            "SELECT user_id,
                    COUNT(*) AS session_count,
                    SUM(COALESCE(active_seconds, 0)
                        + CASE
                            WHEN ended_at IS NULL
                            THEN LEAST(GREATEST(TIMESTAMPDIFF(SECOND, COALESCE(last_seen_at, started_at), NOW()), 0), 300)
                            ELSE 0
                          END) AS active_seconds,
                    SUM(
                        CASE
                            WHEN ended_at IS NOT NULL THEN GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, ended_at))
                            ELSE GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, COALESCE(last_seen_at, started_at)))
                        END
                    ) AS duration_seconds
             FROM user_system_sessions
             WHERE workspace_id = ?
               AND started_at <= ?
               AND COALESCE(ended_at, last_seen_at, started_at) >= ?
             GROUP BY user_id",
            [$workspaceId, $window['end'], $window['start']]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = [
                'session_count' => (int) ($row['session_count'] ?? 0),
                'active_seconds' => (int) ($row['active_seconds'] ?? 0),
                'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
            ];
        }

        return $result;
    }

    private function queryEvidenceActiveDays(array $window, int $workspaceId): array
    {
        $sources = [
            "SELECT assigned_to AS user_id, DATE(created_at) AS event_date FROM tasks WHERE workspace_id = ? AND assigned_to IS NOT NULL AND created_at BETWEEN ? AND ?",
            "SELECT assigned_to AS user_id, DATE(completed_at) AS event_date FROM tasks WHERE workspace_id = ? AND assigned_to IS NOT NULL AND completed_at BETWEEN ? AND ?",
            "SELECT user_id, DATE(created_at) AS event_date FROM activities WHERE workspace_id = ? AND user_id IS NOT NULL AND created_at BETWEEN ? AND ?",
            "SELECT assigned_to AS user_id, DATE(updated_at) AS event_date FROM deals WHERE workspace_id = ? AND assigned_to IS NOT NULL AND updated_at BETWEEN ? AND ?",
            "SELECT created_by AS user_id, DATE(created_at) AS event_date FROM campaigns WHERE workspace_id = ? AND created_by IS NOT NULL AND created_at BETWEEN ? AND ?",
            "SELECT c.created_by AS user_id, DATE(tp.occurred_at) AS event_date
             FROM touchpoints tp JOIN campaigns c ON c.id = tp.campaign_id
             WHERE c.workspace_id = ? AND c.created_by IS NOT NULL AND tp.occurred_at BETWEEN ? AND ?",
        ];
        if ($this->columnExists('outcome_events', 'workspace_id')) {
            $sources[] = "SELECT user_id, DATE(event_at) AS event_date FROM outcome_events WHERE workspace_id = ? AND user_id IS NOT NULL AND event_at BETWEEN ? AND ?";
        }
        $params = [];
        foreach ($sources as $_source) {
            array_push($params, $workspaceId, $window['start'], $window['end']);
        }
        $rows = Database::query(
            'SELECT user_id, COUNT(DISTINCT event_date) AS active_days FROM (' . implode(' UNION ALL ', $sources) . ') evidence_dates GROUP BY user_id',
            $params
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = (int) ($row['active_days'] ?? 0);
        }
        return $result;
    }

    private function buildNormalizedMetrics(array $tasks, array $activities, array $deals, array $campaigns, array $outcomes, array $thresholds, array $window, int $evidenceActiveDays = 0): array
    {
        $completed = (int) ($tasks['completed_tasks'] ?? 0);
        $activityCount = (int) ($activities['activity_count'] ?? 0);
        $activeDays = max($evidenceActiveDays, (int) ($activities['active_days'] ?? 0), (int) ($tasks['task_active_days'] ?? 0));
        $overdue = (int) ($tasks['overdue_open_tasks'] ?? 0);
        $openTasks = (int) ($tasks['open_tasks'] ?? 0);
        $highPriority = (int) ($tasks['high_priority_open_tasks'] ?? 0);
        $wonDeals = (int) ($deals['won_deals'] ?? 0);
        $activeDeals = (int) ($deals['active_deals'] ?? 0);
        $touchedDeals = (int) ($deals['touched_deals'] ?? 0);
        $campaignsCreated = (int) ($campaigns['campaigns_created'] ?? 0);
        $touchpoints = (int) ($campaigns['touchpoints_created'] ?? 0);
        $revenueEvents = (int) ($outcomes['revenue_linked_events'] ?? 0);
        $outcomeEvents = (int) ($outcomes['outcome_events'] ?? 0);
        $tasksCreated = (int) ($tasks['tasks_created_window'] ?? 0);
        $openTasksWindow = (int) ($tasks['open_tasks_window'] ?? 0);
        $daysInWindow = max(1, (int) $window['days']);

        $hasTaskEvidence = ($completed + $tasksCreated) > 0;
        $hasActivityEvidence = $activityCount > 0;
        $hasCommercialEvidence = ($wonDeals + $touchedDeals + $revenueEvents + $outcomeEvents) > 0;
        $hasCampaignEvidence = ($campaignsCreated + $touchpoints) > 0;
        $completionRate = $hasTaskEvidence ? $completed / max(1, $completed + $openTasksWindow) : null;
        $timelinessScore = $hasTaskEvidence ? 1 - min(1, $overdue / max(1, $thresholds['overloaded_task_count'])) : null;
        $activityConsistency = $hasActivityEvidence ? min(1, $activeDays / max(2, min(20, (int) ceil($daysInWindow * 0.45)))) : null;
        $outcomeImpact = $hasCommercialEvidence ? min(1, ($revenueEvents * 2 + $outcomeEvents) / 12) : null;
        $pipelineMovement = $hasCommercialEvidence ? min(1, (($wonDeals * 3) + $touchedDeals) / 16) : null;
        $campaignOutput = $hasCampaignEvidence ? min(1, (($campaignsCreated * 2) + $touchpoints) / 18) : null;
        $hasCurrentTaskContext = $hasTaskEvidence || $openTasks > 0;
        $workloadBalance = $hasCurrentTaskContext ? 1 - min(1, (($openTasks + $highPriority) / max(1, $thresholds['overloaded_task_count'] + 2))) : null;

        return [
            'task_completion' => $completionRate !== null ? round($completionRate * 100, 1) : null,
            'timeliness' => $timelinessScore !== null ? round($timelinessScore * 100, 1) : null,
            'activity_consistency' => $activityConsistency !== null ? round($activityConsistency * 100, 1) : null,
            'outcome_impact' => $outcomeImpact !== null ? round($outcomeImpact * 100, 1) : null,
            'pipeline_movement' => $pipelineMovement !== null ? round($pipelineMovement * 100, 1) : null,
            'campaign_output' => $campaignOutput !== null ? round($campaignOutput * 100, 1) : null,
            'workload_balance' => $workloadBalance !== null ? round(max(0, $workloadBalance) * 100, 1) : null,
            'activity_count' => $activityCount,
            'active_days' => $activeDays,
            'overdue_open_tasks' => $overdue,
            'open_tasks' => $openTasks,
            'tasks_created_window' => $tasksCreated,
            'tasks_completed_window' => $completed,
            'evidence_events' => [
                'tasks' => $completed + $tasksCreated,
                'activities' => $activityCount,
                'commercial' => $wonDeals + $touchedDeals + $revenueEvents + $outcomeEvents,
                'campaigns' => $campaignsCreated + $touchpoints,
            ],
        ];
    }

    private function calculateScore(array $metrics, array $weights): ?float
    {
        $score = 0.0;
        $weightTotal = 0.0;
        foreach ($weights as $metric => $weight) {
            if (!array_key_exists($metric, $metrics) || $metrics[$metric] === null) {
                continue;
            }
            $score += ((float) $metrics[$metric]) * (float) $weight;
            $weightTotal += (float) $weight;
        }
        if ($weightTotal <= 0) {
            return null;
        }
        return round(max(0, min(100, $score / $weightTotal)), 1);
    }

    private function assessEvidenceEligibility(array $metrics, array $weights, array $window): array
    {
        $events = (array) ($metrics['evidence_events'] ?? []);
        $families = array_keys(array_filter($events, static fn($count): bool => (int) $count > 0));
        $eventCount = array_sum(array_map('intval', $events));
        $activeDays = (int) ($metrics['active_days'] ?? 0);
        $days = (int) ($window['days'] ?? 30);
        $representedWeight = 0.0;
        foreach ($weights as $metric => $weight) {
            if (array_key_exists($metric, $metrics) && $metrics[$metric] !== null) {
                $representedWeight += (float) $weight;
            }
        }
        $minimumEvents = $days <= 7 ? 3 : 5;
        $minimumActiveDays = $days <= 7 ? 2 : 3;
        $eligible = $days > 1
            && count($families) >= 2
            && $eventCount >= $minimumEvents
            && $activeDays >= $minimumActiveDays
            && $representedWeight >= 0.60;
        $reason = $eligible ? 'Evidence threshold met.' : ($days <= 1
            ? 'Today is a live operational view; longitudinal people scores are not issued.'
            : 'At least two evidence families, enough dated events, and 60% metric coverage are required.');
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'families' => $families,
            'event_count' => $eventCount,
            'active_days' => $activeDays,
            'weight_coverage' => round($representedWeight, 4),
        ];
    }

    private function fallbackFunctionAssignments(int $workspaceId, string $roleSlug, bool $isOwner): array
    {
        $functionIds = $this->organizationFunctions->defaultFunctionIdsForRole($workspaceId, $roleSlug, $isOwner);
        $functionsById = [];
        foreach ($this->organizationFunctions->listActiveFunctions($workspaceId) as $function) {
            $functionsById[(int) $function['id']] = $function;
        }

        $out = [];
        foreach ($functionIds as $index => $functionId) {
            $function = $functionsById[$functionId] ?? null;
            if (!$function) {
                continue;
            }
            $out[] = [
                'assignment_id' => 0,
                'function_id' => $functionId,
                'name' => (string) ($function['name'] ?? ''),
                'slug' => (string) ($function['slug'] ?? ''),
                'description' => (string) ($function['description'] ?? ''),
                'category' => (string) ($function['category'] ?? 'core'),
                'measurement_strength' => (string) ($function['measurement_strength'] ?? 'partial'),
                'relevance_status' => (string) ($function['relevance_status'] ?? 'active'),
                'relevance_note' => (string) ($function['relevance_note'] ?? ''),
                'assignment_type' => $index === 0 ? 'owner' : 'oversight',
                'importance' => $index === 0 ? 'primary' : 'secondary',
                'is_primary' => $index === 0,
                'is_active' => true,
                'source' => 'inferred_role_default',
                'is_inferred' => true,
                'assignment_confidence' => 'low',
            ];
        }
        return $out;
    }

    private function buildFunctionProfilesForEmployee(array $assignments, array $metrics, array $settings): array
    {
        $profiles = [];
        foreach ($assignments as $assignment) {
            $slug = (string) ($assignment['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $isInferred = !empty($assignment['is_inferred']);
            $weights = $this->weightsForFunction($slug, $settings);
            $score = $this->calculateScore($metrics, $weights);
            $confidence = $isInferred
                ? 'low'
                : $this->functionConfidence($slug, (string) ($assignment['measurement_strength'] ?? 'partial'), $metrics);
            $measurable = !$isInferred && $score !== null && ($confidence !== 'low' || $this->visibleSignalCount($metrics) > 0);
            $measurementReason = $isInferred
                ? 'inferred_role_default'
                : $this->functionMeasurementReason($slug, (string) ($assignment['measurement_strength'] ?? 'partial'), $confidence, $measurable, $metrics);
            $profiles[] = [
                'function_id' => (int) ($assignment['function_id'] ?? 0),
                'name' => (string) ($assignment['name'] ?? $this->functionLabel($slug)),
                'slug' => $slug,
                'score' => $measurable ? $score : null,
                'display_score' => $measurable ? number_format($score, 1) : 'Not enough evidence',
                'confidence' => $confidence,
                'measurement_strength' => (string) ($assignment['measurement_strength'] ?? 'partial'),
                'measurement_reason' => $measurementReason,
                'relevance_status' => (string) ($assignment['relevance_status'] ?? 'active'),
                'relevance_note' => (string) ($assignment['relevance_note'] ?? ''),
                'is_primary' => !empty($assignment['is_primary']),
                'importance' => (string) ($assignment['importance'] ?? 'secondary'),
                'assignment_type' => (string) ($assignment['assignment_type'] ?? 'owner'),
                'source' => (string) ($assignment['source'] ?? ($isInferred ? 'inferred_role_default' : 'explicit_assignment')),
                'is_inferred' => $isInferred,
                'assignment_confidence' => (string) ($assignment['assignment_confidence'] ?? ($isInferred ? 'low' : 'high')),
                'evidence' => $this->functionEvidence($slug, $metrics),
                'interpretation' => $isInferred
                    ? 'Suggested from the access role. Confirm and save Work Ownership before using this as an accountability signal.'
                    : $this->functionInterpretation($slug, $confidence, $measurable),
            ];
        }
        return $profiles;
    }

    private function blendFunctionScore(array $functionProfiles, ?float $fallbackScore): ?float
    {
        $weighted = 0.0;
        $weightTotal = 0.0;
        foreach ($functionProfiles as $profile) {
            if (($profile['score'] ?? null) === null) {
                continue;
            }
            $weight = !empty($profile['is_primary']) ? 1.35 : 0.85;
            if ((string) ($profile['confidence'] ?? 'low') === 'low') {
                $weight *= 0.55;
            }
            $weighted += ((float) $profile['score']) * $weight;
            $weightTotal += $weight;
        }
        if ($weightTotal <= 0) {
            return $fallbackScore;
        }
        return round($weighted / $weightTotal, 1);
    }

    private function weightsForFunction(string $slug, array $settings): array
    {
        $marketing = $settings['scoring_weights']['marketing'] ?? $settings['scoring_weights']['general'];
        $sales = $settings['scoring_weights']['sales'] ?? $settings['scoring_weights']['general'];
        $general = $settings['scoring_weights']['general'];

        return match ($slug) {
            'marketing' => $marketing,
            'sales' => $sales,
            'leadership' => [
                'task_completion' => 0.18,
                'timeliness' => 0.16,
                'activity_consistency' => 0.12,
                'outcome_impact' => 0.22,
                'pipeline_movement' => 0.07,
                'campaign_output' => 0.04,
                'workload_balance' => 0.21,
            ],
            'strategy' => [
                'task_completion' => 0.16,
                'timeliness' => 0.12,
                'activity_consistency' => 0.10,
                'outcome_impact' => 0.28,
                'pipeline_movement' => 0.14,
                'campaign_output' => 0.06,
                'workload_balance' => 0.14,
            ],
            'operations', 'delivery', 'administration' => $general,
            'customer_success' => [
                'task_completion' => 0.22,
                'timeliness' => 0.18,
                'activity_consistency' => 0.18,
                'outcome_impact' => 0.14,
                'pipeline_movement' => 0.10,
                'campaign_output' => 0.02,
                'workload_balance' => 0.16,
            ],
            'finance', 'product', 'people_hr' => [
                'task_completion' => 0.24,
                'timeliness' => 0.18,
                'activity_consistency' => 0.10,
                'outcome_impact' => 0.18,
                'pipeline_movement' => 0.04,
                'campaign_output' => 0.02,
                'workload_balance' => 0.24,
            ],
            default => $general,
        };
    }

    private function functionConfidence(string $slug, string $measurementStrength, array $metrics): string
    {
        $signalCount = $this->visibleSignalCount($metrics);
        if (in_array($measurementStrength, ['weak'], true)) {
            return $signalCount >= 4 && !in_array($slug, ['finance', 'product'], true) ? 'moderate' : 'low';
        }
        if ($signalCount <= 1) {
            return 'low';
        }
        if ($measurementStrength === 'strong' && $signalCount >= 3) {
            return 'high';
        }
        return 'moderate';
    }

    private function visibleSignalCount(array $metrics): int
    {
        $keys = ['activity_count', 'active_days', 'open_tasks', 'overdue_open_tasks', 'task_completion', 'outcome_impact', 'pipeline_movement', 'campaign_output'];
        $count = 0;
        foreach ($keys as $key) {
            if ((float) ($metrics[$key] ?? 0) > 0) {
                $count++;
            }
        }
        return $count;
    }

    private function functionEvidence(string $slug, array $metrics): string
    {
        return match ($slug) {
            'marketing' => 'Campaign output ' . number_format((float) ($metrics['campaign_output'] ?? 0), 1) . '%, activity consistency ' . number_format((float) ($metrics['activity_consistency'] ?? 0), 1) . '%.',
            'sales' => 'Pipeline movement ' . number_format((float) ($metrics['pipeline_movement'] ?? 0), 1) . '%, outcome impact ' . number_format((float) ($metrics['outcome_impact'] ?? 0), 1) . '%.',
            'leadership', 'strategy' => 'Outcome impact ' . number_format((float) ($metrics['outcome_impact'] ?? 0), 1) . '%, workload balance ' . number_format((float) ($metrics['workload_balance'] ?? 0), 1) . '%.',
            default => 'Task completion ' . number_format((float) ($metrics['task_completion'] ?? 0), 1) . '%, timeliness ' . number_format((float) ($metrics['timeliness'] ?? 0), 1) . '%.',
        };
    }

    private function functionInterpretation(string $slug, string $confidence, bool $measurable): string
    {
        if (!$measurable || $confidence === 'low') {
            return in_array($slug, ['finance', 'product', 'people_hr'], true)
                ? 'This function is weakly measurable in CRM data; treat the signal as coverage or tracking evidence before performance judgment.'
                : 'The signal is directional and should be validated with context before judging performance.';
        }
        return 'This function has enough visible operating evidence for a directional performance read.';
    }

    private function functionMeasurementReason(string $slug, string $measurementStrength, string $confidence, bool $measurable, array $metrics): string
    {
        if (!$measurable || $confidence === 'low') {
            return in_array($slug, ['finance', 'product', 'people_hr'], true) ? 'insufficient_evidence' : 'coverage_only';
        }
        if ($measurementStrength === 'strong' && $confidence === 'high') {
            return 'measured';
        }
        if ($this->visibleSignalCount($metrics) >= 3) {
            return 'partially_measured';
        }
        return 'coverage_only';
    }

    private function primaryFunctionLabel(array $assignments): string
    {
        foreach ($assignments as $assignment) {
            if (!empty($assignment['is_primary']) && empty($assignment['is_inferred'])) {
                return (string) ($assignment['name'] ?? '');
            }
        }
        foreach ($assignments as $assignment) {
            if (!empty($assignment['is_primary'])) {
                return 'Suggested ' . (string) ($assignment['name'] ?? 'function');
            }
        }
        return (string) ($assignments[0]['name'] ?? 'Unassigned');
    }

    private function functionLabel(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }

    private function profileConfidence(array $profiles): string
    {
        if ($profiles === []) {
            return 'low';
        }
        $levels = array_column($profiles, 'confidence');
        if (in_array('high', $levels, true)) {
            return in_array('low', $levels, true) ? 'moderate' : 'high';
        }
        return in_array('moderate', $levels, true) ? 'moderate' : 'low';
    }

    private function functionCoverageState(string $slug, int $ownerCount, int $primaryOwners, bool $founderOwned, int $staffCount, ?float $avgScore, string $relevanceStatus): string
    {
        if ($relevanceStatus === 'not_applicable') {
            return 'not_applicable';
        }
        if ($relevanceStatus === 'deferred') {
            return 'deferred';
        }
        if ($relevanceStatus === 'outsourced') {
            return 'outsourced';
        }
        if ($ownerCount <= 0) {
            return 'unassigned';
        }
        if ($staffCount <= 1 || ($founderOwned && $ownerCount === 1)) {
            return 'founder_owned';
        }
        if ($ownerCount === 1) {
            return 'single_owner';
        }
        if ($ownerCount >= 2 && $avgScore !== null) {
            return 'department_backed';
        }
        return $primaryOwners > 0 ? 'shared_ownership' : 'informally_owned';
    }

    private function coverageConfidence(string $measurementStrength, int $ownerCount, array $scores): string
    {
        if ($ownerCount <= 0) {
            return 'high';
        }
        if ($scores === [] || $measurementStrength === 'weak') {
            return 'low';
        }
        if ($measurementStrength === 'strong' && count($scores) >= 2) {
            return 'high';
        }
        return 'moderate';
    }

    private function functionRiskLevel(string $state, ?float $avgScore, string $confidence): string
    {
        if (in_array($state, ['deferred', 'not_applicable', 'outsourced'], true)) {
            return 'low';
        }
        if (in_array($state, ['unassigned', 'founder_owned'], true)) {
            return 'high';
        }
        if ($avgScore !== null && $avgScore < 50) {
            return 'high';
        }
        if ($confidence === 'low' || $state === 'single_owner') {
            return 'medium';
        }
        return 'low';
    }

    private function functionCoverageEvidence(array $function, array $owners, array $participants, ?float $avgScore, array $inferredParticipants = []): string
    {
        $name = (string) ($function['name'] ?? 'Function');
        $relevance = (string) ($function['relevance_status'] ?? 'active');
        if ($relevance === 'deferred') {
            return "{$name} is intentionally deferred for the current organization stage.";
        }
        if ($relevance === 'outsourced') {
            return "{$name} is marked as outsourced; review vendor or partner governance rather than internal performance.";
        }
        if ($relevance === 'not_applicable') {
            return "{$name} is marked not applicable to this business model.";
        }
        $ownerCount = count($owners);
        if ($ownerCount <= 0) {
            if ($inferredParticipants !== []) {
                $suggestedNames = implode(', ', array_slice(array_map(static fn(array $participant): string => (string) ($participant['name'] ?? 'User'), $inferredParticipants), 0, 3));
                return "{$name} has no explicit owner. Role defaults suggest {$suggestedNames}, but setup has not confirmed this ownership.";
            }
            $participantCount = count($participants);
            return $participantCount > 0
                ? "{$name} has {$participantCount} supporting/oversight assignment(s), but no accountable owner."
                : "{$name} is active but has no assigned owner.";
        }
        $ownerNames = implode(', ', array_slice(array_map(static fn(array $owner): string => (string) ($owner['name'] ?? 'User'), $owners), 0, 3));
        $scoreText = $avgScore !== null ? ' Average visible function score: ' . number_format($avgScore, 1) . '.' : ' Function performance is not yet strongly measurable.';
        return "{$name} has {$ownerCount} assigned owner(s): {$ownerNames}.{$scoreText}";
    }

    private function functionNextMove(string $slug, string $state, string $confidence, string $coverageSource = 'explicit'): string
    {
        if ($state === 'deferred') {
            return 'Keep this out of performance scoring and set a date or trigger for when it should become active.';
        }
        if ($state === 'outsourced') {
            return 'Name the vendor or partner review rhythm and one accountability metric.';
        }
        if ($state === 'not_applicable') {
            return 'Exclude from operating coverage unless the business model changes.';
        }
        if ($state === 'unassigned') {
            if ($coverageSource === 'inferred_only') {
                return 'Review the suggested owner, then save explicit Work Ownership before using this as an accountability signal.';
            }
            return 'Assign a named owner or explicitly mark this function as not relevant for the current stage.';
        }
        if ($state === 'founder_owned') {
            return 'Decide whether this function should stay founder-owned this month or receive a temporary owner/check-in rhythm.';
        }
        if ($state === 'single_owner') {
            return 'Create backup coverage and one success metric so the function does not depend on one person silently.';
        }
        if ($confidence === 'low') {
            return 'Improve tracking before treating this as a performance issue.';
        }
        return 'Use function score movement and owner evidence in the next operating review.';
    }

    private function organizationStageSummary(string $stage): string
    {
        return match ($stage) {
            'solo_founder' => 'The company is operated by one founder. Read function coverage, personal capacity, and delegation readiness rather than department performance.',
            'multi_founder' => 'The company is operated by a founder cohort. Read shared ownership, founder load distribution, and continuity before introducing departments.',
            'founder_led_team' => 'The team is active, but material business functions still depend on founders or informal ownership.',
            'functional_team' => 'Work is organized through explicit business functions; formal departments remain optional until the structure and evidence justify them.',
            'departmental_organization' => 'The organization has confirmed department structure. Compare teams only where evidence coverage and sample sizes qualify.',
            'scaling_multi_team' => 'The organization has multiple established teams; prioritize manager support, continuity, workload distribution, and cross-team handoffs.',
            'founder_only' => 'The company is operating through the founder/owner. Read this as function coverage and founder load, not department performance.',
            'founder_led' => 'The company has team signal, but several functions still depend on founder or informal ownership.',
            'function_led' => 'Named functions are emerging before formal department structure.',
            'department_forming' => 'Multiple functions now have enough ownership to consider clearer department or manager structure.',
            'department_led' => 'Formal departments are becoming meaningful performance units.',
            'scaling' => 'The organization has enough breadth for leadership leverage, department variance, and dependency analysis.',
            default => 'Organization stage is directional and should be validated with leadership context.',
        };
    }

    private function buildOperatingTrends(array $filters, array $window, array $settings, array $employees, array $organizationHealth, array $functionCoverage): array
    {
        $thresholds = (array) ($settings['thresholds'] ?? []);
        $current = $this->buildTrendSnapshot($employees, $thresholds, $organizationHealth['score'] ?? null, $functionCoverage, $window);
        $hasScopeFilter = trim((string) ($filters['role'] ?? '')) !== '' || trim((string) ($filters['department'] ?? '')) !== '' || !empty($filters['user_id']);
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $storedSeries = $hasScopeFilter
            ? ['points' => [], 'point_count' => 0, 'status' => 'filtered_baseline', 'headline' => 'Filtered scope baseline', 'methodology_changed' => false]
            : (new OrganizationIntelligenceSnapshotService())->series($workspaceId, (int) ($window['days'] ?? 30));

        $metrics = [
            $this->baselineMetric('Organization health', 'organization_health', $current['organization_health'], 'pts'),
            $this->baselineMetric('Workload pressure', 'workload_pressure', $current['workload_pressure'], '%'),
            $this->baselineMetric('Active time', 'system_active_minutes', $current['system_active_minutes'] / 60, 'h', 1, $current['system_usage_score'], 'measured_current'),
            $this->baselineMetric('Task completion', 'task_completion', $current['task_completion'], '%'),
            $this->baselineMetric('Activity consistency', 'activity_consistency', $current['activity_consistency'], '%'),
            $this->baselineMetric('Outcome / pipeline', 'outcome_pipeline', $current['outcome_pipeline'], '%'),
        ];

        return [
            'window' => [
                'label' => (string) ($window['label'] ?? 'month'),
                'days' => (int) ($window['days'] ?? 30),
                'start' => (string) ($window['start'] ?? ''),
                'end' => (string) ($window['end'] ?? ''),
                'previous_start' => '',
                'previous_end' => '',
            ],
            'headline' => (string) ($storedSeries['headline'] ?? 'Baseline captured'),
            'metrics' => $metrics,
            'series' => (array) ($storedSeries['points'] ?? []),
            'series_status' => (string) ($storedSeries['status'] ?? 'baseline'),
            'methodology_changed' => !empty($storedSeries['methodology_changed']),
            'point_count' => (int) ($storedSeries['point_count'] ?? 0),
            'snapshot_diagnostics' => (new OrganizationIntelligenceSnapshotService())->diagnostics($workspaceId),
            'current' => $current,
            'previous' => [],
        ];
    }

    private function buildTrendSnapshot(array $employees, array $thresholds, ?float $healthScore = null, array $functionCoverage = [], array $window = []): array
    {
        $summary = $this->buildSummary($employees, $thresholds);
        $staffCount = max(1, (int) ($summary['staff_count'] ?? 0));
        $overloadedCount = (int) ($summary['overloaded_count'] ?? 0);
        $openTasks = array_sum(array_map(static fn(array $employee): int => (int) ($employee['open_tasks'] ?? 0), $employees));
        $overdueTasks = array_sum(array_map(static fn(array $employee): int => (int) ($employee['overdue_open_tasks'] ?? 0), $employees));
        $overloadedLimit = max(1, (int) ($thresholds['overloaded_task_count'] ?? 7));
        $workloadPressure = min(100, (($overloadedCount / $staffCount) * 52) + min(36, ($openTasks / max(1, $staffCount * $overloadedLimit)) * 36) + min(12, $overdueTasks * 3));
        $taskCompletion = $this->averageNullableMetric($employees, 'task_completion');
        $activityConsistency = $this->averageNullableMetric($employees, 'activity_consistency');
        $outcomeImpact = $this->averageNullableMetric($employees, 'outcome_impact');
        $pipelineMovement = $this->averageNullableMetric($employees, 'pipeline_movement');
        $outcomePipeline = $outcomeImpact !== null || $pipelineMovement !== null
            ? ((float) ($outcomeImpact ?? 0) + (float) ($pipelineMovement ?? 0)) / (($outcomeImpact !== null ? 1 : 0) + ($pipelineMovement !== null ? 1 : 0))
            : null;
        $timeliness = $this->averageNullableMetric($employees, 'timeliness');
        $systemActiveMinutes = (float) ($summary['system_active_minutes'] ?? 0.0);
        $days = max(1, (int) ($window['days'] ?? 30));
        $systemUsageScore = min(100, ($staffCount > 0 ? ((float) ($summary['avg_system_active_minutes'] ?? 0.0) / max(1, $days * 18)) * 100 : 0));
        $coverageHealth = $this->functionCoverageHealth($functionCoverage);

        $derivedHealth = ($summary['avg_score'] ?? null) !== null ? (int) round(
            ((float) $summary['avg_score'] * 0.36)
            + ((100 - $workloadPressure) * 0.18)
            + ((float) ($taskCompletion ?? 0) * 0.14)
            + ((float) ($activityConsistency ?? 0) * 0.12)
            + ((float) ($outcomePipeline ?? 0) * 0.10)
            + ($coverageHealth * 0.10)
        ) : null;

        return [
            'organization_health' => ($healthScore ?? $derivedHealth) !== null ? max(0, min(100, (float) ($healthScore ?? $derivedHealth))) : null,
            'workload_pressure' => round($workloadPressure, 1),
            'system_active_minutes' => round($systemActiveMinutes, 1),
            'system_usage_score' => round($systemUsageScore, 1),
            'task_completion' => $taskCompletion !== null ? round($taskCompletion, 1) : null,
            'activity_consistency' => $activityConsistency !== null ? round($activityConsistency, 1) : null,
            'outcome_pipeline' => $outcomePipeline !== null ? round($outcomePipeline, 1) : null,
            'response_risk' => $timeliness !== null ? round(max(0, 100 - $timeliness), 1) : null,
            'coverage_health' => round($coverageHealth, 1),
            'coaching_signal' => (int) ($summary['eligible_staff_count'] ?? 0) > 0 ? round(min(100, (((int) ($summary['needs_coaching_count'] ?? 0)) / max(1, (int) $summary['eligible_staff_count'])) * 100), 1) : null,
            'staff_count' => (int) ($summary['staff_count'] ?? 0),
        ];
    }

    private function averageEmployeeMetric(array $employees, string $metric): float
    {
        $values = array_values(array_filter(
            array_map(static fn(array $employee) => $employee['metrics'][$metric] ?? null, $employees),
            static fn($value): bool => $value !== null
        ));
        if ($values === []) {
            return 0.0;
        }

        return array_sum(array_map('floatval', $values)) / count($values);
    }

    private function averageNullableMetric(array $employees, string $metric): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn(array $employee) => $employee['metrics'][$metric] ?? null, $employees),
            static fn($value): bool => $value !== null
        ));
        return $values !== [] ? round(array_sum(array_map('floatval', $values)) / count($values), 1) : null;
    }

    private function functionCoverageHealth(array $functionCoverage): float
    {
        $active = array_values(array_filter($functionCoverage, static fn(array $function): bool => (string) ($function['relevance_status'] ?? 'active') === 'active'));
        if ($active === []) {
            return 0.0;
        }

        $covered = count(array_filter($active, static fn(array $function): bool => ((int) ($function['explicit_owner_count'] ?? $function['owner_count'] ?? 0) + (int) ($function['temporary_owner_count'] ?? 0) + (int) ($function['inferred_owner_count'] ?? 0)) > 0));

        return ($covered / count($active)) * 100;
    }

    private function buildSummary(array $employees, array $thresholds): array
    {
        $count = count($employees);
        $eligible = array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible' && $employee['score'] !== null));
        $eligibleCount = count($eligible);
        $avgScore = $eligibleCount > 0 ? array_sum(array_column($eligible, 'score')) / $eligibleCount : null;
        $highPerformers = count(array_filter($eligible, static fn(array $employee): bool => (float) $employee['score'] >= $thresholds['high_performer']));
        $atRisk = count(array_filter($eligible, static fn(array $employee): bool => (float) $employee['score'] <= $thresholds['at_risk']));
        $coaching = count(array_filter($eligible, static fn(array $employee): bool => (float) $employee['score'] <= $thresholds['needs_coaching']));
        $overloaded = count(array_filter($employees, static fn(array $employee): bool => (int) ($employee['overdue_open_tasks'] ?? 0) >= $thresholds['overloaded_task_count']));
        $systemActiveSeconds = array_sum(array_map(static fn(array $employee): int => (int) ($employee['system_active_seconds'] ?? 0), $employees));
        $systemDurationSeconds = array_sum(array_map(static fn(array $employee): int => (int) ($employee['system_duration_seconds'] ?? 0), $employees));
        $systemSessionCount = array_sum(array_map(static fn(array $employee): int => (int) ($employee['system_session_count'] ?? 0), $employees));

        return [
            'staff_count' => $count,
            'eligible_staff_count' => $eligibleCount,
            'ineligible_staff_count' => max(0, $count - $eligibleCount),
            'eligible_ratio' => $count > 0 ? round($eligibleCount / $count, 4) : 0.0,
            'avg_score' => $avgScore !== null ? round($avgScore, 1) : null,
            'high_performers' => $highPerformers,
            'at_risk_count' => $atRisk,
            'needs_coaching_count' => $coaching,
            'overloaded_count' => $overloaded,
            'system_session_count' => $systemSessionCount,
            'system_active_minutes' => round($systemActiveSeconds / 60, 1),
            'system_active_hours' => round($systemActiveSeconds / 3600, 1),
            'system_duration_minutes' => round($systemDurationSeconds / 60, 1),
            'system_duration_hours' => round($systemDurationSeconds / 3600, 1),
            'avg_system_active_minutes' => $count > 0 ? round(($systemActiveSeconds / 60) / $count, 1) : 0.0,
            'avg_system_active_hours' => $count > 0 ? round(($systemActiveSeconds / 3600) / $count, 1) : 0.0,
            'best_performer_name' => $eligible[0]['name'] ?? 'Not enough evidence',
            'lowest_performer_name' => $eligible !== [] ? $eligible[array_key_last($eligible)]['name'] : 'Not enough evidence',
        ];
    }

    private function buildUserFunctionProfiles(array $employees): array
    {
        $out = [];
        foreach ($employees as $employee) {
            $profiles = (array) ($employee['function_profiles'] ?? []);
            $explicitCount = (int) ($employee['function_load_count'] ?? 0);
            $inferredCount = (int) ($employee['inferred_function_count'] ?? 0);
            $out[(int) ($employee['id'] ?? 0)] = [
                'user_id' => (int) ($employee['id'] ?? 0),
                'name' => (string) ($employee['name'] ?? ''),
                'department' => (string) ($employee['department'] ?? ''),
                'primary_function' => (string) ($employee['primary_function'] ?? 'Unassigned'),
                'score' => $employee['score'] !== null ? (float) $employee['score'] : null,
                'score_status' => (string) ($employee['score_status'] ?? 'insufficient_evidence'),
                'evidence_coverage' => (float) ($employee['evidence_coverage'] ?? 0),
                'score_band' => (string) ($employee['band'] ?? 'steady'),
                'risk_reason' => $this->peopleProfileRiskReason($employee),
                'source_metrics' => [
                    'task_completion_rate' => $employee['metrics']['task_completion'] ?? null,
                    'open_tasks' => (int) ($employee['open_tasks'] ?? 0),
                    'overdue_open_tasks' => (int) ($employee['overdue_open_tasks'] ?? 0),
                    'activity_count' => (int) ($employee['activity_count'] ?? 0),
                    'deal_wins' => (int) ($employee['deal_wins'] ?? 0),
                    'campaigns_created' => (int) ($employee['campaigns_run'] ?? 0),
                    'system_active_minutes' => (float) ($employee['system_active_minutes'] ?? 0.0),
                    'system_active_hours' => (float) ($employee['system_active_hours'] ?? 0.0),
                ],
                'manager_action' => $this->peopleProfileManagerAction($employee),
                'function_load_count' => $explicitCount,
                'explicit_function_count' => $explicitCount,
                'inferred_function_count' => $inferredCount,
                'functions' => $profiles,
                'ownership_source' => $explicitCount > 0 ? ($inferredCount > 0 ? 'mixed' : 'explicit') : ($inferredCount > 0 ? 'inferred_role_default' : 'none'),
                'load_signal' => $explicitCount >= 4 ? 'high' : ($explicitCount >= 2 ? 'moderate' : 'focused'),
                'confidence' => $this->profileConfidence($profiles),
            ];
        }
        return $out;
    }

    private function peopleProfileRiskReason(array $employee): string
    {
        $band = (string) ($employee['band'] ?? 'steady');
        $overdue = (int) ($employee['overdue_open_tasks'] ?? 0);
        $completion = round((float) ($employee['metrics']['task_completion'] ?? 0.0), 1);
        $activityCount = (int) ($employee['activity_count'] ?? 0);

        if ((string) ($employee['score_status'] ?? '') !== 'eligible') {
            return (string) ($employee['score_eligibility_reason'] ?? 'Not enough recorded evidence to issue a people score.');
        }

        if ($band === 'at_risk') {
            return $overdue > 0
                ? "{$overdue} overdue task(s) and {$completion}% completion in the selected window."
                : "Score is below the at-risk threshold with {$completion}% task completion.";
        }
        if ($band === 'needs_coaching') {
            return $overdue > 0
                ? "Coaching band with {$overdue} overdue task(s) to clear."
                : "Coaching band; validate workload, clarity, and captured activity before judging performance.";
        }
        if ($band === 'high_performer') {
            return "Strong visible execution; identify which habits can be repeated by the team.";
        }
        if ($activityCount === 0) {
            return 'No captured activity in this window; check tracking hygiene before drawing a people conclusion.';
        }
        return 'Stable score band in the selected scope.';
    }

    private function peopleProfileManagerAction(array $employee): string
    {
        $band = (string) ($employee['band'] ?? 'steady');
        if ((string) ($employee['score_status'] ?? '') !== 'eligible') {
            return 'Improve recorded work evidence before making a performance judgment.';
        }
        if ($band === 'at_risk') {
            return 'Create a seven-day coaching task with one owner, one obstacle to remove, and one measurable follow-up.';
        }
        if ($band === 'needs_coaching') {
            return 'Run a short manager check-in focused on workload, clarity, and next measurable outcome.';
        }
        if ($band === 'high_performer') {
            return 'Document the repeatable operating habit and decide where it can coach the wider team.';
        }
        return 'Keep normal review cadence and watch for movement in task, activity, or outcome signals.';
    }

    private function buildFunctionCoverage(array $functionCatalog, array $employees, array $summary): array
    {
        $coverage = [];
        foreach ($functionCatalog as $function) {
            $slug = (string) ($function['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $owners = [];
            $participants = [];
            $scores = [];
            $founderOwned = false;
            $primaryOwners = 0;
            $oversightCount = 0;
            $contributorCount = 0;
            $temporaryOwnerCount = 0;
            $explicitParticipantCount = 0;
            $inferredParticipantCount = 0;
            $inferredOwnerCount = 0;
            $inferredParticipants = [];
            $relevanceStatus = (string) ($function['relevance_status'] ?? 'active');
            foreach ($employees as $employee) {
                foreach ((array) ($employee['function_profiles'] ?? []) as $profile) {
                    if ((string) ($profile['slug'] ?? '') !== $slug) {
                        continue;
                    }
                    $isPrimary = !empty($profile['is_primary']);
                    $isInferred = !empty($profile['is_inferred']);
                    $assignmentType = (string) ($profile['assignment_type'] ?? 'owner');
                    $participant = [
                        'user_id' => (int) ($employee['id'] ?? 0),
                        'name' => (string) ($employee['name'] ?? ''),
                        'is_primary' => $isPrimary,
                        'assignment_type' => $assignmentType,
                        'confidence' => (string) ($profile['confidence'] ?? 'low'),
                        'score' => $profile['score'] ?? null,
                        'source' => (string) ($profile['source'] ?? ($isInferred ? 'inferred_role_default' : 'explicit_assignment')),
                        'is_inferred' => $isInferred,
                    ];
                    $participants[] = $participant;
                    if ($isInferred) {
                        $inferredParticipantCount++;
                        $inferredParticipants[] = $participant;
                        if (in_array($assignmentType, ['owner', 'temporary_owner'], true)) {
                            $inferredOwnerCount++;
                        }
                        continue;
                    }

                    $explicitParticipantCount++;
                    $oversightCount += $assignmentType === 'oversight' ? 1 : 0;
                    $contributorCount += $assignmentType === 'contributor' ? 1 : 0;
                    $temporaryOwnerCount += $assignmentType === 'temporary_owner' ? 1 : 0;

                    if (!in_array($assignmentType, ['owner', 'temporary_owner'], true)) {
                        continue;
                    }

                    $primaryOwners += $isPrimary ? 1 : 0;
                    $founderOwned = $founderOwned || in_array((string) ($employee['role'] ?? ''), ['owner', 'admin'], true) || str_contains(strtolower((string) ($employee['role_label'] ?? '')), 'owner');
                    if (($profile['score'] ?? null) !== null) {
                        $scores[] = (float) $profile['score'];
                    }
                    $owners[] = $participant;
                }
            }

            $ownerCount = count($owners);
            $avgScore = $scores !== [] ? round(array_sum($scores) / count($scores), 1) : null;
            $coverageSource = $ownerCount > 0
                ? ($inferredOwnerCount > 0 ? 'mixed' : 'explicit')
                : ($inferredOwnerCount > 0 ? 'inferred_only' : 'none');
            $state = $this->functionCoverageState($slug, $ownerCount, $primaryOwners, $founderOwned, (int) ($summary['staff_count'] ?? 0), $avgScore, $relevanceStatus);
            $confidence = $coverageSource === 'inferred_only'
                ? 'low'
                : $this->coverageConfidence((string) ($function['measurement_strength'] ?? 'partial'), $ownerCount, $scores);
            $coverage[$slug] = [
                'function_id' => (int) ($function['id'] ?? 0),
                'name' => (string) ($function['name'] ?? $this->functionLabel($slug)),
                'slug' => $slug,
                'category' => (string) ($function['category'] ?? 'core'),
                'measurement_strength' => (string) ($function['measurement_strength'] ?? 'partial'),
                'relevance_status' => $relevanceStatus,
                'relevance_note' => (string) ($function['relevance_note'] ?? ''),
                'owner_count' => $ownerCount,
                'explicit_owner_count' => $ownerCount,
                'inferred_owner_count' => $inferredOwnerCount,
                'participant_count' => count($participants),
                'explicit_participant_count' => $explicitParticipantCount,
                'inferred_participant_count' => $inferredParticipantCount,
                'primary_owner_count' => $primaryOwners,
                'oversight_count' => $oversightCount,
                'contributor_count' => $contributorCount,
                'temporary_owner_count' => $temporaryOwnerCount,
                'owners' => $owners,
                'participants' => $participants,
                'inferred_participants' => $inferredParticipants,
                'coverage_source' => $coverageSource,
                'state' => $state,
                'avg_score' => $avgScore,
                'confidence' => $confidence,
                'risk_level' => $this->functionRiskLevel($state, $avgScore, $confidence),
                'evidence' => $this->functionCoverageEvidence($function, $owners, $participants, $avgScore, $inferredParticipants),
                'recommended_next_move' => $this->functionNextMove($slug, $state, $confidence, $coverageSource),
                'interpretation_type' => in_array($state, ['unassigned', 'not_visible', 'founder_owned', 'deferred', 'outsourced', 'not_applicable'], true) ? 'coverage' : 'performance',
            ];
        }
        return $coverage;
    }

    private function buildFunctionPerformance(array $functionCoverage): array
    {
        $out = [];
        foreach ($functionCoverage as $slug => $coverage) {
            $out[$slug] = [
                'name' => (string) ($coverage['name'] ?? $this->functionLabel((string) $slug)),
                'score' => $coverage['avg_score'] ?? null,
                'state' => (string) ($coverage['state'] ?? 'not_visible'),
                'confidence' => (string) ($coverage['confidence'] ?? 'low'),
                'risk_level' => (string) ($coverage['risk_level'] ?? 'medium'),
                'owner_count' => (int) ($coverage['owner_count'] ?? 0),
                'explicit_owner_count' => (int) ($coverage['explicit_owner_count'] ?? $coverage['owner_count'] ?? 0),
                'inferred_owner_count' => (int) ($coverage['inferred_owner_count'] ?? 0),
                'participant_count' => (int) ($coverage['participant_count'] ?? 0),
                'coverage_source' => (string) ($coverage['coverage_source'] ?? 'none'),
                'relevance_status' => (string) ($coverage['relevance_status'] ?? 'active'),
                'evidence' => (string) ($coverage['evidence'] ?? ''),
                'action' => (string) ($coverage['recommended_next_move'] ?? ''),
            ];
        }
        uasort($out, static fn(array $left, array $right): int => ((string) ($right['risk_level'] ?? '') <=> (string) ($left['risk_level'] ?? '')) ?: ((float) ($left['score'] ?? 999) <=> (float) ($right['score'] ?? 999)));
        return $out;
    }

    private function buildFounderLoad(array $employees, array $functionCoverage, array $profile = []): array
    {
        $founder = null;
        $founders = [];
        $leadershipCohort = [];
        $singlePersonCriticalFunctions = [];
        $delegationCandidates = [];
        $outsourcingCandidates = [];
        $inferredFounderFunctions = [];
        $confirmedFounderIds = array_map('intval', (array) ($profile['founder_user_ids'] ?? []));
        foreach ($employees as $employee) {
            $roleText = strtolower((string) (($employee['role'] ?? '') . ' ' . ($employee['role_label'] ?? '')));
            $explicitProfiles = array_values(array_filter((array) ($employee['function_profiles'] ?? []), static fn(array $profile): bool => empty($profile['is_inferred'])));
            $inferredProfiles = array_values(array_filter((array) ($employee['function_profiles'] ?? []), static fn(array $profile): bool => !empty($profile['is_inferred'])));
            $leadershipProfiles = array_values(array_filter($explicitProfiles, static fn(array $profile): bool => (string) ($profile['slug'] ?? '') === 'leadership' || (string) ($profile['assignment_type'] ?? '') === 'oversight'));
            $isFounderLike = $confirmedFounderIds !== []
                ? in_array((int) ($employee['id'] ?? 0), $confirmedFounderIds, true)
                : (str_contains($roleText, 'owner') || !empty($employee['is_owner']));
            $isLeadershipLike = $isFounderLike || str_contains($roleText, 'admin') || (string) ($employee['primary_function'] ?? '') === 'Leadership' || $leadershipProfiles !== [];
            if ($isLeadershipLike) {
                $leadershipCohort[] = [
                    'user_id' => (int) ($employee['id'] ?? 0),
                    'name' => (string) ($employee['name'] ?? ''),
                    'role_label' => (string) ($employee['role_label'] ?? ''),
                    'function_count' => count($explicitProfiles),
                    'inferred_function_count' => count($inferredProfiles),
                    'is_founder_like' => $isFounderLike,
                ];
            }
            if ($founder === null && $isFounderLike) {
                $founder = $employee;
                $inferredFounderFunctions = array_values(array_filter(array_map(static fn(array $profile): string => (string) ($profile['name'] ?? ''), $inferredProfiles)));
            }
            if ($isFounderLike) {
                $founders[] = $employee;
            }
        }

        $founderOwnedFunctions = [];
        $founderCohort = [];
        foreach ($founders as $founderMember) {
            $memberFunctions = [];
            foreach ((array) ($founderMember['function_profiles'] ?? []) as $profile) {
                if (!empty($profile['is_inferred'])) {
                    continue;
                }
                $functionName = (string) ($profile['name'] ?? '');
                if ($functionName !== '') {
                    $memberFunctions[] = $functionName;
                    $founderOwnedFunctions[] = $functionName;
                }
            }
            $founderCohort[] = [
                'user_id' => (int) ($founderMember['id'] ?? 0),
                'name' => (string) ($founderMember['name'] ?? 'Founder / owner'),
                'function_count' => count(array_unique($memberFunctions)),
                'functions' => array_values(array_unique($memberFunctions)),
            ];
        }
        $founderOwnedFunctions = array_values(array_unique(array_filter($founderOwnedFunctions)));

        foreach ($functionCoverage as $function) {
            $state = (string) ($function['state'] ?? '');
            if ((string) ($function['relevance_status'] ?? '') === 'outsourced') {
                $outsourcingCandidates[] = (string) ($function['name'] ?? 'Function');
            }
            if (in_array($state, ['single_owner', 'founder_owned'], true) && (string) ($function['category'] ?? 'core') === 'core') {
                $singlePersonCriticalFunctions[] = (string) ($function['name'] ?? 'Function');
            }
            if ($state === 'founder_owned' || ((int) ($function['temporary_owner_count'] ?? 0) > 0 && (string) ($function['category'] ?? 'core') === 'core')) {
                $delegationCandidates[] = [
                    'function' => (string) ($function['name'] ?? 'Function'),
                    'reason' => (string) ($function['evidence'] ?? ''),
                    'action' => (string) ($function['recommended_next_move'] ?? ''),
                ];
            }
        }

        $loadCount = $founderCohort !== [] ? max(array_column($founderCohort, 'function_count')) : 0;
        if ($loadCount === 0 && $leadershipCohort !== []) {
            $loadCount = max(array_map(static fn(array $leader): int => (int) ($leader['function_count'] ?? 0), $leadershipCohort));
        }
        $level = $loadCount >= 5 ? 'high' : ($loadCount >= 3 ? 'moderate' : 'focused');
        $hasOnlyInferredFounderLoad = $loadCount === 0 && $inferredFounderFunctions !== [];
        $delegateCount = min($loadCount, count($delegationCandidates));
        $outsourceCount = min(max(0, $loadCount - $delegateCount), count($outsourcingCandidates));
        $keepCount = max(0, $loadCount - $delegateCount - $outsourceCount);
        $summary = $loadCount > 0
            ? (count($founderCohort) > 1 ? 'Founder cohort members each carry up to ' : ($founder ? 'Founder currently carries ' : 'Leadership cohort currently carries up to ')) . $loadCount . ' explicit function(s)' . ($founderOwnedFunctions !== [] ? ': ' . implode(', ', array_slice($founderOwnedFunctions, 0, 6)) : '') . '.'
            : ($hasOnlyInferredFounderLoad
                ? 'No explicit founder function load is saved yet. Suggested role defaults point to: ' . implode(', ', array_slice($inferredFounderFunctions, 0, 6)) . '.'
                : 'No explicit founder function load is visible yet.');
        return [
            'founder_user_id' => $founder['id'] ?? null,
            'founder_name' => $founder['name'] ?? 'Founder / owner',
            'founder_count' => count($founderCohort),
            'founder_cohort' => $founderCohort,
            'function_count' => $loadCount,
            'functions' => $founderOwnedFunctions,
            'leadership_cohort' => $leadershipCohort,
            'founder_owned_functions' => $founderOwnedFunctions,
            'inferred_founder_functions' => $inferredFounderFunctions,
            'ownership_source' => $loadCount > 0 ? 'explicit' : ($hasOnlyInferredFounderLoad ? 'inferred_role_default' : 'none'),
            'single_person_critical_functions' => array_values(array_unique($singlePersonCriticalFunctions)),
            'delegation_candidates' => array_slice($delegationCandidates, 0, 5),
            'load_allocation' => [
                'keep' => $keepCount,
                'delegate' => $delegateCount,
                'outsource' => $outsourceCount,
                'automate' => 0,
                'basis' => 'current_explicit_ownership_recommendations',
            ],
            'level' => $level,
            'summary' => $summary,
            'risk' => $hasOnlyInferredFounderLoad
                ? 'Founder load cannot be judged until Work Ownership is explicitly saved.'
                : ($level === 'high' ? 'Founder dependency is high; prioritize delegation or clearer Work Ownership.' : ($level === 'moderate' ? 'Founder load is workable but should be reviewed as activity grows.' : 'Founder load appears focused in the current assignment model.')),
            'confidence' => $loadCount > 0 ? 'moderate' : 'low',
        ];
    }

    private function deriveOrganizationStage(array $summary, array $departmentSummaries, array $functionCoverage, array $founderLoad, array $profile = []): array
    {
        $staffCount = (int) ($summary['staff_count'] ?? 0);
        $assignedFunctions = count(array_filter($functionCoverage, static fn(array $function): bool => (string) ($function['relevance_status'] ?? 'active') === 'active' && (int) ($function['owner_count'] ?? 0) > 0));
        $departmentCount = count(array_filter($departmentSummaries, static fn(array $department): bool => (int) ($department['staff_count'] ?? 0) > 0));
        $departmentBacked = count(array_filter($functionCoverage, static fn(array $function): bool => (string) ($function['relevance_status'] ?? 'active') === 'active' && (int) ($function['owner_count'] ?? 0) >= 2));

        $stage = (string) ($profile['effective_model'] ?? '');
        if ($stage === '') {
            $stage = $staffCount <= 1 ? 'solo_founder' : ((int) ($founderLoad['function_count'] ?? 0) >= 3 ? 'founder_led_team' : ($assignedFunctions >= 2 ? 'functional_team' : 'founder_led_team'));
        }

        return [
            'stage' => $stage,
            'label' => ucwords(str_replace('_', ' ', $stage)),
            'summary' => $this->organizationStageSummary($stage),
            'staff_count' => $staffCount,
            'assigned_function_count' => $assignedFunctions,
            'department_count' => $departmentCount,
            'confidence' => !empty($profile['is_confirmed']) ? 'high' : (string) ($profile['inference_confidence'] ?? ($staffCount <= 1 ? 'moderate' : 'high')),
            'is_confirmed' => !empty($profile['is_confirmed']),
            'review_recommended' => !empty($profile['review_recommended']),
        ];
    }

    private function buildFunctionDependencyRisks(array $functionCoverage, array $founderLoad): array
    {
        $risks = [];
        foreach ($functionCoverage as $function) {
            $ownerCount = (int) ($function['owner_count'] ?? 0);
            $state = (string) ($function['state'] ?? '');
            if ((string) ($function['relevance_status'] ?? 'active') !== 'active') {
                continue;
            }
            if ($ownerCount === 0 && (string) ($function['category'] ?? 'core') === 'core') {
                $risks[] = [
                    'risk_type' => 'function_coverage_gap',
                    'scope_type' => 'function',
                    'scope_label' => (string) ($function['name'] ?? 'Function'),
                    'severity' => 'medium',
                    'confidence' => (string) ($function['confidence'] ?? 'low'),
                    'title' => (string) ($function['name'] ?? 'Function') . ' has no visible owner',
                    'evidence' => (string) ($function['evidence'] ?? ((string) ($function['name'] ?? 'Function') . ' is active but has no assigned owner.')),
                    'implication' => 'This is a coverage gap, not a department performance failure.',
                    'action' => (string) ($function['recommended_next_move'] ?? 'Assign a temporary function owner.'),
                ];
            } elseif ($ownerCount === 1 && in_array($state, ['single_owner', 'founder_owned'], true)) {
                $owner = (array) (($function['owners'][0] ?? []));
                $risks[] = [
                    'risk_type' => 'single_owner_dependency',
                    'scope_type' => 'function',
                    'scope_label' => (string) ($function['name'] ?? 'Function'),
                    'severity' => $state === 'founder_owned' ? 'high' : 'medium',
                    'confidence' => (string) ($function['confidence'] ?? 'moderate'),
                    'title' => (string) ($function['name'] ?? 'Function') . ' depends on one owner',
                    'evidence' => (string) ($function['name'] ?? 'Function') . ' is currently carried by ' . (string) ($owner['name'] ?? 'one person') . '.',
                    'implication' => 'The risk is dependency and continuity, not necessarily poor execution.',
                    'action' => 'Define backup ownership, handoff notes, or a weekly review rhythm for this function.',
                ];
            }
        }

        if ((string) ($founderLoad['level'] ?? '') === 'high') {
            $risks[] = [
                'risk_type' => 'founder_load_concentration',
                'scope_type' => 'organization',
                'scope_label' => 'Founder load',
                'severity' => 'high',
                'confidence' => (string) ($founderLoad['confidence'] ?? 'moderate'),
                'title' => 'Founder load concentration',
                'evidence' => (string) ($founderLoad['summary'] ?? ''),
                'implication' => 'A founder can be performing well and still become the company bottleneck.',
                'action' => 'Pick one repeatable founder-owned function to formalize or delegate in the next 30 days.',
            ];
        }

        return array_slice($risks, 0, 4);
    }

    private function buildDepartmentReadiness(array $departmentSummaries, array $functionCoverage): array
    {
        $out = [];
        foreach ($functionCoverage as $slug => $function) {
            $ownerCount = (int) ($function['owner_count'] ?? 0);
            $relevance = (string) ($function['relevance_status'] ?? 'active');
            $score = $function['avg_score'] ?? null;
            $ready = $ownerCount >= 2 && $score !== null;
            $out[$slug] = [
                'function' => (string) ($function['name'] ?? $this->functionLabel((string) $slug)),
                'state' => $relevance !== 'active' ? $relevance : ($ready ? 'department_ready' : ($ownerCount > 0 ? 'function_owned' : 'not_ready')),
                'owner_count' => $ownerCount,
                'evidence' => $ready
                    ? 'Multiple owners and measurable activity suggest this function may be ready for formal structure.'
                    : ((string) ($function['evidence'] ?? 'Function coverage is still forming.')),
                'action' => $ready
                    ? 'Consider whether this function needs manager ownership, standards, or a formal department.'
                    : 'Keep this as Work Ownership until activity volume and multiple owners justify department structure.',
                'confidence' => (string) ($function['confidence'] ?? 'low'),
            ];
        }
        return $out;
    }

    private function buildNextFunctionToFormalize(array $functionCoverage, array $founderLoad): array
    {
        $candidates = array_values(array_filter($functionCoverage, static fn(array $function): bool => (string) ($function['relevance_status'] ?? 'active') === 'active'));
        usort($candidates, static function (array $left, array $right): int {
            $leftScore = ((string) ($left['state'] ?? '') === 'founder_owned' ? 3 : 0) + ((int) ($left['owner_count'] ?? 0) === 1 ? 2 : 0) + (((string) ($left['risk_level'] ?? '') === 'high') ? 2 : 0);
            $rightScore = ((string) ($right['state'] ?? '') === 'founder_owned' ? 3 : 0) + ((int) ($right['owner_count'] ?? 0) === 1 ? 2 : 0) + (((string) ($right['risk_level'] ?? '') === 'high') ? 2 : 0);
            return $rightScore <=> $leftScore;
        });

        $candidate = $candidates[0] ?? [];
        if ($candidate === []) {
            return [
                'function' => 'No function selected',
                'reason' => 'No active function coverage is available yet.',
                'action' => 'Create or assign the first organization function.',
                'confidence' => 'low',
            ];
        }

        return [
            'function' => (string) ($candidate['name'] ?? 'Function'),
            'slug' => (string) ($candidate['slug'] ?? ''),
            'reason' => (string) ($candidate['evidence'] ?? 'This function has the clearest ownership or dependency signal.'),
            'action' => (string) ($candidate['recommended_next_move'] ?? 'Formalize ownership and success measures.'),
            'confidence' => (string) ($candidate['confidence'] ?? 'low'),
            'founder_context' => (string) ($founderLoad['summary'] ?? ''),
        ];
    }

    private function buildContextualRecommendations(array $organizationStage, array $functionCoverage, array $founderLoad, array $departmentReadiness, array $nextFunctionToFormalize): array
    {
        $stage = (string) ($organizationStage['stage'] ?? 'founder_only');
        $recommendations = [];
        if (in_array($stage, ['solo_founder', 'multi_founder', 'founder_led_team', 'founder_only', 'founder_led'], true)) {
            $recommendations[] = 'Treat missing departments as function-coverage gaps. Do not score unstaffed functions as failed departments.';
            if ((int) ($founderLoad['function_count'] ?? 0) >= 3) {
                $recommendations[] = 'Reduce founder load by formalizing ' . (string) ($nextFunctionToFormalize['function'] ?? 'one function') . ' before adding broader process.';
            }
        } else {
            $recommendations[] = 'Compare department performance only where functions have multiple owners and enough evidence.';
        }

        foreach ($functionCoverage as $function) {
            if ((string) ($function['relevance_status'] ?? 'active') !== 'active') {
                continue;
            }
            if ((string) ($function['confidence'] ?? '') === 'low' && (int) ($function['owner_count'] ?? 0) > 0) {
                $recommendations[] = (string) ($function['name'] ?? 'Function') . ' has ownership but low measurement confidence; improve tracking before judging performance.';
                break;
            }
        }

        foreach ($functionCoverage as $function) {
            $relevance = (string) ($function['relevance_status'] ?? 'active');
            if (in_array($relevance, ['deferred', 'outsourced'], true)) {
                $recommendations[] = (string) ($function['name'] ?? 'Function') . ' is marked ' . str_replace('_', ' ', $relevance) . '; keep it out of performance judgment and review the governance trigger.';
                break;
            }
        }

        foreach ($departmentReadiness as $readiness) {
            if ((string) ($readiness['state'] ?? '') === 'department_ready') {
                $recommendations[] = (string) ($readiness['function'] ?? 'A function') . ' may be ready for clearer department structure or manager ownership.';
                break;
            }
        }

        return array_slice(array_values(array_unique($recommendations)), 0, 5);
    }

    private function buildDepartmentSummaries(array $employees, array $thresholds): array
    {
        $groups = [];
        foreach ($employees as $employee) {
            if ((string) ($employee['department_source'] ?? 'inferred_lane') !== 'explicit') {
                continue;
            }
            $groups[$employee['department']][] = $employee;
        }

        $out = [];
        foreach ($groups as $department => $members) {
            $eligible = array_values(array_filter($members, static fn(array $member): bool => (string) ($member['score_status'] ?? '') === 'eligible' && $member['score'] !== null));
            $eligibleCount = count($eligible);
            $avgScore = $eligibleCount >= 2 && ($eligibleCount / max(1, count($members))) >= 0.5
                ? array_sum(array_column($eligible, 'score')) / $eligibleCount
                : null;
            $confidenceInterval = null;
            if ($avgScore !== null && $eligibleCount >= 2) {
                $variance = array_sum(array_map(
                    static fn(array $member): float => (((float) $member['score']) - $avgScore) ** 2,
                    $eligible
                )) / max(1, $eligibleCount - 1);
                $margin = 1.96 * sqrt($variance) / sqrt($eligibleCount);
                $confidenceInterval = [
                    'low' => round(max(0, $avgScore - $margin), 1),
                    'high' => round(min(100, $avgScore + $margin), 1),
                    'level' => 0.95,
                ];
            }
            $out[$department] = [
                'department' => $department,
                'staff_count' => count($members),
                'eligible_staff_count' => $eligibleCount,
                'score_status' => $avgScore !== null ? 'eligible' : 'insufficient_evidence',
                'avg_score' => $avgScore !== null ? round($avgScore, 1) : null,
                'confidence_interval' => $confidenceInterval,
                'high_performers' => count(array_filter($eligible, static fn(array $member): bool => (float) $member['score'] >= $thresholds['high_performer'])),
                'at_risk_count' => count(array_filter($eligible, static fn(array $member): bool => (float) $member['score'] <= $thresholds['at_risk'])),
                'overloaded_count' => count(array_filter($members, static fn(array $member): bool => (int) ($member['overdue_open_tasks'] ?? 0) >= $thresholds['overloaded_task_count'])),
                'top_member' => $eligible[0]['name'] ?? 'Not enough evidence',
            ];
        }

        ksort($out);
        return $out;
    }

    private function buildRoleSummaries(array $employees): array
    {
        $groups = ['marketing' => [], 'sales' => [], 'general' => []];
        foreach ($employees as $employee) {
            $groups[$employee['role']][] = $employee;
        }

        $out = [];
        foreach ($groups as $role => $members) {
            $eligible = array_values(array_filter($members, static fn(array $member): bool => (string) ($member['score_status'] ?? '') === 'eligible' && $member['score'] !== null));
            $out[$role] = [
                'role' => $role,
                'staff_count' => count($members),
                'eligible_staff_count' => count($eligible),
                'avg_score' => $eligible !== [] ? round(array_sum(array_column($eligible, 'score')) / count($eligible), 1) : null,
                'avg_task_completion' => $this->averageNullableMetric($eligible, 'task_completion'),
                'avg_outcome_impact' => $this->averageNullableMetric($eligible, 'outcome_impact'),
            ];
        }

        return $out;
    }

    private function buildBestStrategies(array $employees, array $summary): array
    {
        $top = array_slice(array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible')), 0, 5);
        if ($top === []) {
            return [];
        }

        $avg = function (string $metric) use ($top): float {
            $values = array_values(array_filter(array_map(static fn(array $employee) => $employee['metrics'][$metric] ?? null, $top), static fn($value): bool => $value !== null));
            return $values !== [] ? array_sum(array_map('floatval', $values)) / count($values) : 0.0;
        };

        $departmentNames = array_values(array_unique(array_map(static fn(array $employee): string => (string) $employee['department'], $top)));

        $strategies = [
            [
                'name' => 'Execution Discipline',
                'summary' => sprintf('Top performers are completing work with %.0f%% task completion and %.0f%% timeliness.', $avg('task_completion'), $avg('timeliness')),
                'evidence' => [
                    'task_completion' => round($avg('task_completion'), 1),
                    'timeliness' => round($avg('timeliness'), 1),
                ],
                'departments' => $departmentNames,
            ],
            [
                'name' => 'Consistency Over Intensity',
                'summary' => sprintf('High-performing staff stay active across %.1f days on average and maintain %.0f%% activity consistency.', array_sum(array_map(static fn(array $employee): float => (float) ($employee['active_days'] ?? 0), $top)) / count($top), $avg('activity_consistency')),
                'evidence' => [
                    'activity_consistency' => round($avg('activity_consistency'), 1),
                    'active_days' => round(array_sum(array_map(static fn(array $employee): float => (float) ($employee['active_days'] ?? 0), $top)) / count($top), 1),
                ],
                'departments' => $departmentNames,
            ],
        ];

        if ($avg('pipeline_movement') >= 35) {
            $strategies[] = [
                'name' => 'Pipeline Momentum',
                'summary' => 'Sales-facing staff are moving deals consistently, indicating strong follow-up and stage hygiene.',
                'evidence' => ['pipeline_movement' => round($avg('pipeline_movement'), 1)],
                'departments' => $departmentNames,
            ];
        }

        if ($avg('campaign_output') >= 25) {
            $strategies[] = [
                'name' => 'Campaign-Led Growth',
                'summary' => 'Marketing activity and touchpoint creation are contributing measurable top-cohort performance lift.',
                'evidence' => ['campaign_output' => round($avg('campaign_output'), 1)],
                'departments' => $departmentNames,
            ];
        }

        if (($summary['overloaded_count'] ?? 0) === 0) {
            $strategies[] = [
                'name' => 'Balanced Workload',
                'summary' => 'Top performers are sustaining output without carrying an overloaded overdue queue.',
                'evidence' => ['workload_balance' => round($avg('workload_balance'), 1)],
                'departments' => $departmentNames,
            ];
        }

        return array_slice($strategies, 0, 4);
    }

    private function buildStrategyLeaderboard(array $employees, array $window, array $settings): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $snapshots = $this->strategySnapshots->listSnapshotsForWindow($workspaceId, (string) $window['start'], (string) $window['end']);
        if ($snapshots === []) {
            return [];
        }

        $employeesById = [];
        foreach ($employees as $employee) {
            $employeesById[(int) ($employee['id'] ?? 0)] = $employee;
        }

        $leaderboard = [];
        $statsByWindow = [];
        foreach ($snapshots as $snapshot) {
            $userId = (int) ($snapshot['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $snapshotWindow = $this->windowForSnapshot($window, $snapshot);
            if ($snapshotWindow === null) {
                continue;
            }

            $windowKey = $this->windowCacheKey($snapshotWindow);
            if (!isset($statsByWindow[$windowKey])) {
                $statsByWindow[$windowKey] = $this->queryWindowStats($snapshotWindow, $workspaceId);
            }

            $tasks = $statsByWindow[$windowKey]['tasks'][$userId] ?? [];
            $activities = $statsByWindow[$windowKey]['activities'][$userId] ?? [];
            $deals = $statsByWindow[$windowKey]['deals'][$userId] ?? [];
            $campaigns = $statsByWindow[$windowKey]['campaigns'][$userId] ?? [];
            $outcomes = $statsByWindow[$windowKey]['outcomes'][$userId] ?? [];
            $metrics = $this->buildNormalizedMetrics($tasks, $activities, $deals, $campaigns, $outcomes, $settings['thresholds'], $snapshotWindow);
            $employee = $employeesById[$userId] ?? [];
            $roleBucket = $this->roleBucket((string) ($snapshot['access_role_slug'] ?? $employee['role'] ?? $snapshot['role'] ?? 'general'));
            $weights = $settings['scoring_weights'][$roleBucket] ?? $settings['scoring_weights']['general'];
            $score = $this->calculateScore($metrics, $weights);
            $name = trim((string) (($snapshot['first_name'] ?? '') . ' ' . ($snapshot['last_name'] ?? '')));
            if ($name === '') {
                $name = (string) ($snapshot['email'] ?? $employee['name'] ?? 'User #' . $userId);
            }
            $department = (string) ($employee['department'] ?? $snapshot['department_name'] ?? $this->departmentForRole($roleBucket, $settings['department_mappings']));

            $leaderboard[] = [
                'snapshot_id' => (int) ($snapshot['id'] ?? 0),
                'user_id' => $userId,
                'user_name' => $name,
                'department' => $department,
                'role' => $roleBucket,
                'role_label' => (string) ($snapshot['access_role_name'] ?? $employee['role_label'] ?? ucfirst($roleBucket)),
                'version' => (int) ($snapshot['version'] ?? 0),
                'status' => (string) ($snapshot['status'] ?? ''),
                'score' => $score,
                'strategy_summary' => $this->strategySnapshots->summarizeSnapshot($snapshot),
                'icp' => (string) ($snapshot['ideal_customer_profile'] ?? ''),
                'target_market_focus' => (string) ($snapshot['target_market_focus'] ?? ''),
                'market_view' => (string) ($snapshot['market_view'] ?? ''),
                'strategy_hypothesis' => (string) ($snapshot['strategy_hypothesis'] ?? ''),
                'competitors' => (string) ($snapshot['competitors'] ?? ''),
                'differentiator' => (string) ($snapshot['differentiator'] ?? ''),
                'evidence' => [
                    'task_completion' => (float) ($metrics['task_completion'] ?? 0.0),
                    'campaign_output' => (float) ($metrics['campaign_output'] ?? 0.0),
                    'activity_consistency' => (float) ($metrics['activity_consistency'] ?? 0.0),
                    'outcome_impact' => (float) ($metrics['outcome_impact'] ?? 0.0),
                    'pipeline_movement' => (float) ($metrics['pipeline_movement'] ?? 0.0),
                    'workload_balance' => (float) ($metrics['workload_balance'] ?? 0.0),
                ],
                'date_range' => [
                    'start' => $snapshotWindow['start'],
                    'end' => $snapshotWindow['end'],
                ],
                'started_at' => (string) ($snapshot['started_at'] ?? ''),
                'ended_at' => $snapshot['ended_at'] ?? null,
            ];
        }

        usort($leaderboard, static fn(array $left, array $right): int => ((float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0)) ?: strcmp((string) ($left['user_name'] ?? ''), (string) ($right['user_name'] ?? '')));

        return array_slice($leaderboard, 0, 10);
    }

    private function windowCacheKey(array $window): string
    {
        return implode('|', [
            (string) ($window['start'] ?? ''),
            (string) ($window['end'] ?? ''),
            (string) ($window['days'] ?? ''),
        ]);
    }

    private function queryWindowStats(array $window, int $workspaceId): array
    {
        return [
            'tasks' => $this->queryTaskStats($window, $workspaceId),
            'activities' => $this->queryActivityStats($window, $workspaceId),
            'deals' => $this->queryDealStats($window, $workspaceId),
            'campaigns' => $this->queryCampaignStats($window, $workspaceId),
            'outcomes' => $this->queryOutcomeStats($window, $workspaceId),
        ];
    }

    private function windowForSnapshot(array $window, array $snapshot): ?array
    {
        $baseStart = strtotime((string) ($window['start'] ?? '')) ?: time();
        $baseEnd = strtotime((string) ($window['end'] ?? '')) ?: time();
        $snapshotStart = strtotime((string) ($snapshot['started_at'] ?? '')) ?: $baseStart;
        $snapshotEnd = !empty($snapshot['ended_at']) ? (strtotime((string) $snapshot['ended_at']) ?: $baseEnd) : $baseEnd;
        $start = max($baseStart, $snapshotStart);
        $end = min($baseEnd, $snapshotEnd);

        if ($end < $start) {
            return null;
        }

        return [
            'label' => (string) ($window['label'] ?? 'custom'),
            'days' => max(1, (int) ceil(($end - $start) / 86400)),
            'start' => date('Y-m-d H:i:s', $start),
            'end' => date('Y-m-d H:i:s', $end),
        ];
    }

    private function buildEvidenceConfidence(array $employees, array $summary, array $window): array
    {
        $staffCount = count($employees);
        $signalCount = 0;
        $totalActivity = 0;
        $limitations = [];

        foreach ($employees as $employee) {
            $activity = (int) ($employee['activity_count'] ?? 0);
            $totalActivity += $activity;
            $hasSignal = $activity > 0
                || (int) ($employee['tasks_completed'] ?? 0) > 0
                || (int) ($employee['open_tasks'] ?? 0) > 0
                || (int) ($employee['deal_wins'] ?? 0) > 0
                || (int) ($employee['deals_advanced'] ?? 0) > 0
                || (int) ($employee['campaigns_run'] ?? 0) > 0
                || (int) ($employee['touchpoints'] ?? 0) > 0
                || (int) ($employee['outcome_events'] ?? 0) > 0
                || (int) ($employee['system_active_seconds'] ?? 0) > 0;
            if ($hasSignal) {
                $signalCount++;
            }
        }

        $coverage = $staffCount > 0 ? $signalCount / $staffCount : 0.0;
        $sampleScore = min(1.0, $staffCount / 12);
        $activityScore = $staffCount > 0 ? min(1.0, $totalActivity / max(1, $staffCount * 3)) : 0.0;
        $score = (int) round(($coverage * 55) + ($sampleScore * 25) + ($activityScore * 20));
        $level = $score >= 72 ? 'high' : ($score >= 42 ? 'moderate' : 'low');

        if ($staffCount < 3) {
            $limitations[] = 'Small team sample; read patterns as directional.';
        }
        if ($coverage < 0.5) {
            $limitations[] = 'Several people have little visible CRM signal; validate whether work is happening outside the system.';
        }
        if ((int) ($summary['staff_count'] ?? 0) === 0) {
            $limitations[] = 'No active staff were available for this filtered view.';
        }

        return [
            'level' => $level,
            'label' => ucfirst($level) . ' confidence',
            'score' => $score,
            'sample_size' => $staffCount,
            'signal_coverage' => round($coverage, 3),
            'coverage_percent' => (int) round($coverage * 100),
            'active_signal_count' => $signalCount,
            'window_days' => (int) ($window['days'] ?? 0),
            'summary' => $level === 'high'
                ? 'Enough recent activity exists to support organization-level pattern reading.'
                : ($level === 'moderate'
                    ? 'Patterns are usable for leadership review, but should be validated with managers before hard people decisions.'
                    : 'Treat insights as early signals; low system visibility may distort the picture.'),
            'limitations' => $limitations,
        ];
    }

    private function buildPeopleRisks(array $employees, array $departmentSummaries, array $gaps, array $thresholds, array $evidenceConfidence, array $functionDependencyRisks = []): array
    {
        $risks = [];
        foreach ($functionDependencyRisks as $functionRisk) {
            $risks[] = [
                'risk_type' => (string) ($functionRisk['risk_type'] ?? 'function_dependency_risk'),
                'scope_type' => (string) ($functionRisk['scope_type'] ?? 'function'),
                'scope_label' => (string) ($functionRisk['scope_label'] ?? 'Function'),
                'user_id' => null,
                'department' => '',
                'severity' => (string) ($functionRisk['severity'] ?? 'medium'),
                'confidence' => (string) ($functionRisk['confidence'] ?? 'moderate'),
                'title' => (string) ($functionRisk['title'] ?? 'Function dependency risk'),
                'evidence' => (string) ($functionRisk['evidence'] ?? ''),
                'implication' => (string) ($functionRisk['implication'] ?? 'This is a function coverage signal, not an individual performance judgment.'),
                'action' => (string) ($functionRisk['action'] ?? 'Clarify Work Ownership and measurement before judging performance.'),
            ];
        }
        foreach ($gaps as $gap) {
            $title = (string) ($gap['title'] ?? 'People signal');
            $riskType = (string) ($gap['risk_type'] ?? $this->riskTypeForGapTitle($title));
            $risks[] = [
                'risk_type' => $riskType,
                'scope_type' => (string) ($gap['scope_type'] ?? 'organization'),
                'scope_label' => (string) ($gap['scope_label'] ?? 'Organization'),
                'user_id' => $gap['user_id'] ?? null,
                'department' => (string) ($gap['department'] ?? ''),
                'severity' => (string) ($gap['severity'] ?? 'medium'),
                'confidence' => (string) ($gap['confidence'] ?? $this->riskConfidence($riskType, $evidenceConfidence)),
                'title' => $this->specialistRiskTitle($title, $riskType),
                'evidence' => (string) ($gap['evidence'] ?? $gap['summary'] ?? ''),
                'implication' => (string) ($gap['summary'] ?? 'This signal should be reviewed before leadership acts.'),
                'action' => (string) ($gap['recommendation'] ?? 'Validate the pattern with the relevant manager and choose one measurable next action.'),
            ];
        }

        $staffCount = count($employees);
        $eligibleEmployees = array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible'));
        $highPerformers = count(array_filter($eligibleEmployees, static fn(array $employee): bool => (string) ($employee['band'] ?? '') === 'high_performer'));
        $atRisk = count(array_filter($eligibleEmployees, static fn(array $employee): bool => (string) ($employee['band'] ?? '') === 'at_risk'));
        $coachingOrAtRisk = count(array_filter($eligibleEmployees, static fn(array $employee): bool => in_array((string) ($employee['band'] ?? ''), ['at_risk', 'needs_coaching'], true)));
        $overloaded = count(array_filter($employees, static fn(array $employee): bool => (int) ($employee['overdue_open_tasks'] ?? 0) >= $thresholds['overloaded_task_count']));
        $scores = array_values(array_map(static fn(array $employee): float => (float) $employee['score'], $eligibleEmployees));
        rsort($scores);
        $eligibleCount = count($eligibleEmployees);
        $scoreConcentration = $eligibleCount >= 3 && $scores !== [] && (($scores[0] ?? 0.0) - ($scores[(int) floor(($eligibleCount - 1) / 2)] ?? 0.0)) >= 12.0;

        if ($eligibleCount >= 3 && $coachingOrAtRisk > 0) {
            $topSignal = $highPerformers > 0 ? "{$highPerformers} high performer(s)" : ($scoreConcentration ? 'a clear top-score concentration' : 'the strongest visible cohort');
            $risks[] = [
                'risk_type' => 'top_performer_dependency',
                'scope_type' => 'organization',
                'scope_label' => 'Organization',
                'user_id' => null,
                'department' => '',
                'severity' => 'medium',
                'confidence' => $this->riskConfidence('top_performer_dependency', $evidenceConfidence),
                'title' => 'Top-performer dependency signal',
                'evidence' => "{$topSignal} versus {$coachingOrAtRisk} staff needing coaching or risk review in a {$staffCount}-person view.",
                'implication' => 'Execution may be leaning on a small strong cohort while weaker operating habits stay unresolved.',
                'action' => 'Codify top-performer routines and redistribute coaching attention before dependency becomes a delivery bottleneck.',
            ];
        }

        if ($overloaded > 0) {
            $risks[] = [
                'risk_type' => 'workload_concentration',
                'scope_type' => 'organization',
                'scope_label' => 'Organization',
                'user_id' => null,
                'department' => '',
                'severity' => $overloaded >= max(2, (int) ceil($staffCount * 0.2)) ? 'high' : 'medium',
                'confidence' => $this->riskConfidence('workload_concentration', $evidenceConfidence),
                'title' => 'Workload concentration risk',
                'evidence' => "{$overloaded} staff member(s) have overdue queues at or above the overload threshold.",
                'implication' => 'Backlog concentration can reduce quality, slow customer follow-through, and hide burnout risk.',
                'action' => 'Run a workload rebalance before assigning new high-priority work.',
            ];
        }

        foreach ($departmentSummaries as $department => $summary) {
            $staff = (int) ($summary['staff_count'] ?? 0);
            $departmentAtRisk = (int) ($summary['at_risk_count'] ?? 0);
            if ($staff > 0 && $departmentAtRisk >= max(2, (int) ceil($staff * 0.35))) {
                $risks[] = [
                    'risk_type' => 'manager_support_gap',
                    'scope_type' => 'department',
                    'scope_label' => (string) $department,
                    'user_id' => null,
                    'department' => (string) $department,
                    'severity' => 'high',
                    'confidence' => $this->riskConfidence('manager_support_gap', $evidenceConfidence),
                    'title' => 'Department manager-support signal',
                    'evidence' => "{$departmentAtRisk} of {$staff} staff in {$department} are below the at-risk threshold.",
                    'implication' => 'The pattern may reflect unclear operating rhythm, insufficient manager support, or role/process friction.',
                    'action' => 'Run a department operating review before treating this as isolated individual performance.',
                ];
            }
        }

        return array_slice($this->dedupeByTitleAndScope($risks), 0, 12);
    }

    private function buildDepartmentIntelligence(array $departmentSummaries, array $employees, array $gaps, array $bestStrategies, array $peopleRisks): array
    {
        $out = [];
        foreach ($departmentSummaries as $department => $summary) {
            $members = array_values(array_filter($employees, static fn(array $employee): bool => (string) ($employee['department'] ?? '') === (string) $department));
            $departmentGaps = array_values(array_filter($gaps, static fn(array $gap): bool => (string) ($gap['department'] ?? '') === (string) $department || ((string) ($gap['scope_type'] ?? '') === 'department' && (string) ($gap['scope_label'] ?? '') === (string) $department)));
            $departmentRisks = array_values(array_filter($peopleRisks, static fn(array $risk): bool => (string) ($risk['department'] ?? '') === (string) $department || ((string) ($risk['scope_type'] ?? '') === 'department' && (string) ($risk['scope_label'] ?? '') === (string) $department)));
            $departmentStrategies = array_values(array_filter($bestStrategies, static fn(array $strategy): bool => in_array((string) $department, (array) ($strategy['departments'] ?? []), true)));

            $metricAverages = $this->averageMetrics($members);
            [$strongestCapability, $strongestScore] = $this->metricExtrema($metricAverages, true);
            [$weakestCapability, $weakestScore] = $this->metricExtrema($metricAverages, false);
            $avgScore = $summary['avg_score'] ?? null;

            $out[(string) $department] = [
                'department' => (string) $department,
                'staff_count' => (int) ($summary['staff_count'] ?? 0),
                'avg_score' => $avgScore !== null ? (float) $avgScore : null,
                'score_status' => $avgScore !== null ? 'eligible' : 'insufficient_evidence',
                'health_label' => $avgScore === null ? 'Not enough evidence' : ($avgScore >= 72 ? 'Healthy' : ($avgScore >= 58 ? 'Watch' : 'Needs leadership attention')),
                'strongest_capability' => $this->metricLabel($strongestCapability),
                'strongest_score' => round($strongestScore, 1),
                'biggest_weakness' => $this->metricLabel($weakestCapability),
                'weakest_score' => round($weakestScore, 1),
                'workload_pressure' => (int) ($summary['overloaded_count'] ?? 0),
                'risk_count' => count($departmentRisks),
                'top_member' => (string) ($summary['top_member'] ?? 'N/A'),
                'strategy_signal' => $departmentStrategies[0]['name'] ?? 'No strong strategy signal yet',
                'manager_action' => $departmentGaps[0]['recommendation'] ?? 'Confirm the department rhythm, rebalance visible overload, and pick one measurable operating improvement.',
                'confidence' => count($members) >= 3 ? 'moderate' : 'low',
            ];
        }

        return $out;
    }

    private function buildStructuralReadiness(array $functionCoverage, array $departmentSummaries, array $profile): array
    {
        $active = array_values(array_filter($functionCoverage, static fn(array $function): bool => (string) ($function['relevance_status'] ?? 'active') === 'active'));
        $explicitlyOwned = count(array_filter($active, static fn(array $function): bool => (int) ($function['explicit_owner_count'] ?? 0) > 0));
        $backedUp = count(array_filter($active, static fn(array $function): bool => (int) ($function['explicit_owner_count'] ?? 0) >= 2));
        $total = count($active);
        $ownership = $total > 0 ? ($explicitlyOwned / $total) * 100 : 0.0;
        $continuity = $total > 0 ? ($backedUp / $total) * 100 : 0.0;
        $model = (string) ($profile['effective_model'] ?? 'solo_founder');
        $departmentComponent = in_array($model, ['departmental_organization', 'scaling_multi_team'], true)
            ? min(100, count($departmentSummaries) * 25)
            : 100;
        $score = round(($ownership * 0.60) + ($continuity * 0.25) + ($departmentComponent * 0.15), 1);
        return [
            'score' => $score,
            'label' => $score >= 75 ? 'Strong structural readiness' : ($score >= 50 ? 'Structure is forming' : 'Ownership foundation needs attention'),
            'effective_model' => $model,
            'active_function_count' => $total,
            'explicitly_owned_function_count' => $explicitlyOwned,
            'backed_up_function_count' => $backedUp,
            'explicit_department_count' => count($departmentSummaries),
            'components' => ['ownership_coverage' => round($ownership, 1), 'continuity_coverage' => round($continuity, 1), 'department_readiness' => round($departmentComponent, 1)],
        ];
    }

    private function baselineMetric(string $label, string $key, ?float $current, string $unit = '', int $precision = 0, ?float $visualScore = null, string $basis = 'live_current'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'current' => $current !== null ? round($current, $precision) : null,
            'previous' => null,
            'delta' => null,
            'unit' => $unit,
            'precision' => $precision,
            'direction' => 'baseline',
            'status' => $current !== null ? 'baseline' : 'insufficient evidence',
            'tone' => 'neutral',
            'visual_score' => $current !== null ? round(max(0, min(100, $visualScore ?? $current)), 1) : 0.0,
            'basis' => $basis,
        ];
    }

    private function buildOrganizationHealth(array $summary, array $departmentSummaries, array $peopleRisks, array $bestStrategies, array $evidenceConfidence, array $functionCoverage = [], array $founderLoad = [], array $structuralReadiness = []): array
    {
        $staffCount = max(1, (int) ($summary['staff_count'] ?? 0));
        $avgScore = $summary['avg_score'] ?? null;
        $eligibleCount = (int) ($summary['eligible_staff_count'] ?? 0);
        $eligibleRatio = (float) ($summary['eligible_ratio'] ?? 0);
        $soloMode = (string) ($structuralReadiness['effective_model'] ?? '') === 'solo_founder';
        $executionEligible = $avgScore !== null && ($soloMode ? $eligibleCount >= 1 : ($eligibleCount >= 2 && $eligibleRatio >= 0.5));
        $overloadedRate = ((int) ($summary['overloaded_count'] ?? 0)) / $staffCount;
        $atRiskRate = ((int) ($summary['at_risk_count'] ?? 0)) / $staffCount;
        $riskPenalty = 0;
        foreach ($peopleRisks as $risk) {
            $riskPenalty += match ((string) ($risk['severity'] ?? 'medium')) {
                'high' => 9,
                'low' => 2,
                default => 5,
            };
        }

        $departmentSpread = 0.0;
        if (count($departmentSummaries) > 1) {
            $scores = array_values(array_filter(array_map(static fn(array $department) => $department['avg_score'] ?? null, $departmentSummaries), static fn($score): bool => $score !== null));
            if (count($scores) > 1) {
                $departmentSpread = max($scores) - min($scores);
            }
        }

        $workloadScore = max(0, 100 - ($overloadedRate * 100));
        $riskScore = max(0, 100 - min(45, $riskPenalty) - ($atRiskRate * 30) - min(15, $departmentSpread / 2));
        $strategyScore = $bestStrategies !== [] ? 78 : 48;
        $activeCoreFunctions = array_filter($functionCoverage, static fn(array $function): bool => (string) ($function['category'] ?? '') === 'core' && (string) ($function['relevance_status'] ?? 'active') === 'active');
        $coveredCore = count(array_filter($activeCoreFunctions, static fn(array $function): bool => (int) ($function['explicit_owner_count'] ?? $function['owner_count'] ?? 0) > 0));
        $inferredCore = count(array_filter($activeCoreFunctions, static fn(array $function): bool => (int) ($function['explicit_owner_count'] ?? $function['owner_count'] ?? 0) <= 0 && (int) ($function['inferred_owner_count'] ?? 0) > 0));
        $coreTotal = max(1, count($activeCoreFunctions));
        $functionCoverageScore = $functionCoverage !== [] ? (int) round(($coveredCore / $coreTotal) * 100) : 50;
        $ownershipConfidence = $functionCoverage === [] ? 'low' : ($coveredCore === count($activeCoreFunctions) ? 'high' : ($coveredCore > 0 ? 'moderate' : 'low'));
        $founderLoadPenalty = (string) ($founderLoad['level'] ?? '') === 'high' ? 8 : ((string) ($founderLoad['level'] ?? '') === 'moderate' ? 3 : 0);
        $confidenceAdjustment = ((string) ($evidenceConfidence['level'] ?? 'low')) === 'low' ? -8 : 0;
        $score = $executionEligible
            ? (int) round(((float) $avgScore * 0.38) + ($riskScore * 0.22) + ($workloadScore * 0.14) + ($strategyScore * 0.10) + ((float) ($structuralReadiness['score'] ?? $functionCoverageScore) * 0.16) + $confidenceAdjustment - $founderLoadPenalty)
            : null;
        $score = $score !== null ? max(0, min(100, $score)) : null;

        return [
            'score' => $score,
            'score_status' => $executionEligible ? 'eligible' : 'insufficient_evidence',
            'label' => $score === null ? 'Baseline forming' : ($score >= 75 ? 'Strong operating health' : ($score >= 62 ? 'Stable with watchpoints' : ($score >= 48 ? 'Needs leadership attention' : 'Fragile operating health'))),
            'confidence' => (string) ($evidenceConfidence['level'] ?? 'low'),
            'ownership_confidence' => $ownershipConfidence,
            'summary' => $score === null
                ? 'Execution evidence does not yet qualify a combined Organization Health score. Use structural readiness and measured operating signals while the baseline forms.'
                : ($score >= 75
                ? 'The organization shows solid execution signals with manageable people-risk concentration.'
                : ($score >= 62
                    ? 'The organization is operating, but leadership should watch risk concentration and workload pressure.'
                    : 'The organization needs focused leadership attention before small people and execution signals compound.')),
            'components' => [
                'average_score' => $avgScore !== null ? round((float) $avgScore, 1) : null,
                'risk_score' => round($riskScore, 1),
                'workload_score' => round($workloadScore, 1),
                'strategy_score' => round($strategyScore, 1),
                'function_coverage_score' => round($functionCoverageScore, 1),
                'structural_readiness_score' => (float) ($structuralReadiness['score'] ?? 0),
            ],
            'risk_score_explanation' => [
                'meaning' => 'Higher is healthier. The score shows how much risk resilience remains after current people and department penalties are deducted.',
                'starting_score' => 100.0,
                'people_risk_penalty' => round((float) min(45, $riskPenalty), 1),
                'at_risk_share_penalty' => round($atRiskRate * 30, 1),
                'department_spread_penalty' => round((float) min(15, $departmentSpread / 2), 1),
                'risk_item_count' => count($peopleRisks),
                'high_risk_item_count' => count(array_filter(
                    $peopleRisks,
                    static fn(array $risk): bool => (string) ($risk['severity'] ?? 'medium') === 'high'
                )),
                'formula' => '100 minus the people-risk penalty, at-risk staff-share penalty, and department performance-spread penalty.',
            ],
            'eligibility' => ['eligible_people_count' => $eligibleCount, 'total_people_count' => (int) ($summary['staff_count'] ?? 0), 'eligible_ratio' => $eligibleRatio],
            'ownership' => [
                'active_core_functions' => count($activeCoreFunctions),
                'explicitly_covered_core_functions' => $coveredCore,
                'inferred_only_core_functions' => $inferredCore,
                'coverage_source' => $inferredCore > 0 ? ($coveredCore > 0 ? 'mixed' : 'inferred_role_default') : ($coveredCore > 0 ? 'explicit' : 'none'),
            ],
        ];
    }

    private function buildLeadershipAttention(array $summary, array $departmentSummaries, array $gaps, array $peopleRisks, array $departmentIntelligence, array $organizationHealth, array $evidenceConfidence, array $functionCoverage = [], array $founderLoad = [], array $nextFunctionToFormalize = []): array
    {
        $items = [];
        if ((string) ($founderLoad['level'] ?? '') === 'high') {
            $items[] = [
                'title' => 'Founder load needs deliberate reduction',
                'why' => 'A founder carrying many functions can become the constraint even when individual execution looks strong.',
                'evidence' => (string) ($founderLoad['summary'] ?? ''),
                'action' => 'Formalize or delegate ' . (string) ($nextFunctionToFormalize['function'] ?? 'one function') . ' before adding more commitments.',
                'severity' => 'high',
                'confidence' => (string) ($founderLoad['confidence'] ?? 'moderate'),
                'timeframe' => 'Next 30 days',
            ];
        }
        foreach ($functionCoverage as $function) {
            if (count($items) >= 2) {
                break;
            }
            if ((string) ($function['relevance_status'] ?? 'active') !== 'active' || (string) ($function['state'] ?? '') !== 'unassigned') {
                continue;
            }
            $items[] = [
                'title' => (string) ($function['name'] ?? 'Function') . ' ownership is missing',
                'why' => 'This is a function coverage gap; leadership should not read it as department underperformance.',
                'evidence' => (string) ($function['evidence'] ?? ''),
                'action' => (string) ($function['recommended_next_move'] ?? 'Assign a temporary function owner.'),
                'severity' => 'medium',
                'confidence' => (string) ($function['confidence'] ?? 'moderate'),
                'timeframe' => 'This week',
            ];
        }
        if (($organizationHealth['score'] ?? null) !== null && (int) $organizationHealth['score'] < 62) {
            $items[] = [
                'title' => 'Organization health needs leadership review',
                'why' => (string) ($organizationHealth['summary'] ?? 'The organization health score is below the stable range.'),
                'evidence' => 'Composite health score: ' . (int) ($organizationHealth['score'] ?? 0) . '/100.',
                'action' => 'Use this week to pick one operating risk and assign a clear owner, due date, and success measure.',
                'severity' => (int) ($organizationHealth['score'] ?? 0) < 48 ? 'high' : 'medium',
                'confidence' => (string) ($organizationHealth['confidence'] ?? $evidenceConfidence['level'] ?? 'low'),
                'timeframe' => 'This week',
            ];
        }

        foreach ($peopleRisks as $risk) {
            if (count($items) >= 5) {
                break;
            }
            $items[] = [
                'title' => (string) ($risk['title'] ?? 'People risk signal'),
                'why' => (string) ($risk['implication'] ?? 'This signal may affect execution if leadership ignores it.'),
                'evidence' => (string) ($risk['evidence'] ?? ''),
                'action' => (string) ($risk['action'] ?? 'Validate the signal and choose one measurable next action.'),
                'severity' => (string) ($risk['severity'] ?? 'medium'),
                'confidence' => (string) ($risk['confidence'] ?? $evidenceConfidence['level'] ?? 'low'),
                'timeframe' => ((string) ($risk['severity'] ?? 'medium')) === 'high' ? 'Next 7 days' : 'Next 30 days',
            ];
        }

        $departments = array_values($departmentIntelligence);
        usort($departments, static fn(array $left, array $right): int => ((int) ($right['risk_count'] ?? 0) <=> (int) ($left['risk_count'] ?? 0)) ?: ((float) ($left['avg_score'] ?? 0) <=> (float) ($right['avg_score'] ?? 0)));
        foreach ($departments as $department) {
            if (count($items) >= 5) {
                break;
            }
            if (($department['avg_score'] ?? null) === null && (int) ($department['risk_count'] ?? 0) === 0) {
                continue;
            }
            if (($department['avg_score'] ?? null) !== null && (float) $department['avg_score'] >= 62 && (int) ($department['risk_count'] ?? 0) === 0) {
                continue;
            }
            $items[] = [
                'title' => (string) ($department['department'] ?? 'Department') . ' operating review',
                'why' => 'This department has the clearest combination of low score or concentrated people-risk signals.',
                'evidence' => (($department['avg_score'] ?? null) !== null ? 'Average score ' . number_format((float) $department['avg_score'], 1) . '; ' : 'No qualified department score; ') . (int) ($department['risk_count'] ?? 0) . ' risk signal(s).',
                'action' => (string) ($department['manager_action'] ?? 'Run a department operating review and choose one measurable improvement.'),
                'severity' => ($department['avg_score'] ?? null) !== null && (float) $department['avg_score'] < 55 ? 'high' : 'medium',
                'confidence' => (string) ($department['confidence'] ?? $evidenceConfidence['level'] ?? 'low'),
                'timeframe' => 'Next 14 days',
            ];
        }

        if (count($items) < 5 && (string) ($evidenceConfidence['level'] ?? 'low') === 'low') {
            $items[] = [
                'title' => 'Insight confidence is limited',
                'why' => 'Low system visibility can make people and execution patterns look weaker or stronger than they are.',
                'evidence' => (string) ($evidenceConfidence['summary'] ?? 'Low evidence confidence.'),
                'action' => 'Confirm CRM adoption, tracking hygiene, and manager context before making strong people decisions.',
                'severity' => 'medium',
                'confidence' => 'high',
                'timeframe' => 'Before acting',
            ];
        }

        return array_slice($this->dedupeByTitleAndScope($items), 0, 5);
    }

    private function buildFounderBrief(array $summary, array $organizationHealth, array $leadershipAttention, array $departmentIntelligence, array $bestStrategies, array $evidenceConfidence, array $organizationStage = [], array $founderLoad = [], array $nextFunctionToFormalize = []): array
    {
        $departments = array_values($departmentIntelligence);
        usort($departments, static fn(array $left, array $right): int => ((float) ($left['avg_score'] ?? 0) <=> (float) ($right['avg_score'] ?? 0)));
        $watchDepartment = $departments[0]['department'] ?? 'No department watchpoint';
        $topStrategy = $bestStrategies[0]['name'] ?? 'No strong strategy pattern yet';
        $firstAttention = $leadershipAttention[0] ?? [];

        return [
            'headline' => (string) ($organizationHealth['label'] ?? 'Organization health readout'),
            'overall_readout' => (string) ($organizationStage['summary'] ?? $organizationHealth['summary'] ?? 'Review the current organization health signals before leadership action.'),
            'biggest_strength' => (int) ($summary['high_performers'] ?? 0) > 0
                ? (int) ($summary['high_performers'] ?? 0) . ' high-performing staff signal repeatable operating habits.'
                : 'The workspace has enough structure to begin reading organization-level patterns.',
            'biggest_risk' => (string) ($firstAttention['title'] ?? 'No urgent risk detected in this filtered view.'),
            'leadership_priority' => (string) ($firstAttention['action'] ?? 'Keep reviewing organization health, workload, and strategy signals weekly.'),
            'organization_stage' => (string) ($organizationStage['label'] ?? 'Organization stage forming'),
            'department_watch' => (string) $watchDepartment,
            'dependency_signal' => (string) ($founderLoad['summary'] ?? '') !== ''
                ? (string) ($founderLoad['summary'] ?? '')
                : ((int) ($summary['overloaded_count'] ?? 0) > 0
                ? (int) ($summary['overloaded_count'] ?? 0) . ' staff member(s) show visible workload pressure.'
                : 'No major workload concentration signal is visible in this window.'),
            'recommended_action' => (string) ($firstAttention['action'] ?? $nextFunctionToFormalize['action'] ?? 'Use the Brief tab as the leadership pre-read before weekly operating review.'),
            'next_function_to_formalize' => (string) ($nextFunctionToFormalize['function'] ?? ''),
            'strategy_signal' => (string) $topStrategy,
            'confidence' => (string) ($evidenceConfidence['level'] ?? 'low'),
        ];
    }

    private function riskTypeForGapTitle(string $title): string
    {
        $title = strtolower($title);
        if (str_contains($title, 'workload') || str_contains($title, 'overload')) {
            return 'workload_overload';
        }
        if (str_contains($title, 'activity')) {
            return 'data_visibility_risk';
        }
        if (str_contains($title, 'department')) {
            return 'department_coaching_need';
        }
        if (str_contains($title, 'strategy')) {
            return 'weak_strategy_execution_signal';
        }
        return 'underperformance_signal';
    }

    private function riskConfidence(string $riskType, array $evidenceConfidence): string
    {
        $base = (string) ($evidenceConfidence['level'] ?? 'low');
        if ($riskType === 'data_visibility_risk') {
            return 'low';
        }
        if (in_array($riskType, ['workload_overload', 'workload_concentration'], true) && $base === 'high') {
            return 'high';
        }
        return $base === 'low' ? 'moderate' : $base;
    }

    private function specialistRiskTitle(string $title, string $riskType): string
    {
        return match ($riskType) {
            'data_visibility_risk' => 'Low visibility or adoption signal',
            'workload_overload' => 'Workload overload signal',
            'department_coaching_need' => 'Department coaching need',
            'weak_strategy_execution_signal' => 'Strategy-to-execution weakness',
            'top_performer_dependency' => 'Top-performer dependency signal',
            'manager_support_gap' => 'Manager-support pattern',
            default => $title,
        };
    }

    private function dedupeByTitleAndScope(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['title'] ?? ''))) . '|' . strtolower(trim((string) ($item['scope_label'] ?? $item['department'] ?? '')));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function averageMetrics(array $employees): array
    {
        $metrics = [
            'task_completion',
            'timeliness',
            'activity_consistency',
            'outcome_impact',
            'pipeline_movement',
            'campaign_output',
            'workload_balance',
        ];
        $out = [];
        foreach ($metrics as $metric) {
            $out[$metric] = $employees !== []
                ? array_sum(array_map(static fn(array $employee): float => (float) ($employee['metrics'][$metric] ?? 0.0), $employees)) / count($employees)
                : 0.0;
        }
        return $out;
    }

    private function metricExtrema(array $metricAverages, bool $highest): array
    {
        if ($metricAverages === []) {
            return ['task_completion', 0.0];
        }
        $targetMetric = array_key_first($metricAverages);
        $targetValue = (float) ($metricAverages[$targetMetric] ?? 0.0);
        foreach ($metricAverages as $metric => $value) {
            $value = (float) $value;
            if (($highest && $value > $targetValue) || (!$highest && $value < $targetValue)) {
                $targetMetric = (string) $metric;
                $targetValue = $value;
            }
        }
        return [$targetMetric, $targetValue];
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'task_completion' => 'Task completion',
            'timeliness' => 'Timeliness',
            'activity_consistency' => 'Activity consistency',
            'outcome_impact' => 'Outcome impact',
            'pipeline_movement' => 'Pipeline movement',
            'campaign_output' => 'Campaign output',
            'workload_balance' => 'Workload balance',
            default => ucwords(str_replace('_', ' ', $metric)),
        };
    }

    private function buildGapAnalysis(array $employees, array $departmentSummaries, array $thresholds): array
    {
        $gaps = [];

        foreach ($employees as $employee) {
            $scoreEligible = (string) ($employee['score_status'] ?? '') === 'eligible' && $employee['score'] !== null;
            if ($scoreEligible && (float) $employee['score'] <= $thresholds['at_risk']) {
                $gaps[] = [
                    'risk_type' => 'underperformance_signal',
                    'scope_type' => 'employee',
                    'scope_label' => $employee['name'],
                    'user_id' => $employee['id'],
                    'department' => $employee['department'],
                    'severity' => 'high',
                    'confidence' => 'moderate',
                    'title' => 'At-risk performance',
                    'summary' => $employee['name'] . ' is below the at-risk threshold; treat this as a coaching signal to validate, not a character judgment.',
                    'evidence' => 'Current blended score: ' . number_format((float) ($employee['score'] ?? 0), 1) . '; role: ' . (string) ($employee['role_label'] ?? ucfirst((string) ($employee['role'] ?? 'general'))) . '.',
                    'recommendation' => 'Set a weekly recovery plan focused on role clarity, overdue commitments, support needs, and one measurable outcome.',
                ];
            }
            if ((int) ($employee['overdue_open_tasks'] ?? 0) >= $thresholds['overloaded_task_count']) {
                $gaps[] = [
                    'risk_type' => 'workload_overload',
                    'scope_type' => 'employee',
                    'scope_label' => $employee['name'],
                    'user_id' => $employee['id'],
                    'department' => $employee['department'],
                    'severity' => 'medium',
                    'confidence' => 'moderate',
                    'title' => 'Workload overload',
                    'summary' => $employee['name'] . ' has a high overdue queue, which risks quality and follow-through.',
                    'evidence' => (int) ($employee['overdue_open_tasks'] ?? 0) . ' overdue open task(s) against an overload threshold of ' . (int) $thresholds['overloaded_task_count'] . '.',
                    'recommendation' => 'Reduce queue pressure, clarify what can be paused, and rebalance urgent work before assigning more high-priority work.',
                ];
            }
            if ((int) ($employee['activity_count'] ?? 0) === 0) {
                $gaps[] = [
                    'risk_type' => 'data_visibility_risk',
                    'scope_type' => 'employee',
                    'scope_label' => $employee['name'],
                    'user_id' => $employee['id'],
                    'department' => $employee['department'],
                    'severity' => 'medium',
                    'confidence' => 'low',
                    'title' => 'Low visible activity',
                    'summary' => $employee['name'] . ' has little or no captured activity in the selected window; this may reflect tracking hygiene or work outside the CRM.',
                    'evidence' => '0 captured activities in the selected window.',
                    'recommendation' => 'Check adoption, tracking hygiene, and whether work is happening outside recorded systems.',
                ];
            }
            if ($scoreEligible && $employee['metrics']['outcome_impact'] !== null && $employee['metrics']['activity_consistency'] !== null && (float) $employee['metrics']['outcome_impact'] < 20 && (float) $employee['metrics']['activity_consistency'] >= 35 && (float) $employee['score'] <= $thresholds['needs_coaching']) {
                $gaps[] = [
                    'risk_type' => 'weak_strategy_execution_signal',
                    'scope_type' => 'employee',
                    'scope_label' => $employee['name'],
                    'user_id' => $employee['id'],
                    'department' => $employee['department'],
                    'severity' => 'medium',
                    'confidence' => 'moderate',
                    'title' => 'Weak strategy-to-execution signal',
                    'summary' => $employee['name'] . ' has visible activity but weak outcome impact, suggesting the work may need sharper priority or strategy alignment.',
                    'evidence' => 'Activity consistency ' . number_format((float) ($employee['metrics']['activity_consistency'] ?? 0), 1) . '%, outcome impact ' . number_format((float) ($employee['metrics']['outcome_impact'] ?? 0), 1) . '%.',
                    'recommendation' => 'Review whether the current work maps to the highest-value strategy and define one outcome metric for the next cycle.',
                ];
            }
        }

        foreach ($departmentSummaries as $department => $summary) {
            if (($summary['avg_score'] ?? null) !== null && (float) $summary['avg_score'] <= max(50, $thresholds['needs_coaching'])) {
                $gaps[] = [
                    'risk_type' => 'department_coaching_need',
                    'scope_type' => 'department',
                    'scope_label' => $department,
                    'department' => $department,
                    'severity' => 'high',
                    'confidence' => ((int) ($summary['staff_count'] ?? 0)) >= 3 ? 'moderate' : 'low',
                    'title' => 'Department-level coaching need',
                    'summary' => $department . ' is operating below the expected performance level for the current score model.',
                    'evidence' => 'Average score ' . number_format((float) ($summary['avg_score'] ?? 0), 1) . ' across ' . (int) ($summary['staff_count'] ?? 0) . ' staff.',
                    'recommendation' => 'Run a short operating review and standardize the behaviours of the team\'s strongest performers.',
                ];
            }
            if ((int) ($summary['overloaded_count'] ?? 0) > 0 && (int) ($summary['staff_count'] ?? 0) > 0) {
                $gaps[] = [
                    'risk_type' => 'workload_concentration',
                    'scope_type' => 'department',
                    'scope_label' => $department,
                    'department' => $department,
                    'severity' => (int) ($summary['overloaded_count'] ?? 0) >= max(2, (int) ceil(((int) ($summary['staff_count'] ?? 0)) * 0.25)) ? 'high' : 'medium',
                    'confidence' => 'moderate',
                    'title' => 'Department workload concentration',
                    'summary' => $department . ' has visible overdue workload concentration that may slow delivery or hide support needs.',
                    'evidence' => (int) ($summary['overloaded_count'] ?? 0) . ' overloaded staff out of ' . (int) ($summary['staff_count'] ?? 0) . '.',
                    'recommendation' => 'Review active work allocation and move nonessential commitments away from overloaded staff.',
                ];
            }
        }

        return array_slice($gaps, 0, 18);
    }

    private function resolveWindow(string $timeframe): array
    {
        $timeframe = strtolower(trim($timeframe));
        $days = match ($timeframe) {
            'today' => 1,
            'week' => 7,
            'quarter' => 90,
            'year' => max(1, (int) date('z') + 1),
            default => 30,
        };

        return [
            'label' => in_array($timeframe, ['today', 'week', 'month', 'quarter', 'year'], true) ? $timeframe : 'month',
            'days' => $days,
            'start' => $timeframe === 'year'
                ? date('Y-01-01 00:00:00')
                : date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            'end' => date('Y-m-d H:i:s'),
        ];
    }

    private function roleBucket(string $role): string
    {
        $role = strtolower(trim($role));
        if (str_contains($role, 'marketing')) {
            return 'marketing';
        }
        if (str_contains($role, 'sales')) {
            return 'sales';
        }
        return 'general';
    }

    private function departmentForRole(string $role, array $mappings): string
    {
        $role = strtolower(trim($role));
        if (isset($mappings[$role])) {
            return (string) $mappings[$role];
        }
        return match ($this->roleBucket($role)) {
            'marketing' => 'Marketing',
            'sales' => 'Sales',
            default => 'Operations',
        };
    }

    private function scoreBand(float $score, array $thresholds): string
    {
        if ($score >= $thresholds['high_performer']) {
            return 'high_performer';
        }
        if ($score <= $thresholds['at_risk']) {
            return 'at_risk';
        }
        if ($score <= $thresholds['needs_coaching']) {
            return 'needs_coaching';
        }
        return 'steady';
    }

    private function recordRuntimeEvent(
        int $workspaceId,
        string $capability,
        string $eventType,
        string $status,
        float $startedAt,
        array $metadata = [],
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {
        $capability = trim($capability) !== '' ? trim($capability) : 'dashboard';
        $this->runtimeEvents->record([
            'workspace_id' => $workspaceId,
            'user_id' => Auth::userId() ?? null,
            'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
            'capability_key' => 'hr_analytics.' . $capability,
            'event_type' => $eventType,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }

    private function buildDataQuality(array $employees, array $window): array
    {
        $eligible = count(array_filter($employees, static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible'));
        $total = count($employees);
        $averageCoverage = $total > 0
            ? array_sum(array_map(static fn(array $employee): float => (float) ($employee['evidence_coverage'] ?? 0), $employees)) / $total
            : 0.0;
        return [
            'calculation_version' => OrganizationIntelligenceSnapshotService::CALCULATION_VERSION,
            'eligible_people_count' => $eligible,
            'ineligible_people_count' => max(0, $total - $eligible),
            'total_people_count' => $total,
            'eligible_ratio' => $total > 0 ? round($eligible / $total, 4) : 0.0,
            'average_evidence_coverage' => round($averageCoverage, 4),
            'window_days' => (int) ($window['days'] ?? 30),
            'evidence_policy' => [
                'today_scores_enabled' => false,
                'minimum_families' => 2,
                'minimum_events_7d' => 3,
                'minimum_events_longer' => 5,
                'minimum_weight_coverage' => 0.60,
                'system_time_is_supporting_only' => true,
            ],
        ];
    }

    private function safeRuntimeMetadata(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = $value;
            }
        }
        return $out;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
