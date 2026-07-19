<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$tasks = new Tasks();
$canViewAllTasks = mobileCanViewAllTasks($user);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Authorization::can('tasks.write', $user)) {
        mobileJson(['error' => 'You do not have permission to update tasks.'], 403);
    }
    $input = mobileRequestBody();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        mobileJson(['error' => 'Task id is required.'], 422);
    }
    $task = $tasks->getById($id);
    if (!$task || !mobileCanAccessTask($task, $userId, $user)) {
        mobileJson(['error' => 'Task not found or not accessible.'], 404);
    }

    $updates = [];
    if (isset($input['status'])) {
        $updates['status'] = (string) $input['status'];
        if ((string) $input['status'] === 'completed') {
            $updates['_completion_source'] = 'compatibility';
            $updates['_completion_explanation'] = 'Task completed explicitly from the mobile status control.';
        }
    }
    if (isset($input['priority'])) {
        $updates['priority'] = (string) $input['priority'];
    }
    if (isset($input['due_date'])) {
        $updates['due_date'] = $input['due_date'];
    }
    $updates['actor_user_id'] = $userId;

    if ($updates === ['actor_user_id' => $userId]) {
        mobileJson(['error' => 'No supported task updates provided.'], 422);
    }

    $tasks->update($id, $updates);
    mobileJson(['success' => true, 'data' => mobileTaskDetail($tasks->getById($id) ?? [])]);
}

if (!Authorization::can('tasks.read', $user)) {
    mobileJson(['error' => 'You do not have permission to view tasks.'], 403);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $task = $tasks->getById($id);
    if (!$task || !mobileCanAccessTask($task, $userId, $user)) {
        mobileJson(['error' => 'Task not found or not accessible.'], 404);
    }
    mobileJson(['success' => true, 'data' => mobileTaskDetail($task)]);
}

$ownerScope = mobileResolveVisibilityScope($user, 'tasks.view_all', $_GET['owner_scope'] ?? null);
$filters = [];
if ($ownerScope !== 'all') {
    $filters['assigned_to'] = $userId;
}
foreach (['status', 'priority', 'search'] as $key) {
    if (!empty($_GET[$key])) {
        $filters[$key] = (string) $_GET[$key];
    }
}
if (!empty($_GET['overdue'])) {
    $filters['overdue'] = true;
}
if (!empty($_GET['due_today'])) {
    $filters['due_today'] = true;
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

mobileJson([
    'success' => true,
    'data' => [
        'items' => array_map('mobileTaskSummary', $tasks->getAll($filters, $limit, $offset)),
        'total' => $tasks->getCount($filters),
        'owner_scope' => $ownerScope,
    ],
]);
