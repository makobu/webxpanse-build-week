<?php
/**
 * Task Subtasks API
 * POST: Update subtask fields (currently supports toggling completion)
 *
 * Body:
 * - csrf_token
 * - subtask_id (int)
 * - completed (bool/int)
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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Modules\Tasks;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    Auth::jsonAuthError('', 401, 'Unauthorized');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canViewAllTasks = Authorization::can('tasks.view_all', $user);
if (!Authorization::can('tasks.write', $user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Accept JSON or form body
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
    Auth::jsonAuthError('csrf_invalid', 403, 'Invalid security token');
    exit;
}

$subtaskId = (int) ($input['subtask_id'] ?? $input['subtaskId'] ?? $input['id'] ?? 0);
$taskId = (int) ($input['task_id'] ?? $input['taskId'] ?? 0);
$subtaskTitle = trim((string) ($input['subtask_title'] ?? $input['subtaskTitle'] ?? ''));

if ($subtaskId <= 0 && $taskId > 0 && $subtaskTitle !== '') {
    $lookup = Database::queryOne(
        "SELECT id
         FROM task_subtasks
         WHERE task_id = ? AND title = ?
         ORDER BY id ASC
         LIMIT 1",
        [$taskId, $subtaskTitle]
    );
    $subtaskId = (int) ($lookup['id'] ?? 0);
}

if ($subtaskId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'subtask_id is required']);
    exit;
}

// Load subtask + parent task for authorization
$row = Database::queryOne(
    "SELECT st.id as subtask_id, st.task_id,
            t.assigned_to, t.created_by
     FROM task_subtasks st
     INNER JOIN tasks t ON t.id = st.task_id
     WHERE t.workspace_id = ? AND st.id = ?",
    [(int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0), $subtaskId]
);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Subtask not found']);
    exit;
}

$canAccess = $canViewAllTasks
    || ((int) ($row['assigned_to'] ?? 0) === $userId)
    || ((int) ($row['created_by'] ?? 0) === $userId);

if (!$canAccess) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$completed = !empty($input['completed']) ? 1 : 0;

try {
    $tasks = new Tasks();
    if (!$tasks->updateSubtask($subtaskId, ['completed' => $completed, 'actor_user_id' => $userId])) {
        http_response_code(422);
        echo json_encode(['error' => 'Subtask was not updated']);
        exit;
    }
    echo json_encode(['success' => true, 'subtask_id' => $subtaskId, 'completed' => (bool) $completed]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update subtask']);
}

