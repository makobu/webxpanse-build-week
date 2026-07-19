<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\PluginRuntimeEventService;
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
$canView = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user);
if ($workspaceId <= 0 || !$canView) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Workspace plugin runtime events are not available.']);
    exit;
}

$filters = ['workspace_id' => $workspaceId];
foreach (['skill_key', 'capability_key', 'event_type', 'status', 'entity_type'] as $field) {
    if (isset($_GET[$field]) && trim((string) $_GET[$field]) !== '') {
        $filters[$field] = trim((string) $_GET[$field]);
    }
}

$service = new PluginRuntimeEventService();
echo json_encode([
    'success' => true,
    'events' => $service->recent($filters, (int) ($_GET['limit'] ?? 50)),
    'summary' => $service->summary($filters),
]);
