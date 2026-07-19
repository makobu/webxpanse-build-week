<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Targets;
use CRM\Modules\UserPreferences;

class AIUserWorkContextService
{
    private UserPreferences $preferences;
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct()
    {
        $this->preferences = new UserPreferences();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }

    public function buildContext(int $userId, string $surface, array $operatingContext = []): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $userId = $this->analyticsWorkspace->ensureScopedUserId($userId, $workspaceId) ?? $userId;
        $user = $this->resolveUser($userId);
        $featureAccess = $this->buildFeatureAccess($user);
        $workload = $this->buildWorkloadSignals($userId, $workspaceId);
        $taskGapContext = $this->buildTaskGapContext($userId, $workspaceId);
        $ownership = $this->buildOwnershipScope($userId, $workspaceId);
        $recentExecution = $this->buildRecentExecutionState($userId, $workspaceId);
        $aiPreferences = $this->buildAIPreferences($userId, $workspaceId);
        $marketplaceContext = $this->buildMarketplaceModuleContext($workspaceId, $userId);

        return [
            'identity' => [
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'email' => (string) ($user['email'] ?? ''),
                'display_name' => $this->buildDisplayName($user),
                'role' => (string) ($user['role'] ?? 'user'),
                'last_login_at' => (string) ($user['last_login'] ?? ''),
                'email_verified' => !empty($user['email_verified_at']),
                'is_authenticated_session_user' => ((int) ($user['id'] ?? 0)) === $userId,
            ],
            'surface' => [
                'name' => $surface,
                'current_page' => (string) ($operatingContext['surface']['current_page'] ?? ''),
            ],
            'feature_access' => $featureAccess,
            'my_workload' => $workload,
            'task_gap_context' => $taskGapContext,
            'ownership_scope' => $ownership,
            'recent_execution_state' => $recentExecution,
            'ai_preferences' => $aiPreferences,
            'marketplace_modules' => $marketplaceContext,
            'summary' => $this->buildSummary($user, $featureAccess, $workload, $taskGapContext, $ownership, $aiPreferences),
        ];
    }

    public function summarize(array $context): array
    {
        return [
            'display_name' => (string) ($context['identity']['display_name'] ?? ''),
            'role' => (string) ($context['identity']['role'] ?? 'user'),
            'open_tasks' => (int) ($context['my_workload']['open_tasks'] ?? 0),
            'overdue_tasks' => (int) ($context['my_workload']['overdue_tasks'] ?? 0),
            'task_gap_count' => (int) ($context['task_gap_context']['tasks_with_gaps'] ?? 0),
            'owned_open_deals' => (int) ($context['my_workload']['owned_open_deals'] ?? 0),
            'active_targets' => (int) ($context['ownership_scope']['active_targets'] ?? 0),
            'effective_mode' => (string) ($context['ai_preferences']['effective_guidance_mode'] ?? ''),
            'context_strictness' => (string) ($context['ai_preferences']['context_strictness'] ?? ''),
            'summary' => (string) ($context['summary'] ?? ''),
        ];
    }

    private function resolveUser(int $userId): array
    {
        $authUser = Auth::user();
        if ((int) ($authUser['id'] ?? 0) === $userId) {
            return $authUser;
        }

        try {
            return Database::queryOne(
                "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.last_login, u.email_verified_at
                 FROM users u
                 INNER JOIN workspace_memberships wm ON wm.user_id = u.id
                 WHERE u.id = ?
                   AND wm.workspace_id = ?
                   AND wm.membership_status = 'active'
                 LIMIT 1",
                [$userId, $this->analyticsWorkspace->requireAnalyticsWorkspaceId()]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function buildFeatureAccess(array $user): array
    {
        $rbacAvailable = Authorization::isRbacAvailable();
        $userId = (int) ($user['id'] ?? 0);
        $accessRole = $rbacAvailable && $userId > 0 ? Authorization::getUserRole($userId) : null;
        $isAdminAccess = ($accessRole['slug'] ?? null) === 'admin';

        $features = [
            'dashboard' => true,
            'contacts' => true,
            'companies' => true,
            'deals' => true,
            'tasks' => true,
            'inbox' => true,
            'targets' => true,
            'reports' => $rbacAvailable === false ? true : Authorization::can('settings.monitoring', $user),
            'campaigns' => $rbacAvailable === false ? true : Authorization::can('campaigns.manage', $user),
            'workflows' => $rbacAvailable === false ? true : Authorization::can('workflows.manage', $user),
            'settings' => $rbacAvailable === false ? true : Authorization::canAny([
                'settings.general',
                'settings.email',
                'settings.email_assistant',
                'settings.whatsapp',
                'settings.ai',
                'settings.calendar',
                'settings.sms',
                'settings.ai_autoresponder',
                'settings.enrichment',
                'settings.scoring',
                'settings.company',
                'settings.monitoring',
                'settings.deal_automation',
                'settings.invoicing',
                'settings.commercial_automation',
            ], $user),
            'users' => Authorization::canAccessUsersPage($user),
        ];

        return [
            'rbac_available' => $rbacAvailable,
            'admin_override' => $isAdminAccess,
            'legacy_fallback_mode' => Authorization::isLegacyFallbackMode($user),
            'features' => $features,
            'editable_surfaces' => array_values(array_keys(array_filter($features, static fn(bool $allowed): bool => $allowed))),
        ];
    }

    private function buildWorkloadSignals(int $userId, int $workspaceId): array
    {
        return [
            'open_tasks' => $this->countQuery(
                "SELECT COUNT(*) AS cnt FROM tasks WHERE workspace_id = ? AND assigned_to = ? AND status NOT IN ('completed', 'cancelled')",
                [$workspaceId, $userId]
            ),
            'overdue_tasks' => $this->countQuery(
                "SELECT COUNT(*) AS cnt
                 FROM tasks
                 WHERE workspace_id = ?
                   AND assigned_to = ?
                   AND status NOT IN ('completed', 'cancelled')
                   AND due_date IS NOT NULL
                   AND due_date < NOW()",
                [$workspaceId, $userId]
            ),
            'tasks_due_today' => $this->countQuery(
                "SELECT COUNT(*) AS cnt
                 FROM tasks
                 WHERE workspace_id = ?
                   AND assigned_to = ?
                   AND status NOT IN ('completed', 'cancelled')
                   AND DATE(due_date) = CURDATE()",
                [$workspaceId, $userId]
            ),
            'owned_open_deals' => $this->countQuery(
                "SELECT COUNT(*) AS cnt
                 FROM deals
                 WHERE workspace_id = ?
                   AND assigned_to = ?
                   AND stage NOT IN ('closed_won', 'closed_lost')",
                [$workspaceId, $userId]
            ),
            'open_deals_total' => $this->countQuery(
                "SELECT COUNT(*) AS cnt
                 FROM deals
                 WHERE workspace_id = ?
                   AND stage NOT IN ('closed_won', 'closed_lost')",
                [$workspaceId]
            ),
        ];
    }

    private function buildOwnershipScope(int $userId, int $workspaceId): array
    {
        $activeTargetCount = 0;
        $atRiskTargetCount = 0;
        $targetSummary = [];
        try {
            $targets = new Targets();
            $activeTargets = $targets->getAll(['user_id' => $userId, 'status' => 'active'], 10, 0);
            $activeTargetCount = count($activeTargets);
            foreach ($activeTargets as $target) {
                $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
                if (in_array($band, ['at_risk', 'behind', 'blocked', 'missed'], true)) {
                    $atRiskTargetCount++;
                }
                if (count($targetSummary) < 5) {
                    $targetSummary[] = [
                        'id' => (int) ($target['id'] ?? 0),
                        'title' => (string) ($target['title'] ?? ''),
                        'scope' => (string) ($target['scope'] ?? 'personal'),
                        'progress_mode' => (string) ($target['progress_mode'] ?? 'manual'),
                        'status_band' => $band,
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return [
            'contacts' => $this->countQuery("SELECT COUNT(*) AS cnt FROM contacts WHERE workspace_id = ? AND assigned_to = ?", [$workspaceId, $userId]),
            'companies' => $this->countQuery("SELECT COUNT(*) AS cnt FROM companies WHERE workspace_id = ? AND assigned_to = ?", [$workspaceId, $userId]),
            'deals' => $this->countQuery("SELECT COUNT(*) AS cnt FROM deals WHERE workspace_id = ? AND assigned_to = ?", [$workspaceId, $userId]),
            'active_targets' => $activeTargetCount,
            'at_risk_targets' => $atRiskTargetCount,
            'target_summary' => $targetSummary,
        ];
    }

    private function buildTaskGapContext(int $userId, int $workspaceId): array
    {
        try {
            $tasks = Database::query(
                "SELECT t.id, t.title, t.status, t.priority, t.due_date,
                        COUNT(st.id) AS subtask_total,
                        SUM(CASE WHEN st.completed = 1 THEN 1 ELSE 0 END) AS subtask_completed
                 FROM tasks t
                 LEFT JOIN task_subtasks st ON st.task_id = t.id
                 WHERE t.workspace_id = ?
                   AND t.assigned_to = ?
                   AND t.status NOT IN ('completed', 'cancelled')
                 GROUP BY t.id, t.title, t.status, t.priority, t.due_date
                 HAVING COUNT(st.id) > 0 AND SUM(CASE WHEN st.completed = 1 THEN 1 ELSE 0 END) < COUNT(st.id)
                 ORDER BY
                    CASE t.priority
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        ELSE 4
                    END ASC,
                    CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END ASC,
                    t.due_date ASC,
                    t.id ASC
                 LIMIT 5",
                [$workspaceId, $userId]
            );
        } catch (\Throwable $e) {
            return [
                'tasks_with_gaps' => 0,
                'open_gap_subtasks' => 0,
                'top_gap_tasks' => [],
            ];
        }

        $topGapTasks = [];
        $openGapSubtasks = 0;
        foreach ($tasks as $task) {
            $taskId = (int) ($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }

            $missingSubtasks = Database::query(
                "SELECT id, title, description, `order`
                 FROM task_subtasks
                 WHERE task_id = ?
                   AND completed = 0
                 ORDER BY `order` ASC, id ASC
                 LIMIT 3",
                [$taskId]
            );

            $subtaskTotal = (int) ($task['subtask_total'] ?? 0);
            $subtaskCompleted = (int) ($task['subtask_completed'] ?? 0);
            $remainingCount = max(0, $subtaskTotal - $subtaskCompleted);
            $openGapSubtasks += $remainingCount;

            $topGapTasks[] = [
                'task_id' => $taskId,
                'title' => (string) ($task['title'] ?? ''),
                'priority' => (string) ($task['priority'] ?? 'medium'),
                'status' => (string) ($task['status'] ?? 'pending'),
                'due_date' => (string) ($task['due_date'] ?? ''),
                'subtask_total' => $subtaskTotal,
                'subtask_completed' => $subtaskCompleted,
                'subtask_remaining' => $remainingCount,
                'missing_subtasks' => array_map(static function (array $subtask): array {
                    return [
                        'id' => (int) ($subtask['id'] ?? 0),
                        'title' => (string) ($subtask['title'] ?? ''),
                        'description' => (string) ($subtask['description'] ?? ''),
                    ];
                }, $missingSubtasks),
            ];
        }

        return [
            'tasks_with_gaps' => count($topGapTasks),
            'open_gap_subtasks' => $openGapSubtasks,
            'top_gap_tasks' => $topGapTasks,
        ];
    }

    private function buildRecentExecutionState(int $userId, int $workspaceId): array
    {
        return [
            'completed_tasks_last_7d' => $this->countQuery(
                "SELECT COUNT(*) AS cnt
                 FROM tasks
                 WHERE workspace_id = ?
                   AND assigned_to = ?
                   AND status = 'completed'
                   AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                [$workspaceId, $userId]
            ),
            'activities_last_7d' => $this->countQuery(
                "SELECT COUNT(*) AS cnt
                 FROM activities
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                [$workspaceId, $userId]
            ),
            'guidance_runs_last_7d' => $this->countQuery(
                $this->columnExists('ai_guidance_runs', 'workspace_id')
                    ? "SELECT COUNT(*) AS cnt
                       FROM ai_guidance_runs
                       WHERE workspace_id = ?
                         AND user_id = ?
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                    : "SELECT 0 AS cnt",
                $this->columnExists('ai_guidance_runs', 'workspace_id') ? [$workspaceId, $userId] : []
            ),
        ];
    }

    private function buildAIPreferences(int $userId, int $workspaceId): array
    {
        $coachEnabled = false;
        $personalBriefReady = false;
        $coachRecommendationsReady = false;
        try {
            $setup = new AICoachWorkspaceSetupService();
            $coachEnabled = $setup->isWorkspaceEnabled($workspaceId);
            $briefGate = $setup->getDashboardBriefGate($workspaceId, $userId);
            $personalBriefReady = !empty($briefGate['personal_brief_ready']);
            $coachRecommendationsReady = !empty($briefGate['recommendations_ready']);
        } catch (\Throwable $e) {
            $coachEnabled = false;
            $personalBriefReady = false;
            $coachRecommendationsReady = false;
        }

        return [
            'coach_enabled' => $coachEnabled && $coachRecommendationsReady,
            'coach_workspace_enabled' => $coachEnabled,
            'coach_personal_brief_ready' => $personalBriefReady,
            'coach_recommendations_ready' => $coachRecommendationsReady,
            'effective_guidance_mode' => $this->preferences->getEffectiveAIGuidanceMode($userId),
            'mode_lock' => (string) $this->preferences->getAIModeLock($userId),
            'context_strictness' => (string) $this->preferences->getAIContextStrictness($userId),
            'advice_min_confidence' => (float) ($this->preferences->getAIAdviceMinConfidence($userId) ?? 0.88),
            'action_min_confidence' => (float) ($this->preferences->getAIActionMinConfidence($userId) ?? 0.92),
            'goal_relevance_min_score' => (float) ($this->preferences->getAIGoalRelevanceMinScore($userId) ?? 0.70),
            'missing_context_behavior' => (string) $this->preferences->getAIMissingContextBehavior($userId),
            'auto_task_completion_enabled' => $this->preferences->isAIAutoTaskCompletionEnabled($userId),
            'auto_task_completion_min_confidence' => (float) ($this->preferences->getAIAutoTaskCompletionMinConfidence($userId) ?? 0.95),
            'hours_per_week_sales' => (string) ($this->preferences->getHoursPerWeekSales($userId) ?? ''),
        ];
    }

    private function buildMarketplaceModuleContext(int $workspaceId, int $userId): array
    {
        try {
            $installer = new WorkspaceSkillInstallService();
            $moduleContext = $installer->buildContextForWorkspace($workspaceId, $userId);
            $out = [
                'installed_keys' => (array) ($moduleContext['installed_keys'] ?? []),
                'skill_keys' => (array) ($moduleContext['skill_keys'] ?? []),
                'plugin_keys' => (array) ($moduleContext['plugin_keys'] ?? []),
                'readiness' => (array) ($moduleContext['readiness'] ?? []),
            ];

            if (Authorization::isSuperAdmin(Auth::user())) {
                $out['superadmin_analytics'] = (new WorkspaceMarketplacePerformanceService())->buildWorkspaceSummary($workspaceId, 30);
            }

            return $out;
        } catch (\Throwable $e) {
            return [
                'installed_keys' => [],
                'skill_keys' => [],
                'plugin_keys' => [],
                'readiness' => [],
            ];
        }
    }

    private function buildDisplayName(array $user): string
    {
        $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
        if ($name !== '') {
            return $name;
        }

        return (string) ($user['email'] ?? 'User');
    }

    private function buildSummary(array $user, array $featureAccess, array $workload, array $taskGapContext, array $ownership, array $aiPreferences): string
    {
        $role = (string) ($user['role'] ?? 'user');
        $workloadSummary = sprintf(
            '%d open tasks, %d overdue, %d owned open deals',
            (int) ($workload['open_tasks'] ?? 0),
            (int) ($workload['overdue_tasks'] ?? 0),
            (int) ($workload['owned_open_deals'] ?? 0)
        );

        $scopeSummary = sprintf(
            '%d owned contacts, %d active targets',
            (int) ($ownership['contacts'] ?? 0),
            (int) ($ownership['active_targets'] ?? 0)
        );

        $accessSummary = implode(', ', array_slice((array) ($featureAccess['editable_surfaces'] ?? []), 0, 5));
        if ($accessSummary === '') {
            $accessSummary = 'base surfaces only';
        }

        $gapSummary = '';
        if (!empty($taskGapContext['tasks_with_gaps'])) {
            $topGapTask = (array) (($taskGapContext['top_gap_tasks'][0] ?? []) ?: []);
            $topGapTitle = trim((string) ($topGapTask['title'] ?? ''));
            $remaining = (int) ($topGapTask['subtask_remaining'] ?? 0);
            $gapSummary = sprintf(
                ' Task gaps: %d tasks with %d open subtasks. Top gap: %s (%d remaining subtasks).',
                (int) ($taskGapContext['tasks_with_gaps'] ?? 0),
                (int) ($taskGapContext['open_gap_subtasks'] ?? 0),
                $topGapTitle !== '' ? $topGapTitle : 'pending task',
                $remaining
            );
        }

        return sprintf(
            'Logged-in user context for %s: %s. Scope: %s. Effective mode %s with %s context strictness. Feature access: %s.%s',
            $role,
            $workloadSummary,
            $scopeSummary,
            (string) ($aiPreferences['effective_guidance_mode'] ?? ''),
            (string) ($aiPreferences['context_strictness'] ?? 'strict'),
            $accessSummary,
            $gapSummary
        );
    }

    private function countQuery(string $sql, array $params = []): int
    {
        try {
            $row = Database::queryOne($sql, $params);
            return (int) ($row['cnt'] ?? $row['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
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
