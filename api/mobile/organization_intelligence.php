<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Services\HRAnalyticsService;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\ContactStageHistoryService;
use CRM\Services\OrganizationIntelligenceContextService;
use CRM\Services\OrganizationIntelligenceConversationService;
use CRM\Services\OrganizationIntelligenceSnapshotService;
use CRM\Services\OrganizationIntelligenceEngineService;
use CRM\Services\OrganizationIntelligenceMutationContextService;
use CRM\Services\OrganizationIntelligenceMonitoringService;

function mobileOrganizationRecentCoachingTasks(int $workspaceId): array
{
    if ($workspaceId <= 0 || !Database::tableExists('tasks')) {
        return [];
    }

    $where = ['workspace_id = ?'];
    $params = [$workspaceId];
    if (Database::columnExists('tasks', 'metadata_json')) {
        $where[] = "(metadata_json LIKE '%coaching_task%' OR metadata_json LIKE '%action_plan_task%')";
    } elseif (Database::columnExists('tasks', 'source_surface')) {
        $where[] = "source_surface = 'hr_analytics'";
    } else {
        $where[] = "title LIKE '%coaching%'";
    }

    return array_map(
        'mobileTaskSummary',
        Database::query(
            "SELECT *
             FROM tasks
             WHERE " . implode(' AND ', $where) . "
             ORDER BY created_at DESC, id DESC
             LIMIT 8",
            $params
        )
    );
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);

mobileRequireAnyPermission($user, ['hr.analytics.view'], 'Organization Intelligence is not available to this user.');
mobileRequireOrganizationRuntime($workspaceId, $user);

$service = new HRAnalyticsService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'DELETE') {
        $input = mobileRequestBody();
        $conversationId = (int) ($input['conversation_id'] ?? $_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            mobileJson(['error' => 'Conversation ID is required.'], 422);
        }
        $owner = Database::queryOne(
            "SELECT id FROM organization_intelligence_conversations
             WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'organization_intelligence' LIMIT 1",
            [$conversationId, $workspaceId, (int) ($auth['user_id'] ?? 0)]
        );
        if (!$owner) {
            mobileJson(['error' => 'Conversation is outside your private scope.', 'error_code' => 'conversation_scope_rejected'], 403);
        }
        $conversationService = new OrganizationIntelligenceConversationService();
        $conversationService->clear($conversationId, $workspaceId, (int) ($auth['user_id'] ?? 0));
        $replacement = $conversationService->currentOrCreate($workspaceId, (int) ($auth['user_id'] ?? 0));
        mobileJson(['success' => true, 'data' => [
            'cleared_conversation_id' => $conversationId,
            'conversation_id' => (int) ($replacement['id'] ?? 0),
            'messages' => [],
        ]]);
    }

    if ($method === 'POST') {
        $input = mobileRequestBody();
        mobileRequireAnyPermission($user, ['hr.analytics.manage'], 'Organization Intelligence task creation is not available to this user.');
        try {
            if (!empty($input['mutation_context_token'])) {
                (new OrganizationIntelligenceMutationContextService())->verify(
                    (string) $input['mutation_context_token'],
                    $workspaceId,
                    (int) ($auth['user_id'] ?? 0)
                );
            } else {
                $cachedAt = strtotime((string) ($input['cached_generated_at'] ?? ''));
                $snapshotStatus = (string) ((new OrganizationIntelligenceSnapshotService())->diagnostics($workspaceId)['status'] ?? 'missing');
                if (!$cachedAt || $cachedAt < time() - OrganizationIntelligenceMutationContextService::TTL_SECONDS
                    || in_array($snapshotStatus, ['missing', 'stale'], true)) {
                    throw new RuntimeException('Legacy mutation context is stale.');
                }
            }
        } catch (Throwable $e) {
            mobileJson(['error' => 'Refresh stale Organization Intelligence data before creating tasks.', 'error_code' => 'stale_data_mutation_blocked'], 409);
        }
        $action = (string) ($input['action'] ?? '');
        $actorUserId = (int) ($auth['user_id'] ?? 0);

        if ($action === 'create_coaching_task') {
            $taskId = $service->createCoachingTask(
                $actorUserId,
                (int) ($input['target_user_id'] ?? $input['user_id'] ?? 0),
                trim((string) ($input['title'] ?? 'Coaching follow-up')),
                trim((string) ($input['description'] ?? '')),
                trim((string) ($input['due_date'] ?? '')) ?: null,
                strtolower(trim((string) ($input['priority'] ?? 'high'))) ?: 'high'
            );
            mobileJson([
                'success' => true,
                'data' => [
                    'task_id' => $taskId,
                    'route' => '/tasks/' . $taskId,
                ],
            ]);
        }

        if ($action === 'create_action_plan_tasks') {
            $candidates = $input['task_candidates'] ?? $input['candidates'] ?? [];
            if (is_array($candidates)) {
                $candidates = array_map(static function ($candidate) {
                    if (!is_array($candidate)) {
                        return $candidate;
                    }
                    return [
                        'key' => (string) ($candidate['candidate_key'] ?? $candidate['key'] ?? ''),
                        'title' => (string) ($candidate['title'] ?? ''),
                        'description' => (string) ($candidate['detail'] ?? $candidate['description'] ?? ''),
                        'priority' => (string) ($candidate['priority'] ?? $candidate['severity'] ?? 'medium'),
                        'due_date_offset' => (int) ($candidate['due_date_offset'] ?? 7),
                        'source_scope' => (string) ($candidate['source_scope'] ?? 'Organization'),
                        'source_risk_type' => (string) ($candidate['source_risk_type'] ?? 'organization_action_plan'),
                    ];
                }, $candidates);
            }
            $result = $service->createActionPlanTasks(
                $actorUserId,
                is_array($candidates) ? $candidates : [],
                !empty($input['assignee_user_id']) ? (int) $input['assignee_user_id'] : null
            );
            mobileJson([
                'success' => true,
                'data' => $result + [
                    'routes' => array_map(static fn($id): string => '/tasks/' . (int) $id, (array) ($result['task_ids'] ?? [])),
                ],
            ]);
        }

        mobileJson(['error' => 'Unsupported action.'], 422);
    }

    if ($method !== 'GET') {
        mobileJson(['error' => 'Method not allowed.'], 405);
    }

    $timeframe = trim((string) ($_GET['timeframe'] ?? 'month'));
    $roleFamily = trim((string) ($_GET['role'] ?? ''));
    if (!in_array($timeframe, OrganizationIntelligenceContextService::TIMEFRAMES, true)
        || !in_array($roleFamily, OrganizationIntelligenceContextService::ROLE_FAMILIES, true)) {
        mobileJson(['error' => 'Invalid Organization Intelligence filter.'], 422);
    }
    $requestedUserId = !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null;
    $scopedUserId = (new AnalyticsWorkspaceService())->ensureScopedUserId($requestedUserId, $workspaceId);
    if ($requestedUserId !== null && $scopedUserId === null) {
        mobileJson(['error' => 'Person is outside the active workspace scope.'], 422);
    }
    $filters = [
        'timeframe' => $timeframe,
        'role' => $roleFamily,
        'department' => trim((string) ($_GET['department'] ?? '')),
        'user_id' => $scopedUserId,
    ];
    $dashboard = (new OrganizationIntelligenceEngineService($service))->buildDashboard($filters, false, [
        'capability' => 'mobile_dashboard',
        'swot' => false,
        'manager_tips' => false,
        'strategic_pointers' => false,
    ]);
    $actionPlan = $service->buildActionPlanDraftFromDashboard(
        $dashboard,
        !empty($_GET['target_user_id']) ? (int) $_GET['target_user_id'] : null,
        trim((string) ($_GET['department'] ?? '')) ?: null,
        false
    );
    $payload = mobileOrganizationIntelligencePayload($dashboard, $actionPlan);
    $payload['recent_coaching_tasks'] = mobileOrganizationRecentCoachingTasks($workspaceId);
    $trendWindow = (array) ($dashboard['operating_trends']['window'] ?? []);
    $stageHistory = new ContactStageHistoryService();
    $stageStart = (string) ($trendWindow['start'] ?? date('Y-m-d 00:00:00', strtotime('-30 days')));
    $stageEnd = (string) ($trendWindow['end'] ?? date('Y-m-d 23:59:59'));
    $payload['pipeline'] = [
        'stage_distribution' => $stageHistory->distribution($workspaceId, $stageStart, $stageEnd, $filters['user_id'], (string) $filters['role'], (string) $filters['department']),
        'conversion_journey' => $stageHistory->journey($workspaceId, $stageStart, $stageEnd, $filters['user_id'], (string) $filters['role'], (string) $filters['department']),
    ];
    $payload['snapshot_metadata'] = (new OrganizationIntelligenceSnapshotService())->diagnostics($workspaceId);
    $mutationContext = (new OrganizationIntelligenceMutationContextService())->issue(
        $workspaceId,
        (int) ($auth['user_id'] ?? 0),
        !empty($payload['snapshot_metadata']['latest_snapshot_id']) ? (int) $payload['snapshot_metadata']['latest_snapshot_id'] : null,
        2
    );
    $payload['mutation_context_token'] = $mutationContext['token'];
    $payload['mutation_context_expires_at'] = $mutationContext['expires_at'];
    $conversationService = new OrganizationIntelligenceConversationService();
    $conversation = $conversationService->currentOrCreate($workspaceId, (int) ($auth['user_id'] ?? 0));
    $payload['clarity'] = [
        'conversation_id' => (int) ($conversation['id'] ?? 0),
        'messages' => $conversationService->history((int) ($conversation['id'] ?? 0), $workspaceId, (int) ($auth['user_id'] ?? 0), 20),
        'retention_days' => 90,
        'surface' => 'organization_intelligence',
    ];
    $payload['filter_options'] = [
        'timeframes' => OrganizationIntelligenceContextService::TIMEFRAMES,
        'role_families' => OrganizationIntelligenceContextService::ROLE_FAMILIES,
        'departments' => array_map(static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
        ], Database::query('SELECT id, name, slug FROM departments WHERE workspace_id = ? AND is_active = 1 ORDER BY name', [$workspaceId])),
        'people' => array_map(static function (array $row): array {
            $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
            return ['id' => (int) ($row['id'] ?? 0), 'name' => $name !== '' ? $name : (string) ($row['email'] ?? 'User')];
        }, (new AnalyticsWorkspaceService())->listActiveWorkspaceUsers($workspaceId)),
    ];

    (new OrganizationIntelligenceMonitoringService())->recordSignal(
        $workspaceId,
        'mobile_contract',
        '',
        (int) ($auth['user_id'] ?? 0)
    );

    mobileJson([
        'success' => true,
        'data' => $payload,
    ]);
} catch (Throwable $e) {
    (new OrganizationIntelligenceMonitoringService())->recordSignal(
        $workspaceId,
        'serializer',
        'oi_serializer_contract',
        (int) ($auth['user_id'] ?? 0)
    );
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'organization_intelligence_failed',
    ], str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422);
}
