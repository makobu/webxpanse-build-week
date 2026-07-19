<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Currencies;
use CRM\Security;
use CRM\Services\FinanceCashAccountService;
use CRM\Services\FinanceLedgerService;
use CRM\Services\FinanceOpeningSetupService;
use CRM\Services\FinanceReportDocumentService;
use CRM\Services\FinanceStatementService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

Authorization::requirePermission('finance.view');

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
(new WorkspaceFinanceGateService())->assertRuntimeReady($workspaceId, $user, false);

$dateFrom = (string) ($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = (string) ($_GET['date_to'] ?? date('Y-m-t'));
$cashAccountId = !empty($_GET['cash_account_id']) ? (int) $_GET['cash_account_id'] : null;
$reportService = new FinanceReportDocumentService(new Currencies());
$type = $reportService->normalizeType((string) ($_GET['type'] ?? 'profit'));

try {
    $ledgerService = new FinanceLedgerService();
    $cashAccountService = new FinanceCashAccountService();
    $statementService = new FinanceStatementService(null, null, $ledgerService, null, $cashAccountService);
    $statements = $statementService->generate($workspaceId, $userId, $dateFrom, $dateTo);
    $currency = strtoupper((string) (($statements['currency'] ?? '') ?: 'USD'));
    $bankStatement = $statementService->generateBankStatement($workspaceId, $dateFrom, $dateTo, $cashAccountId);
    $opening = (array) ((new FinanceOpeningSetupService(null, $cashAccountService, null, $ledgerService))->setupFormData($workspaceId)['summary'] ?? []);
    $context = $reportService->brandingContext(
        $workspaceId,
        WorkspaceContext::currentWorkspace() ?? [],
        (string) (($statements['period']['date_from'] ?? null) ?: $dateFrom),
        (string) (($statements['period']['date_to'] ?? null) ?: $dateTo),
        $currency
    );

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . Security::sanitizeHeaderFilename($reportService->filename($type, $context)) . '"');
    $reportService->outputPdf($type, $statements, $bankStatement, $opening, $context, 'I');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report PDF could not be generated.';
}
