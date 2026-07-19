<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\FinanceCashAccountService;
use CRM\Services\FinanceExpenseService;
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

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$user = Auth::user();
$userId = (int) (($user['id'] ?? 0));
(new WorkspaceFinanceGateService())->assertRuntimeReady($workspaceId, $user, true);
$service = new FinanceExpenseService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        Authorization::requirePermission('finance.view', true);
        echo json_encode([
            'cash_accounts' => (new FinanceCashAccountService())->listAccounts($workspaceId),
            'categories' => $service->listCategories($workspaceId),
            'expenses' => $service->listExpenses($workspaceId, $_GET, 100, 0),
            'recurring_expenses' => $service->listRecurringExpenses($workspaceId),
        ]);
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

    $action = (string) ($input['action'] ?? 'save_expense');
    if ($action === 'delete_expense') {
        $service->deleteExpense($workspaceId, (int) ($input['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'save_recurring') {
        $id = $service->saveRecurringExpense($workspaceId, $input, $userId);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }
    if ($action === 'delete_recurring') {
        $service->deleteRecurringExpense($workspaceId, (int) ($input['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'save_category') {
        $id = $service->saveCategory($workspaceId, (string) ($input['name'] ?? ''), (string) ($input['category_type'] ?? 'other'), $userId, [
            'statement_group' => (string) ($input['statement_group'] ?? ''),
            'cash_flow_group' => (string) ($input['cash_flow_group'] ?? ''),
            'profit_treatment' => (string) ($input['profit_treatment'] ?? 'normal'),
            'is_deductible' => !empty($input['is_deductible']) ? 1 : 0,
            'is_cogs' => !empty($input['is_cogs']) ? 1 : 0,
        ]);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    $id = $service->saveExpense($workspaceId, $input, $userId);
    echo json_encode(['success' => true, 'id' => $id]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
