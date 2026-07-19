<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$user = Auth::user() ?: [];
if ($workspaceId <= 0 || !(Authorization::isSuperAdmin($user) || Authorization::can('tasks.read', $user) || Authorization::can('workspace.skills.view', $user))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Task plugin sources are not available.']);
    exit;
}

if (!Database::columnExists('tasks', 'source_surface')) {
    echo json_encode(['success' => true, 'sources' => []]);
    exit;
}

$rows = Database::query(
    "SELECT source_skill_key, source_plugin_key, source_surface, source_capability_key, COUNT(*) AS task_count
     FROM tasks
     WHERE workspace_id = ?
       AND (
            source_skill_key IS NOT NULL
            OR source_plugin_key IS NOT NULL
            OR source_surface IS NOT NULL
            OR source_capability_key IS NOT NULL
       )
     GROUP BY source_skill_key, source_plugin_key, source_surface, source_capability_key
     ORDER BY task_count DESC, source_surface ASC
     LIMIT 100",
    [$workspaceId]
);

echo json_encode(['success' => true, 'sources' => $rows]);
