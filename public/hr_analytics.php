<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Security;
use CRM\Session;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\HRAnalyticsService;
use CRM\Services\ContactStageHistoryService;
use CRM\Services\OrganizationIntelligenceProfileService;
use CRM\Services\OrganizationIntelligenceEngineService;
use CRM\Services\OrganizationIntelligenceSnapshotService;
use CRM\Services\OrganizationIntelligenceMonitoringService;
use CRM\Services\OrganizationIntelligenceMutationContextService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;
use CRM\Services\WorkspaceHRAnalyticsGateService;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Services\WorkspaceMarketplacePerformanceService;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('hr.analytics.view', $user)) {
    header('Location: dashboard.php');
    exit;
}

$canManage = Authorization::can('hr.analytics.manage', $user);
$canSettings = Authorization::can('hr.analytics.settings', $user);
$canManageUsers = Authorization::can('admin.users.manage', $user);
$settingsModule = new HRAnalyticsSettings();
$service = new HRAnalyticsService($settingsModule);
$analyticsWorkspace = new AnalyticsWorkspaceService();
$workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();
(new WorkspaceBusinessIntelligenceGateService())->enforceWeb($workspaceId, $user, 'Organization Intelligence');
(new WorkspaceHRAnalyticsGateService())->enforceWebRuntime($workspaceId, $user);
$webLatestSnapshot = (new OrganizationIntelligenceSnapshotService())->latest($workspaceId);
$webMutationContext = (new OrganizationIntelligenceMutationContextService())->issue(
    $workspaceId,
    (int) ($user['id'] ?? 0),
    !empty($webLatestSnapshot['id']) ? (int) $webLatestSnapshot['id'] : null,
    2
);
$verifyMutationContext = static function () use ($workspaceId, $user): void {
    (new OrganizationIntelligenceMutationContextService())->verify(
        (string) ($_POST['mutation_context_token'] ?? ''),
        $workspaceId,
        (int) ($user['id'] ?? 0)
    );
};
$languageLevelContext = (new WorkspaceLanguageLevelService())->currentContext((int) ($user['id'] ?? 0));

$tabs = [
    'brief' => ['label' => 'Executive Brief', 'icon' => 'fa-solid fa-building-columns'],
    'people' => ['label' => 'People & HR', 'icon' => 'fa-solid fa-user-group'],
    'analytics' => ['label' => 'Executive Analytics', 'icon' => 'fa-solid fa-chart-line'],
    'priorities' => ['label' => 'Priorities', 'icon' => 'fa-solid fa-list-check'],
];
if ($canSettings) {
    $tabs['settings'] = ['label' => 'Methodology', 'icon' => 'fa-solid fa-sliders'];
}

$requestInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$activeTab = (string) ($requestInput['tab'] ?? 'brief');
$tabAliases = [
    'overview' => 'brief',
    'strategy' => 'priorities',
    'swot' => 'analytics',
    'actions' => 'priorities',
];
$activeTab = $tabAliases[$activeTab] ?? $activeTab;
if (!isset($tabs[$activeTab])) {
    $activeTab = 'brief';
}

$requestedPriorityView = (string) ($requestInput['priority_view'] ?? 'leadership');
$priorityViewAliases = [
    'actions' => 'action_plan',
    'action-plan' => 'action_plan',
    'plan' => 'action_plan',
    'access_profile' => 'access',
    'access-profile' => 'access',
    'plugin_health' => 'diagnostics',
    'health' => 'diagnostics',
];
$requestedPriorityView = $priorityViewAliases[$requestedPriorityView] ?? $requestedPriorityView;

$success = null;
$error = null;
$actionPlanDraft = null;
$draftRequested = false;
$draftTargetUserId = null;
$draftTargetDepartment = null;

$filters = [
    'timeframe' => (string) ($requestInput['timeframe'] ?? 'month'),
    'role' => (string) ($requestInput['role'] ?? ''),
    'department' => (string) ($requestInput['department'] ?? ''),
    'user_id' => !empty($requestInput['user_id']) && ctype_digit((string) $requestInput['user_id'])
        ? $analyticsWorkspace->ensureScopedUserId((int) $requestInput['user_id'], $workspaceId)
        : null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Refresh and try again.';
    } else {
        try {
            $postAction = (string) ($_POST['hr_action'] ?? '');
            if ($postAction === 'save_settings' && $canSettings) {
                $settingsModule->save([
                    'ai_enabled' => !empty($_POST['ai_enabled']),
                    'thresholds' => [
                        'high_performer' => (int) ($_POST['threshold_high_performer'] ?? 75),
                        'at_risk' => (int) ($_POST['threshold_at_risk'] ?? 45),
                        'needs_coaching' => (int) ($_POST['threshold_needs_coaching'] ?? 55),
                        'overloaded_task_count' => (int) ($_POST['threshold_overloaded_task_count'] ?? 7),
                        'inactive_days' => (int) ($_POST['threshold_inactive_days'] ?? 10),
                    ],
                    'department_mappings' => [
                        'marketing' => trim((string) ($_POST['department_marketing'] ?? 'Marketing')),
                        'sales' => trim((string) ($_POST['department_sales'] ?? 'Sales')),
                        'admin' => trim((string) ($_POST['department_admin'] ?? 'Leadership')),
                        'owner' => trim((string) ($_POST['department_owner'] ?? 'Leadership')),
                        'viewer' => trim((string) ($_POST['department_viewer'] ?? 'Operations')),
                    ],
                    'prompt_config' => [
                        'manager_focus' => trim((string) ($_POST['manager_focus'] ?? '')),
                        'swot_focus' => trim((string) ($_POST['swot_focus'] ?? '')),
                    ],
                ], (int) ($user['id'] ?? 0), $workspaceId);
                $success = 'HR analytics settings updated.';
            } elseif ($postAction === 'confirm_operating_model' && $canSettings) {
                (new OrganizationIntelligenceProfileService())->confirm(
                    $workspaceId,
                    (string) ($_POST['operating_model'] ?? ''),
                    is_array($_POST['founder_user_ids'] ?? null) ? $_POST['founder_user_ids'] : [],
                    (int) ($user['id'] ?? 0)
                );
                $success = 'Organization operating model confirmed.';
            } elseif ($postAction === 'create_coaching_task' && $canManage) {
                $verifyMutationContext();
                $taskId = $service->createCoachingTask(
                    (int) ($user['id'] ?? 0),
                    (int) ($_POST['target_user_id'] ?? 0),
                    trim((string) ($_POST['task_title'] ?? 'Coaching follow-up')),
                    trim((string) ($_POST['task_description'] ?? '')),
                    !empty($_POST['task_due_date']) ? (string) $_POST['task_due_date'] : null,
                    trim((string) ($_POST['task_priority'] ?? 'high'))
                );
                $success = 'Coaching task created as task #' . $taskId . '.';
            } elseif ($postAction === 'assign_access_role' && $canManage && $canManageUsers) {
                $service->assignAccessRole((int) ($user['id'] ?? 0), (int) ($_POST['target_user_id'] ?? 0), (int) ($_POST['role_id'] ?? 0));
                $success = 'Access profile updated.';
            } elseif ($postAction === 'create_action_plan_tasks' && $canManage) {
                $verifyMutationContext();
                $postedCandidates = is_array($_POST['task_candidates'] ?? null) ? $_POST['task_candidates'] : [];
                $selectedKeys = array_values(array_filter(array_map(
                    static fn($key): string => preg_replace('/[^a-z0-9_.-]+/', '_', strtolower(trim((string) $key))) ?: '',
                    (array) ($_POST['task_candidate_keys'] ?? [])
                )));
                $selectedCandidates = [];
                foreach ($selectedKeys as $selectedKey) {
                    if (!isset($postedCandidates[$selectedKey]) || !is_array($postedCandidates[$selectedKey])) {
                        continue;
                    }
                    $candidate = $postedCandidates[$selectedKey];
                    $candidate['key'] = $selectedKey;
                    $selectedCandidates[] = $candidate;
                }
                $taskResult = $service->createActionPlanTasks(
                    (int) ($user['id'] ?? 0),
                    $selectedCandidates,
                    !empty($_POST['task_assignee_user_id']) ? (int) $_POST['task_assignee_user_id'] : null
                );
                $success = 'Created ' . (int) ($taskResult['created_count'] ?? 0) . ' action-plan task(s): #' . implode(', #', array_map('intval', (array) ($taskResult['task_ids'] ?? []))) . '.';
                $draftRequested = true;
            } elseif ($postAction === 'draft_action_plan' && $canManage) {
                $draftRequested = true;
                $draftTargetUserId = !empty($_POST['target_user_id']) ? (int) $_POST['target_user_id'] : null;
                $draftTargetDepartment = !empty($_POST['target_department']) ? (string) $_POST['target_department'] : null;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$dashboard = (new OrganizationIntelligenceEngineService($service))->buildDashboard($filters, false);
$snapshotService = new OrganizationIntelligenceSnapshotService();
$snapshotDiagnostics = $snapshotService->diagnostics($workspaceId);
$oiRuntimeDiagnostics = (new OrganizationIntelligenceMonitoringService())->diagnostics($workspaceId);
$trendRangeAvailability = [
    'week' => $snapshotService->series($workspaceId, 7)['point_count'] > 0,
    'month' => $snapshotService->series($workspaceId, 30)['point_count'] > 0,
    'quarter' => $snapshotService->series($workspaceId, 90)['point_count'] > 0,
    'year' => $snapshotService->series($workspaceId, max(7, (int) date('z') + 1))['point_count'] > 0,
];
$settings = $dashboard['settings'];
$users = array_map(
    static function (array $userRow): array {
        $label = trim((string) (($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? '')));
        if ($label === '') {
            $label = (string) ($userRow['email'] ?? 'User');
        }

        return [
            'id' => (int) ($userRow['id'] ?? 0),
            'label' => $label,
        ];
    },
    $analyticsWorkspace->listActiveWorkspaceUsers($workspaceId)
);
$departmentOptions = array_values(array_filter(array_map(
    static fn(array $row): string => trim((string) ($row['name'] ?? '')),
    Database::query(
        "SELECT name FROM departments WHERE workspace_id = ? AND is_active = 1 ORDER BY name",
        [$workspaceId]
    )
)));
$assignableRoles = $canManageUsers ? Authorization::getAssignableRoles($user) : [];

$priorityViews = [
    'leadership' => ['label' => 'Leadership', 'icon' => 'fa-solid fa-compass'],
];
if ($canManage) {
    $priorityViews['action_plan'] = ['label' => 'Action Plan', 'icon' => 'fa-solid fa-clipboard-list'];
    $priorityViews['tasks'] = ['label' => 'Tasks', 'icon' => 'fa-solid fa-list-check'];
}
if ($canManageUsers && $assignableRoles !== []) {
    $priorityViews['access'] = ['label' => 'Access Profile', 'icon' => 'fa-solid fa-user-shield'];
}
if ($canManage) {
    $priorityViews['diagnostics'] = ['label' => 'Diagnostics', 'icon' => 'fa-solid fa-wave-square'];
}
$activePriorityView = isset($priorityViews[$requestedPriorityView]) ? $requestedPriorityView : 'leadership';

if ($draftRequested && $canManage) {
    try {
        $actionPlanDraft = $service->buildActionPlanDraftFromDashboard(
            $dashboard,
            $draftTargetUserId,
            $draftTargetDepartment,
            true
        );
        $success = $success ?? 'Action plan draft generated.';
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($actionPlanDraft === null && $canManage) {
    $actionPlanDraft = $service->buildActionPlanDraftFromDashboard($dashboard, null, null, false);
}

$actionPlanTaskCandidates = [];
$managerBriefText = '';
if (is_array($actionPlanDraft)) {
    $actionPlanTaskCandidates = array_values((array) ($actionPlanDraft['task_candidates'] ?? []));
    $managerBriefLines = array_filter([
        (string) ($actionPlanDraft['title'] ?? 'Action Plan'),
        (string) ($actionPlanDraft['summary'] ?? ''),
        !empty($actionPlanDraft['diagnosis']) ? 'Diagnosis: ' . (string) $actionPlanDraft['diagnosis'] : '',
        !empty($actionPlanDraft['seven_day_actions']) ? "7-day actions:\n- " . implode("\n- ", array_map('strval', (array) $actionPlanDraft['seven_day_actions'])) : '',
        !empty($actionPlanDraft['thirty_day_actions']) ? "30-day actions:\n- " . implode("\n- ", array_map('strval', (array) $actionPlanDraft['thirty_day_actions'])) : '',
    ], static fn(string $line): bool => trim($line) !== '');
    $managerBriefText = implode("\n\n", $managerBriefLines);
}

$pluginPerformance = $canManage
    ? (new WorkspaceMarketplacePerformanceService())->buildModulePerformance($workspaceId, WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP, 30)
    : [];
$pluginRuntimeUsage = (array) ($pluginPerformance['runtime'] ?? []);
$pluginHealthStatus = 'unused';
if ((int) ($pluginRuntimeUsage['failed_runs_30d'] ?? 0) > 0) {
    $pluginHealthStatus = 'failing';
} elseif ((int) ($pluginRuntimeUsage['blocked_runs_30d'] ?? 0) > 0) {
    $pluginHealthStatus = 'blocked';
} elseif ((int) ($pluginRuntimeUsage['successful_runs_30d'] ?? 0) > 0 || (int) ($pluginRuntimeUsage['events_30d'] ?? 0) > 0) {
    $pluginHealthStatus = 'used';
}

$buildTabUrl = static function (string $tab, ?string $priorityView = null) use ($filters): string {
    $query = [
        'tab' => $tab,
        'timeframe' => $filters['timeframe'],
        'role' => $filters['role'],
        'department' => $filters['department'],
    ];
    if ($tab === 'priorities') {
        $query['priority_view'] = $priorityView ?: 'leadership';
    }
    if (!empty($filters['user_id'])) {
        $query['user_id'] = (string) $filters['user_id'];
    }

    return 'hr_analytics.php?' . http_build_query(array_filter($query, static fn($value): bool => $value !== '' && $value !== null));
};

$buildPriorityViewUrl = static function (string $priorityView) use ($buildTabUrl): string {
    return $buildTabUrl('priorities', $priorityView);
};

$buildTimeframeUrl = static function (string $timeframe) use ($activeTab, $activePriorityView, $filters): string {
    $query = [
        'tab' => $activeTab,
        'timeframe' => $timeframe,
        'role' => $filters['role'],
        'department' => $filters['department'],
    ];
    if ($activeTab === 'priorities') {
        $query['priority_view'] = $activePriorityView;
    }
    if (!empty($filters['user_id'])) {
        $query['user_id'] = (int) $filters['user_id'];
    }
    return 'hr_analytics.php?' . http_build_query(array_filter($query, static fn($value): bool => $value !== '' && $value !== null));
};

$buildResetUrl = static function (string $tab, ?string $priorityView = null): string {
    $query = ['tab' => $tab];
    if ($tab === 'priorities') {
        $query['priority_view'] = $priorityView ?: 'leadership';
    }

    return 'hr_analytics.php?' . http_build_query($query);
};

$renderHiddenState = static function (string $tab, ?string $priorityView = null) use ($filters, $activePriorityView): void {
    ?>
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
    <?php if ($tab === 'priorities'): ?>
        <input type="hidden" name="priority_view" value="<?php echo htmlspecialchars($priorityView ?: $activePriorityView); ?>">
    <?php endif; ?>
    <input type="hidden" name="timeframe" value="<?php echo htmlspecialchars((string) $filters['timeframe']); ?>">
    <input type="hidden" name="role" value="<?php echo htmlspecialchars((string) $filters['role']); ?>">
    <input type="hidden" name="department" value="<?php echo htmlspecialchars((string) $filters['department']); ?>">
    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string) ($filters['user_id'] ?? '')); ?>">
    <?php
};

$timeframeLabels = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'quarter' => 'Last 90 days', 'year' => 'Year to date'];
$roleLabels = ['marketing' => 'Marketing', 'sales' => 'Sales', 'general' => 'General / Ops'];
$selectedUserLabel = '';
foreach ($users as $userRow) {
    if ((string) ($filters['user_id'] ?? '') === (string) $userRow['id']) {
        $selectedUserLabel = (string) $userRow['label'];
        break;
    }
}
$swotFilterParts = [
    $timeframeLabels[$filters['timeframe']] ?? 'Last 30 days',
    $filters['department'] !== '' ? (string) $filters['department'] : 'All departments',
    $filters['role'] !== '' ? ($roleLabels[$filters['role']] ?? ucfirst((string) $filters['role'])) : 'All roles',
    $selectedUserLabel !== '' ? $selectedUserLabel : 'All staff',
];
$swotFilterContext = implode(' / ', array_filter($swotFilterParts, static fn($value): bool => trim((string) $value) !== ''));
$swotQuadrants = [
    'strengths' => [
        'label' => 'Strengths',
        'icon' => 'fa-solid fa-arrow-trend-up',
        'tone' => 'Signals to protect and repeat',
    ],
    'weaknesses' => [
        'label' => 'Weaknesses',
        'icon' => 'fa-solid fa-circle-exclamation',
        'tone' => 'Friction to coach or simplify',
    ],
    'opportunities' => [
        'label' => 'Opportunities',
        'icon' => 'fa-solid fa-seedling',
        'tone' => 'Useful openings for the next move',
    ],
    'threats' => [
        'label' => 'Threats',
        'icon' => 'fa-solid fa-shield-halved',
        'tone' => 'Risks to watch before they spread',
    ],
];
$strategyFilterContext = $swotFilterContext;
$strategyRoleMeta = [
    'marketing' => [
        'label' => 'Marketing',
        'icon' => 'fa-solid fa-bullhorn',
        'tone' => 'Message, campaign, and audience direction',
    ],
    'sales' => [
        'label' => 'Sales',
        'icon' => 'fa-solid fa-chart-line',
        'tone' => 'Pipeline movement and buyer follow-through',
    ],
    'general' => [
        'label' => 'General / Ops',
        'icon' => 'fa-solid fa-layer-group',
        'tone' => 'Operating rhythm and cross-team execution',
    ],
];
$strategyEvidenceLabels = [
    'task_completion' => 'Tasks',
    'campaign_output' => 'Campaign',
    'activity_consistency' => 'Activity',
    'outcome_impact' => 'Outcome',
    'pipeline_movement' => 'Pipeline',
    'workload_balance' => 'Workload',
];
$analyticsFilterContext = $swotFilterContext;
$overviewKpis = [
    [
        'label' => 'Average Score',
        'value' => ($dashboard['summary']['avg_score'] ?? null) !== null
            ? number_format((float) $dashboard['summary']['avg_score'], 1)
            : 'Baseline forming',
        'icon' => 'fa-solid fa-gauge-high',
        'tone' => 'Blended operating health for the selected team.',
        'variant' => 'steady',
    ],
    [
        'label' => 'High Performers',
        'value' => (string) (int) ($dashboard['summary']['high_performers'] ?? 0),
        'icon' => 'fa-solid fa-arrow-trend-up',
        'tone' => 'People showing repeatable strong signals.',
        'variant' => 'success',
    ],
    [
        'label' => 'At Risk',
        'value' => (string) (int) ($dashboard['summary']['at_risk_count'] ?? 0),
        'icon' => 'fa-solid fa-triangle-exclamation',
        'tone' => 'Staff who may need closer manager attention.',
        'variant' => 'danger',
    ],
    [
        'label' => 'Overloaded',
        'value' => (string) (int) ($dashboard['summary']['overloaded_count'] ?? 0),
        'icon' => 'fa-solid fa-layer-group',
        'tone' => 'Workload pressure visible in the current window.',
        'variant' => 'warning',
    ],
];
$roleDisplayLabels = [
    'marketing' => 'Marketing',
    'sales' => 'Sales',
    'general' => 'General / Ops',
];
$founderBrief = (array) ($dashboard['founder_brief'] ?? []);
$organizationHealth = (array) ($dashboard['organization_health'] ?? []);
$organizationProfile = (array) ($dashboard['organization_profile'] ?? []);
$dataQuality = (array) ($dashboard['data_quality'] ?? []);
$structuralReadiness = (array) ($dashboard['structural_readiness'] ?? []);
$organizationStage = (array) ($dashboard['organization_stage'] ?? []);
$functionCoverage = (array) ($dashboard['function_coverage'] ?? []);
$functionPerformance = (array) ($dashboard['function_performance'] ?? []);
$userFunctionProfiles = (array) ($dashboard['user_function_profiles'] ?? []);
$founderLoad = (array) ($dashboard['founder_load'] ?? []);
$departmentReadiness = (array) ($dashboard['department_readiness'] ?? []);
$nextFunctionToFormalize = (array) ($dashboard['next_function_to_formalize'] ?? []);
$contextualRecommendations = array_values((array) ($dashboard['contextual_recommendations'] ?? []));
$leadershipAttention = array_values((array) ($dashboard['leadership_attention'] ?? []));
$peopleRisks = array_values((array) ($dashboard['people_risks'] ?? []));
$departmentIntelligence = (array) ($dashboard['department_intelligence'] ?? []);
$evidenceConfidence = (array) ($dashboard['evidence_confidence'] ?? []);
$ownershipHealth = (array) ($organizationHealth['ownership'] ?? []);
$eligiblePeopleGraph = array_values(array_filter(
    (array) ($dashboard['employees'] ?? []),
    static fn(array $employee): bool => (string) ($employee['score_status'] ?? '') === 'eligible' && ($employee['score'] ?? null) !== null
));
$excludedPeopleGraphCount = max(0, count((array) ($dashboard['employees'] ?? [])) - count($eligiblePeopleGraph));
$maxOpenTasksGraph = max(1, ...array_map(static fn(array $employee): int => (int) ($employee['open_tasks'] ?? 0), (array) ($dashboard['employees'] ?? [])));
$operatingTrends = (array) ($dashboard['operating_trends'] ?? []);
$trendMetrics = array_values((array) ($operatingTrends['metrics'] ?? []));
$trendMetricsByKey = [];
foreach ($trendMetrics as $metric) {
    $trendMetricsByKey[(string) ($metric['key'] ?? '')] = (array) $metric;
}
$trendCurrent = (array) ($operatingTrends['current'] ?? []);
$trendSeries = array_values(array_filter((array) ($operatingTrends['series'] ?? []), static fn(array $point): bool => ($point['value'] ?? null) !== null));
$trendChartWidth = 640;
$trendChartHeight = 260;
$trendChartLeft = 44;
$trendChartRight = 594;
$trendChartTop = 26;
$trendChartBottom = 214;
$trendPointCount = max(1, count($trendSeries) - 1);
$trendChartPoints = [];
foreach ($trendSeries as $index => $point) {
    $value = max(0, min(100, (float) ($point['value'] ?? 0)));
    $x = $trendPointCount > 0 ? $trendChartLeft + (($trendChartRight - $trendChartLeft) * ($index / $trendPointCount)) : ($trendChartLeft + $trendChartRight) / 2;
    $y = $trendChartBottom - (($trendChartBottom - $trendChartTop) * ($value / 100));
    $trendChartPoints[] = ['x' => round($x, 1), 'y' => round($y, 1), 'value' => $value, 'label' => (string) ($point['label'] ?? $point['date'] ?? '')];
}
$trendPolylinePoints = array_map(static fn(array $point): string => $point['x'] . ',' . $point['y'], $trendChartPoints);
$lastTrendPoint = end($trendSeries);
reset($trendSeries);
$trendPointValue = ($lastTrendPoint['value'] ?? null) !== null ? (int) $lastTrendPoint['value'] : null;
$trendLimitedPoints = max(0, 7 - count($trendSeries));

$metricValue = static function (array $metric): string {
    if (($metric['current'] ?? null) === null) {
        return 'Not enough evidence';
    }
    $precision = (int) ($metric['precision'] ?? 0);
    $value = number_format((float) ($metric['current'] ?? 0), $precision);
    $unit = (string) ($metric['unit'] ?? '');
    return $unit !== '' ? $value . ' ' . $unit : $value;
};
$formatHoursFromMinutes = static function (float $minutes): string {
    $hours = max(0.0, $minutes / 60);
    $precision = $hours > 0 && $hours < 10 ? 1 : 1;
    $formatted = number_format($hours, $precision);
    if (str_ends_with($formatted, '.0')) {
        $formatted = substr($formatted, 0, -2);
    }
    return $formatted . ' h';
};
$signalValue = static function ($value, string $suffix = '%'): string {
    return $value !== null ? number_format((float) $value, 0) . $suffix : 'Not enough evidence';
};
$ringSignals = [
    [
        'label' => 'Activity footprint',
        'value' => isset($trendMetricsByKey['system_active_minutes']) ? $metricValue($trendMetricsByKey['system_active_minutes']) : $formatHoursFromMinutes((float) ($dashboard['summary']['system_active_minutes'] ?? 0)),
        'score' => (float) ($trendMetricsByKey['system_active_minutes']['visual_score'] ?? 0),
        'status' => (string) ($trendMetricsByKey['system_active_minutes']['status'] ?? 'stable'),
        'tone' => (string) ($trendMetricsByKey['system_active_minutes']['tone'] ?? 'neutral'),
    ],
    [
        'label' => 'Workload pressure',
        'value' => $signalValue($trendCurrent['workload_pressure'] ?? null),
        'score' => (float) ($trendCurrent['workload_pressure'] ?? 0),
        'status' => (string) ($trendMetricsByKey['workload_pressure']['status'] ?? 'stable'),
        'tone' => (string) ($trendMetricsByKey['workload_pressure']['tone'] ?? 'neutral'),
    ],
    [
        'label' => 'Response risk',
        'value' => $signalValue($trendCurrent['response_risk'] ?? null),
        'score' => (float) ($trendCurrent['response_risk'] ?? 0),
        'status' => ($trendCurrent['response_risk'] ?? null) === null ? 'insufficient evidence' : ((float) $trendCurrent['response_risk'] >= 55 ? 'watch' : 'stable'),
        'tone' => ($trendCurrent['response_risk'] ?? null) === null ? 'neutral' : ((float) $trendCurrent['response_risk'] >= 55 ? 'warning' : 'neutral'),
    ],
    [
        'label' => 'Coverage health',
        'value' => $signalValue($trendCurrent['coverage_health'] ?? null),
        'score' => (float) ($trendCurrent['coverage_health'] ?? 0),
        'status' => (float) ($trendCurrent['coverage_health'] ?? 0) < 55 ? 'watch' : 'stable',
        'tone' => (float) ($trendCurrent['coverage_health'] ?? 0) < 55 ? 'warning' : 'positive',
    ],
    [
        'label' => 'Coaching signals',
        'value' => $signalValue($trendCurrent['coaching_signal'] ?? null),
        'score' => (float) ($trendCurrent['coaching_signal'] ?? 0),
        'status' => ($trendCurrent['coaching_signal'] ?? null) === null ? 'insufficient evidence' : ((float) $trendCurrent['coaching_signal'] >= 45 ? 'needs review' : ((float) $trendCurrent['coaching_signal'] > 0 ? 'watch' : 'stable')),
        'tone' => ($trendCurrent['coaching_signal'] ?? null) === null ? 'neutral' : ((float) $trendCurrent['coaching_signal'] >= 45 ? 'danger' : ((float) $trendCurrent['coaching_signal'] > 0 ? 'warning' : 'positive')),
    ],
];

$scopeReadoutParts = [
    match ((string) $filters['timeframe']) {
        'today' => 'today',
        'week' => '7 days',
        'quarter' => '90 days',
        'year' => 'year to date',
        default => '30 days',
    },
    $filters['department'] !== '' ? (string) $filters['department'] : 'all teams',
    $filters['role'] !== '' ? strtolower($roleLabels[$filters['role']] ?? (string) $filters['role']) : 'all roles',
    $selectedUserLabel !== '' ? $selectedUserLabel : 'all staff',
];
$scopeInstrumentLine = implode(' / ', $scopeReadoutParts);

$conversionFunnel = [];
$conversionJourney = [];
try {
    $stageWindow = (array) ($operatingTrends['window'] ?? []);
    $stageWindowStart = (string) ($stageWindow['start'] ?? date('Y-m-d 00:00:00', strtotime('-30 days')));
    $stageWindowEnd = (string) ($stageWindow['end'] ?? date('Y-m-d 23:59:59'));
    $distribution = (new ContactStageHistoryService())->distribution(
        $workspaceId,
        $stageWindowStart,
        $stageWindowEnd,
        !empty($filters['user_id']) ? (int) $filters['user_id'] : null,
        (string) ($filters['role'] ?? ''),
        (string) ($filters['department'] ?? '')
    );
    $conversionFunnel = (array) ($distribution['stages'] ?? []);
    $conversionJourney = (new ContactStageHistoryService())->journey(
        $workspaceId,
        $stageWindowStart,
        $stageWindowEnd,
        !empty($filters['user_id']) ? (int) $filters['user_id'] : null,
        (string) ($filters['role'] ?? ''),
        (string) ($filters['department'] ?? '')
    );
} catch (\Throwable $e) {
    $conversionFunnel = [];
}
$funnelStageLabels = [
    'new' => 'New',
    'contacted' => 'Contacted',
    'qualified' => 'Qualified',
    'proposal' => 'Proposal',
    'negotiation' => 'Negotiation',
    'won' => 'Won',
    'lost' => 'Lost',
];
$funnelMaxCount = 1;
$funnelTotalCount = 0;
foreach ($conversionFunnel as $stageData) {
    $stageCount = (int) ($stageData['count'] ?? 0);
    $funnelTotalCount += max(0, $stageCount);
    $funnelMaxCount = max($funnelMaxCount, $stageCount);
}
$funnelHasData = $funnelTotalCount > 0;
$funnelRows = [];
$funnelBottlenecks = 0;
foreach ($funnelStageLabels as $stageKey => $stageLabel) {
    $stageData = (array) ($conversionFunnel[$stageKey] ?? []);
    $count = (int) ($stageData['count'] ?? 0);
    $isBottleneck = false;
    $funnelRows[] = [
        'key' => $stageKey,
        'label' => $stageLabel,
        'count' => $count,
        'width' => $count > 0 ? max(2, min(100, ($count / $funnelMaxCount) * 100)) : 0,
        'share' => round((float) ($stageData['share'] ?? 0), 1),
        'conversion_rate' => null,
        'drop_off_rate' => null,
        'avg_dwell_days' => null,
        'basis' => 'current_stage_distribution',
        'is_bottleneck' => $isBottleneck,
    ];
}

$confidenceClass = static function (string $confidence): string {
    $confidence = strtolower(trim($confidence));
    return in_array($confidence, ['low', 'moderate', 'high'], true) ? $confidence : 'moderate';
};

$severityClass = static function (string $severity): string {
    $severity = strtolower(trim($severity));
    return in_array($severity, ['low', 'medium', 'high'], true) ? $severity : 'medium';
};

$functionRiskRank = static function (array $function): int {
    $relevance = (string) ($function['relevance_status'] ?? 'active');
    $state = (string) ($function['state'] ?? 'not_visible');
    $risk = (string) ($function['risk_level'] ?? 'medium');
    $confidence = (string) ($function['confidence'] ?? 'low');
    $ownerCount = (int) ($function['owner_count'] ?? 0);
    $temporaryOwnerCount = (int) ($function['temporary_owner_count'] ?? 0);

    if ($relevance === 'active' && $ownerCount + $temporaryOwnerCount === 0) {
        return 0;
    }
    if (in_array($state, ['founder_owned', 'single_owner_dependency'], true)) {
        return 1;
    }
    if ($risk === 'high') {
        return 2;
    }
    if ($confidence === 'low') {
        return 3;
    }
    if (in_array($relevance, ['deferred', 'outsourced'], true)) {
        return 4;
    }
    return 5;
};

$functionCoverageWatchlist = array_values($functionCoverage);
usort($functionCoverageWatchlist, static function (array $left, array $right) use ($functionRiskRank): int {
    $rank = $functionRiskRank($left) <=> $functionRiskRank($right);
    if ($rank !== 0) {
        return $rank;
    }
    $riskOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
    return ($riskOrder[(string) ($left['risk_level'] ?? 'medium')] ?? 1) <=> ($riskOrder[(string) ($right['risk_level'] ?? 'medium')] ?? 1);
});
$functionCoverageWatchlist = array_slice($functionCoverageWatchlist, 0, 6);
$activeFunctionCount = 0;
$unownedActiveFunctionCount = 0;
foreach ($functionCoverage as $function) {
    $relevance = (string) ($function['relevance_status'] ?? 'active');
    if ($relevance === 'active') {
        $activeFunctionCount++;
        if (((int) ($function['owner_count'] ?? 0) + (int) ($function['temporary_owner_count'] ?? 0)) === 0) {
            $unownedActiveFunctionCount++;
        }
    }
}

$executiveScopeNotes = [
    $timeframeLabels[$filters['timeframe']] ?? 'Last 30 days',
    ($filters['department'] !== '' ? (string) $filters['department'] : 'All departments'),
    ($filters['role'] !== '' ? ($roleLabels[$filters['role']] ?? ucfirst((string) $filters['role'])) : 'All roles'),
    (string) ($evidenceConfidence['label'] ?? 'Low confidence') . ' evidence',
];

$executivePriorities = [];
foreach (array_slice($leadershipAttention, 0, 6) as $item) {
    $executivePriorities[] = [
        'source' => 'Leadership',
        'title' => (string) ($item['title'] ?? 'Leadership signal'),
        'why' => (string) ($item['why'] ?? ''),
        'action' => (string) ($item['action'] ?? 'Validate this signal with the manager.'),
        'severity' => $severityClass((string) ($item['severity'] ?? 'medium')),
        'confidence' => $confidenceClass((string) ($item['confidence'] ?? 'moderate')),
        'timeframe' => (string) ($item['timeframe'] ?? 'Next review'),
        'evidence' => (string) ($item['evidence'] ?? ''),
    ];
}
if (count($executivePriorities) < 4) {
    foreach (array_slice($peopleRisks, 0, 4 - count($executivePriorities)) as $risk) {
        $executivePriorities[] = [
            'source' => 'People & HR',
            'title' => (string) ($risk['title'] ?? 'People support signal'),
            'why' => (string) ($risk['implication'] ?? ''),
            'action' => 'Schedule a supportive check-in and agree one measurable next step.',
            'severity' => $severityClass((string) ($risk['severity'] ?? 'medium')),
            'confidence' => $confidenceClass((string) ($risk['confidence'] ?? 'moderate')),
            'timeframe' => 'This week',
            'evidence' => (string) ($risk['evidence'] ?? ''),
        ];
    }
}
if ($executivePriorities === [] && $contextualRecommendations !== []) {
    foreach (array_slice($contextualRecommendations, 0, 3) as $recommendation) {
        $executivePriorities[] = [
            'source' => 'Direction',
            'title' => 'Recommended move',
            'why' => (string) $recommendation,
            'action' => 'Review with leadership and convert into one tracked action.',
            'severity' => 'medium',
            'confidence' => $confidenceClass((string) ($evidenceConfidence['level'] ?? 'moderate')),
            'timeframe' => 'Next review',
            'evidence' => (string) ($evidenceConfidence['summary'] ?? ''),
        ];
    }
}

$briefPriorities = array_slice($executivePriorities, 0, 3);
$primaryDirection = (string) ($founderBrief['recommended_action'] ?? ($executivePriorities[0]['action'] ?? 'Review the operating signals with leadership.'));

$organizationIntelligenceCompactText = static function ($value, int $maxLength = 170): string {
    $text = preg_replace('/\s+/', ' ', strip_tags((string) $value)) ?? '';
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (strlen($text) <= $maxLength) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $maxLength - 1))) . '...';
};

$organizationIntelligenceSignalText = static function ($item) use ($organizationIntelligenceCompactText): string {
    if (is_array($item)) {
        $title = $organizationIntelligenceCompactText((string) ($item['title'] ?? ''));
        $detail = $organizationIntelligenceCompactText((string) ($item['impact'] ?? $item['evidence'] ?? $item['summary'] ?? ''), 140);
        if ($title !== '' && $detail !== '') {
            return $title . ' - ' . $detail;
        }

        return $title !== '' ? $title : $detail;
    }

    return $organizationIntelligenceCompactText((string) $item);
};

$organizationIntelligenceAddSignal = static function (array &$target, $signal, int $limit = 3) use ($organizationIntelligenceSignalText): void {
    if (count($target) >= $limit) {
        return;
    }
    $text = $organizationIntelligenceSignalText($signal);
    if ($text === '' || in_array($text, $target, true)) {
        return;
    }
    $target[] = $text;
};

$organizationIntelligenceSuccessSignals = [];
$organizationIntelligenceWatchSignals = [];
$organizationHealthScore = (int) ($organizationHealth['score'] ?? 0);
$organizationHealthLabel = $organizationIntelligenceCompactText((string) ($organizationHealth['label'] ?? ''));
if ($organizationHealthLabel !== '' || $organizationHealthScore > 0) {
    $healthSignal = 'Organization health: ' . ($organizationHealthLabel !== '' ? $organizationHealthLabel : 'Needs review') . ' (' . $organizationHealthScore . '/100)';
    if ($organizationHealthScore >= 62) {
        $organizationIntelligenceSuccessSignals[] = $healthSignal;
    } else {
        $organizationIntelligenceWatchSignals[] = $healthSignal;
    }
}
if ((int) ($dashboard['summary']['high_performers'] ?? 0) > 0) {
    $organizationIntelligenceSuccessSignals[] = (int) $dashboard['summary']['high_performers'] . ' high performer signal(s) in the selected scope.';
}
if ((int) ($dashboard['summary']['at_risk_count'] ?? 0) > 0) {
    $organizationIntelligenceWatchSignals[] = (int) $dashboard['summary']['at_risk_count'] . ' staff support signal(s) need manager attention.';
}
if ((int) ($dashboard['summary']['overloaded_count'] ?? 0) > 0) {
    $organizationIntelligenceWatchSignals[] = (int) $dashboard['summary']['overloaded_count'] . ' capacity pressure signal(s) are visible.';
}
foreach (array_slice((array) ($dashboard['swot']['org']['strengths'] ?? []), 0, 3) as $signal) {
    $organizationIntelligenceAddSignal($organizationIntelligenceSuccessSignals, $signal);
}
foreach (array_merge(
    array_slice((array) ($dashboard['swot']['org']['weaknesses'] ?? []), 0, 2),
    array_slice((array) ($dashboard['swot']['org']['threats'] ?? []), 0, 2)
) as $signal) {
    $organizationIntelligenceAddSignal($organizationIntelligenceWatchSignals, $signal);
}
foreach (array_slice($executivePriorities, 0, 3) as $priority) {
    $organizationIntelligenceAddSignal($organizationIntelligenceWatchSignals, [
        'title' => (string) ($priority['title'] ?? ''),
        'impact' => (string) ($priority['why'] ?: $priority['action'] ?? ''),
    ]);
}

$organizationIntelligenceTrendMetrics = [];
foreach (array_slice($trendMetrics, 0, 6) as $metric) {
    $organizationIntelligenceTrendMetrics[] = [
        'label' => $organizationIntelligenceCompactText((string) ($metric['label'] ?? 'Metric'), 60),
        'value' => $metricValue((array) $metric),
        'status' => $organizationIntelligenceCompactText((string) ($metric['status'] ?? 'stable'), 40),
        'tone' => $organizationIntelligenceCompactText((string) ($metric['tone'] ?? 'neutral'), 40),
        'basis' => $organizationIntelligenceCompactText((string) ($metric['basis'] ?? 'derived'), 40),
    ];
}

$organizationIntelligencePriorities = [];
foreach (array_slice($executivePriorities, 0, 3) as $priority) {
    $organizationIntelligencePriorities[] = [
        'title' => $organizationIntelligenceCompactText((string) ($priority['title'] ?? 'Leadership signal'), 90),
        'action' => $organizationIntelligenceCompactText((string) ($priority['action'] ?? 'Validate this signal with leadership.'), 150),
        'severity' => $severityClass((string) ($priority['severity'] ?? 'medium')),
        'confidence' => $confidenceClass((string) ($priority['confidence'] ?? 'moderate')),
        'evidence' => $organizationIntelligenceCompactText((string) ($priority['evidence'] ?? ''), 150),
    ];
}

$organizationIntelligenceContext = [
    'page_key' => 'organization_intelligence',
    'room' => $activeTab === 'settings' ? 'methodology' : $activeTab,
    'sub_room' => $activeTab === 'priorities' ? $activePriorityView : '',
    'timeframe' => (string) $filters['timeframe'],
    'role' => (string) $filters['role'],
    'department' => (string) $filters['department'],
    'user_id' => $filters['user_id'],
];

$pageTitle = 'Organization Intelligence - ' . brandProductName();
$bodyClass = 'organization-intelligence-dark-shell';
$hrAnalyticsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_HR_ANALYTICS);
ob_start();
?>
<link rel="stylesheet" href="assets/css/hr-analytics.css?v=<?php echo (int) @filemtime(__DIR__ . '/assets/css/hr-analytics.css'); ?>">
<?php echo PageGuideVideoUi::assets(); ?>
<script>
window.organizationIntelligenceChatContext = <?php echo json_encode($organizationIntelligenceContext, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
</script>

<div class="hr-analytics-page">
    <div class="hr-shell">
        <?php if ($success): ?><div class="hr-banner hr-banner-ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="hr-banner hr-banner-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <section class="hr-executive-header" aria-label="Organization Intelligence executive office">
            <div class="hr-executive-identity">
                <span class="hr-executive-mark"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                <div>
                    <h1 class="hr-title">Organization Intelligence</h1>
                    <p class="hr-copy">Executive office for leadership clarity and organizational health.</p>
                </div>
            </div>
            <div class="hr-instrument-panel">
                <div class="hr-scope-readout">
                    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                    <strong><?php echo htmlspecialchars($scopeInstrumentLine); ?></strong>
                </div>
                <div class="hr-confidence-readout">
                    <span>Confidence</span>
                    <strong><?php echo htmlspecialchars((string) ($evidenceConfidence['label'] ?? 'Low confidence')); ?></strong>
                </div>
                <div class="hr-language-readout">
                    <span>Language</span>
                    <strong><?php echo htmlspecialchars((string) ($languageLevelContext['label'] ?? 'Default')); ?></strong>
                </div>
                <?php if ($hrAnalyticsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_HR_ANALYTICS, 'Organization Intelligence page guide', 'hr-guide-control'); ?>
                <?php endif; ?>
                <details class="hr-refine-menu">
                    <summary>
                        <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                        <span>Refine</span>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <form method="get" class="hr-scope-drawer" aria-label="Refine Organization Intelligence scope">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                        <?php if ($activeTab === 'priorities'): ?>
                            <input type="hidden" name="priority_view" value="<?php echo htmlspecialchars($activePriorityView); ?>">
                        <?php endif; ?>
                        <label>Timeframe<select name="timeframe"><?php foreach (['today'=>'Today','week'=>'Last 7 days','month'=>'Last 30 days','quarter'=>'Last 90 days','year'=>'Year to date'] as $value=>$label): ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($filters['timeframe'] === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></label>
                        <label>Role<select name="role"><option value="">All roles</option><?php foreach (['marketing'=>'Marketing','sales'=>'Sales','general'=>'General / Ops'] as $value=>$label): ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($filters['role'] === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></label>
                        <label>Team<select name="department"><option value="">All explicit teams</option><?php foreach ($departmentOptions as $department): ?><option value="<?php echo htmlspecialchars($department); ?>" <?php echo ($filters['department'] === $department) ? 'selected' : ''; ?>><?php echo htmlspecialchars($department); ?></option><?php endforeach; ?></select></label>
                        <label>Staff<select name="user_id"><option value="">All staff</option><?php foreach ($users as $userRow): ?><option value="<?php echo (int) $userRow['id']; ?>" <?php echo ((string) ($filters['user_id'] ?? '') === (string) $userRow['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $userRow['label']); ?></option><?php endforeach; ?></select></label>
                        <div class="hr-filter-actions">
                            <button type="submit" class="hr-btn hr-btn-primary"><i class="fa-solid fa-check"></i>Apply</button>
                            <a href="<?php echo htmlspecialchars($buildResetUrl($activeTab, $activeTab === 'priorities' ? $activePriorityView : null)); ?>" class="hr-btn hr-btn-secondary">Reset</a>
                        </div>
                    </form>
                </details>
            </div>
        </section>

        <nav class="hr-tabs hr-executive-rooms" aria-label="Organization Intelligence rooms">
            <?php foreach ($tabs as $tabKey => $tab): ?>
                <?php $tabPriorityTarget = ($tabKey === 'priorities' && $activeTab === 'priorities') ? $activePriorityView : null; ?>
                <a href="<?php echo htmlspecialchars($buildTabUrl($tabKey, $tabPriorityTarget)); ?>" class="hr-tab <?php echo $activeTab === $tabKey ? 'is-active' : ''; ?>" data-mobile-label="<?php echo htmlspecialchars(['brief' => 'Brief', 'people' => 'People', 'analytics' => 'Analytics', 'priorities' => 'Priorities', 'settings' => 'Methodology'][$tabKey] ?? (string) $tab['label']); ?>">
                    <i class="<?php echo htmlspecialchars($tab['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($tab['label']); ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (isset($tabs['settings'])): ?>
                <details class="hr-room-overflow">
                    <summary aria-label="More Organization Intelligence rooms"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i><span>More</span></summary>
                    <a href="<?php echo htmlspecialchars($buildTabUrl('settings')); ?>"><i class="fa-solid fa-sliders" aria-hidden="true"></i>Methodology</a>
                </details>
            <?php endif; ?>
        </nav>

        <?php if ($activeTab === 'brief'): ?>
            <section class="hr-card hr-operating-model-card" aria-label="Organization operating model">
                <div>
                    <span class="hr-mini">Operating model</span>
                    <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($organizationProfile['effective_model'] ?? 'solo_founder')))); ?></strong>
                    <small><?php echo !empty($organizationProfile['is_confirmed']) ? 'Owner confirmed' : 'Inferred at ' . htmlspecialchars((string) ($organizationProfile['inference_confidence'] ?? 'low')) . ' confidence'; ?> / calculation v2</small>
                </div>
                <div>
                    <span class="hr-mini">Structural readiness</span>
                    <strong><?php echo ($structuralReadiness['score'] ?? null) !== null ? number_format((float) $structuralReadiness['score'], 0) . '/100' : 'Not measured'; ?></strong>
                    <small><?php echo htmlspecialchars((string) ($structuralReadiness['label'] ?? 'Baseline forming')); ?></small>
                </div>
                <div>
                    <span class="hr-mini">Evidence coverage</span>
                    <strong><?php echo number_format((float) ($dataQuality['eligible_ratio'] ?? 0) * 100, 0); ?>%</strong>
                    <small><?php echo (int) ($dataQuality['eligible_people_count'] ?? 0); ?> of <?php echo (int) ($dataQuality['total_people_count'] ?? 0); ?> people eligible</small>
                </div>
                <?php if (!empty($organizationProfile['review_recommended'])): ?><a class="hr-btn hr-btn-secondary" href="<?php echo htmlspecialchars($buildTabUrl('settings')); ?>">Review model</a><?php endif; ?>
            </section>
            <section class="hr-executive-brief-grid">
                <article class="hr-card hr-trend-panel" data-oi-trend-panel>
                    <div class="hr-panel-head">
                        <div>
                            <h2>Operating Health</h2>
                            <span><?php echo htmlspecialchars((string) ($operatingTrends['headline'] ?? 'Baseline captured')); ?></span>
                        </div>
                        <div class="hr-range-switch" aria-label="Trend range">
                            <?php foreach (['week' => '7D', 'month' => '30D', 'quarter' => '90D', 'year' => 'YTD'] as $rangeKey => $rangeLabel): ?>
                                <?php if (!empty($trendRangeAvailability[$rangeKey])): ?>
                                    <a href="<?php echo htmlspecialchars($buildTimeframeUrl($rangeKey)); ?>" class="<?php echo $filters['timeframe'] === $rangeKey ? 'is-active' : ''; ?>" aria-current="<?php echo $filters['timeframe'] === $rangeKey ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($rangeLabel); ?></a>
                                <?php else: ?>
                                    <span class="is-disabled" aria-disabled="true" title="No stored snapshots are available in this range"><?php echo htmlspecialchars($rangeLabel); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="hr-trend-stage">
                        <svg class="hr-trend-svg" viewBox="0 0 <?php echo $trendChartWidth; ?> <?php echo $trendChartHeight; ?>" role="img" aria-labelledby="oi-trend-title oi-trend-desc">
                            <title id="oi-trend-title">Organization health daily snapshots</title>
                            <desc id="oi-trend-desc"><?php echo htmlspecialchars(count($trendSeries) . ' compatible stored snapshot point(s). ' . (string) ($operatingTrends['headline'] ?? 'Baseline captured')); ?></desc>
                            <line x1="<?php echo $trendChartLeft; ?>" y1="26" x2="<?php echo $trendChartRight; ?>" y2="26" />
                            <line x1="<?php echo $trendChartLeft; ?>" y1="88" x2="<?php echo $trendChartRight; ?>" y2="88" />
                            <line x1="<?php echo $trendChartLeft; ?>" y1="151" x2="<?php echo $trendChartRight; ?>" y2="151" />
                            <line x1="<?php echo $trendChartLeft; ?>" y1="<?php echo $trendChartBottom; ?>" x2="<?php echo $trendChartRight; ?>" y2="<?php echo $trendChartBottom; ?>" />
                            <text x="8" y="31">100</text><text x="14" y="93">67</text><text x="14" y="156">33</text><text x="25" y="219">0</text>
                            <?php if (count($trendPolylinePoints) >= 3): ?><polyline points="<?php echo htmlspecialchars(implode(' ', $trendPolylinePoints)); ?>" class="hr-trend-line" /><?php endif; ?>
                            <?php foreach ($trendChartPoints as $chartPoint): ?>
                                <circle cx="<?php echo (float) $chartPoint['x']; ?>" cy="<?php echo (float) $chartPoint['y']; ?>" r="5"><title><?php echo htmlspecialchars((string) $chartPoint['label'] . ': ' . number_format((float) $chartPoint['value'], 0) . '/100'); ?></title></circle>
                            <?php endforeach; ?>
                        </svg>
                        <?php if ($trendSeries === []): ?><p class="hr-empty hr-trend-empty"><?php echo (int) ($operatingTrends['point_count'] ?? 0) > 0 ? 'Baseline captured; execution health remains unscored until evidence qualifies.' : 'No stored daily snapshot yet. Run the snapshot capture to establish an honest baseline.'; ?></p><?php endif; ?>
                        <div class="hr-trend-score">
                            <strong><?php echo ($organizationHealth['score'] ?? null) !== null ? (int) $organizationHealth['score'] : '—'; ?></strong>
                            <span><?php echo htmlspecialchars((string) ($organizationHealth['label'] ?? 'Needs review')); ?></span>
                            <small><?php echo (int) ($operatingTrends['point_count'] ?? 0); ?> stored point(s) / <?php echo count($trendSeries) === 2 ? 'two-point comparison' : htmlspecialchars((string) ($operatingTrends['series_status'] ?? 'baseline')); ?></small>
                        </div>
                    </div>
                    <?php if ($trendSeries !== []): ?><div class="hr-visually-hidden"><table><caption>Organization health snapshot values</caption><thead><tr><th>Date</th><th>Score</th></tr></thead><tbody><?php foreach ($trendSeries as $point): ?><tr><td><?php echo htmlspecialchars((string) ($point['date'] ?? $point['label'] ?? '')); ?></td><td><?php echo number_format((float) $point['value'], 0); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                    <div class="hr-trend-metric-strip">
                        <?php foreach (array_slice($trendMetrics, 0, 4) as $metric): ?>
                            <span class="hr-trend-chip hr-trend-<?php echo htmlspecialchars((string) ($metric['tone'] ?? 'neutral')); ?>">
                                <strong><?php echo htmlspecialchars((string) ($metric['label'] ?? 'Signal')); ?></strong>
                                <?php echo htmlspecialchars($metricValue((array) $metric)); ?> / <?php echo htmlspecialchars((string) ($metric['status'] ?? 'stable')); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php $bulletComponents = array_slice(array_filter((array) ($organizationHealth['components'] ?? []), 'is_numeric'), 0, 5, true); ?>
                    <?php if ($bulletComponents !== []): ?>
                        <div class="hr-component-bullets">
                            <h3>Health components</h3>
                            <svg viewBox="0 0 620 <?php echo 34 + (count($bulletComponents) * 34); ?>" role="img" aria-labelledby="oi-components-title oi-components-desc">
                                <title id="oi-components-title">Organization health component bullet chart</title>
                                <desc id="oi-components-desc">Measured components on a zero to one hundred scale. Calculation basis <?php echo htmlspecialchars((string) ($dashboard['calculation_version'] ?? 'oi-score-v2')); ?>.</desc>
                                <line x1="190" y1="20" x2="590" y2="20" class="hr-bullet-axis" />
                                <text x="184" y="14">0</text><text x="380" y="14">50</text><text x="576" y="14">100</text>
                                <?php $bulletIndex = 0; foreach ($bulletComponents as $componentKey => $componentValue): $bulletY = 34 + ($bulletIndex * 34); $bulletWidth = max(0, min(400, ((float) $componentValue / 100) * 400)); ?>
                                    <text x="0" y="<?php echo $bulletY + 14; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $componentKey))); ?></text>
                                    <rect x="190" y="<?php echo $bulletY; ?>" width="400" height="18" rx="4" class="hr-bullet-track" />
                                    <rect x="190" y="<?php echo $bulletY; ?>" width="<?php echo $bulletWidth; ?>" height="18" rx="4" class="hr-bullet-value" />
                                    <text x="<?php echo min(594, 198 + $bulletWidth); ?>" y="<?php echo $bulletY + 14; ?>" class="hr-bullet-label"><?php echo number_format((float) $componentValue, 0); ?></text>
                                <?php $bulletIndex++; endforeach; ?>
                            </svg>
                            <div class="hr-visually-hidden"><table><caption>Organization health components</caption><thead><tr><th>Component</th><th>Value</th><th>Unit</th><th>Basis</th></tr></thead><tbody><?php foreach ($bulletComponents as $componentKey => $componentValue): ?><tr><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $componentKey))); ?></td><td><?php echo number_format((float) $componentValue, 1); ?></td><td>out of 100</td><td><?php echo htmlspecialchars((string) ($dashboard['calculation_version'] ?? 'oi-score-v2')); ?></td></tr><?php endforeach; ?></tbody></table></div>
                        </div>
                    <?php endif; ?>
                </article>

                <aside class="hr-card hr-executive-memo-card">
                    <div class="hr-panel-head">
                        <div>
                            <h2>Executive Memo</h2>
                            <span><?php echo htmlspecialchars((string) ($operatingTrends['headline'] ?? 'Trend posture is stable')); ?></span>
                        </div>
                    </div>
                    <div class="hr-memo-list">
                        <div class="hr-memo-row">
                            <i class="fa-solid fa-bullseye" aria-hidden="true"></i>
                            <div><strong>Focus</strong><span><?php echo htmlspecialchars((string) ($founderBrief['biggest_strength'] ?? 'Protect the strongest operating habit.')); ?></span></div>
                        </div>
                        <div class="hr-memo-row">
                            <i class="fa-solid fa-user-group" aria-hidden="true"></i>
                            <div><strong>Support</strong><span><?php echo htmlspecialchars((string) ($founderBrief['dependency_signal'] ?? 'Watch workload and coaching signals.')); ?></span></div>
                        </div>
                        <div class="hr-memo-row">
                            <i class="fa-solid fa-compass" aria-hidden="true"></i>
                            <div><strong>Direction</strong><span><?php echo htmlspecialchars($primaryDirection); ?></span></div>
                        </div>
                    </div>
                </aside>
            </section>

            <section class="hr-executive-lower-grid">
                <article class="hr-card hr-priority-table-card">
                    <div class="hr-panel-head">
                        <div>
                            <h2>Top Priorities</h2>
                            <span>Leadership attention</span>
                        </div>
                        <a href="<?php echo htmlspecialchars($buildTabUrl('priorities')); ?>">View all</a>
                    </div>
                    <?php if ($briefPriorities !== []): ?>
                        <div class="hr-priority-table">
                            <div class="hr-priority-table-head">
                                <span>Priority</span><span>Why</span><span>Urgency</span><span>Confidence</span>
                            </div>
                            <?php foreach ($briefPriorities as $index => $priority): ?>
                                <article class="hr-priority-table-row hr-severity-<?php echo htmlspecialchars((string) $priority['severity']); ?>">
                                    <span><b><?php echo $index + 1; ?></b><?php echo htmlspecialchars((string) $priority['title']); ?></span>
                                    <span><?php echo htmlspecialchars((string) ($priority['why'] ?: $priority['action'])); ?></span>
                                    <span><?php echo htmlspecialchars(ucfirst((string) $priority['severity'])); ?></span>
                                    <span><?php echo htmlspecialchars(ucfirst((string) $priority['confidence'])); ?></span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No leadership priority is visible in this scope.</p>
                    <?php endif; ?>
                </article>

                <article class="hr-card hr-signal-ring-card">
                    <div class="hr-panel-head">
                        <div>
                            <h2>Key Signals</h2>
                            <span>Graph-led readout</span>
                        </div>
                        <a href="<?php echo htmlspecialchars($buildTabUrl('analytics')); ?>">All metrics</a>
                    </div>
                    <div class="hr-signal-rings">
                        <?php foreach ($ringSignals as $signal): ?>
                            <div class="hr-signal-ring hr-signal-<?php echo htmlspecialchars((string) ($signal['tone'] ?? 'neutral')); ?>" style="--score: <?php echo max(0, min(100, (float) ($signal['score'] ?? 0))); ?>;">
                                <div class="hr-ring-meter"><strong><?php echo htmlspecialchars((string) ($signal['value'] ?? '0')); ?></strong></div>
                                <span><?php echo htmlspecialchars((string) ($signal['label'] ?? 'Signal')); ?></span>
                                <small><?php echo htmlspecialchars(ucfirst((string) ($signal['status'] ?? 'stable'))); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>

            <section class="hr-grid hr-grid-2">
                <article class="hr-card hr-funnel-card hr-funnel-card-brief" data-oi-funnel-panel>
                    <div class="hr-panel-head">
                        <div>
                            <h2>Pipeline Stage Distribution</h2>
                            <span>Current-stage cohort, not conversion history</span>
                        </div>
                        <a href="<?php echo htmlspecialchars($buildTabUrl('analytics')); ?>">Open analytics</a>
                    </div>
                    <div class="hr-funnel-rows">
                        <?php if (!$funnelHasData): ?>
                            <p class="hr-empty hr-funnel-empty">No contact stage data was captured for this scope.</p>
                        <?php else: ?>
                            <?php foreach (array_slice($funnelRows, 0, 4) as $row): ?>
                                <div class="hr-funnel-row <?php echo !empty($row['is_bottleneck']) ? 'is-bottleneck' : ''; ?>">
                                    <div><strong><?php echo htmlspecialchars((string) $row['label']); ?></strong><span><?php echo (int) $row['count']; ?> contacts</span></div>
                                    <div class="hr-funnel-track"><span style="width: <?php echo (float) $row['width']; ?>%;"></span></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="hr-card hr-function-watch-panel">
                    <div class="hr-panel-head">
                        <div>
                            <h2>Business Areas</h2>
                            <span><?php echo $activeFunctionCount; ?> active / <?php echo $unownedActiveFunctionCount; ?> unowned</span>
                        </div>
                        <a href="organization_intelligence_setup.php">Setup</a>
                    </div>
                    <?php if ($functionCoverageWatchlist !== []): ?>
                        <div class="hr-function-watch-list">
                            <?php foreach (array_slice($functionCoverageWatchlist, 0, 5) as $function): ?>
                                <div class="hr-function-watch-row">
                                    <span><?php echo htmlspecialchars((string) ($function['name'] ?? 'Function')); ?></span>
                                    <strong><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($function['state'] ?? 'not_visible')))); ?></strong>
                                    <small><?php echo htmlspecialchars(ucfirst((string) ($function['risk_level'] ?? 'medium'))); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No active business areas are configured.</p>
                    <?php endif; ?>
                </article>
            </section>

        <?php elseif ($activeTab === 'people'): ?>
            <section class="hr-card hr-room-intro">
                <div>
                    <h2>People & HR</h2>
                    <p>Supportive signals for workload pressure, coaching opportunities, responsibility coverage, and team structure.</p>
                </div>
                <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($evidenceConfidence['level'] ?? 'low'))); ?>"><?php echo htmlspecialchars((string) ($evidenceConfidence['label'] ?? 'Low confidence')); ?></span>
            </section>
            <section class="hr-card hr-people-signal-board">
                <div class="hr-panel-head">
                    <div>
                        <h2>People Signals</h2>
                        <span>Activity footprint, capacity pressure, and support signals</span>
                    </div>
                </div>
                <div class="hr-people-signal-grid">
                    <div class="hr-people-signal">
                        <span>System active time</span>
                        <strong><?php echo htmlspecialchars($formatHoursFromMinutes((float) ($dashboard['summary']['system_active_minutes'] ?? 0))); ?></strong>
                        <small><?php echo htmlspecialchars($formatHoursFromMinutes((float) ($dashboard['summary']['avg_system_active_minutes'] ?? 0))); ?> avg / person</small>
                    </div>
                    <div class="hr-people-signal">
                        <span>Capacity pressure</span>
                        <strong><?php echo htmlspecialchars($signalValue($trendCurrent['workload_pressure'] ?? null)); ?></strong>
                        <small><?php echo (int) ($dashboard['summary']['overloaded_count'] ?? 0); ?> visible overload signal(s)</small>
                    </div>
                    <div class="hr-people-signal">
                        <span>Coaching support</span>
                        <strong><?php echo (int) ($dashboard['summary']['needs_coaching_count'] ?? 0); ?></strong>
                        <small>needs-support signal(s)</small>
                    </div>
                    <div class="hr-people-signal">
                        <span>Coverage health</span>
                        <strong><?php echo number_format((float) ($trendCurrent['coverage_health'] ?? 0), 0); ?>%</strong>
                        <small><?php echo (int) ($ownershipHealth['explicitly_covered_core_functions'] ?? 0); ?> core area(s) explicitly covered</small>
                    </div>
                </div>
            </section>
            <section class="hr-grid hr-grid-2">
                <article class="hr-card">
                    <div class="hr-panel-head"><div><h2>Workload distribution</h2><span>Current open and overdue tasks / point in time</span></div></div>
                    <div class="hr-funnel-rows" role="list" aria-label="Current workload distribution">
                        <?php foreach ((array) ($dashboard['employees'] ?? []) as $employee): ?>
                            <div class="hr-funnel-row" role="listitem">
                                <div><strong><?php echo htmlspecialchars((string) ($employee['name'] ?? 'Person')); ?></strong><span><?php echo (int) ($employee['open_tasks'] ?? 0); ?> open / <?php echo (int) ($employee['overdue_open_tasks'] ?? 0); ?> overdue</span></div>
                                <div class="hr-funnel-track"><span style="width: <?php echo min(100, ((int) ($employee['open_tasks'] ?? 0) / $maxOpenTasksGraph) * 100); ?>%;"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <article class="hr-card">
                    <div class="hr-panel-head"><div><h2>Score versus confidence</h2><span><?php echo count($eligiblePeopleGraph); ?> eligible / <?php echo $excludedPeopleGraphCount; ?> excluded for insufficient evidence</span></div></div>
                    <?php if ($eligiblePeopleGraph !== []): ?>
                        <svg class="hr-scatter-svg" viewBox="0 0 420 250" role="img" aria-labelledby="oi-scatter-title oi-scatter-desc">
                            <title id="oi-scatter-title">Eligible people score versus evidence coverage</title>
                            <desc id="oi-scatter-desc">Only evidence-eligible people are plotted. Horizontal axis is evidence coverage and vertical axis is score.</desc>
                            <line x1="46" y1="18" x2="46" y2="215"></line><line x1="46" y1="215" x2="405" y2="215"></line>
                            <text x="8" y="24">100</text><text x="20" y="219">0</text><text x="45" y="238">0%</text><text x="365" y="238">100% evidence</text>
                            <?php foreach ($eligiblePeopleGraph as $employee): $x = 46 + (359 * min(1, (float) ($employee['evidence_coverage'] ?? 0))); $y = 215 - (197 * ((float) $employee['score'] / 100)); ?>
                                <circle cx="<?php echo round($x, 1); ?>" cy="<?php echo round($y, 1); ?>" r="6"><title><?php echo htmlspecialchars((string) ($employee['name'] ?? 'Person') . ': score ' . number_format((float) $employee['score'], 1) . ', evidence ' . number_format((float) ($employee['evidence_coverage'] ?? 0) * 100, 0) . '%'); ?></title></circle>
                            <?php endforeach; ?>
                        </svg>
                        <div class="hr-visually-hidden"><table><caption>Eligible people score and evidence coverage</caption><thead><tr><th>Person</th><th>Score</th><th>Evidence coverage</th></tr></thead><tbody><?php foreach ($eligiblePeopleGraph as $employee): ?><tr><td><?php echo htmlspecialchars((string) ($employee['name'] ?? 'Person')); ?></td><td><?php echo number_format((float) $employee['score'], 1); ?></td><td><?php echo number_format((float) ($employee['evidence_coverage'] ?? 0) * 100, 0); ?>%</td></tr><?php endforeach; ?></tbody></table></div>
                    <?php else: ?><p class="hr-empty">No people meet the evidence threshold for this chart.</p><?php endif; ?>
                </article>
            </section>
            <section class="hr-card hr-brief-panel">
                <div class="hr-section-header">
                    <div>
                        <h2>Responsibility Profiles</h2>
                        <span class="hr-mini">People reviewed against the business areas they carry</span>
                    </div>
                </div>
                <?php if ($userFunctionProfiles !== []): ?>
                    <div class="hr-function-profile-list">
                        <?php foreach ($userFunctionProfiles as $profile): ?>
                            <article class="hr-function-profile-card">
                                <div class="hr-row-between">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string) ($profile['name'] ?? 'User')); ?></strong>
                                        <p><?php echo htmlspecialchars((string) ($profile['primary_function'] ?? 'Unassigned')); ?> primary / <?php echo (int) ($profile['function_load_count'] ?? 0); ?> function(s)</p>
                                    </div>
                                    <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($profile['confidence'] ?? 'low'))); ?>"><?php echo htmlspecialchars(ucfirst((string) ($profile['confidence'] ?? 'low'))); ?></span>
                                </div>
                                <div class="hr-brief-chip-row">
                                    <span class="hr-brief-chip">Band: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($profile['score_band'] ?? 'steady')))); ?></span>
                                    <span class="hr-brief-chip">Score: <?php echo ($profile['score'] ?? null) !== null ? number_format((float) $profile['score'], 1) : 'Not enough evidence'; ?></span>
                                    <span class="hr-brief-chip">Source: <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($profile['ownership_source'] ?? 'none'))); ?></span>
                                </div>
                                <p class="hr-profile-reason"><?php echo htmlspecialchars((string) ($profile['risk_reason'] ?? 'Review current evidence before acting.')); ?></p>
                                <?php $sourceMetrics = (array) ($profile['source_metrics'] ?? []); ?>
                                <div class="hr-function-chip-row">
                                    <span>Completion <?php echo ($sourceMetrics['task_completion_rate'] ?? null) !== null ? number_format((float) $sourceMetrics['task_completion_rate'], 1) . '%' : 'not measured'; ?></span>
                                    <span><?php echo (int) ($sourceMetrics['open_tasks'] ?? 0); ?> open</span>
                                    <span><?php echo (int) ($sourceMetrics['overdue_open_tasks'] ?? 0); ?> overdue</span>
                                    <span><?php echo (int) ($sourceMetrics['activity_count'] ?? 0); ?> activities</span>
                                    <span><?php echo htmlspecialchars($formatHoursFromMinutes((float) ($sourceMetrics['system_active_minutes'] ?? 0))); ?> active</span>
                                </div>
                                <div class="hr-function-chip-row">
                                    <?php foreach (array_slice((array) ($profile['functions'] ?? []), 0, 6) as $functionProfile): ?>
                                        <span title="<?php echo htmlspecialchars((string) ($functionProfile['interpretation'] ?? '')); ?>">
                                            <?php echo htmlspecialchars((string) ($functionProfile['name'] ?? 'Function')); ?>
                                            <?php echo ($functionProfile['score'] ?? null) !== null ? ' ' . number_format((float) $functionProfile['score'], 0) : ' low evidence'; ?>
                                            / <?php echo !empty($functionProfile['is_inferred']) ? 'suggested role default' : 'saved ownership'; ?>
                                            · <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($functionProfile['assignment_type'] ?? 'contributor'))); ?>
                                            · <?php echo htmlspecialchars(str_replace('_', ' ', (string) ($functionProfile['measurement_reason'] ?? 'coverage_only'))); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="hr-action-line"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><?php echo htmlspecialchars((string) ($profile['manager_action'] ?? 'Choose one measurable manager follow-up.')); ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hr-empty">No responsibility profiles are available yet; owner coverage and user account responsibilities will shape this view as people are added.</p>
                <?php endif; ?>
            </section>
            <section class="hr-grid hr-grid-2">
                <div class="hr-card hr-people-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Strong Momentum</h2>
                            <span class="hr-mini">People showing repeatable strong signals</span>
                        </div>
                    </div>
                    <?php if (!empty($dashboard['best_performers'])): ?>
                        <div class="hr-people-card-list">
                            <?php foreach ($dashboard['best_performers'] as $employee): ?>
                                <article class="hr-people-card hr-people-card-<?php echo htmlspecialchars((string) ($employee['band'] ?? 'steady')); ?>">
                                    <div class="hr-people-card-head">
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) $employee['name']); ?></h3>
                                            <span class="hr-mini"><?php echo htmlspecialchars((string) $employee['role_label']); ?> / <?php echo htmlspecialchars((string) $employee['department']); ?></span>
                                            <span class="hr-mini"><?php echo htmlspecialchars((string) ($employee['primary_function'] ?? 'Unassigned')); ?> function</span>
                                        </div>
                                        <span class="hr-people-score"><?php echo number_format((float) $employee['score'], 1); ?></span>
                                    </div>
                                    <div class="hr-people-chip-row">
                                        <span><?php echo (int) $employee['tasks_completed']; ?> tasks</span>
                                        <span><?php echo (int) $employee['deal_wins']; ?> wins</span>
                                        <span><?php echo (int) $employee['activity_count']; ?> activities</span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No best performer data is available for this scope; broaden the timeframe or confirm completed tasks, outcomes, and activity are being captured.</p>
                    <?php endif; ?>
                </div>
                <div class="hr-card hr-people-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Needs Support</h2>
                            <span class="hr-mini">Coaching and workload signals to validate</span>
                        </div>
                    </div>
                    <?php if (!empty($dashboard['struggling_performers'])): ?>
                        <div class="hr-people-card-list">
                            <?php foreach ($dashboard['struggling_performers'] as $employee): ?>
                                <article class="hr-people-card hr-people-card-<?php echo htmlspecialchars((string) ($employee['band'] ?? 'needs_coaching')); ?>">
                                    <div class="hr-people-card-head">
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) $employee['name']); ?></h3>
                                            <span class="hr-mini"><?php echo htmlspecialchars((string) $employee['role_label']); ?> / <?php echo htmlspecialchars((string) $employee['department']); ?></span>
                                            <span class="hr-mini"><?php echo htmlspecialchars((string) ($employee['primary_function'] ?? 'Unassigned')); ?> function</span>
                                        </div>
                                        <span class="hr-people-score"><?php echo number_format((float) $employee['score'], 1); ?></span>
                                    </div>
                                    <div class="hr-people-chip-row">
                                        <span><?php echo (int) $employee['open_tasks']; ?> open</span>
                                        <span><?php echo (int) $employee['overdue_open_tasks']; ?> overdue</span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No at-risk staff are visible in this scope; keep the readout tied to the current filters and check tracking coverage before acting.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="hr-grid hr-grid-2">
                <div class="hr-card hr-people-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Coaching Signals</h2>
                            <span class="hr-mini">Needs support, not labels</span>
                        </div>
                    </div>
                    <?php if ($peopleRisks !== []): ?>
                        <div class="hr-risk-list">
                            <?php foreach (array_slice($peopleRisks, 0, 5) as $risk): ?>
                                <?php $severity = $severityClass((string) ($risk['severity'] ?? 'medium')); ?>
                                <article class="hr-risk-card hr-severity-<?php echo htmlspecialchars($severity); ?>">
                                    <div class="hr-row-between">
                                        <strong><?php echo htmlspecialchars((string) ($risk['title'] ?? 'Coaching signal')); ?></strong>
                                        <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($risk['confidence'] ?? 'moderate'))); ?>"><?php echo htmlspecialchars(ucfirst((string) ($risk['confidence'] ?? 'moderate'))); ?></span>
                                    </div>
                                    <p><?php echo htmlspecialchars((string) ($risk['implication'] ?? '')); ?></p>
                                    <small><?php echo htmlspecialchars((string) ($risk['evidence'] ?? '')); ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No coaching signals are visible in this scope; keep the view tied to actual activity and review again after more evidence is captured.</p>
                    <?php endif; ?>
                </div>
                <div class="hr-card hr-people-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Team Comparison</h2>
                            <span class="hr-mini">Optional department and team structure signals</span>
                        </div>
                    </div>
                    <?php if ($departmentIntelligence !== []): ?>
                        <div class="hr-department-intel-list">
                            <?php foreach (array_slice($departmentIntelligence, 0, 5) as $departmentIntel): ?>
                                <article class="hr-department-intel-card">
                                    <div class="hr-row-between">
                                        <strong><?php echo htmlspecialchars((string) ($departmentIntel['department'] ?? 'Department')); ?></strong>
                                        <span class="hr-people-score"><?php echo ($departmentIntel['avg_score'] ?? null) !== null ? number_format((float) $departmentIntel['avg_score'], 1) : '&mdash;'; ?></span>
                                    </div>
                                    <p><?php echo htmlspecialchars((string) ($departmentIntel['health_label'] ?? 'Needs review')); ?></p>
                                    <div class="hr-brief-chip-row">
                                        <span class="hr-brief-chip">Strong: <?php echo htmlspecialchars((string) ($departmentIntel['strongest_capability'] ?? 'N/A')); ?></span>
                                        <span class="hr-brief-chip">Watch: <?php echo htmlspecialchars((string) ($departmentIntel['biggest_weakness'] ?? 'N/A')); ?></span>
                                    </div>
                                    <small><?php echo htmlspecialchars((string) ($departmentIntel['manager_action'] ?? 'Review this department in the next operating review.')); ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">Departments are optional. Add team structure when HR-style reporting or team comparisons become useful.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="hr-card hr-people-panel">
                <div class="hr-section-header"><h2>Business Area Performance</h2><span class="hr-mini">Coverage, score, and measurement confidence</span></div>
                <?php if ($functionPerformance !== []): ?>
                    <div class="hr-people-metric-grid">
                        <?php foreach ($functionPerformance as $function): ?>
                            <article class="hr-people-metric-card">
                                <strong><?php echo htmlspecialchars((string) ($function['name'] ?? 'Function')); ?></strong>
                                <span><?php echo ($function['score'] ?? null) !== null ? number_format((float) $function['score'], 1) . ' avg score' : 'Low evidence'; ?></span>
                                <small><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($function['state'] ?? 'not visible')))); ?> / <?php echo htmlspecialchars((string) ($function['confidence'] ?? 'low')); ?> confidence</small>
                                <small><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($function['relevance_status'] ?? 'active')))); ?> / <?php echo (int) ($function['explicit_owner_count'] ?? $function['owner_count'] ?? 0); ?> explicit owner(s)<?php if ((int) ($function['inferred_owner_count'] ?? 0) > 0): ?> / <?php echo (int) $function['inferred_owner_count']; ?> inferred suggestion(s)<?php endif; ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hr-empty">No business area performance data is available yet; responsibility coverage and captured work activity will shape this view.</p>
                <?php endif; ?>
            </section>
            <section class="hr-grid hr-grid-2">
                <div class="hr-card hr-people-panel">
                    <div class="hr-section-header"><h2>Departments</h2><span class="hr-mini">Average operating health</span></div>
                    <?php if (!empty($dashboard['department_summaries'])): ?>
                        <div class="hr-people-metric-grid">
                            <?php foreach ($dashboard['department_summaries'] as $summary): ?>
                                <article class="hr-people-metric-card">
                                    <strong><?php echo htmlspecialchars((string) $summary['department']); ?></strong>
                                    <span><?php echo ($summary['avg_score'] ?? null) !== null ? number_format((float) $summary['avg_score'], 1) . ' avg score' : 'Not enough evidence'; ?></span>
                                    <small><?php echo (int) $summary['staff_count']; ?> staff / <?php echo (int) $summary['at_risk_count']; ?> at risk</small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No department metrics are available yet; departments remain optional until this workspace has meaningful operating lanes.</p>
                    <?php endif; ?>
                </div>
                <div class="hr-card hr-people-panel">
                    <div class="hr-section-header"><h2>Roles</h2><span class="hr-mini">Score by operating lane</span></div>
                    <?php if (!empty($dashboard['role_summaries'])): ?>
                        <div class="hr-people-metric-grid">
                            <?php foreach ($dashboard['role_summaries'] as $summary): ?>
                                <?php $roleKey = (string) ($summary['role'] ?? 'general'); ?>
                                <article class="hr-people-metric-card">
                                    <strong><?php echo htmlspecialchars($roleDisplayLabels[$roleKey] ?? ucfirst($roleKey)); ?></strong>
                                    <span><?php echo ($summary['avg_score'] ?? null) !== null ? number_format((float) $summary['avg_score'], 1) . ' avg score' : 'Not enough evidence'; ?></span>
                                    <small><?php echo ($summary['avg_task_completion'] ?? null) !== null ? number_format((float) $summary['avg_task_completion'], 1) . '% task completion' : 'Task completion not measured'; ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No role metrics are available for this scope; broaden the filter or confirm users have active workspace roles.</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($activeTab === 'analytics'): ?>
            <section class="hr-card hr-room-intro">
                <div>
                    <h2>Executive Analytics</h2>
                    <p>Operating health, business area coverage, trends, and supporting evidence for deeper review.</p>
                </div>
                <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($evidenceConfidence['level'] ?? 'low'))); ?>"><?php echo htmlspecialchars((string) ($evidenceConfidence['label'] ?? 'Low confidence')); ?></span>
            </section>
            <section class="hr-grid hr-grid-2 hr-analytics-command-grid">
                <article class="hr-card hr-trend-analysis-card">
                    <div class="hr-panel-head">
                        <div>
                            <h2>Trend Analysis</h2>
                            <span><?php echo htmlspecialchars((string) ($operatingTrends['headline'] ?? 'Trend posture is stable')); ?></span>
                        </div>
                    </div>
                    <div class="hr-trend-analysis-list">
                        <?php foreach ($trendMetrics as $metric): ?>
                            <div class="hr-trend-analysis-row hr-trend-<?php echo htmlspecialchars((string) ($metric['tone'] ?? 'neutral')); ?>">
                                <span><?php echo htmlspecialchars((string) ($metric['label'] ?? 'Signal')); ?></span>
                                <div class="hr-trend-analysis-track"><b style="width: <?php echo max(0, min(100, (float) ($metric['visual_score'] ?? 0))); ?>%;"></b></div>
                                <strong><?php echo htmlspecialchars($metricValue((array) $metric)); ?></strong>
                                <small><?php echo htmlspecialchars(ucfirst((string) ($metric['status'] ?? 'stable'))); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="hr-card hr-funnel-card">
                    <div class="hr-panel-head">
                        <div>
                            <h2>Pipeline Stage Distribution</h2>
                            <span><?php echo !empty($conversionJourney['available']) ? 'Journey history is ready' : (int) ($conversionJourney['sample_size'] ?? 0) . '/20 durable transitions'; ?></span>
                        </div>
                    </div>
                    <div class="hr-funnel-rows hr-funnel-rows-full">
                        <?php if (!$funnelHasData): ?>
                            <p class="hr-empty hr-funnel-empty">No contact stage data is available for this scope.</p>
                        <?php else: ?>
                            <?php foreach ($funnelRows as $row): ?>
                                <div class="hr-funnel-row <?php echo !empty($row['is_bottleneck']) ? 'is-bottleneck' : ''; ?>">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string) $row['label']); ?></strong>
                                        <span><?php echo (int) $row['count']; ?> contacts</span>
                                    </div>
                                    <div class="hr-funnel-track"><span style="width: <?php echo (float) $row['width']; ?>%;"></span></div>
                                    <small><?php echo number_format((float) ($row['share'] ?? 0), 1); ?>% of contacts / current-stage distribution</small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>
            </section>
            <section class="hr-grid hr-grid-2">
                <article class="hr-card">
                    <div class="hr-panel-head"><div><h2>Responsibility coverage</h2><span>Explicit ownership only; inferred lanes are labelled separately</span></div></div>
                    <div class="hr-coverage-heatmap" role="list" aria-label="Responsibility coverage heatmap">
                        <?php foreach ($functionCoverage as $function): $ownerCount = (int) ($function['explicit_owner_count'] ?? 0); $heatState = $ownerCount >= 2 ? 'strong' : ($ownerCount === 1 ? 'single' : 'gap'); ?>
                            <div class="hr-coverage-cell is-<?php echo $heatState; ?>" role="listitem" aria-label="<?php echo htmlspecialchars((string) ($function['name'] ?? 'Function') . ': ' . $ownerCount . ' explicit owners'); ?>">
                                <strong><?php echo htmlspecialchars((string) ($function['name'] ?? 'Function')); ?></strong>
                                <span><?php echo $ownerCount; ?> explicit / <?php echo (int) ($function['inferred_owner_count'] ?? 0); ?> inferred</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($conversionJourney['available'])): ?>
                        <hr>
                        <div class="hr-panel-head"><div><h3>Conversion journey</h3><span><?php echo (int) ($conversionJourney['sample_size'] ?? 0); ?> durable, non-baseline transitions</span></div></div>
                        <div class="hr-funnel-rows" role="list" aria-label="Durable pipeline transition journey">
                            <?php foreach ((array) ($conversionJourney['transitions'] ?? []) as $transition): ?>
                                <div class="hr-funnel-row" role="listitem">
                                    <div><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($transition['from_stage'] ?? 'unknown'))) . ' to ' . ucwords(str_replace('_', ' ', (string) ($transition['to_stage'] ?? 'unknown')))); ?></strong><span><?php echo (int) ($transition['transition_count'] ?? 0); ?> transitions</span></div>
                                    <div class="hr-funnel-track"><span style="width: <?php echo (float) ($transition['transition_share'] ?? 0); ?>%;"></span></div>
                                    <small><?php echo number_format((float) ($transition['transition_share'] ?? 0), 1); ?>% of departures from <?php echo htmlspecialchars((string) ($transition['from_stage'] ?? 'this stage')); ?> / dwell unavailable until paired transition timestamps mature</small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="hr-card">
                    <div class="hr-panel-head"><div><h2>Explicit-department variance</h2><span>Eligible scores with 95% confidence intervals</span></div></div>
                    <?php if (!empty($dashboard['department_summaries'])): ?>
                        <div class="hr-department-variance" role="list">
                            <?php foreach ($dashboard['department_summaries'] as $department): $score = $department['avg_score'] ?? null; $interval = (array) ($department['confidence_interval'] ?? []); ?>
                                <div role="listitem">
                                    <div><strong><?php echo htmlspecialchars((string) ($department['department'] ?? 'Department')); ?></strong><span><?php echo $score !== null ? number_format((float) $score, 1) : 'Not enough evidence'; ?> / n=<?php echo (int) ($department['eligible_staff_count'] ?? 0); ?></span></div>
                                    <?php if ($score !== null): ?><div class="hr-variance-track" aria-label="<?php echo htmlspecialchars(number_format((float) ($interval['low'] ?? $score), 1) . ' to ' . number_format((float) ($interval['high'] ?? $score), 1)); ?>"><span style="left: <?php echo (float) ($interval['low'] ?? $score); ?>%; width: <?php echo max(1, (float) ($interval['high'] ?? $score) - (float) ($interval['low'] ?? $score)); ?>%;"><b style="left: <?php echo max(0, min(100, ((float) $score - (float) ($interval['low'] ?? $score)) / max(.1, (float) ($interval['high'] ?? $score) - (float) ($interval['low'] ?? $score)) * 100)); ?>%;"></b></span></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="hr-empty">No explicit departments qualify for comparison.</p><?php endif; ?>
                </article>
            </section>
            <section class="hr-overview-kpis">
                <?php foreach ($overviewKpis as $kpi): ?>
                    <article class="hr-overview-kpi hr-overview-kpi-<?php echo htmlspecialchars((string) $kpi['variant']); ?>">
                        <span class="hr-overview-kpi-icon"><i class="<?php echo htmlspecialchars((string) $kpi['icon']); ?>" aria-hidden="true"></i></span>
                        <div>
                            <span class="hr-mini"><?php echo htmlspecialchars((string) $kpi['label']); ?></span>
                            <strong><?php echo htmlspecialchars((string) $kpi['value']); ?></strong>
                            <p><?php echo htmlspecialchars((string) $kpi['tone']); ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
            <section class="hr-grid hr-grid-2">
                <div class="hr-card hr-overview-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Organization Stage</h2>
                            <span class="hr-mini">Stage-aware interpretation</span>
                        </div>
                        <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($organizationStage['confidence'] ?? 'moderate'))); ?>"><?php echo htmlspecialchars(ucfirst((string) ($organizationStage['confidence'] ?? 'moderate'))); ?> confidence</span>
                    </div>
                    <div class="hr-stage-card">
                        <strong><?php echo htmlspecialchars((string) ($organizationStage['label'] ?? 'Founder Led')); ?></strong>
                        <p><?php echo htmlspecialchars((string) ($organizationStage['summary'] ?? 'Organization stage is directional and should be validated with leadership context.')); ?></p>
                        <div class="hr-brief-chip-row">
                            <span class="hr-brief-chip"><?php echo (int) ($organizationStage['staff_count'] ?? 0); ?> people</span>
                            <span class="hr-brief-chip"><?php echo (int) ($organizationStage['assigned_function_count'] ?? 0); ?> assigned functions</span>
                            <span class="hr-brief-chip"><?php echo (int) ($organizationStage['department_count'] ?? 0); ?> departments</span>
                        </div>
                    </div>
                </div>
                <div class="hr-card hr-overview-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Leadership Load</h2>
                            <span class="hr-mini">Dependency and ownership concentration</span>
                        </div>
                        <span class="hr-pill hr-pill-<?php echo (string) ($founderLoad['level'] ?? 'focused') === 'high' ? 'at_risk' : ((string) ($founderLoad['level'] ?? 'focused') === 'moderate' ? 'needs_coaching' : 'steady'); ?>"><?php echo htmlspecialchars(ucfirst((string) ($founderLoad['level'] ?? 'focused'))); ?></span>
                    </div>
                    <div class="hr-stage-card">
                        <strong><?php echo htmlspecialchars((string) ($founderLoad['founder_name'] ?? 'Founder / owner')); ?></strong>
                        <p><?php echo htmlspecialchars((string) ($founderLoad['summary'] ?? 'Leadership load is directional and should be validated with the owner.')); ?></p>
                        <?php $loadAllocation = (array) ($founderLoad['load_allocation'] ?? []); $allocationTotal = max(1, array_sum(array_map('intval', array_intersect_key($loadAllocation, array_flip(['keep','delegate','outsource','automate']))))); ?>
                        <div class="hr-founder-stack" role="img" aria-label="Founder load recommendations: keep <?php echo (int) ($loadAllocation['keep'] ?? 0); ?>, delegate <?php echo (int) ($loadAllocation['delegate'] ?? 0); ?>, outsource <?php echo (int) ($loadAllocation['outsource'] ?? 0); ?>, automate <?php echo (int) ($loadAllocation['automate'] ?? 0); ?>">
                            <?php foreach (['keep','delegate','outsource','automate'] as $allocationKey): $allocationCount = (int) ($loadAllocation[$allocationKey] ?? 0); if ($allocationCount <= 0) continue; ?><span class="is-<?php echo $allocationKey; ?>" style="width: <?php echo ($allocationCount / $allocationTotal) * 100; ?>%;"><?php echo htmlspecialchars(ucfirst($allocationKey)); ?> <?php echo $allocationCount; ?></span><?php endforeach; ?>
                        </div>
                        <small>Current explicit ownership recommendation basis; automation remains zero until a supported automation signal exists.</small>
                        <div class="hr-evidence-box"><span>Read as</span><?php echo htmlspecialchars((string) ($founderLoad['risk'] ?? 'Leadership load should be reviewed as business areas change.')); ?></div>
                    </div>
                </div>
            </section>
            <section class="hr-card hr-strategy-overview">
                <div>
                    <h2>Direction Evidence</h2>
                    <p>Strategy quality is read from active strategy snapshots, role-aware performance signals, and the filtered operating window.</p>
                </div>
                <div class="hr-strategy-context">
                    <span class="hr-mini">Current scope</span>
                    <strong><?php echo htmlspecialchars($strategyFilterContext); ?></strong>
                </div>
            </section>
            <section class="hr-grid hr-grid-2">
                <div class="hr-card hr-strategy-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Best Performing Strategies</h2>
                            <span class="hr-mini">Pattern library from top performers</span>
                        </div>
                    </div>
                    <?php if (!empty($dashboard['best_strategies'])): ?>
                        <div class="hr-strategy-pattern-grid">
                            <?php foreach ($dashboard['best_strategies'] as $strategy): ?>
                                <?php $evidence = (array) ($strategy['evidence'] ?? []); ?>
                                <article class="hr-strategy-pattern-card">
                                    <div class="hr-strategy-card-head">
                                        <span class="hr-strategy-icon"><i class="fa-solid fa-compass" aria-hidden="true"></i></span>
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) ($strategy['name'] ?? 'Strategy')); ?></h3>
                                            <p><?php echo htmlspecialchars((string) ($strategy['summary'] ?? '')); ?></p>
                                        </div>
                                    </div>
                                    <?php if ($evidence !== []): ?>
                                        <div class="hr-strategy-chip-row">
                                            <?php foreach ($evidence as $evidenceKey => $evidenceValue): ?>
                                                <span class="hr-strategy-chip">
                                                    <?php echo htmlspecialchars($strategyEvidenceLabels[(string) $evidenceKey] ?? ucwords(str_replace('_', ' ', (string) $evidenceKey))); ?>
                                                    <?php echo number_format((float) $evidenceValue, 1); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No strategy patterns are available for this filtered window.</p>
                    <?php endif; ?>
                </div>
                <div class="hr-card hr-strategy-panel">
                    <div class="hr-section-header">
                        <div>
                            <h2>Strategic Pointers</h2>
                            <span class="hr-mini">Role-specific guidance</span>
                        </div>
                    </div>
                    <?php if (!empty($dashboard['strategic_pointers'])): ?>
                        <div class="hr-strategy-role-grid">
                            <?php foreach ($dashboard['strategic_pointers'] as $role => $pointers): ?>
                                <?php
                                $roleKey = (string) $role;
                                $roleMeta = $strategyRoleMeta[$roleKey] ?? [
                                    'label' => ucfirst($roleKey),
                                    'icon' => 'fa-solid fa-circle-nodes',
                                    'tone' => 'Role-specific strategy guidance',
                                ];
                                ?>
                                <article class="hr-strategy-role-card hr-strategy-role-card-<?php echo htmlspecialchars($roleKey); ?>">
                                    <div class="hr-strategy-role-head">
                                        <span class="hr-strategy-role-icon"><i class="<?php echo htmlspecialchars($roleMeta['icon']); ?>" aria-hidden="true"></i></span>
                                        <div>
                                            <h3><?php echo htmlspecialchars($roleMeta['label']); ?></h3>
                                            <p><?php echo htmlspecialchars($roleMeta['tone']); ?></p>
                                        </div>
                                    </div>
                                    <ul class="hr-strategy-list">
                                        <?php foreach ((array) $pointers as $pointer): ?>
                                            <li><?php echo htmlspecialchars((string) $pointer); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No strategic pointers are available yet; capture strategy snapshots or broaden the window to connect strategy patterns with outcomes.</p>
                    <?php endif; ?>
                </div>
            </section>
            <section class="hr-card hr-strategy-panel">
                <div class="hr-section-header">
                    <div>
                        <h2>Strategy Snapshot Leaderboard</h2>
                        <span class="hr-mini">Ranked from active personal strategy snapshots</span>
                    </div>
                </div>
                <?php if (!empty($dashboard['strategy_leaderboard'])): ?>
                    <div class="hr-strategy-leaderboard">
                        <?php foreach ($dashboard['strategy_leaderboard'] as $rank => $snapshot): ?>
                            <?php
                            $snapshotEvidence = (array) ($snapshot['evidence'] ?? []);
                            $strategyTitle = (string) (($snapshot['icp'] ?? '') ?: ($snapshot['target_market_focus'] ?? 'Personal ICP'));
                            $strategySummary = (string) (($snapshot['strategy_hypothesis'] ?? '') ?: ($snapshot['strategy_summary'] ?? ''));
                            $competition = (string) (($snapshot['competitors'] ?? '') ?: ($snapshot['differentiator'] ?? 'No competitor note'));
                            ?>
                            <article class="hr-strategy-leader-card">
                                <div class="hr-strategy-leader-rank">#<?php echo (int) $rank + 1; ?></div>
                                <div class="hr-strategy-leader-main">
                                    <div class="hr-strategy-leader-head">
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) ($snapshot['user_name'] ?? 'User')); ?></h3>
                                            <span class="hr-mini"><?php echo htmlspecialchars((string) ($snapshot['department'] ?? '')); ?> - v<?php echo (int) ($snapshot['version'] ?? 0); ?></span>
                                        </div>
                                        <span class="hr-strategy-score"><?php echo number_format((float) ($snapshot['score'] ?? 0), 1); ?></span>
                                    </div>
                                    <div class="hr-strategy-leader-copy">
                                        <p class="hr-strategy-eyebrow">ICP / Strategy</p>
                                        <strong><?php echo htmlspecialchars($strategyTitle); ?></strong>
                                        <p><?php echo htmlspecialchars($strategySummary); ?></p>
                                    </div>
                                    <div class="hr-strategy-leader-copy">
                                        <p class="hr-strategy-eyebrow">Competition signal</p>
                                        <p><?php echo htmlspecialchars($competition); ?></p>
                                    </div>
                                    <div class="hr-strategy-chip-row">
                                        <?php foreach ($strategyEvidenceLabels as $evidenceKey => $evidenceLabel): ?>
                                            <?php if (array_key_exists($evidenceKey, $snapshotEvidence)): ?>
                                                <span class="hr-strategy-chip">
                                                    <?php echo htmlspecialchars($evidenceLabel); ?>
                                                    <?php echo number_format((float) $snapshotEvidence[$evidenceKey], 0); ?>%
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hr-empty">No strategy snapshots are available in this filtered window.</p>
                <?php endif; ?>
            </section>
            <section class="hr-card hr-swot-panel">
                <div class="hr-section-header">
                    <div>
                        <h2>Supporting SWOT</h2>
                        <span class="hr-mini">Evidence grouped for executive review</span>
                    </div>
                </div>
                <?php if (!empty($dashboard['swot']['org'])): ?>
                    <div class="hr-swot-board hr-swot-board-compact">
                        <?php foreach ($swotQuadrants as $swotKey => $swotMeta): ?>
                            <?php $items = array_values((array) ($dashboard['swot']['org'][$swotKey] ?? [])); ?>
                            <article class="hr-swot-card hr-swot-card-<?php echo htmlspecialchars($swotKey); ?>">
                                <div class="hr-swot-card-head">
                                    <span class="hr-swot-icon"><i class="<?php echo htmlspecialchars($swotMeta['icon']); ?>" aria-hidden="true"></i></span>
                                    <div>
                                        <h3><?php echo htmlspecialchars($swotMeta['label']); ?></h3>
                                        <p><?php echo htmlspecialchars($swotMeta['tone']); ?></p>
                                    </div>
                                </div>
                                <div class="hr-swot-signal-list">
                                    <?php if ($items !== []): ?>
                                        <?php foreach (array_slice($items, 0, 2) as $item): ?>
                                            <?php if (is_array($item)): ?>
                                                <article class="hr-swot-mini-signal">
                                                    <strong><?php echo htmlspecialchars((string) ($item['title'] ?? 'SWOT signal')); ?></strong>
                                                    <p><?php echo htmlspecialchars((string) ($item['impact'] ?? $item['evidence'] ?? '')); ?></p>
                                                </article>
                                            <?php else: ?>
                                                <p class="hr-swot-legacy-item"><?php echo htmlspecialchars((string) $item); ?></p>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="hr-swot-muted">No clear signal in this quadrant for the current scope.</p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="hr-empty">SWOT signals will appear after workspace activity is available.</p>
                <?php endif; ?>
            </section>
        <?php elseif ($activeTab === 'priorities'): ?>
            <section class="hr-card hr-actions-overview">
                <div>
                    <h2>Direction & Priorities</h2>
                    <p>Ranked leadership items, contextual recommendations, and follow-through tools for the current operating scope.</p>
                </div>
                <div class="hr-actions-context">
                    <span class="hr-mini">Current scope</span>
                    <strong><?php echo htmlspecialchars($analyticsFilterContext); ?></strong>
                </div>
            </section>
            <nav class="hr-priority-subtabs" aria-label="Priorities workspaces">
                <?php foreach ($priorityViews as $priorityViewKey => $priorityView): ?>
                    <a href="<?php echo htmlspecialchars($buildPriorityViewUrl($priorityViewKey)); ?>" class="hr-priority-tab <?php echo $activePriorityView === $priorityViewKey ? 'is-active' : ''; ?>" <?php echo $activePriorityView === $priorityViewKey ? 'aria-current="page"' : ''; ?>>
                        <i class="<?php echo htmlspecialchars((string) $priorityView['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $priorityView['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($activePriorityView === 'leadership'): ?>
                <section class="hr-card hr-office-panel hr-priority-room" id="hr-priority-view-leadership">
                    <div class="hr-section-header">
                        <div>
                            <h2>Leadership Priorities</h2>
                            <span class="hr-mini">What needs attention, why it matters, and what to do next</span>
                        </div>
                        <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($evidenceConfidence['level'] ?? 'low'))); ?>"><?php echo htmlspecialchars((string) ($evidenceConfidence['label'] ?? 'Low confidence')); ?></span>
                    </div>
                    <?php if ($executivePriorities !== []): ?>
                        <div class="hr-priority-list hr-priority-list-wide">
                            <?php foreach ($executivePriorities as $priority): ?>
                                <article class="hr-priority-row hr-severity-<?php echo htmlspecialchars((string) $priority['severity']); ?>">
                                    <div class="hr-priority-marker"></div>
                                    <div>
                                        <div class="hr-row-between">
                                            <strong><?php echo htmlspecialchars((string) $priority['title']); ?></strong>
                                            <span class="hr-pill hr-pill-<?php echo $priority['severity'] === 'high' ? 'at_risk' : ($priority['severity'] === 'low' ? 'steady' : 'needs_coaching'); ?>"><?php echo htmlspecialchars(ucfirst((string) $priority['severity'])); ?></span>
                                        </div>
                                        <p><?php echo htmlspecialchars((string) ($priority['why'] ?: $priority['action'])); ?></p>
                                        <?php if ($priority['evidence'] !== ''): ?>
                                            <div class="hr-evidence-box"><span>Evidence</span><?php echo htmlspecialchars((string) $priority['evidence']); ?></div>
                                        <?php endif; ?>
                                        <div class="hr-action-line"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><?php echo htmlspecialchars((string) $priority['action']); ?></div>
                                        <div class="hr-brief-chip-row">
                                            <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars((string) $priority['confidence']); ?>"><?php echo htmlspecialchars(ucfirst((string) $priority['confidence'])); ?> confidence</span>
                                            <span class="hr-brief-chip"><?php echo htmlspecialchars((string) $priority['source']); ?></span>
                                            <span class="hr-brief-chip"><?php echo htmlspecialchars((string) $priority['timeframe']); ?></span>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hr-empty">No leadership priority is visible in this scope; broaden the timeframe or confirm activity capture before treating the operating picture as complete.</p>
                    <?php endif; ?>
                </section>
                <?php if ($contextualRecommendations !== []): ?>
                    <section class="hr-card hr-office-panel hr-priority-room">
                        <div class="hr-section-header"><h2>Contextual Recommendations</h2><span class="hr-mini">Avoiding generic advice</span></div>
                        <div class="hr-recommendation-list">
                            <?php foreach ($contextualRecommendations as $recommendation): ?>
                                <div class="hr-action-line"><i class="fa-solid fa-check" aria-hidden="true"></i><?php echo htmlspecialchars((string) $recommendation); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php elseif ($activePriorityView === 'action_plan' && $canManage): ?>
                <section class="hr-card hr-actions-panel hr-priority-room" id="hr-priority-view-action-plan">
                    <div class="hr-section-header">
                        <div>
                            <h2>Action Plan Draft</h2>
                            <span class="hr-mini">Generate manager-ready next steps</span>
                        </div>
                    </div>
                    <form method="post" class="hr-actions-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="hr_action" value="draft_action_plan">
                        <?php $renderHiddenState('priorities', 'action_plan'); ?>
                        <div class="hr-form-grid">
                            <label>Target staff<select name="target_user_id"><option value="">Organization-wide draft</option><?php foreach ($users as $userRow): ?><option value="<?php echo (int) $userRow['id']; ?>"><?php echo htmlspecialchars((string) $userRow['label']); ?></option><?php endforeach; ?></select></label>
                            <label>Department<select name="target_department"><option value="">No department override</option><?php foreach (array_keys($dashboard['department_summaries']) as $department): ?><option value="<?php echo htmlspecialchars($department); ?>"><?php echo htmlspecialchars($department); ?></option><?php endforeach; ?></select></label>
                        </div>
                        <div class="hr-actions"><button type="submit" class="hr-btn hr-btn-secondary">Generate Action Plan Draft</button></div>
                    </form>
                    <div class="hr-actions-draft">
                        <?php if ($actionPlanDraft): ?>
                            <div class="hr-row-between">
                                <strong><?php echo htmlspecialchars((string) ($actionPlanDraft['title'] ?? 'Action Plan')); ?></strong>
                                <span class="hr-confidence-chip hr-confidence-<?php echo htmlspecialchars($confidenceClass((string) ($actionPlanDraft['confidence'] ?? 'moderate'))); ?>"><?php echo htmlspecialchars(ucfirst((string) ($actionPlanDraft['confidence'] ?? 'moderate'))); ?> confidence</span>
                            </div>
                            <p><?php echo htmlspecialchars((string) ($actionPlanDraft['summary'] ?? '')); ?></p>
                            <?php if (!empty($actionPlanDraft['diagnosis'])): ?>
                                <div class="hr-plan-section">
                                    <span>Diagnosis</span>
                                    <p><?php echo htmlspecialchars((string) $actionPlanDraft['diagnosis']); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php foreach ([
                                'manager_questions' => 'Manager questions',
                                'seven_day_actions' => '7-day actions',
                                'thirty_day_actions' => '30-day actions',
                                'success_metrics' => 'Success metrics',
                                'escalation_triggers' => 'Escalation triggers',
                                'what_not_to_do' => 'What not to do',
                            ] as $planKey => $planLabel): ?>
                                <?php $planItems = array_values((array) ($actionPlanDraft[$planKey] ?? [])); ?>
                                <?php if ($planItems !== []): ?>
                                    <div class="hr-plan-section">
                                        <span><?php echo htmlspecialchars($planLabel); ?></span>
                                        <ul class="hr-strategy-list">
                                            <?php foreach ($planItems as $action): ?>
                                                <li><?php echo htmlspecialchars((string) $action); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($actionPlanDraft['seven_day_actions']) && !empty($actionPlanDraft['actions'])): ?>
                                <ul class="hr-strategy-list">
                                    <?php foreach ((array) ($actionPlanDraft['actions'] ?? []) as $action): ?>
                                        <li><?php echo htmlspecialchars((string) $action); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($managerBriefText !== ''): ?>
                                <div class="hr-manager-brief-copy">
                                    <div class="hr-manager-brief-head">
                                        <h3 id="hr-manager-brief-heading">Manager brief</h3>
                                        <button type="button" class="hr-btn hr-btn-secondary" data-copy-target="hr-manager-brief-copy"><i class="fa-solid fa-copy" aria-hidden="true"></i><span data-copy-label>Copy manager brief</span></button>
                                    </div>
                                    <div id="hr-manager-brief-copy" class="hr-manager-brief-body" role="region" aria-labelledby="hr-manager-brief-heading" tabindex="0"><?php echo htmlspecialchars($managerBriefText); ?></div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="hr-empty">Generate an action plan draft for the organization, a department, or an individual staff member.</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($activePriorityView === 'tasks' && $canManage): ?>
                <section class="hr-grid hr-grid-2 hr-actions-workbench hr-priority-room" id="hr-priority-view-tasks">
                    <div class="hr-card hr-actions-panel">
                        <div class="hr-section-header">
                            <div>
                                <h2>Coaching Task</h2>
                                <span class="hr-mini">Turn insight into tracked follow-up</span>
                            </div>
                        </div>
                        <form method="post" class="hr-actions-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                            <input type="hidden" name="hr_action" value="create_coaching_task">
                            <input type="hidden" name="mutation_context_token" value="<?php echo htmlspecialchars((string) $webMutationContext['token']); ?>">
                            <?php $renderHiddenState('priorities', 'tasks'); ?>
                            <div class="hr-form-grid">
                                <label>Target staff<select name="target_user_id" required><option value="">Select staff member</option><?php foreach ($users as $userRow): ?><option value="<?php echo (int) $userRow['id']; ?>"><?php echo htmlspecialchars((string) $userRow['label']); ?></option><?php endforeach; ?></select></label>
                                <label>Priority<select name="task_priority"><option value="high">High</option><option value="urgent">Urgent</option><option value="medium">Medium</option></select></label>
                            </div>
                            <label>Task title<input type="text" name="task_title" value="Coaching follow-up" required></label>
                            <label>Description<textarea name="task_description" rows="4">Review the flagged performance gaps, agree one corrective action, and confirm a measurable outcome for the next check-in.</textarea></label>
                            <label>Due date<input type="datetime-local" name="task_due_date"></label>
                            <div class="hr-actions"><button type="submit" class="hr-btn hr-btn-primary">Create Coaching Task</button></div>
                        </form>
                    </div>
                    <div class="hr-card hr-actions-panel">
                        <div class="hr-section-header">
                            <div>
                                <h2>7-Day Task Candidates</h2>
                                <span class="hr-mini">Create selected tasks from the current action plan</span>
                            </div>
                        </div>
                        <?php if ($actionPlanTaskCandidates !== []): ?>
                            <form method="post" class="hr-task-candidate-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="hr_action" value="create_action_plan_tasks">
                                <input type="hidden" name="mutation_context_token" value="<?php echo htmlspecialchars((string) $webMutationContext['token']); ?>">
                                <?php $renderHiddenState('priorities', 'tasks'); ?>
                                <label>Assign created tasks to<select name="task_assignee_user_id"><option value="">Me / current manager</option><?php foreach ($users as $userRow): ?><option value="<?php echo (int) $userRow['id']; ?>"><?php echo htmlspecialchars((string) $userRow['label']); ?></option><?php endforeach; ?></select></label>
                                <div class="hr-task-candidate-list">
                                    <?php foreach ($actionPlanTaskCandidates as $candidate): ?>
                                        <?php
                                            $candidate = is_array($candidate) ? $candidate : [];
                                            $candidateKey = preg_replace('/[^a-z0-9_.-]+/', '_', strtolower(trim((string) ($candidate['key'] ?? '')))) ?: '';
                                            if ($candidateKey === '') {
                                                continue;
                                            }
                                            $dueOffset = (int) ($candidate['due_date_offset'] ?? 7);
                                        ?>
                                        <label class="hr-task-candidate">
                                            <input type="checkbox" name="task_candidate_keys[]" value="<?php echo htmlspecialchars($candidateKey); ?>" <?php echo $dueOffset <= 7 ? 'checked' : ''; ?>>
                                            <span>
                                                <strong><?php echo htmlspecialchars((string) ($candidate['title'] ?? 'Action-plan task')); ?></strong>
                                                <small><?php echo htmlspecialchars(ucfirst((string) ($candidate['priority'] ?? 'medium'))); ?> priority / due in <?php echo $dueOffset; ?> day(s) / <?php echo htmlspecialchars((string) ($candidate['source_scope'] ?? 'Organization')); ?></small>
                                            </span>
                                            <?php foreach (['title', 'description', 'priority', 'due_date_offset', 'source_scope', 'source_risk_type'] as $field): ?>
                                                <input type="hidden" name="task_candidates[<?php echo htmlspecialchars($candidateKey); ?>][<?php echo htmlspecialchars($field); ?>]" value="<?php echo htmlspecialchars((string) ($candidate[$field] ?? '')); ?>">
                                            <?php endforeach; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="hr-actions"><button type="submit" class="hr-btn hr-btn-primary">Create Selected 7-Day Tasks</button></div>
                            </form>
                        <?php else: ?>
                            <div class="hr-actions-draft">
                                <p class="hr-empty">Generate an action plan draft to review candidate tasks here.</p>
                                <a href="<?php echo htmlspecialchars($buildPriorityViewUrl('action_plan')); ?>" class="hr-inline-action"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>Open Action Plan</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($activePriorityView === 'access' && $canManageUsers && $assignableRoles !== []): ?>
                <section class="hr-card hr-actions-panel hr-actions-access-panel hr-priority-room" id="hr-priority-view-access">
                    <div class="hr-section-header">
                        <div>
                            <h2>Access Profile</h2>
                            <span class="hr-mini">Available because you can manage users</span>
                        </div>
                    </div>
                    <form method="post" class="hr-form-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="hr_action" value="assign_access_role">
                        <?php $renderHiddenState('priorities', 'access'); ?>
                        <label>Target staff<select name="target_user_id" required><option value="">Select staff member</option><?php foreach ($users as $userRow): ?><option value="<?php echo (int) $userRow['id']; ?>"><?php echo htmlspecialchars((string) $userRow['label']); ?></option><?php endforeach; ?></select></label>
                        <label>Access profile<select name="role_id" required><option value="">Select role</option><?php foreach ($assignableRoles as $role): ?><option value="<?php echo (int) $role['id']; ?>"><?php echo htmlspecialchars((string) $role['name']); ?></option><?php endforeach; ?></select></label>
                        <div class="hr-actions"><button type="submit" class="hr-btn hr-btn-secondary">Update Access Profile</button></div>
                    </form>
                </section>
            <?php elseif ($activePriorityView === 'diagnostics' && $canManage): ?>
                <section class="hr-card hr-plugin-health-panel hr-plugin-health-<?php echo htmlspecialchars($pluginHealthStatus); ?> hr-priority-room" id="hr-priority-view-diagnostics">
                    <div class="hr-section-header">
                        <div>
                            <h2>Plugin Health</h2>
                            <span class="hr-mini">Organization Intelligence runtime diagnostics, last 30 days</span>
                        </div>
                        <span class="hr-pill hr-pill-<?php echo $pluginHealthStatus === 'failing' ? 'at_risk' : ($pluginHealthStatus === 'blocked' ? 'needs_coaching' : 'steady'); ?>"><?php echo htmlspecialchars(ucfirst($pluginHealthStatus)); ?></span>
                    </div>
                    <div class="hr-plugin-health-grid">
                        <span><strong><?php echo (int) ($pluginRuntimeUsage['successful_runs_30d'] ?? 0); ?></strong> successful runs</span>
                        <span><strong><?php echo (int) ($pluginRuntimeUsage['blocked_runs_30d'] ?? 0); ?></strong> blocked runs</span>
                        <span><strong><?php echo (int) ($pluginRuntimeUsage['failed_runs_30d'] ?? 0); ?></strong> failed runs</span>
                        <span><strong><?php echo number_format((float) ($pluginRuntimeUsage['avg_duration_ms'] ?? 0), 1); ?> ms</strong> average duration</span>
                        <span><strong><?php echo htmlspecialchars($formatHoursFromMinutes((float) ($pluginRuntimeUsage['active_minutes_30d'] ?? 0))); ?></strong> active time</span>
                        <span><strong><?php echo htmlspecialchars((string) ($pluginRuntimeUsage['latest_capability'] ?? 'None yet')); ?></strong> latest capability</span>
                        <span><strong><?php echo htmlspecialchars((string) ($organizationProfile['engine_version'] ?? 'v2')); ?></strong> active engine</span>
                        <span><strong><?php echo htmlspecialchars((string) ($organizationProfile['rollout_state'] ?? 'v2_enabled')); ?></strong> rollout state</span>
                        <span><strong><?php echo htmlspecialchars((string) ($snapshotDiagnostics['status'] ?? 'missing')); ?></strong> snapshot status</span>
                        <span><strong><?php echo isset($snapshotDiagnostics['age_hours']) ? number_format((float) $snapshotDiagnostics['age_hours'], 1) . ' h' : 'Never'; ?></strong> snapshot age</span>
                        <span><strong><?php echo htmlspecialchars((string) ($snapshotDiagnostics['scheduler']['derived_status'] ?? 'not recorded')); ?></strong> scheduler health</span>
                        <span><strong><?php echo htmlspecialchars((string) ($snapshotDiagnostics['calculation_version'] ?? 'none')); ?></strong> snapshot calculation</span>
                        <span><strong><?php echo number_format((float) ($oiRuntimeDiagnostics['ai_fallback_rate'] ?? 0) * 100, 0); ?>%</strong> Clarity fallback rate</span>
                        <span><strong><?php echo htmlspecialchars(implode(', ', array_map('strval', (array) ($oiRuntimeDiagnostics['mobile_schema_versions_observed'] ?? []))) ?: 'none'); ?></strong> mobile schemas observed</span>
                        <span><strong><?php echo (int) ($oiRuntimeDiagnostics['scope_rejections_15m'] ?? 0); ?></strong> scope rejections / 15m</span>
                    </div>
                    <?php if (!empty($organizationProfile['shadow_comparison'])): ?><p class="hr-mini">Shadow comparison: <?php echo htmlspecialchars(json_encode($organizationProfile['shadow_comparison'], JSON_UNESCAPED_SLASHES)); ?></p><?php endif; ?>
                </section>
            <?php endif; ?>
        <?php elseif ($activeTab === 'settings' && $canSettings): ?>
            <section class="hr-card hr-settings-panel">
                <div class="hr-section-header">
                    <div>
                        <h2>Organization model</h2>
                        <span class="hr-mini">Confirmation always wins over later inference.</span>
                    </div>
                </div>
                <form method="post" class="hr-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <input type="hidden" name="hr_action" value="confirm_operating_model">
                    <?php $renderHiddenState('settings'); ?>
                    <div class="hr-form-grid">
                        <label>Operating model
                            <select name="operating_model" required>
                                <?php foreach (OrganizationIntelligenceProfileService::MODELS as $model): ?>
                                    <option value="<?php echo htmlspecialchars($model); ?>" <?php echo ($organizationProfile['effective_model'] ?? '') === $model ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $model))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <fieldset class="hr-founder-selector"><legend>Founder cohort</legend>
                            <?php foreach ($users as $userRow): ?><label><input type="checkbox" name="founder_user_ids[]" value="<?php echo (int) $userRow['id']; ?>" <?php echo in_array((int) $userRow['id'], array_map('intval', (array) ($organizationProfile['founder_user_ids'] ?? [])), true) ? 'checked' : ''; ?>><?php echo htmlspecialchars((string) $userRow['label']); ?></label><?php endforeach; ?>
                        </fieldset>
                    </div>
                    <p class="hr-mini">Inferred: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($organizationProfile['inferred_model'] ?? '')))); ?>. <?php echo !empty($organizationProfile['review_recommended']) ? 'The current inference differs from the confirmed model; review is recommended.' : 'No model conflict detected.'; ?></p>
                    <div class="hr-actions"><button type="submit" class="hr-btn hr-btn-primary">Confirm model</button></div>
                </form>
            </section>
            <section class="hr-card hr-settings-panel">
                <div class="hr-section-header">
                    <div>
                        <h2>Settings & Methodology</h2>
                        <span class="hr-mini">Thresholds, department mapping, and AI guidance</span>
                    </div>
                </div>
                <form method="post" class="hr-settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <input type="hidden" name="hr_action" value="save_settings">
                    <?php $renderHiddenState('settings'); ?>
                    <section class="hr-settings-section">
                        <div class="hr-settings-section-head">
                            <span class="hr-strategy-icon"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></span>
                            <div>
                                <h3>AI assistance</h3>
                                <p>Controls whether SWOT, tips, and draft plans can use AI generation.</p>
                            </div>
                        </div>
                        <label class="hr-toggle"><input type="checkbox" name="ai_enabled" value="1" <?php echo !empty($settings['ai_enabled']) ? 'checked' : ''; ?>>Enable AI-generated SWOT, tips, and action-plan drafts</label>
                    </section>
                    <section class="hr-settings-section">
                        <div class="hr-settings-section-head">
                            <span class="hr-strategy-icon"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
                            <div>
                                <h3>Score thresholds</h3>
                                <p>Define how the dashboard classifies strong, coaching, risk, and overload signals.</p>
                            </div>
                        </div>
                        <div class="hr-form-grid" data-threshold-ordering>
                            <label>High performer threshold<input type="number" min="1" max="100" name="threshold_high_performer" value="<?php echo (int) ($settings['thresholds']['high_performer'] ?? 75); ?>"></label>
                            <label>At-risk threshold<input type="number" min="0" max="98" name="threshold_at_risk" value="<?php echo (int) ($settings['thresholds']['at_risk'] ?? 45); ?>"></label>
                            <label>Needs coaching threshold<input type="number" min="1" max="99" name="threshold_needs_coaching" value="<?php echo (int) ($settings['thresholds']['needs_coaching'] ?? 55); ?>"></label>
                            <label>Overloaded task count<input type="number" min="1" max="50" name="threshold_overloaded_task_count" value="<?php echo (int) ($settings['thresholds']['overloaded_task_count'] ?? 7); ?>"></label>
                        </div>
                        <p class="hr-mini">Required order: at risk &lt; needs coaching &lt; high performer.</p>
                    </section>
                    <section class="hr-settings-section">
                        <div class="hr-settings-section-head">
                            <span class="hr-strategy-icon"><i class="fa-solid fa-sitemap" aria-hidden="true"></i></span>
                            <div>
                                <h3>Department mapping</h3>
                                <p>Keep HR summaries aligned with how this workspace names operating lanes.</p>
                            </div>
                        </div>
                        <div class="hr-form-grid">
                            <label>Marketing department label<input type="text" name="department_marketing" value="<?php echo htmlspecialchars((string) ($settings['department_mappings']['marketing'] ?? 'Marketing')); ?>"></label>
                            <label>Sales department label<input type="text" name="department_sales" value="<?php echo htmlspecialchars((string) ($settings['department_mappings']['sales'] ?? 'Sales')); ?>"></label>
                            <label>Leadership department label<input type="text" name="department_admin" value="<?php echo htmlspecialchars((string) ($settings['department_mappings']['admin'] ?? 'Leadership')); ?>"></label>
                            <label>Operations department label<input type="text" name="department_viewer" value="<?php echo htmlspecialchars((string) ($settings['department_mappings']['viewer'] ?? 'Operations')); ?>"></label>
                        </div>
                    </section>
                    <section class="hr-settings-section">
                        <div class="hr-settings-section-head">
                            <span class="hr-strategy-icon"><i class="fa-solid fa-message" aria-hidden="true"></i></span>
                            <div>
                                <h3>Guidance prompts</h3>
                                <p>Shape how manager coaching and SWOT guidance should read.</p>
                            </div>
                        </div>
                        <div class="hr-form-grid">
                            <label class="hr-span-2">Manager guidance focus<textarea name="manager_focus" rows="3"><?php echo htmlspecialchars((string) ($settings['prompt_config']['manager_focus'] ?? '')); ?></textarea></label>
                            <label class="hr-span-2">SWOT guidance focus<textarea name="swot_focus" rows="3"><?php echo htmlspecialchars((string) ($settings['prompt_config']['swot_focus'] ?? '')); ?></textarea></label>
                        </div>
                    </section>
                    <div class="hr-actions"><button type="submit" class="hr-btn hr-btn-primary">Save Settings</button></div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy-target]');
    if (!button) {
        return;
    }
    var target = document.getElementById(button.getAttribute('data-copy-target'));
    if (!target) {
        return;
    }
    var copyText = typeof target.value === 'string' ? target.value : (target.textContent || '');
    var selectCopyTarget = function () {
        if (typeof target.focus === 'function') {
            target.focus();
        }
        if (typeof target.select === 'function') {
            target.select();
            return;
        }
        if (window.getSelection && document.createRange) {
            var range = document.createRange();
            range.selectNodeContents(target);
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        }
    };
    var markCopied = function () {
        var label = button.querySelector('[data-copy-label]');
        if (label) {
            label.textContent = 'Copied manager brief';
            return;
        }
        button.textContent = 'Copied manager brief';
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(copyText).then(function () {
            markCopied();
        }).catch(function () {
            selectCopyTarget();
            document.execCommand('copy');
            markCopied();
        });
        return;
    }
    selectCopyTarget();
    document.execCommand('copy');
    markCopied();
});

document.querySelectorAll('[data-threshold-ordering]').forEach(function (group) {
    var form = group.closest('form');
    if (!form) return;
    form.addEventListener('submit', function (event) {
        var atRisk = form.querySelector('[name="threshold_at_risk"]');
        var coaching = form.querySelector('[name="threshold_needs_coaching"]');
        var high = form.querySelector('[name="threshold_high_performer"]');
        if (!atRisk || !coaching || !high) return;
        var valid = Number(atRisk.value) < Number(coaching.value) && Number(coaching.value) < Number(high.value);
        coaching.setCustomValidity(valid ? '' : 'Use at risk < needs coaching < high performer.');
        if (!valid) {
            event.preventDefault();
            coaching.reportValidity();
        }
    });
});
</script>
<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_HR_ANALYTICS, 'How to use Organization Intelligence', $hrAnalyticsGuideVideoUrl); ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
