<?php
/**
 * Create Task API (supports subtasks for AI Coach and checklist tasks)
 * POST: title, description (optional), subtasks (optional array of strings or {title, description})
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Tasks;
use CRM\Modules\AITaskAutomationService;
use CRM\Services\AIAdviceFollowThroughService;
use CRM\Services\ClarityOperatorControlsService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

// Accept JSON or form body
$input = $_POST;
if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

$csrfToken = $input['csrf_token'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$title = trim($input['title'] ?? '');
if ($title === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Title is required']);
    exit;
}

$description = trim($input['description'] ?? '');
$subtasksInput = $input['subtasks'] ?? [];
if (!is_array($subtasksInput)) {
    $subtasksInput = [];
}

$targetId = !empty($input['target_id']) ? (int) $input['target_id'] : 0;
if ($targetId > 0) {
    // Validate ownership (or allow admin)
    $target = Database::queryOne(
        "SELECT id, user_id FROM targets WHERE workspace_id = ? AND id = ?",
        [(int) (WorkspaceContext::currentWorkspaceId() ?? 0), $targetId]
    );
    $canViewAllTasks = Authorization::can('tasks.view_all', $user);
    if (!$target || (!$canViewAllTasks && (int) ($target['user_id'] ?? 0) !== $userId)) {
        $targetId = 0;
    }
}

$subtasks = [];
foreach ($subtasksInput as $st) {
    if (is_string($st) && $st !== '') {
        $subtasks[] = ['title' => $st, 'description' => ''];
    } elseif (is_array($st) && !empty(trim($st['title'] ?? ''))) {
        $subtasks[] = ['title' => trim($st['title']), 'description' => trim($st['description'] ?? '')];
    }
}

try {
    $tasks = new Tasks();
    $automation = new AITaskAutomationService();
    $rawMetadata = $input['metadata_json'] ?? null;
    $metadata = $rawMetadata;
    if (is_string($metadata)) {
        $metadata = json_decode($metadata, true);
    }
    $metadata = is_array($metadata) ? $metadata : [];

    $clarityControls = new ClarityOperatorControlsService();
    if ($clarityControls->isClarityFeedbackTask($input, $metadata) && !$clarityControls->enabled()) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Clarity operator controls are available only in the default workspace.',
            'blocked_reason' => ClarityOperatorControlsService::BLOCKED_REASON,
        ]);
        exit;
    }

    $taskData = [
        'title' => $title,
        'description' => $description,
        'created_by' => $userId,
        'assigned_to' => !empty($input['assigned_to']) ? (int) $input['assigned_to'] : $userId,
        'status' => $input['status'] ?? 'pending',
        'priority' => $input['priority'] ?? 'medium',
        'due_date' => !empty($input['due_date']) ? $input['due_date'] : null,
        'contact_id' => !empty($input['contact_id']) ? (int) $input['contact_id'] : null,
        'target_id' => $targetId > 0 ? $targetId : null,
        'metadata_json' => $rawMetadata,
        'source_skill_key' => $input['source_skill_key'] ?? ($metadata['marketplace_skill_key'] ?? null),
        'source_plugin_key' => $input['source_plugin_key'] ?? ($metadata['marketplace_plugin_key'] ?? null),
        'source_surface' => $input['source_surface'] ?? ($metadata['source_surface'] ?? null),
        'source_capability_key' => $input['source_capability_key'] ?? ($metadata['source_capability_key'] ?? null),
        'source_run_id' => $input['source_run_id'] ?? (
            $metadata['guidance_run_id']
            ?? $metadata['assistant_run_id']
            ?? $metadata['commercial_run_id']
            ?? null
        ),
    ];

    if ((string) ($metadata['source_surface'] ?? '') === 'ai_coach') {
        $prepared = $automation->prepareCoachTaskCreationPayload($taskData, $subtasks);
        $taskData = $prepared['task_data'];
        $subtasks = $prepared['subtasks'];
        $title = (string) ($taskData['title'] ?? $title);
        $description = (string) ($taskData['description'] ?? $description);
        $metadata = is_array($taskData['metadata_json'] ?? null) ? $taskData['metadata_json'] : [];
    }

    if (!empty($metadata['source_surface']) && (string) $metadata['source_surface'] === 'ai_coach') {
        $existingTask = $automation->findOpenTaskForRecommendation($userId, [
            'title' => $title,
            'target_id' => $targetId,
            'recommendation_key' => $metadata['recommendation_key'] ?? null,
        ]);
        if ($existingTask) {
            $existingTaskId = (int) ($existingTask['id'] ?? 0);
            echo json_encode([
                'success' => true,
                'task_id' => $existingTaskId,
                'existing_task' => true,
                'link' => (function_exists('publicUrl') ? publicUrl('task_view.php') : '/task_view.php') . '?id=' . $existingTaskId,
            ]);
            exit;
        }
    }

    if (!empty($subtasks)) {
        $taskId = $tasks->createWithSubtasks($taskData, $subtasks);
    } else {
        $taskId = $tasks->create($taskData);
    }

    if (!empty($metadata['source_surface']) && (!empty($metadata['guidance_run_id']) || !empty($metadata['assistant_run_id']) || !empty($metadata['commercial_run_id']) || !empty($metadata['approval_id']))) {
        try {
            $followThrough = new AIAdviceFollowThroughService();
            $followThrough->linkManualAction([
                'task_id' => (int) $taskId,
                'user_id' => $userId,
                'surface' => (string) $metadata['source_surface'],
                'guidance_run_id' => !empty($metadata['guidance_run_id']) ? (int) $metadata['guidance_run_id'] : null,
                'assistant_run_id' => !empty($metadata['assistant_run_id']) ? (int) $metadata['assistant_run_id'] : null,
                'commercial_run_id' => !empty($metadata['commercial_run_id']) ? (int) $metadata['commercial_run_id'] : null,
                'approval_id' => !empty($metadata['approval_id']) ? (int) $metadata['approval_id'] : null,
                'linked_contact_id' => !empty($metadata['linked_contact_id']) ? (int) $metadata['linked_contact_id'] : null,
                'linked_deal_id' => !empty($metadata['linked_deal_id']) ? (int) $metadata['linked_deal_id'] : null,
                'linked_invoice_id' => !empty($metadata['linked_invoice_id']) ? (int) $metadata['linked_invoice_id'] : null,
                'recommendation_key' => $metadata['recommendation_key'] ?? null,
                'message_hash' => $metadata['message_hash'] ?? null,
                'source_recommendation_type' => $metadata['source_recommendation_type'] ?? null,
                'notes' => 'Task created from linked AI follow-through.',
            ]);
        } catch (\Throwable $linkError) {
            error_log('AI follow-through linkage failed after task create: ' . $linkError->getMessage());
        }
    }

    if ((string) ($metadata['source_surface'] ?? '') === 'ai_coach' && !empty($metadata['marketplace_skill_key'])) {
        try {
            (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(
                (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
                $userId,
                (string) $metadata['marketplace_skill_key'],
                'coach',
                'task_created',
                [
                    'metadata' => [
                        'source' => 'api_tasks_create',
                        'task_id' => (int) $taskId,
                        'recommendation_key' => $metadata['recommendation_key'] ?? null,
                        'marketplace_setup_url' => $metadata['marketplace_setup_url'] ?? null,
                        'marketplace_next_setup_step' => is_array($metadata['marketplace_next_setup_step'] ?? null) ? $metadata['marketplace_next_setup_step'] : null,
                        'marketplace_setup_progress' => is_array($metadata['marketplace_setup_progress'] ?? null) ? $metadata['marketplace_setup_progress'] : null,
                        'marketplace_activation_bundle' => is_array($metadata['marketplace_activation_bundle'] ?? null) ? $metadata['marketplace_activation_bundle'] : null,
                    ],
                ]
            );
        } catch (\Throwable $eventError) {
            error_log('Marketplace recommendation task-created analytics failed: ' . $eventError->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'link' => (function_exists('publicUrl') ? publicUrl('task_view.php') : '/task_view.php') . '?id=' . $taskId,
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
