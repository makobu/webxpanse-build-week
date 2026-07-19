<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\FounderFinanceService;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('finance.view', true);

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
(new WorkspaceFinanceGateService())->assertRuntimeReady($workspaceId, $user, true);

try {
    echo json_encode((new FounderFinanceService())->dashboard(
        $workspaceId,
        $userId,
        $_GET['date_from'] ?? null,
        $_GET['date_to'] ?? null
    ));
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load finance summary']);
}
