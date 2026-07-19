<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use CRM\Database;
use CRM\Modules\Tasks;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$taskId = (int) ($_GET['task_id'] ?? $_GET['id'] ?? 0);
if ($taskId <= 0) {
    mobileJson(['error' => 'Task id is required.'], 422);
}

$task = (new Tasks())->getById($taskId);
if (!$task || !mobileCanAccessTask($task, $userId, $user)) {
    mobileJson(['error' => 'Task not found or not accessible.'], 404);
}

mobileJson([
    'success' => true,
    'data' => mobileAiTaskInsights($taskId),
]);
