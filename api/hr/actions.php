<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\HRAnalyticsService;

$service = new HRAnalyticsService();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Authorization::requirePermission('hr.analytics.manage', true);
    hrAnalyticsRequireRuntimeReady('actions.draft');
    echo json_encode([
        'success' => true,
        'draft' => $service->buildActionPlanDraft(
            hrAnalyticsApiFilters($_GET),
            !empty($_GET['target_user_id']) ? (int) $_GET['target_user_id'] : null,
            !empty($_GET['department']) ? (string) $_GET['department'] : null
        ),
    ]);
    exit;
}

Authorization::requirePermission('hr.analytics.manage', true);
hrAnalyticsRequireRuntimeReady('actions');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$action = trim((string) ($input['action'] ?? ''));
$actorUserId = (int) (Auth::user()['id'] ?? 0);

try {
    if ($action === 'create_coaching_task') {
        $taskId = $service->createCoachingTask(
            $actorUserId,
            (int) ($input['target_user_id'] ?? 0),
            trim((string) ($input['title'] ?? 'Coaching follow-up')),
            trim((string) ($input['description'] ?? '')),
            !empty($input['due_date']) ? (string) $input['due_date'] : null,
            trim((string) ($input['priority'] ?? 'high'))
        );
        echo json_encode(['success' => true, 'task_id' => $taskId]);
        exit;
    }

    if ($action === 'assign_access_role') {
        $service->assignAccessRole($actorUserId, (int) ($input['target_user_id'] ?? 0), (int) ($input['role_id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'create_action_plan_tasks') {
        $result = $service->createActionPlanTasks(
            $actorUserId,
            is_array($input['task_candidates'] ?? null) ? $input['task_candidates'] : [],
            !empty($input['target_user_id']) ? (int) $input['target_user_id'] : null
        );
        echo json_encode(['success' => true] + $result);
        exit;
    }

    if ($action === 'draft_action_plan') {
        echo json_encode([
            'success' => true,
            'draft' => $service->buildActionPlanDraft(
                hrAnalyticsApiFilters($input),
                !empty($input['target_user_id']) ? (int) $input['target_user_id'] : null,
                !empty($input['department']) ? (string) $input['department'] : null
            ),
        ]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unsupported action']);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
