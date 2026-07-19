<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceTargetAutomationSettingsService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
header('Content-Type: application/json');
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'error_code' => 'unauthorized']);
    exit;
}
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$role = (string) (Session::get('active_workspace_role') ?? 'viewer');
$canAdmin = in_array($role, ['owner','admin','superadmin'], true) || \CRM\Authorization::isSuperAdmin($user);
$service = new WorkspaceTargetAutomationSettingsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['settings' => $service->get($workspaceId), 'can_manage' => $canAdmin]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'error_code' => 'method_not_allowed']);
    exit;
}
$input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
if (!$canAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Workspace administrator permission is required.', 'error_code' => 'forbidden']);
    exit;
}
if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token', 'error_code' => 'invalid_csrf']);
    exit;
}
try {
    echo json_encode(['success' => true, 'settings' => $service->save($workspaceId, $input, $userId)]);
} catch (\Throwable $e) {
    http_response_code($e instanceof \InvalidArgumentException ? 422 : 500);
    echo json_encode(['error' => $e->getMessage(), 'error_code' => 'settings_failed']);
}

