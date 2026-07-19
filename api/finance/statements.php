<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\FinanceStatementService;
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
$userId = (int) (($user['id'] ?? 0));
(new WorkspaceFinanceGateService())->assertRuntimeReady($workspaceId, $user, true);

try {
    $statements = (new FinanceStatementService())->generate(
        $workspaceId,
        $userId,
        $_GET['date_from'] ?? null,
        $_GET['date_to'] ?? null
    );

    $type = (string) ($_GET['type'] ?? 'all');
    if ($type === 'pnl') {
        echo json_encode($statements['profit_and_loss']);
    } elseif ($type === 'cash_flow') {
        echo json_encode($statements['cash_flow']);
    } elseif ($type === 'bank_statement') {
        $accountId = !empty($_GET['cash_account_id']) ? (int) $_GET['cash_account_id'] : null;
        echo json_encode((new FinanceStatementService())->generateBankStatement(
            $workspaceId,
            $_GET['date_from'] ?? null,
            $_GET['date_to'] ?? null,
            $accountId
        ));
    } elseif ($type === 'balance_sheet') {
        echo json_encode($statements['working_balance_sheet']);
    } elseif ($type === 'budget_variance') {
        echo json_encode($statements['budget_variance']);
    } else {
        echo json_encode($statements);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load finance statements']);
}
