<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\PluginRuntimeRegistryService;
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
    echo json_encode(['success' => false, 'error' => 'Workspace plugin capabilities are not available.']);
    exit;
}

$type = trim((string) ($_GET['type'] ?? ''));
$registry = new PluginRuntimeRegistryService();
$capabilities = $type !== ''
    ? $registry->capabilitiesByType($workspaceId, $type, (int) ($user['id'] ?? 0), true)
    : $registry->capabilitiesForWorkspace($workspaceId, (int) ($user['id'] ?? 0), true);

echo json_encode(['success' => true, 'capabilities' => $capabilities]);
