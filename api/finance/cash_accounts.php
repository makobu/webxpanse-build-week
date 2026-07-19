<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\FinanceCashAccountService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$user = Auth::user();
$userId = (int) (($user['id'] ?? 0));
(new WorkspaceFinanceGateService())->assertRuntimeReady($workspaceId, $user, true);
$service = new FinanceCashAccountService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Authorization::requirePermission('finance.view', true);
        echo json_encode(['cash_accounts' => $service->listAccounts($workspaceId, true)]);
        exit;
    }

    Authorization::requirePermission('finance.manage', true);
    $input = $_POST;
    if (empty($input) && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    }
    if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }

    if ((string) ($input['action'] ?? '') === 'deactivate_account') {
        $service->deactivateAccount($workspaceId, (int) ($input['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }

    $id = $service->saveAccount($workspaceId, $input, $userId);
    echo json_encode(['success' => true, 'id' => $id]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
