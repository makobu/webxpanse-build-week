<?php
/**
 * Task completion review API
 *
 * POST JSON/form:
 * - action: evaluate | complete | dismiss
 * - task_id
 * - decision (for complete): complete_anyway | complete_recommended
 * - override_reason (optional)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\TaskCompletionReviewService;
use CRM\Services\TaskAssignmentAccessService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$taskId = (int) ($input['task_id'] ?? $input['id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'task_id is required']);
    exit;
}

$task = (new \CRM\Modules\Tasks())->getById($taskId);
if (!$task) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Task not found']);
    exit;
}

$user = Auth::user() ?: [];
$userId = (int) ($user['id'] ?? 0);
$canAccess = Authorization::can('tasks.view_all', $user)
    || (int) ($task['assigned_to'] ?? 0) === $userId
    || (int) ($task['created_by'] ?? 0) === $userId;
if (!Authorization::can('tasks.read', $user) || !$canAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}
if (!(new TaskAssignmentAccessService())->canWriteTasks($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$service = new TaskCompletionReviewService();
$action = (string) ($input['action'] ?? 'evaluate');

try {
    if ($action === 'dismiss') {
        $service->clearReview($taskId);
        echo json_encode(['success' => true, 'dismissed' => true]);
        exit;
    }

    if ($action === 'complete') {
        $decision = (string) ($input['decision'] ?? 'complete_anyway');
        $reason = (string) ($input['override_reason'] ?? '');
        $result = $service->completeFromReview($taskId, $userId, $decision, $reason);
        if (empty($result['success'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Unable to complete task', 'review' => $result['review'] ?? null]);
            exit;
        }
        echo json_encode(['success' => true, 'completed' => true, 'review' => $result['review'] ?? null]);
        exit;
    }

    $review = $service->evaluateCompletion($task, $userId);
    $service->persistReview($taskId, $review, $userId);

    echo json_encode([
        'success' => true,
        'review' => $review,
        'requires_review' => $service->requiresReview($task),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
