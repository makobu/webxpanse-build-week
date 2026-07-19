<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;
use CRM\Services\AITaskCompletionService;
use CRM\Services\TaskCompletionCoordinator;
use CRM\Services\TaskCompletionReviewService;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}
if (!Authorization::can('tasks.write', $user)) {
    mobileJson(['error' => 'You do not have permission to complete tasks.'], 403);
}

$input = mobileRequestBody();
$taskId = (int) ($input['task_id'] ?? $input['id'] ?? 0);
$action = trim((string) ($input['action'] ?? 'evaluate'));
$tasks = new Tasks();
$task = $tasks->getById($taskId);
if ($taskId <= 0 || !$task || !mobileCanAccessTask($task, $userId, $user)) {
    mobileJson(['error' => 'Task not found or not accessible.'], 404);
}

$coordinator = new TaskCompletionCoordinator($tasks);
try {
    if ($action === 'evaluate') {
        (new AITaskCompletionService())->scanForCompletionEvidence($userId, $taskId, [
            'actor_user_id' => $userId,
            'enforce_task_access' => true,
            'evaluate_manual_task' => true,
            'workspace_id' => (int) ($task['workspace_id'] ?? 0),
        ]);
    } elseif (in_array($action, ['complete_recommended', 'complete_anyway'], true)) {
        $result = (new TaskCompletionReviewService())->completeFromReview(
            $taskId,
            $userId,
            $action,
            trim((string) ($input['override_reason'] ?? ''))
        );
        if (empty($result['success'])) {
            mobileJson(['error' => 'Task could not be completed.', 'review' => $result['review'] ?? null], 422);
        }
    } elseif ($action === 'reopen') {
        if (!$coordinator->reopen($taskId, [
            'actor_user_id' => $userId,
            'reason' => trim((string) ($input['reason'] ?? '')),
        ])) {
            mobileJson(['error' => 'Task could not be reopened.'], 422);
        }
    } elseif ($action === 'set_mode') {
        if (!$coordinator->setMode($taskId, (string) ($input['mode'] ?? 'manual'), $userId)) {
            mobileJson(['error' => 'Task completion mode could not be updated.'], 422);
        }
    } else {
        mobileJson(['error' => 'Unsupported completion action.'], 422);
    }

    $fresh = $tasks->getById($taskId) ?? [];
    mobileJson(['success' => true, 'data' => mobileTaskDetail($fresh)]);
} catch (Throwable $e) {
    error_log('Mobile task completion failed: ' . $e->getMessage());
    mobileJson(['error' => 'Task completion could not be processed.'], 422);
}
