<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}
if (!Authorization::can('tasks.write', $user)) {
    mobileJson(['error' => 'You do not have permission to update task checklists.'], 403);
}

$input = mobileRequestBody();
$taskId = (int) ($input['task_id'] ?? 0);
$subtaskId = (int) ($input['subtask_id'] ?? 0);
$tasks = new Tasks();
$task = $tasks->getById($taskId);
if ($taskId <= 0 || $subtaskId <= 0 || !$task || !mobileCanAccessTask($task, $userId, $user)) {
    mobileJson(['error' => 'Task or checklist item not found.'], 404);
}
$subtaskOwner = Database::queryOne(
    'SELECT st.task_id FROM task_subtasks st INNER JOIN tasks t ON t.id = st.task_id WHERE t.workspace_id = ? AND st.id = ? LIMIT 1',
    [(int) ($task['workspace_id'] ?? 0), $subtaskId]
);
if ((int) ($subtaskOwner['task_id'] ?? 0) !== $taskId) {
    mobileJson(['error' => 'Task or checklist item not found.'], 404);
}

try {
    if (!$tasks->updateSubtask($subtaskId, ['completed' => !empty($input['completed']) ? 1 : 0, 'actor_user_id' => $userId])) {
        mobileJson(['error' => 'Checklist item was not updated.'], 422);
    }
    mobileJson(['success' => true, 'data' => mobileTaskDetail($tasks->getById($taskId) ?? [])]);
} catch (Throwable $e) {
    error_log('Mobile subtask update failed: ' . $e->getMessage());
    mobileJson(['error' => 'Checklist item could not be updated.'], 422);
}
