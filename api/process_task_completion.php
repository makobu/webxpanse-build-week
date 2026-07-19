<?php
/**
 * Process Task Completion (Web endpoint)
 *
 * Call this from a live cron monitor or hosting control panel to scan tasks
 * for completion evidence without requiring a browser session.
 *
 * Requires authentication (session + CSRF) or PROCESS_QUEUE_SECRET token.
 */

ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
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
use CRM\Security;
use CRM\Session;
use CRM\Services\TaskCompletionScanQueueService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$queueSecret = $_ENV['PROCESS_QUEUE_SECRET'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$authenticated = (!empty($queueSecret) && hash_equals($queueSecret, (string) $token))
    || (Auth::check() && Security::validateCSRF($csrfToken));
$secretAuthenticated = !empty($queueSecret) && hash_equals($queueSecret, (string) $token);

if (!$authenticated) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$service = new TaskCompletionScanQueueService();
$taskId = isset($_GET['task_id']) ? (int) $_GET['task_id'] : (isset($input['task_id']) ? (int) $input['task_id'] : null);
$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (isset($input['user_id']) ? (int) $input['user_id'] : 0);
$workspaceId = isset($_GET['workspace_id']) ? (int) $_GET['workspace_id'] : (isset($input['workspace_id']) ? (int) $input['workspace_id'] : 0);
$batchLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : (isset($input['limit']) ? (int) $input['limit'] : 25);
$force = !empty($input['force']) || !empty($_GET['force']);

$summary = [
    'queued_users' => 0,
    'processed_jobs' => 0,
    'completed_jobs' => 0,
    'failed_jobs' => 0,
    'completed_tasks' => 0,
    'results' => [],
];

try {
    if ($taskId !== null && $taskId > 0) {
        $task = Database::queryOne("SELECT workspace_id, assigned_to, created_by FROM tasks WHERE id = ?", [$taskId]);
        if (!$secretAuthenticated) {
            $actor = Auth::user() ?: [];
            $actorId = (int) ($actor['id'] ?? 0);
            $canAccess = Authorization::can('tasks.view_all', $actor)
                || $actorId === (int) ($task['assigned_to'] ?? 0)
                || $actorId === (int) ($task['created_by'] ?? 0);
            if (!$canAccess || !Authorization::can('tasks.write', $actor) || (int) ($task['workspace_id'] ?? 0) !== (int) (WorkspaceContext::currentWorkspaceId() ?? 0)) {
                throw new RuntimeException('Task scan is not allowed for this user.');
            }
        }
        $scanUserId = (int) (($task['assigned_to'] ?? 0) ?: ($task['created_by'] ?? 0));
        $taskWorkspaceId = (int) ($task['workspace_id'] ?? 0);
        if ($scanUserId <= 0) {
            throw new RuntimeException('Task owner could not be resolved.');
        }
        if ($taskWorkspaceId <= 0) {
            throw new RuntimeException('Task workspace could not be resolved.');
        }
        $service->enqueueForUser($scanUserId, Auth::check() ? (int) (Auth::user()['id'] ?? 0) : null, 'manual', true, $taskWorkspaceId);
        $summary['queued_users'] = 1;
    } else {
        if ($userId > 0) {
            if (!$secretAuthenticated && ($userId !== (int) (Auth::user()['id'] ?? 0) || ($workspaceId > 0 && $workspaceId !== (int) (WorkspaceContext::currentWorkspaceId() ?? 0)))) {
                throw new RuntimeException('Task scan is not allowed for this user.');
            }
            $queued = $service->enqueueForUser($userId, Auth::check() ? (int) (Auth::user()['id'] ?? 0) : null, 'manual', $force, $workspaceId > 0 ? $workspaceId : null);
            $summary['queued_users'] = in_array((string) ($queued['status'] ?? ''), ['queued', 'running'], true) ? 1 : 0;
        } else {
            $queueSummary = $service->enqueueForActiveUsers('cron');
            $summary['queued_users'] = (int) ($queueSummary['queued_users'] ?? 0);
        }
    }

    $processSummary = $service->processQueuedScans($batchLimit);
    $summary['processed_jobs'] = (int) ($processSummary['processed_jobs'] ?? 0);
    $summary['completed_jobs'] = (int) ($processSummary['completed_jobs'] ?? 0);
    $summary['failed_jobs'] = (int) ($processSummary['failed_jobs'] ?? 0);
    $summary['completed_tasks'] = (int) ($processSummary['completed_tasks'] ?? 0);
    $summary['results'] = $processSummary['results'] ?? [];

    ob_clean();
    echo json_encode([
        'success' => true,
        'summary' => $summary,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Task completion processing failed',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
