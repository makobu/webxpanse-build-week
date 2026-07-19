<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\TargetPluginIntegrationService;
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
if ($workspaceId <= 0 || !(Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.view', $user))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Target metric providers are not available.']);
    exit;
}

echo json_encode([
    'success' => true,
    'providers' => (new TargetPluginIntegrationService())->providersForWorkspace($workspaceId),
]);
