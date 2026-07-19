<?php
require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Currencies;
use CRM\Security;
use CRM\Services\FinanceCashAccountService;
use CRM\Services\FinanceExpenseService;
use CRM\Services\FinanceIncomeService;
use CRM\Services\FinanceLedgerService;
use CRM\Services\FinanceOpeningSetupService;
use CRM\Services\FinanceOwnerEquityService;
use CRM\Services\FinanceReportDocumentService;
use CRM\Services\FinanceStatementService;
use CRM\Services\FounderFinanceService;
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

$canManageFinance = Authorization::can('finance.manage', $user);
$workspaceScope = null;
$cashAccountService = new FinanceCashAccountService($workspaceScope);
$incomeService = new FinanceIncomeService($workspaceScope, $cashAccountService);
$expenseService = new FinanceExpenseService($workspaceScope, $cashAccountService);
$ledgerService = new FinanceLedgerService($workspaceScope);
$statementService = new FinanceStatementService($workspaceScope, null, $ledgerService, $incomeService, $cashAccountService);
$ownerEquityService = new FinanceOwnerEquityService($workspaceScope);
$finance = new FounderFinanceService($expenseService, null, $workspaceScope, $ledgerService, $statementService, $cashAccountService, $incomeService);
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$defaultCurrencyCode = strtoupper((string) (($defaultCurrency['code'] ?? '') ?: 'USD'));

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$tabs = ['today', 'in', 'out', 'capital', 'bank', 'reports', 'setup'];
$tabAliases = [
    'overview' => 'today',
    'expenses' => 'out',
    'budget' => 'setup',
    'recurring' => 'setup',
    'unit_economics' => 'reports',
    'pnl' => 'reports',
    'cash_flow' => 'reports',
    'balance_sheet' => 'capital',
];
$requestedTab = (string) ($_GET['tab'] ?? 'today');
$activeTab = $tabAliases[$requestedTab] ?? $requestedTab;
$activeTab = in_array($activeTab, $tabs, true) ? $activeTab : 'today';
$success = null;
$error = null;

function financeMoney(float $amount, string $currency = 'USD', ?Currencies $currenciesModule = null): string
{
    $currencyCode = strtoupper(trim($currency)) ?: 'USD';
    if ($currenciesModule === null && isset($GLOBALS['currenciesModule']) && $GLOBALS['currenciesModule'] instanceof Currencies) {
        $currenciesModule = $GLOBALS['currenciesModule'];
    }
    if ($currenciesModule !== null) {
        try {
            if ($currenciesModule->getByCode($currencyCode)) {
                return $currenciesModule->formatAmount($amount, $currencyCode);
            }
        } catch (Throwable $e) {
        }
    }

    return $currencyCode . ' ' . number_format($amount, 2);
}

function financeTabUrl(string $tab, string $dateFrom, string $dateTo, array $params = []): string
{
    return 'finance.php?tab=' . urlencode($tab) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo)
        . ($params === [] ? '' : '&' . http_build_query($params));
}

function financeApiUrl(string $endpoint, string $dateFrom, string $dateTo, array $params = []): string
{
    return '../api/finance/' . $endpoint . '?date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo)
        . ($params === [] ? '' : '&' . http_build_query($params));
}

function financeReportPdfUrl(string $type, string $dateFrom, string $dateTo, ?int $cashAccountId = null): string
{
    $params = [
        'type' => $type,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
    if ($cashAccountId !== null) {
        $params['cash_account_id'] = $cashAccountId;
    }

    return 'finance_report_pdf.php?' . http_build_query($params);
}

function financeTooltipBubble(string $text): string
{
    return '<span class="finance-tooltip-bubble" role="tooltip">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

function financeHelp(string $text): string
{
    return '<span class="finance-tooltip finance-help" tabindex="0" aria-label="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '">?' . financeTooltipBubble($text) . '</span>';
}

function financeAccountOptions(array $accounts, mixed $selected): string
{
    $html = '';
    foreach ($accounts as $account) {
        if (empty($account['is_active'])) {
            continue;
        }
        $id = (string) ($account['id'] ?? '');
        $html .= '<option value="' . htmlspecialchars($id) . '" ' . ($id === (string) $selected ? 'selected' : '') . '>'
            . htmlspecialchars((string) ($account['name'] ?? 'Account')) . '</option>';
    }
    return $html;
}

function financeIncomeCategoryOptions(array $categories, mixed $selected): string
{
    $html = '<option value="">Pick</option>';
    foreach ($categories as $category) {
        if (isset($category['is_active']) && empty($category['is_active'])) {
            continue;
        }
        $id = (string) ($category['id'] ?? '');
        $html .= '<option value="' . htmlspecialchars($id) . '" ' . ($id === (string) $selected ? 'selected' : '') . '>'
            . htmlspecialchars((string) ($category['name'] ?? 'Label')) . '</option>';
    }
    return $html;
}

function financeExpenseCategoryOptions(array $categories, mixed $selected): string
{
    $html = '<option value="">Pick</option>';
    foreach ($categories as $category) {
        if (isset($category['is_active']) && empty($category['is_active'])) {
            continue;
        }
        $id = (string) ($category['id'] ?? '');
        $html .= '<option value="' . htmlspecialchars($id) . '" ' . ($id === (string) $selected ? 'selected' : '') . '>'
            . htmlspecialchars((string) ($category['name'] ?? 'Label')) . '</option>';
    }
    return $html;
}

function financeLedgerTypeLabel(string $type, array $typeMeta = []): string
{
    $labels = [
        'founder_capital' => 'Owner money in',
        'equity_funding' => 'Investor money in',
        'loan_received' => 'Loan received',
        'loan_repayment' => 'Loan paid',
        'asset_purchase' => 'Asset bought',
        'opening_balance' => 'Opening correction',
        'manual_adjustment' => 'Correction',
    ];

    return $labels[$type] ?? (string) ($typeMeta['label'] ?? ucwords(str_replace('_', ' ', $type)));
}

function financeReceiptUploadPath(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('5MB max.');
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? 'receipt'), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
        throw new RuntimeException('PDF or image only.');
    }
    $dir = __DIR__ . '/../uploads/finance_receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $safeName = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target = $dir . '/' . $safeName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        throw new RuntimeException('Upload failed.');
    }
    return 'uploads/finance_receipts/' . $safeName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageFinance) {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid token.');
        }
        $postedTab = (string) ($_POST['tab'] ?? $activeTab);
        $activeTab = in_array($postedTab, $tabs, true) ? $postedTab : $activeTab;
        $action = (string) ($_POST['finance_action'] ?? '');

        if ($action === 'save_income') {
            $payload = $_POST;
            if (trim((string) ($payload['description'] ?? '')) === '') {
                $payload['description'] = trim((string) ($payload['source'] ?? '')) ?: 'Money in';
            }
            $incomeService->saveEntry($workspaceId, $payload, $userId);
            $success = 'Saved.';
            $activeTab = 'in';
        } elseif ($action === 'delete_income') {
            $incomeService->deleteEntry($workspaceId, (int) ($_POST['id'] ?? 0));
            $success = 'Deleted.';
            $activeTab = 'in';
        } elseif ($action === 'save_expense') {
            $receiptPath = financeReceiptUploadPath($_FILES['receipt_file'] ?? []);
            $payload = $_POST;
            if (trim((string) ($payload['description'] ?? '')) === '') {
                $payload['description'] = trim((string) ($payload['vendor'] ?? '')) ?: 'Money out';
            }
            if ($receiptPath !== '') {
                $payload['receipt_path'] = $receiptPath;
            }
            $expenseService->saveExpense($workspaceId, $payload, $userId);
            $success = 'Saved.';
            $activeTab = 'out';
        } elseif ($action === 'delete_expense') {
            $expenseService->deleteExpense($workspaceId, (int) ($_POST['id'] ?? 0));
            $success = 'Deleted.';
            $activeTab = 'out';
        } elseif ($action === 'save_ledger_transaction') {
            $transactionId = (int) ($_POST['transaction_id'] ?? 0);
            if ($transactionId > 0) {
                $ledgerService->replaceGuidedTransaction($workspaceId, $transactionId, $_POST, $userId);
            } else {
                $ledgerService->saveGuidedTransaction($workspaceId, $_POST, $userId);
            }
            $success = 'Saved.';
            $activeTab = 'capital';
        } elseif ($action === 'delete_ledger_transaction') {
            $ledgerService->deleteGuidedTransaction($workspaceId, (int) ($_POST['transaction_id'] ?? 0));
            $success = 'Deleted.';
            $activeTab = 'capital';
        } elseif ($action === 'save_cash_account') {
            $cashAccountService->saveAccount($workspaceId, $_POST, $userId);
            $success = 'Saved.';
            $activeTab = 'setup';
        } elseif ($action === 'deactivate_account') {
            $cashAccountService->deactivateAccount($workspaceId, (int) ($_POST['id'] ?? 0));
            $success = 'Removed.';
            $activeTab = 'setup';
        } elseif ($action === 'save_category') {
            $expenseService->saveCategory($workspaceId, (string) ($_POST['name'] ?? ''), (string) ($_POST['category_type'] ?? 'other'), $userId, [
                'statement_group' => (string) ($_POST['statement_group'] ?? ''),
                'cash_flow_group' => (string) ($_POST['cash_flow_group'] ?? ''),
                'profit_treatment' => (string) ($_POST['profit_treatment'] ?? 'normal'),
                'is_deductible' => !empty($_POST['is_deductible']) ? 1 : 0,
                'is_cogs' => !empty($_POST['is_cogs']) ? 1 : 0,
            ]);
            $success = 'Saved.';
            $activeTab = 'setup';
        } elseif ($action === 'save_income_category') {
            $incomeService->saveCategory($workspaceId, (string) ($_POST['name'] ?? ''), (string) ($_POST['income_type'] ?? 'sale'), $userId);
            $success = 'Saved.';
            $activeTab = 'setup';
        } elseif ($action === 'save_recurring') {
            $payload = $_POST;
            if (trim((string) ($payload['description'] ?? '')) === '') {
                $payload['description'] = trim((string) ($payload['vendor'] ?? '')) ?: 'Recurring';
            }
            $expenseService->saveRecurringExpense($workspaceId, $payload, $userId);
            $success = 'Saved.';
            $activeTab = 'setup';
        } elseif ($action === 'delete_recurring') {
            $expenseService->deleteRecurringExpense($workspaceId, (int) ($_POST['id'] ?? 0));
            $success = 'Deleted.';
            $activeTab = 'setup';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$editExpense = !empty($_GET['edit_expense']) ? $expenseService->getExpense($workspaceId, (int) $_GET['edit_expense']) : null;
$editIncome = !empty($_GET['edit_income']) ? $incomeService->getEntry($workspaceId, (int) $_GET['edit_income']) : null;
$editLedger = !empty($_GET['edit_ledger']) ? $ledgerService->getTransaction($workspaceId, (int) $_GET['edit_ledger']) : null;
if ($editExpense) {
    $activeTab = 'out';
}
if ($editIncome) {
    $activeTab = 'in';
}
if ($editLedger) {
    $activeTab = 'capital';
}

$dashboard = $finance->dashboard($workspaceId, $userId, $dateFrom, $dateTo);
$summary = (array) ($dashboard['summary'] ?? []);
$statements = (array) ($dashboard['statements'] ?? []);
$pnl = (array) ($statements['profit_and_loss'] ?? []);
$cashFlow = (array) ($statements['cash_flow'] ?? []);
$balanceSheet = (array) ($statements['working_balance_sheet'] ?? []);
$reportQuality = (array) ($statements['report_quality'] ?? []);
$categories = (array) ($dashboard['categories'] ?? []);
$incomeCategories = (array) ($dashboard['income_categories'] ?? []);
$cashAccounts = (array) ($dashboard['cash_accounts'] ?? []);
$vendors = (array) ($dashboard['vendors'] ?? []);
$expenses = (array) ($dashboard['expenses'] ?? []);
$incomeEntries = (array) ($dashboard['income_entries'] ?? []);
$recurring = (array) ($dashboard['recurring_expenses'] ?? []);
$ledgerTransactions = (array) ($dashboard['ledger_transactions'] ?? []);
$financeOwnerOptions = $ownerEquityService->activeOwners($workspaceId);
$currency = strtoupper((string) (($summary['currency'] ?? '') ?: $defaultCurrencyCode));
$csrf = Security::getCsrfToken();
$selectedCashAccountId = !empty($_GET['cash_account_id']) ? (int) $_GET['cash_account_id'] : null;
$bankStatement = $statementService->generateBankStatement($workspaceId, $dateFrom, $dateTo, $selectedCashAccountId);
$bankRows = (array) ($bankStatement['rows'] ?? []);
$latestRows = array_slice(array_reverse($bankRows), 0, 8);
$pnlSummary = (array) ($pnl['summary'] ?? []);
$cashFlowSections = (array) ($cashFlow['sections'] ?? []);
$bankSummary = (array) ($bankStatement['summary'] ?? []);
$financeOpeningSetup = (new FinanceOpeningSetupService($workspaceScope, $cashAccountService, $ownerEquityService, $ledgerService))->setupFormData($workspaceId);
$financeOpeningSummary = (array) ($financeOpeningSetup['summary'] ?? []);
$pnlDrilldowns = (array) ($pnl['drilldowns'] ?? []);
$paidInvoices = (array) ($pnlDrilldowns['revenue'] ?? []);
$defaultCashAccountName = 'Main Bank';
foreach ($cashAccounts as $account) {
    if (!empty($account['is_default'])) {
        $defaultCashAccountName = (string) ($account['name'] ?? $defaultCashAccountName);
        break;
    }
}
if ($defaultCashAccountName === 'Main Bank' && isset($cashAccounts[0])) {
    $defaultCashAccountName = (string) ($cashAccounts[0]['name'] ?? $defaultCashAccountName);
}
$expenseCategoryTreatments = [];
foreach ($categories as $category) {
    $expenseCategoryTreatments[(string) ($category['id'] ?? '')] = (string) ($category['profit_treatment'] ?? 'normal');
}
$latestInRows = [];
foreach ($paidInvoices as $invoice) {
    $latestInRows[] = [
        'date' => substr((string) ($invoice['activity_date'] ?? ''), 0, 10),
        'account' => $defaultCashAccountName,
        'from' => (string) ($invoice['invoice_number'] ?? 'Invoice'),
        'label' => 'Sales',
        'amount' => (float) ($invoice['amount'] ?? 0),
        'currency' => (string) ($invoice['currency'] ?? $currency),
    ];
}
foreach ($incomeEntries as $entry) {
    $latestInRows[] = [
        'date' => (string) ($entry['income_date'] ?? ''),
        'account' => (string) ($entry['cash_account_name'] ?? ''),
        'from' => (string) ($entry['source'] ?? ''),
        'label' => (string) ($entry['income_category_name'] ?? ucwords(str_replace('_', ' ', (string) ($entry['income_type'] ?? 'sale')))),
        'amount' => (float) ($entry['amount'] ?? 0),
        'currency' => (string) ($entry['currency'] ?? $currency),
    ];
}
usort($latestInRows, static fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
$latestInRows = array_slice($latestInRows, 0, 5);
$latestOutRows = array_slice($expenses, 0, 5);
$openingBank = (float) ($financeOpeningSummary['bank_total'] ?? 0);
$openingAssets = (float) ($financeOpeningSummary['assets_total'] ?? 0);
$openingReceivables = (float) ($financeOpeningSummary['receivables_total'] ?? 0);
$openingLiabilities = (float) ($financeOpeningSummary['liabilities_total'] ?? 0);
$openingBusinessValue = (float) ($financeOpeningSummary['business_value'] ?? ($openingBank + $openingAssets + $openingReceivables - $openingLiabilities));
$openingTotalAssets = $openingBank + $openingAssets + $openingReceivables;
$openingTotalClaims = $openingLiabilities + $openingBusinessValue;
$financeReportDocumentService = new FinanceReportDocumentService($currenciesModule);
$financeReportContext = $financeReportDocumentService->brandingContext(
    $workspaceId,
    WorkspaceContext::currentWorkspace() ?? [],
    $dateFrom,
    $dateTo,
    $currency
);
$financeReportDocuments = [];
$financeReportExportUrls = [];
foreach ($financeReportDocumentService->reportTypes() as $reportType => $reportMeta) {
    $financeReportDocuments[$reportType] = $financeReportDocumentService->renderHtml($reportType, $statements, $bankStatement, $financeOpeningSummary, $financeReportContext);
    $financeReportExportUrls[$reportType] = financeReportPdfUrl($reportType, $dateFrom, $dateTo, $selectedCashAccountId);
}
$ledgerOpeningDetails = (array) ($editLedger['opening_details'] ?? []);
$ledgerTypes = $ledgerService->transactionTypes();
$ledgerForm = [
    'id' => (int) ($editLedger['id'] ?? 0),
    'transaction_type' => (string) ($editLedger['transaction_type'] ?? 'founder_capital'),
    'transaction_date' => (string) ($editLedger['transaction_date'] ?? date('Y-m-d')),
    'cash_account_id' => (string) ($editLedger['cash_account_id'] ?? ''),
    'amount' => (string) ($editLedger['amount'] ?? ''),
    'currency' => (string) ($editLedger['currency'] ?? $currency),
    'counterparty' => (string) ($editLedger['counterparty'] ?? ''),
    'owner_user_id' => (string) ($editLedger['owner_user_id'] ?? ''),
    'memo' => (string) ($editLedger['memo'] ?? ''),
    'opening_cash' => (string) ($ledgerOpeningDetails['opening_cash'] ?? ''),
    'opening_receivables' => (string) ($ledgerOpeningDetails['opening_receivables'] ?? ''),
    'opening_payables' => (string) ($ledgerOpeningDetails['opening_payables'] ?? ''),
    'opening_loan_balance' => (string) ($ledgerOpeningDetails['opening_loan_balance'] ?? ''),
    'opening_assets' => (string) ($ledgerOpeningDetails['opening_assets'] ?? ''),
    'opening_equity' => (string) ($ledgerOpeningDetails['opening_equity'] ?? ''),
];

$incomeForm = [
    'id' => (int) ($editIncome['id'] ?? 0),
    'income_date' => (string) ($editIncome['income_date'] ?? date('Y-m-d')),
    'cash_account_id' => (string) ($editIncome['cash_account_id'] ?? ''),
    'income_type' => (string) ($editIncome['income_type'] ?? 'sale'),
    'income_category_id' => (string) ($editIncome['income_category_id'] ?? ''),
    'source' => (string) ($editIncome['source'] ?? ''),
    'description' => (string) ($editIncome['description'] ?? ''),
    'amount' => (string) ($editIncome['amount'] ?? ''),
    'currency' => (string) ($editIncome['currency'] ?? $currency),
    'notes' => (string) ($editIncome['notes'] ?? ''),
];

$expenseForm = [
    'id' => (int) ($editExpense['id'] ?? 0),
    'expense_date' => (string) ($editExpense['expense_date'] ?? date('Y-m-d')),
    'cash_account_id' => (string) ($editExpense['cash_account_id'] ?? ''),
    'category_id' => (string) ($editExpense['category_id'] ?? ''),
    'vendor' => (string) ($editExpense['vendor_name'] ?? $editExpense['vendor'] ?? ''),
    'description' => (string) ($editExpense['description'] ?? ''),
    'amount' => (string) ($editExpense['amount'] ?? ''),
    'currency' => (string) ($editExpense['currency'] ?? $currency),
    'payment_method' => (string) ($editExpense['payment_method'] ?? 'bank_transfer'),
    'status' => (string) ($editExpense['status'] ?? 'paid'),
    'notes' => (string) ($editExpense['notes'] ?? ''),
];

$statementGroups = ['cost_of_sales' => 'Cost', 'operating_expense' => 'Expense', 'payroll' => 'Payroll', 'tax' => 'Tax', 'asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity'];
$cashFlowGroups = ['operating' => 'Operating', 'investing' => 'Investing', 'financing' => 'Financing'];
$categoryTypes = ['operations' => 'Operations', 'marketing' => 'Marketing', 'payroll' => 'Payroll', 'software' => 'Software', 'inventory' => 'Inventory', 'taxes' => 'Taxes', 'other' => 'Other'];
$accountTypes = ['bank' => 'Bank', 'cash' => 'Cash', 'mobile_money' => 'Mobile', 'other' => 'Other'];
$tabLabels = ['today' => 'Today', 'in' => 'In', 'out' => 'Out', 'capital' => 'Capital', 'bank' => 'Bank', 'reports' => 'Reports', 'setup' => 'Setup'];

$pageTitle = 'Finance - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/premium-pages.css')); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/dashboard-premium.css')); ?>">
<style>
.finance-dashboard{background:#f5f5f5;min-height:auto;padding:1rem 0 3rem;color:var(--app-text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}.finance-shell{max-width:1320px;margin:0 auto;padding:0 1.25rem;display:grid;gap:.9rem}.finance-top{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.finance-title h1{margin:0;font-size:2rem;font-weight:550;line-height:1}.finance-title span{display:block;margin-top:.25rem;color:#64748b;font-size:.86rem}.finance-date{display:flex;gap:.42rem;align-items:center;flex-wrap:wrap}.finance-date input,.finance-field input,.finance-field select,.finance-field textarea{box-sizing:border-box;width:100%;border:1px solid #dbe3ee;border-radius:8px;background:#fff;color:#0f172a;font:inherit;font-size:.86rem;padding:.58rem .65rem;min-height:2.35rem}.finance-btn{display:inline-flex;align-items:center;justify-content:center;gap:.38rem;border:1px solid #dbe3ee;border-radius:8px;background:#fff;color:#0f172a;text-decoration:none;font-size:.84rem;font-weight:650;padding:.55rem .72rem;min-height:2.35rem;cursor:pointer}.finance-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}.finance-btn.danger{color:#b91c1c}.finance-tabs{display:flex;gap:.35rem;overflow-x:auto}.finance-tab{display:inline-flex;align-items:center;text-decoration:none;border:1px solid #dbe3ee;border-radius:999px;background:#fff;color:#64748b;padding:.42rem .72rem;font-size:.8rem;font-weight:650;white-space:nowrap}.finance-tab.is-active{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}.finance-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}.finance-card,.finance-panel{background:#fff;border:1px solid #dbe3ee;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0}.finance-card{padding:.9rem}.finance-card span{display:block;color:#64748b;font-size:.72rem;text-transform:uppercase;font-weight:700}.finance-card strong{display:block;margin-top:.38rem;font-size:1.15rem;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.finance-card.risk{background:#fffbeb;border-color:#fde68a}.finance-split{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.36fr);gap:.9rem;align-items:start}.finance-panel{padding:1rem}.finance-panel-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.75rem}.finance-panel h2{margin:0;font-size:1rem;font-weight:650}.finance-table-wrap{overflow-x:auto;border:1px solid #e2e8f0;border-radius:9px}.finance-table{width:100%;border-collapse:collapse;min-width:680px}.finance-table.is-small{min-width:420px}.finance-table th,.finance-table td{padding:.62rem .68rem;border-bottom:1px solid #edf2f7;text-align:left;vertical-align:top;font-size:.86rem}.finance-table th{background:#f8fafc;color:#64748b;font-size:.68rem;text-transform:uppercase;font-weight:750}.finance-table tr:last-child td{border-bottom:0}.finance-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.65rem}.finance-field{display:grid;gap:.32rem;color:#475569;font-size:.78rem;font-weight:700}.finance-field.wide{grid-column:1/-1}.finance-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}.finance-actions.end{margin-top:.75rem}.finance-msg{border-radius:8px;padding:.62rem .75rem;font-size:.84rem;font-weight:650}.finance-msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}.finance-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.finance-empty{padding:.8rem;color:#64748b;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;font-size:.86rem}.finance-row{display:flex;align-items:center;justify-content:space-between;gap:.7rem;padding:.55rem 0;border-bottom:1px solid #edf2f7;font-size:.88rem}.finance-row:last-child{border-bottom:0}.finance-pill{display:inline-flex;align-items:center;border:1px solid #dbe3ee;border-radius:999px;background:#f8fafc;color:#475569;padding:.28rem .52rem;font-size:.72rem;font-weight:700}.finance-tooltip{position:relative}.finance-help{display:inline-flex;align-items:center;justify-content:center;width:1rem;height:1rem;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.68rem;font-weight:800}.finance-tooltip-bubble{position:absolute;left:50%;bottom:calc(100% + .45rem);z-index:20;width:max-content;max-width:240px;transform:translateX(-50%);opacity:0;visibility:hidden;border:1px solid #1e293b;border-radius:8px;background:#0f172a;color:#fff;padding:.5rem .6rem;font-size:.75rem;font-weight:500;line-height:1.3;text-align:left}.finance-tooltip:hover .finance-tooltip-bubble,.finance-tooltip:focus .finance-tooltip-bubble{opacity:1;visibility:visible}.finance-report{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem}.finance-report .finance-table{min-width:0}.finance-muted{color:#64748b;font-size:.8rem}
@media(max-width:900px){.finance-grid,.finance-split,.finance-report{grid-template-columns:1fr}.finance-shell{padding:0 .85rem}.finance-date{width:100%}.finance-date input{min-width:0;flex:1}.finance-card strong{font-size:1rem}}
.finance-dashboard{background:#f6f7f9;padding:1rem 0 3rem;color:#0f172a}
.finance-shell{gap:1rem}
.finance-top{padding:.25rem 0}
.finance-title{display:flex;align-items:center;gap:.65rem;min-width:0}
.finance-title h1{font-size:1.55rem;letter-spacing:0;font-weight:700}
.finance-title span{display:none}
.finance-title .finance-pill{display:inline-flex}
.finance-date{margin-left:auto}
.finance-date input,.finance-field input,.finance-field select,.finance-field textarea{border-color:#cfd9e8;border-radius:8px;min-height:2.55rem;font-size:.92rem}
.finance-btn{border-color:#d7e0ec;border-radius:8px;min-height:2.45rem;font-size:.86rem;font-weight:700;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease,transform .15s ease}
.finance-btn:hover{background:#f8fafc;border-color:#b9c8da}
.finance-btn:focus-visible,.finance-tab:focus-visible,.finance-field input:focus,.finance-field select:focus,.finance-field textarea:focus,.finance-help:focus-visible{outline:3px solid rgba(37,99,235,.18);outline-offset:2px}
.finance-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}
.finance-btn.primary:hover{background:#1d4ed8}
.finance-btn.danger{background:#fff;color:#b42318;border-color:#fed7d7}
.finance-page-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
.finance-tabs{background:#fff;border:1px solid #dbe3ee;border-radius:8px;padding:.35rem;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.finance-tab{border:0;border-radius:7px;background:transparent;color:#475569;padding:.55rem .75rem}
.finance-tab.is-active{background:#eff6ff;color:#1d4ed8;box-shadow:inset 0 0 0 1px #bfdbfe}
.finance-kpi-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}
.finance-card,.finance-panel{border-radius:8px;border-color:#dbe3ee;box-shadow:0 1px 2px rgba(15,23,42,.05)}
.finance-card{padding:1rem}
.finance-card span{color:#64748b;font-size:.7rem;letter-spacing:.03em}
.finance-card strong{font-size:1.2rem}
.finance-card.risk{background:#fff7ed;border-color:#fed7aa}
.finance-panel{padding:1rem}
.finance-panel-head{min-height:2.45rem;margin-bottom:.85rem}
.finance-panel h2{font-size:1rem;font-weight:700}
.finance-panel-subhead{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin:1rem 0 .6rem}
.finance-panel-subhead h3{margin:0;font-size:.9rem;font-weight:700}
.finance-split{grid-template-columns:minmax(0,1fr) minmax(300px,.34fr)}
.finance-report{grid-template-columns:repeat(2,minmax(0,1fr))}
.finance-report-stack{display:grid;gap:.85rem}
.finance-report-tabs{display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.85rem}
.finance-report-tab{border:1px solid #dbe3ee;border-radius:999px;background:#fff;color:#475569;padding:.5rem .78rem;text-decoration:none;font-size:.8rem;font-weight:800;cursor:pointer}
.finance-report-tab.is-active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;box-shadow:inset 0 0 0 1px #bfdbfe}
.finance-report-tab:focus-visible{outline:3px solid rgba(37,99,235,.18);outline-offset:2px}
.finance-report-library{display:grid;gap:1rem}
.finance-report-tabbed,.finance-report-tab-panels,.finance-report-panel,.finance-report-panel-body{min-width:0}
.finance-report-tabbed{display:grid;gap:.9rem}
.finance-report-panel{border:1px solid #dbe3ee;border-radius:12px;background:#f8fafc;overflow:hidden}
.finance-report-panel[hidden]{display:none!important}
.finance-report-panel-body{max-width:100%;overflow-x:auto}
.finance-report-panel .finance-report-document{box-sizing:border-box;margin:0;border:0;border-top:1px solid #cbd5e1;min-width:min(620px,100%)}
.finance-report-document-card{width:min(1120px,calc(100vw - 2rem));padding:0;background:#f1f5f9;border-top:6px solid #2563eb;border-radius:16px;box-shadow:0 30px 90px rgba(15,23,42,.34)}
.finance-report-toolbar{display:flex;justify-content:space-between;align-items:center;gap:1rem;background:#0f172a;color:#fff;padding:.95rem 4.8rem .95rem 1.15rem}
.finance-report-toolbar strong{font-size:1rem;font-weight:900}
.finance-report-toolbar span{display:block;margin-top:.18rem;color:#cbd5e1;font-size:.78rem;font-weight:600;line-height:1.3}
.finance-report-toolbar .finance-actions{gap:.6rem}
.finance-report-toolbar .finance-btn{background:#fff;color:#0f172a;border-color:#fff;font-weight:900;padding:.72rem .95rem}
.finance-report-toolbar .finance-modal-close{border-color:#fff;color:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,.18)}
.finance-report-document{background:#fff;color:#111827;margin:1rem;padding:1.45rem 1.55rem 1.15rem;border:1px solid #cbd5e1;font-family:Georgia,'Times New Roman',serif}
.finance-document-header{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;border-bottom:2px solid #111827;padding-bottom:1rem;margin-bottom:1rem}
.finance-document-brand{display:flex;gap:.85rem;align-items:center;min-width:0}
.finance-document-logo{width:104px;min-height:48px;display:flex;align-items:center}
.finance-document-logo img{max-width:104px;max-height:48px;object-fit:contain}
.finance-document-brand strong{display:block;font-size:1.05rem}
.finance-document-brand span{display:block;color:#64748b;font-size:.78rem;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
.finance-document-meta{border-collapse:collapse;color:#334155;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:.78rem}
.finance-document-meta td{padding:.18rem 0 .18rem .8rem;text-align:right}
.finance-document-meta td:first-child{color:#64748b;text-transform:uppercase;font-size:.68rem;font-weight:800}
.finance-document-title{margin-bottom:1rem}
.finance-document-title span{color:#475569;text-transform:uppercase;font:800 .7rem/1 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;letter-spacing:.06em}
.finance-document-title h2{margin:.35rem 0 .25rem;font-size:2rem;line-height:1.05;letter-spacing:0}
.finance-document-title p{margin:0;color:#475569;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
.finance-document-table{width:100%;border-collapse:collapse;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
.finance-document-table th{background:#111827;color:#fff;text-transform:uppercase;font-size:.68rem;letter-spacing:.04em;padding:.65rem;border:1px solid #111827}
.finance-document-table td{padding:.7rem .65rem;border-bottom:1px solid #e5e7eb}
.finance-document-table .amount{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.finance-document-table .section td{background:#f3f4f6;color:#374151;font-weight:800;text-transform:uppercase;font-size:.72rem;letter-spacing:.04em}
.finance-document-table .subtotal td,.finance-document-table .total td{font-weight:800;border-top:1px solid #111827}
.finance-document-table .grand td{font-weight:900;border-top:2px solid #111827;border-bottom:2px solid #111827;background:#f9fafb}
.finance-document-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.65rem;margin:1rem 0}
.finance-document-summary div{border:1px solid #d1d5db;border-radius:8px;padding:.75rem;background:#f9fafb}
.finance-document-summary span{display:block;color:#64748b;text-transform:uppercase;font-size:.68rem;font-weight:800}
.finance-document-summary strong{display:block;margin-top:.25rem;font-size:1rem}
.finance-report-t-account{display:grid;grid-template-columns:1fr 1fr;border:2px solid #111827;background:#fff}
.finance-report-t-side{padding:1rem}
.finance-report-t-side + .finance-report-t-side{border-left:2px solid #111827}
.finance-report-t-side h3{margin:0 0 .85rem;padding-bottom:.55rem;border-bottom:1px solid #111827;font-size:1rem;text-transform:uppercase}
.finance-report-t-row,.finance-report-t-total{display:flex;justify-content:space-between;gap:1rem;padding:.65rem 0;border-bottom:1px solid #e5e7eb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
.finance-report-t-row strong,.finance-report-t-total strong{font-variant-numeric:tabular-nums}
.finance-report-t-total{font-weight:900;border-top:2px solid #111827;border-bottom:0;margin-top:.35rem}
.finance-document-balance-check{display:flex;justify-content:space-between;gap:1rem;margin-top:1rem;border:1px solid #111827;padding:.75rem 1rem;font-weight:900;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif}
.finance-document-footer{margin-top:1.1rem;padding-top:.75rem;border-top:1px solid #d1d5db;color:#64748b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:.78rem}
.finance-table-wrap{overflow-x:visible;border-color:#e2e8f0;border-radius:8px}
.finance-table{min-width:0}
.finance-table th{background:#f8fafc;color:#475569;letter-spacing:.03em}
.finance-table th,.finance-table td{padding:.68rem .72rem}
.finance-table td:last-child{width:1%;white-space:nowrap}
.finance-empty{border-radius:8px;color:#64748b}
.finance-empty-cell{color:#64748b;text-align:center}
.finance-pill{border-radius:999px}
.finance-pill.soft{background:#eef2ff;border-color:#c7d2fe;color:#3730a3}
.finance-pill.warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.finance-muted{color:#64748b;font-size:.82rem}
.finance-more{grid-column:1/-1;border:1px solid #e2e8f0;border-radius:8px;background:#fbfdff;padding:.65rem}
.finance-more summary{cursor:pointer;color:#475569;font-size:.82rem;font-weight:800;list-style:none}
.finance-more summary::-webkit-details-marker{display:none}
.finance-more summary::after{content:'+';float:right;color:#64748b}
.finance-more[open] summary::after{content:'-'}
.finance-more-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.65rem;margin-top:.7rem}
.finance-mini-help{display:inline-flex;align-items:center;gap:.35rem;color:#64748b;font-size:.8rem}
.finance-help{width:1.05rem;height:1.05rem}
.finance-statement-cards{display:grid;gap:.55rem}
.finance-row-card{display:grid;grid-template-columns:1fr auto;gap:.25rem .75rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:.75rem}
.finance-row-card span{color:#64748b;font-size:.72rem;font-weight:800;text-transform:uppercase}
.finance-row-card strong{font-size:.93rem;font-weight:700}
.finance-row-card .finance-row-card-full{grid-column:1/-1}
.finance-t-account{display:grid;grid-template-columns:1fr 1fr;border:1px solid #dbe3ee;border-radius:8px;overflow:hidden;background:#fff}
.finance-t-side{padding:1rem}
.finance-t-side + .finance-t-side{border-left:1px solid #dbe3ee}
.finance-t-head,.finance-t-row,.finance-t-total{display:flex;justify-content:space-between;gap:1rem;align-items:center}
.finance-t-head{font-weight:800;margin-bottom:.65rem}
.finance-t-row{padding:.5rem 0;border-bottom:1px solid #eef2f7;color:#334155}
.finance-t-row strong,.finance-t-total strong{font-variant-numeric:tabular-nums}
.finance-t-total{margin-top:.7rem;padding-top:.7rem;border-top:2px solid #0f172a;font-weight:800}
.finance-inline-sections{display:grid;gap:1rem}
.finance-section-divider{border:0;border-top:1px solid #e2e8f0;margin:1rem 0}
.finance-form-title{display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.finance-form-title h2{margin:0}
.finance-form-title .finance-muted{font-weight:600}
.finance-btn-loud{border:0;color:#fff;box-shadow:0 10px 22px rgba(15,23,42,.12);padding-inline:1rem;min-width:8.4rem}
.finance-btn-loud:hover{color:#fff;transform:translateY(-1px);box-shadow:0 14px 26px rgba(15,23,42,.16)}
.finance-btn-in{background:#12805c}
.finance-btn-in:hover{background:#0f6f51}
.finance-btn-out{background:#c2412f}
.finance-btn-out:hover{background:#a9382a}
.finance-btn-funding{background:#6d5bd0}
.finance-btn-funding:hover{background:#5b4cc0}
.finance-card{position:relative;overflow:hidden}
.finance-card::before{content:'';position:absolute;inset:0 auto 0 0;width:4px;background:#cbd5e1}
.finance-card-in{background:#f0fdf8;border-color:#bbf7d0}
.finance-card-in::before{background:#10b981}
.finance-card-out{background:#fff7ed;border-color:#fed7aa}
.finance-card-out::before{background:#f97316}
.finance-card-net::before{background:#2563eb}
.finance-card-net.risk::before{background:#f59e0b}
.finance-card-bank{background:#eff6ff;border-color:#bfdbfe}
.finance-card-bank::before{background:#2563eb}
.finance-card-funding{background:#f5f3ff;border-color:#ddd6fe}
.finance-card-funding::before{background:#7c3aed}
.finance-money-in{color:#047857}
.finance-money-out{color:#b42318}
.finance-money-bank{color:#1d4ed8}
.finance-money-funding{color:#5b21b6}
.finance-row-card{position:relative;overflow:hidden}
.finance-row-card::before{content:'';position:absolute;inset:0 auto 0 0;width:3px;background:#cbd5e1}
.finance-row-in::before{background:#10b981}
.finance-row-out::before{background:#f97316}
.finance-row-funding::before{background:#7c3aed}
.finance-single-flow{grid-template-columns:1fr}
body.finance-modal-open{overflow:hidden}
.finance-modal[hidden]{display:none!important}
.finance-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:1rem}
.finance-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(2px)}
.finance-modal-card{position:relative;width:min(720px,calc(100vw - 2rem));max-height:calc(100vh - 2rem);overflow:auto;border:1px solid #dbe3ee;border-top:5px solid var(--finance-accent,#2563eb);border-radius:14px;background:#fff;padding:1.05rem;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.finance-modal-card-in{--finance-accent:#10b981}
.finance-modal-card-out{--finance-accent:#f97316}
.finance-modal-card-funding{--finance-accent:#7c3aed}
.finance-modal-card .finance-form{margin-top:.9rem}
.finance-modal-close{display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border:1px solid #dbe3ee;border-radius:999px;background:#fff;color:#475569;font-size:1rem;font-weight:800;cursor:pointer}
.finance-modal-close:hover{background:#f8fafc;color:#0f172a}
@media(max-width:760px){
    .finance-dashboard{padding-top:.65rem}
    .finance-shell{padding:0 .75rem}
    .finance-top{align-items:flex-start}
    .finance-title h1{font-size:1.35rem}
    .finance-date{width:100%;display:grid;grid-template-columns:1fr 1fr auto;margin-left:0}
    .finance-page-actions{width:100%}
    .finance-page-actions .finance-btn{flex:1}
    .finance-kpi-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
    .finance-grid,.finance-split,.finance-report{grid-template-columns:1fr}
    .finance-panel{padding:.85rem}
    .finance-table-wrap{border:0}
    .finance-table,.finance-table tbody,.finance-table tr,.finance-table td{display:block;width:100%}
    .finance-table thead{display:none}
    .finance-table tr{border:1px solid #e2e8f0;border-radius:8px;background:#fff;margin-bottom:.65rem;padding:.3rem .65rem}
    .finance-table td{display:flex;justify-content:space-between;gap:1rem;border-bottom:1px solid #eef2f7;padding:.55rem 0;text-align:right}
    .finance-table td::before{content:attr(data-label);color:#64748b;font-size:.72rem;font-weight:800;text-transform:uppercase;text-align:left}
    .finance-table td:last-child{width:auto;border-bottom:0}
    .finance-table td.finance-empty-cell{display:block;text-align:center}
    .finance-table td.finance-empty-cell::before{display:none}
    .finance-actions{justify-content:flex-end}
    .finance-t-account{grid-template-columns:1fr}
    .finance-t-side + .finance-t-side{border-left:0;border-top:1px solid #dbe3ee}
    .finance-report-tabs,.finance-document-summary,.finance-report-t-account{grid-template-columns:1fr}
    .finance-report-tabs{display:grid}
    .finance-report-tab{width:100%}
    .finance-document-header{display:block}
    .finance-document-meta td{text-align:left;padding:.18rem .8rem .18rem 0}
    .finance-document-title h2{font-size:1.45rem}
    .finance-report-t-side + .finance-report-t-side{border-left:0;border-top:2px solid #111827}
    .finance-report-toolbar{align-items:flex-start;flex-direction:column;padding:.95rem}
    .finance-report-document{margin:.55rem;padding:1rem .9rem}
    .finance-modal{align-items:end;padding:0}
    .finance-modal-card{width:100%;max-height:90vh;border-radius:16px 16px 0 0;padding:.95rem}
    .finance-report-document-card{padding:0}
    .finance-btn-loud{width:100%;min-width:0}
}
</style>

<div class="finance-dashboard">
<div class="finance-shell">
    <div class="finance-top">
        <div class="finance-title">
            <h1>Finance</h1>
            <span class="finance-pill"><?php echo htmlspecialchars($currency); ?></span>
        </div>
        <form method="GET" class="finance-date">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <?php if ($selectedCashAccountId !== null): ?><input type="hidden" name="cash_account_id" value="<?php echo (int) $selectedCashAccountId; ?>"><?php endif; ?>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
            <button class="finance-btn" type="submit">Go</button>
        </form>
    </div>

    <nav class="finance-tabs" aria-label="Finance">
        <?php foreach ($tabLabels as $tab => $label): ?>
            <a class="finance-tab <?php echo $activeTab === $tab ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(financeTabUrl($tab, $dateFrom, $dateTo)); ?>"><?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($success): ?><div class="finance-msg ok"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="finance-msg err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($activeTab === 'today'): ?>
        <section class="finance-page-actions">
            <?php if ($canManageFinance): ?>
                <button class="finance-btn finance-btn-loud finance-btn-in" type="button" data-finance-open="finance-modal-in">+ In</button>
                <button class="finance-btn finance-btn-loud finance-btn-out" type="button" data-finance-open="finance-modal-out">+ Out</button>
            <?php endif; ?>
            <a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('bank', $dateFrom, $dateTo)); ?>">Bank</a>
        </section>
        <section class="finance-kpi-strip">
            <article class="finance-card finance-card-in"><span>In</span><strong class="finance-money-in"><?php echo htmlspecialchars(financeMoney((float) ($summary['money_in'] ?? 0), $currency)); ?></strong></article>
            <article class="finance-card finance-card-out"><span>Out</span><strong class="finance-money-out"><?php echo htmlspecialchars(financeMoney((float) ($summary['money_out'] ?? 0), $currency)); ?></strong></article>
            <article class="finance-card finance-card-net <?php echo (float) ($summary['net_cash_movement'] ?? 0) < 0 ? 'risk' : ''; ?>"><span>Net</span><strong><?php echo htmlspecialchars(financeMoney((float) ($summary['net_cash_movement'] ?? 0), $currency)); ?></strong></article>
            <article class="finance-card finance-card-bank"><span>Bank</span><strong class="finance-money-bank"><?php echo htmlspecialchars(financeMoney((float) ($bankSummary['ending_balance'] ?? 0), $currency)); ?></strong></article>
        </section>
        <section class="finance-report">
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>Latest In</h2><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('in', $dateFrom, $dateTo)); ?>">Open</a></div>
                <?php if ($latestInRows === []): ?><div class="finance-empty">No entries</div><?php endif; ?>
                <div class="finance-statement-cards">
                    <?php foreach ($latestInRows as $row): ?>
                        <article class="finance-row-card finance-row-in">
                            <span><?php echo htmlspecialchars((string) ($row['date'] ?? '')); ?></span>
                            <strong class="finance-money-in"><?php echo htmlspecialchars(financeMoney((float) ($row['amount'] ?? 0), (string) ($row['currency'] ?? $currency))); ?></strong>
                            <div><?php echo htmlspecialchars((string) ($row['from'] ?? '')); ?></div>
                            <div><?php echo htmlspecialchars((string) ($row['label'] ?? '')); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>Latest Out</h2><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('out', $dateFrom, $dateTo)); ?>">Open</a></div>
                <?php if ($latestOutRows === []): ?><div class="finance-empty">No entries</div><?php endif; ?>
                <div class="finance-statement-cards">
                    <?php foreach ($latestOutRows as $row): ?>
                        <article class="finance-row-card finance-row-out">
                            <span><?php echo htmlspecialchars((string) ($row['expense_date'] ?? '')); ?></span>
                            <strong class="finance-money-out"><?php echo htmlspecialchars(financeMoney((float) ($row['amount'] ?? 0), (string) ($row['currency'] ?? $currency))); ?></strong>
                            <div><?php echo htmlspecialchars((string) ($row['vendor_name'] ?? '')); ?></div>
                            <div><?php echo htmlspecialchars((string) ($row['category_name'] ?? '')); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="finance-panel">
            <div class="finance-panel-head"><h2>Bank</h2><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('bank', $dateFrom, $dateTo)); ?>">Statement</a></div>
            <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Account</th><th>Ref</th><th>In</th><th>Out</th><th>Balance</th></tr></thead><tbody>
                <?php if ($latestRows === []): ?><tr><td class="finance-empty-cell" colspan="6">No entries</td></tr><?php endif; ?>
                <?php foreach ($latestRows as $row): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string) ($row['date'] ?? '')); ?></td><td data-label="Account"><?php echo htmlspecialchars((string) ($row['cash_account_name'] ?? '')); ?></td><td data-label="Ref"><?php echo htmlspecialchars((string) ($row['ref'] ?? '')); ?></td><td data-label="In" class="finance-money-in"><?php echo (float) ($row['money_in'] ?? 0) > 0 ? htmlspecialchars(financeMoney((float) $row['money_in'], $currency)) : ''; ?></td><td data-label="Out" class="finance-money-out"><?php echo (float) ($row['money_out'] ?? 0) > 0 ? htmlspecialchars(financeMoney((float) $row['money_out'], $currency)) : ''; ?></td><td data-label="Balance" class="finance-money-bank"><?php echo htmlspecialchars(financeMoney((float) ($row['balance'] ?? 0), $currency)); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

    <?php elseif ($activeTab === 'in'): ?>
        <section class="finance-split finance-single-flow">
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>In</h2><div class="finance-actions"><span class="finance-pill"><?php echo htmlspecialchars(financeMoney((float) ($summary['money_in'] ?? 0), $currency)); ?></span><?php if ($canManageFinance): ?><button class="finance-btn finance-btn-loud finance-btn-in" type="button" data-finance-open="finance-modal-in">+ Add money in</button><?php endif; ?></div></div>
                <div class="finance-panel-subhead"><h3>Invoices</h3><span class="finance-muted">Auto</span></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Account</th><th>From</th><th>Label</th><th>Amount</th><th></th></tr></thead><tbody>
                    <?php if ($paidInvoices === []): ?><tr><td class="finance-empty-cell" colspan="6">No entries</td></tr><?php endif; ?>
                    <?php foreach ($paidInvoices as $invoice): ?><tr><td data-label="Date"><?php echo htmlspecialchars(substr((string) ($invoice['activity_date'] ?? ''), 0, 10)); ?></td><td data-label="Account"><?php echo htmlspecialchars($defaultCashAccountName); ?></td><td data-label="From"><?php echo htmlspecialchars((string) ($invoice['invoice_number'] ?? 'Invoice')); ?></td><td data-label="Label">Sales</td><td data-label="Amount" class="finance-money-in"><?php echo htmlspecialchars(financeMoney((float) ($invoice['amount'] ?? 0), (string) ($invoice['currency'] ?? $currency))); ?></td><td data-label=""></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <div class="finance-panel-subhead"><h3>Other entries</h3></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Account</th><th>From</th><th>Label</th><th>Amount</th><th></th></tr></thead><tbody>
                    <?php if ($incomeEntries === []): ?><tr><td class="finance-empty-cell" colspan="6">No entries</td></tr><?php endif; ?>
                    <?php foreach ($incomeEntries as $entry): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string) ($entry['income_date'] ?? '')); ?></td><td data-label="Account"><?php echo htmlspecialchars((string) ($entry['cash_account_name'] ?? '')); ?></td><td data-label="From"><?php echo htmlspecialchars((string) ($entry['source'] ?? '')); ?></td><td data-label="Label"><?php echo htmlspecialchars((string) ($entry['income_category_name'] ?? ucwords(str_replace('_', ' ', (string) ($entry['income_type'] ?? 'sale'))))); ?></td><td data-label="Amount" class="finance-money-in"><?php echo htmlspecialchars(financeMoney((float) ($entry['amount'] ?? 0), (string) ($entry['currency'] ?? $currency))); ?></td><td data-label=""><?php if ($canManageFinance): ?><span class="finance-actions"><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('in', $dateFrom, $dateTo)); ?>&edit_income=<?php echo (int) ($entry['id'] ?? 0); ?>">Edit</a><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="in"><input type="hidden" name="finance_action" value="delete_income"><input type="hidden" name="id" value="<?php echo (int) ($entry['id'] ?? 0); ?>"><button class="finance-btn danger" type="submit">Del</button></form></span><?php endif; ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
        </section>

    <?php elseif ($activeTab === 'out'): ?>
        <section class="finance-split finance-single-flow">
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>Out</h2><div class="finance-actions"><span class="finance-pill"><?php echo htmlspecialchars(financeMoney((float) ($summary['money_out'] ?? 0), $currency)); ?></span><?php if ($canManageFinance): ?><button class="finance-btn finance-btn-loud finance-btn-out" type="button" data-finance-open="finance-modal-out">+ Add money out</button><?php endif; ?></div></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Account</th><th>To</th><th>Label</th><th>Amount</th><th></th></tr></thead><tbody>
                    <?php if ($expenses === []): ?><tr><td class="finance-empty-cell" colspan="6">No entries</td></tr><?php endif; ?>
                    <?php foreach ($expenses as $expense): ?><?php $isProfitShare = ($expenseCategoryTreatments[(string) ($expense['category_id'] ?? '')] ?? 'normal') === 'post_net_profit_share'; ?><tr><td data-label="Date"><?php echo htmlspecialchars((string) ($expense['expense_date'] ?? '')); ?></td><td data-label="Account"><?php echo htmlspecialchars((string) ($expense['cash_account_name'] ?? '')); ?></td><td data-label="To"><?php echo htmlspecialchars((string) ($expense['vendor_name'] ?? '')); ?></td><td data-label="Label"><?php echo htmlspecialchars((string) ($expense['category_name'] ?? '')); ?> <?php echo $isProfitShare ? '<span class="finance-pill warn">Share</span>' : ''; ?></td><td data-label="Amount" class="finance-money-out"><?php echo htmlspecialchars(financeMoney((float) ($expense['amount'] ?? 0), (string) ($expense['currency'] ?? $currency))); ?></td><td data-label=""><?php if ($canManageFinance): ?><span class="finance-actions"><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('out', $dateFrom, $dateTo)); ?>&edit_expense=<?php echo (int) ($expense['id'] ?? 0); ?>">Edit</a><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="out"><input type="hidden" name="finance_action" value="delete_expense"><input type="hidden" name="id" value="<?php echo (int) ($expense['id'] ?? 0); ?>"><button class="finance-btn danger" type="submit">Del</button></form></span><?php endif; ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
        </section>

    <?php elseif ($activeTab === 'capital'): ?>
        <section class="finance-split finance-single-flow">
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>Funding</h2><div class="finance-actions"><span class="finance-pill"><?php echo htmlspecialchars(financeMoney((float) ($cashFlow['summary']['financing_cash_flow'] ?? 0), $currency)); ?></span><?php if ($canManageFinance): ?><button class="finance-btn finance-btn-loud finance-btn-funding" type="button" data-finance-open="finance-modal-funding">+ Add funding</button><?php endif; ?></div></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Account</th><th>From/To</th><th>Type</th><th>Amount</th><th></th></tr></thead><tbody>
                    <?php if ($ledgerTransactions === []): ?><tr><td class="finance-empty-cell" colspan="6">No entries</td></tr><?php endif; ?>
                    <?php foreach ($ledgerTransactions as $entry): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string) ($entry['transaction_date'] ?? '')); ?></td><td data-label="Account"><?php echo htmlspecialchars((string) ($entry['cash_account_name'] ?? '')); ?></td><td data-label="From/To"><?php echo htmlspecialchars((string) ($entry['counterparty'] ?? '')); ?></td><td data-label="Type"><?php echo htmlspecialchars(financeLedgerTypeLabel((string) ($entry['transaction_type'] ?? ''))); ?></td><td data-label="Amount" class="finance-money-funding"><?php echo htmlspecialchars(financeMoney((float) ($entry['amount'] ?? 0), (string) ($entry['currency'] ?? $currency))); ?></td><td data-label=""><?php if ($canManageFinance): ?><span class="finance-actions"><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('capital', $dateFrom, $dateTo)); ?>&edit_ledger=<?php echo (int) ($entry['id'] ?? 0); ?>">Edit</a><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="capital"><input type="hidden" name="finance_action" value="delete_ledger_transaction"><input type="hidden" name="transaction_id" value="<?php echo (int) ($entry['id'] ?? 0); ?>"><button class="finance-btn danger" type="submit">Del</button></form></span><?php endif; ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
        </section>

    <?php elseif ($activeTab === 'bank'): ?>
        <section class="finance-panel">
            <div class="finance-panel-head"><h2>Bank</h2><form method="GET" class="finance-date"><input type="hidden" name="tab" value="bank"><input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>"><input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>"><select name="cash_account_id" aria-label="Account"><?php echo '<option value="">All accounts</option>' . financeAccountOptions($cashAccounts, $selectedCashAccountId); ?></select><button class="finance-btn" type="submit">Go</button></form></div>
            <section class="finance-kpi-strip" style="margin-bottom:.75rem;"><article class="finance-card finance-card-bank"><span>Open</span><strong class="finance-money-bank"><?php echo htmlspecialchars(financeMoney((float) ($bankSummary['opening_balance'] ?? 0), $currency)); ?></strong></article><article class="finance-card finance-card-in"><span>In</span><strong class="finance-money-in"><?php echo htmlspecialchars(financeMoney((float) ($bankSummary['cash_in'] ?? 0), $currency)); ?></strong></article><article class="finance-card finance-card-out"><span>Out</span><strong class="finance-money-out"><?php echo htmlspecialchars(financeMoney((float) ($bankSummary['cash_out'] ?? 0), $currency)); ?></strong></article><article class="finance-card finance-card-bank"><span>Close</span><strong class="finance-money-bank"><?php echo htmlspecialchars(financeMoney((float) ($bankSummary['ending_balance'] ?? 0), $currency)); ?></strong></article></section>
            <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Date</th><th>Account</th><th>Ref</th><th>In</th><th>Out</th><th>Balance</th></tr></thead><tbody>
                <?php if ($bankRows === []): ?><tr><td class="finance-empty-cell" colspan="6">No entries</td></tr><?php endif; ?>
                <?php foreach ($bankRows as $row): ?><tr><td data-label="Date"><?php echo htmlspecialchars((string) ($row['date'] ?? '')); ?></td><td data-label="Account"><?php echo htmlspecialchars((string) ($row['cash_account_name'] ?? '')); ?></td><td data-label="Ref"><?php echo htmlspecialchars((string) ($row['ref'] ?? '')); ?></td><td data-label="In" class="finance-money-in"><?php echo (float) ($row['money_in'] ?? 0) > 0 ? htmlspecialchars(financeMoney((float) $row['money_in'], $currency)) : ''; ?></td><td data-label="Out" class="finance-money-out"><?php echo (float) ($row['money_out'] ?? 0) > 0 ? htmlspecialchars(financeMoney((float) $row['money_out'], $currency)) : ''; ?></td><td data-label="Balance" class="finance-money-bank"><?php echo htmlspecialchars(financeMoney((float) ($row['balance'] ?? 0), $currency)); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

    <?php elseif ($activeTab === 'reports'): ?>
        <section class="finance-report-library">
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>Reports</h2><span class="finance-pill"><?php echo htmlspecialchars((string) ($reportQuality['confidence'] ?? 'ok')); ?></span></div>
                <?php $reportTypes = $financeReportDocumentService->reportTypes(); $activeReportType = array_key_exists('cashflow', $reportTypes) ? 'cashflow' : (string) array_key_first($reportTypes); ?>
                <div class="finance-report-tabbed">
                    <div class="finance-report-tabs" role="tablist" aria-label="Finance reports">
                        <?php foreach ($reportTypes as $reportType => $reportMeta): ?>
                            <?php $isActiveReport = $reportType === $activeReportType; ?>
                            <button
                                class="finance-report-tab <?php echo $isActiveReport ? 'is-active' : ''; ?>"
                                id="finance-report-tab-<?php echo htmlspecialchars($reportType); ?>"
                                type="button"
                                role="tab"
                                aria-selected="<?php echo $isActiveReport ? 'true' : 'false'; ?>"
                                aria-controls="finance-report-panel-<?php echo htmlspecialchars($reportType); ?>"
                                tabindex="<?php echo $isActiveReport ? '0' : '-1'; ?>"
                                data-finance-report-tab="<?php echo htmlspecialchars($reportType); ?>"
                            ><?php echo htmlspecialchars($reportMeta['short']); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="finance-report-tab-panels">
                        <?php foreach ($reportTypes as $reportType => $reportMeta): ?>
                            <?php $isActiveReport = $reportType === $activeReportType; ?>
                            <section
                                class="finance-report-panel"
                                id="finance-report-panel-<?php echo htmlspecialchars($reportType); ?>"
                                role="tabpanel"
                                aria-labelledby="finance-report-tab-<?php echo htmlspecialchars($reportType); ?>"
                                data-finance-report-panel="<?php echo htmlspecialchars($reportType); ?>"
                                <?php echo $isActiveReport ? '' : 'hidden'; ?>
                            >
                                <div class="finance-report-toolbar">
                                    <div>
                                        <strong><?php echo htmlspecialchars($reportMeta['title']); ?></strong>
                                        <span><?php echo htmlspecialchars($reportMeta['description']); ?></span>
                                    </div>
                                    <div class="finance-actions">
                                        <a class="finance-btn" href="<?php echo htmlspecialchars((string) ($financeReportExportUrls[$reportType] ?? '#')); ?>" target="_blank" rel="noopener">Export PDF</a>
                                    </div>
                                </div>
                                <div class="finance-report-panel-body">
                                    <?php echo $financeReportDocuments[$reportType] ?? ''; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <section class="finance-panel"><div class="finance-panel-head"><h2>Checks</h2></div><?php $warnings = (array) ($reportQuality['warnings'] ?? []); if ($warnings === []): ?><div class="finance-empty">No warnings</div><?php else: ?><div class="finance-actions"><?php foreach ($warnings as $warning): ?><span class="finance-pill"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $warning))); ?></span><?php endforeach; ?></div><?php endif; ?></section>
        </section>

    <?php elseif ($activeTab === 'setup'): ?>
        <section class="finance-report">
            <div class="finance-panel"><div class="finance-panel-head"><h2>Initial</h2><a class="finance-btn" href="workspace_skills.php?module=finance#setup">Edit setup</a></div><table class="finance-table is-small"><tbody><tr><td data-label="Line">Bank</td><td data-label="Amount"><?php echo htmlspecialchars(financeMoney($openingBank, $currency)); ?></td></tr><tr><td data-label="Line">Things owned</td><td data-label="Amount"><?php echo htmlspecialchars(financeMoney($openingAssets, $currency)); ?></td></tr><tr><td data-label="Line">Owed to us</td><td data-label="Amount"><?php echo htmlspecialchars(financeMoney($openingReceivables, $currency)); ?></td></tr><tr><td data-label="Line">Owed by us</td><td data-label="Amount"><?php echo htmlspecialchars(financeMoney($openingLiabilities, $currency)); ?></td></tr><tr><td data-label="Line"><strong>Owner value</strong></td><td data-label="Amount"><strong><?php echo htmlspecialchars(financeMoney($openingBusinessValue, $currency)); ?></strong></td></tr></tbody></table></div>
        </section>
        <section class="finance-split">
            <div class="finance-panel">
                <div class="finance-panel-head"><h2>Accounts</h2></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Name</th><th>Kind</th><th>Currency</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($cashAccounts as $account): ?><tr><td data-label="Name"><?php echo htmlspecialchars((string) ($account['name'] ?? '')); ?> <?php echo !empty($account['is_default']) ? '<span class="finance-pill">Default</span>' : ''; ?></td><td data-label="Kind"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($account['account_type'] ?? 'bank')))); ?></td><td data-label="Currency"><?php echo htmlspecialchars((string) ($account['currency'] ?? $currency)); ?></td><td data-label="Status"><?php echo !empty($account['is_active']) ? 'On' : 'Off'; ?></td><td data-label=""><?php if ($canManageFinance && empty($account['is_default']) && !empty($account['is_active'])): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="setup"><input type="hidden" name="finance_action" value="deactivate_account"><input type="hidden" name="id" value="<?php echo (int) ($account['id'] ?? 0); ?>"><button class="finance-btn danger" type="submit">Off</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="finance-panel-head" style="margin-top:1rem;"><h2>Income labels</h2></div>
                <div class="finance-table-wrap"><table class="finance-table is-small"><thead><tr><th>Name</th><th>Use</th></tr></thead><tbody><?php foreach ($incomeCategories as $category): ?><tr><td data-label="Name"><?php echo htmlspecialchars((string) ($category['name'] ?? '')); ?></td><td data-label="Use"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($category['income_type'] ?? 'sale')))); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="finance-panel-head" style="margin-top:1rem;"><h2>Expense labels</h2></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>Name</th><th>Use</th><th>Cash</th></tr></thead><tbody><?php foreach ($categories as $category): ?><tr><td data-label="Name"><?php echo htmlspecialchars((string) ($category['name'] ?? '')); ?></td><td data-label="Use"><?php echo (($category['profit_treatment'] ?? 'normal') === 'post_net_profit_share') ? 'Profit share' : htmlspecialchars((string) ($statementGroups[$category['statement_group'] ?? 'operating_expense'] ?? $category['statement_group'] ?? '')); ?></td><td data-label="Cash"><?php echo htmlspecialchars((string) ($cashFlowGroups[$category['cash_flow_group'] ?? 'operating'] ?? 'Operating')); ?></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="finance-panel-head" style="margin-top:1rem;"><h2>Recurring</h2></div>
                <div class="finance-table-wrap"><table class="finance-table"><thead><tr><th>To</th><th>Label</th><th>Amount</th><th>Next</th><th></th></tr></thead><tbody><?php if ($recurring === []): ?><tr><td class="finance-empty-cell" colspan="5">No entries</td></tr><?php endif; ?><?php foreach ($recurring as $row): ?><tr><td data-label="To"><?php echo htmlspecialchars((string) ($row['vendor_name'] ?? '')); ?></td><td data-label="Label"><?php echo htmlspecialchars((string) ($row['category_name'] ?? $row['description'] ?? '')); ?></td><td data-label="Amount"><?php echo htmlspecialchars(financeMoney((float) ($row['amount'] ?? 0), (string) ($row['currency'] ?? $currency))); ?></td><td data-label="Next"><?php echo htmlspecialchars((string) ($row['next_due_date'] ?? '')); ?></td><td data-label=""><?php if ($canManageFinance): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="setup"><input type="hidden" name="finance_action" value="delete_recurring"><input type="hidden" name="id" value="<?php echo (int) ($row['id'] ?? 0); ?>"><button class="finance-btn danger" type="submit">Del</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
            </div>
            <?php if ($canManageFinance): ?><aside class="finance-panel">
                <div class="finance-panel-head"><h2>Add</h2></div>
                <div class="finance-panel-subhead"><h3>Account</h3></div>
                <form method="POST" class="finance-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="setup"><input type="hidden" name="finance_action" value="save_cash_account"><label class="finance-field">Account<input name="name" required></label><label class="finance-field">Type<select name="account_type"><?php foreach ($accountTypes as $type => $label): ?><option value="<?php echo $type; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></label><label class="finance-field">Currency<input name="currency" value="<?php echo htmlspecialchars($currency); ?>" maxlength="10"></label><label class="finance-field"><span>Default</span><select name="is_default"><option value="0">No</option><option value="1">Yes</option></select></label><div class="finance-actions end"><button class="finance-btn primary" type="submit">Save</button></div></form>
                <hr class="finance-section-divider">
                <div class="finance-panel-subhead"><h3>Income label</h3></div>
                <form method="POST" class="finance-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="setup"><input type="hidden" name="finance_action" value="save_income_category"><label class="finance-field">Name<input name="name" required></label><label class="finance-field">Use<select name="income_type"><option value="sale">Sales</option><option value="other_income">Other income</option></select></label><div class="finance-actions end"><button class="finance-btn primary" type="submit">Save</button></div></form>
                <hr class="finance-section-divider">
                <div class="finance-panel-subhead"><h3>Expense label</h3></div>
                <form method="POST" class="finance-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="setup"><input type="hidden" name="finance_action" value="save_category"><label class="finance-field">Name<input name="name" required></label><label class="finance-field">Use<select name="profit_treatment"><option value="normal">Normal</option><option value="post_net_profit_share">Profit share</option></select></label><details class="finance-more"><summary>More</summary><div class="finance-more-grid"><label class="finance-field">Kind<select name="category_type"><?php foreach ($categoryTypes as $type => $label): ?><option value="<?php echo $type; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></label><label class="finance-field">Profit<select name="statement_group"><?php foreach ($statementGroups as $group => $label): ?><option value="<?php echo $group; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></label><label class="finance-field">Cash<select name="cash_flow_group"><?php foreach ($cashFlowGroups as $group => $label): ?><option value="<?php echo $group; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></label></div></details><div class="finance-actions end"><button class="finance-btn primary" type="submit">Save</button></div></form>
                <hr class="finance-section-divider">
                <div class="finance-panel-subhead"><h3>Recurring</h3></div>
                <form method="POST" class="finance-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="setup"><input type="hidden" name="finance_action" value="save_recurring"><label class="finance-field">To<input name="vendor"></label><label class="finance-field">Label<select name="category_id"><?php echo financeExpenseCategoryOptions($categories, ''); ?></select></label><label class="finance-field">Amount<input type="number" step="0.01" min="0" name="amount" required></label><label class="finance-field">Next<input type="date" name="next_due_date"></label><details class="finance-more"><summary>More</summary><div class="finance-more-grid"><label class="finance-field">Currency<input name="currency" value="<?php echo htmlspecialchars($currency); ?>" maxlength="10"></label><label class="finance-field">Every<select name="frequency"><option value="weekly">Week</option><option value="monthly" selected>Month</option><option value="quarterly">Quarter</option><option value="yearly">Year</option></select></label><label class="finance-field wide">Note<input name="description"></label></div></details><input type="hidden" name="is_active" value="1"><div class="finance-actions end"><button class="finance-btn primary" type="submit">Save</button><a class="finance-btn" href="workspace_skills.php?module=finance&setup_tab=owners#setup">Initial</a></div></form>
            </aside><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManageFinance): ?>
        <section class="finance-modal" id="finance-modal-in" role="dialog" aria-modal="true" aria-labelledby="finance-modal-in-title" aria-hidden="true" hidden <?php echo $incomeForm['id'] > 0 ? 'data-finance-open-on-load="1"' : ''; ?>>
            <div class="finance-modal-backdrop" data-finance-modal-close></div>
            <div class="finance-modal-card finance-modal-card-in">
                <div class="finance-form-title"><h2 id="finance-modal-in-title"><?php echo $incomeForm['id'] > 0 ? 'Edit money in' : 'Add money in'; ?></h2><div class="finance-actions"><?php echo financeHelp('Money received by the business. Invoice payments are already counted.'); ?><button class="finance-modal-close" type="button" data-finance-modal-close aria-label="Close">x</button></div></div>
                <form method="POST" class="finance-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="in"><input type="hidden" name="finance_action" value="save_income"><input type="hidden" name="id" value="<?php echo (int) $incomeForm['id']; ?>">
                    <label class="finance-field">Date<input type="date" name="income_date" value="<?php echo htmlspecialchars($incomeForm['income_date']); ?>" required></label>
                    <label class="finance-field">Account<select name="cash_account_id" required><?php echo financeAccountOptions($cashAccounts, $incomeForm['cash_account_id']); ?></select></label>
                    <input type="hidden" name="income_type" value="<?php echo htmlspecialchars($incomeForm['income_type'] ?: 'sale'); ?>">
                    <label class="finance-field">Label<select name="income_category_id" required><?php echo financeIncomeCategoryOptions($incomeCategories, $incomeForm['income_category_id']); ?></select></label>
                    <label class="finance-field">Amount<input type="number" step="0.01" min="0" name="amount" value="<?php echo htmlspecialchars($incomeForm['amount']); ?>" required></label>
                    <label class="finance-field">From<input name="source" value="<?php echo htmlspecialchars($incomeForm['source']); ?>"></label>
                    <details class="finance-more"><summary>More</summary><div class="finance-more-grid"><label class="finance-field">Currency<input name="currency" maxlength="10" value="<?php echo htmlspecialchars($incomeForm['currency']); ?>"></label><label class="finance-field wide">Note<input name="description" value="<?php echo htmlspecialchars($incomeForm['description']); ?>"></label></div></details>
                    <div class="finance-actions end"><button class="finance-btn finance-btn-loud finance-btn-in" type="submit">Save</button><?php if ($incomeForm['id'] > 0): ?><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('in', $dateFrom, $dateTo)); ?>">Cancel</a><?php endif; ?></div>
                </form>
            </div>
        </section>

        <section class="finance-modal" id="finance-modal-out" role="dialog" aria-modal="true" aria-labelledby="finance-modal-out-title" aria-hidden="true" hidden <?php echo $expenseForm['id'] > 0 ? 'data-finance-open-on-load="1"' : ''; ?>>
            <div class="finance-modal-backdrop" data-finance-modal-close></div>
            <div class="finance-modal-card finance-modal-card-out">
                <div class="finance-form-title"><h2 id="finance-modal-out-title"><?php echo $expenseForm['id'] > 0 ? 'Edit money out' : 'Add money out'; ?></h2><div class="finance-actions"><?php echo financeHelp('Money paid by the business. Use Owner salary or Profit share for owner payouts.'); ?><button class="finance-modal-close" type="button" data-finance-modal-close aria-label="Close">x</button></div></div>
                <form method="POST" enctype="multipart/form-data" class="finance-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="out"><input type="hidden" name="finance_action" value="save_expense"><input type="hidden" name="id" value="<?php echo (int) $expenseForm['id']; ?>">
                    <label class="finance-field">Date<input type="date" name="expense_date" value="<?php echo htmlspecialchars($expenseForm['expense_date']); ?>" required></label>
                    <label class="finance-field">Account<select name="cash_account_id" required><?php echo financeAccountOptions($cashAccounts, $expenseForm['cash_account_id']); ?></select></label>
                    <label class="finance-field">Label<select name="category_id" required><?php echo financeExpenseCategoryOptions($categories, $expenseForm['category_id']); ?></select></label>
                    <label class="finance-field">Amount<input type="number" step="0.01" min="0" name="amount" value="<?php echo htmlspecialchars($expenseForm['amount']); ?>" required></label>
                    <label class="finance-field">To<input name="vendor" value="<?php echo htmlspecialchars($expenseForm['vendor']); ?>"></label>
                    <details class="finance-more"><summary>More</summary><div class="finance-more-grid"><label class="finance-field">Currency<input name="currency" maxlength="10" value="<?php echo htmlspecialchars($expenseForm['currency']); ?>"></label><label class="finance-field">Status<select name="status"><?php foreach (['paid', 'planned', 'reimbursed', 'cancelled'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $expenseForm['status'] === $status ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option><?php endforeach; ?></select></label><label class="finance-field">Method<select name="payment_method"><?php foreach (['bank_transfer', 'cash', 'card', 'mobile_money', 'other'] as $method): ?><option value="<?php echo $method; ?>" <?php echo $expenseForm['payment_method'] === $method ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $method))); ?></option><?php endforeach; ?></select></label><label class="finance-field wide">Note<input name="description" value="<?php echo htmlspecialchars($expenseForm['description']); ?>"></label><label class="finance-field wide">File<input type="file" name="receipt_file" accept=".pdf,.png,.jpg,.jpeg,.webp"></label></div></details>
                    <div class="finance-actions end"><button class="finance-btn finance-btn-loud finance-btn-out" type="submit">Save</button><?php if ($expenseForm['id'] > 0): ?><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('out', $dateFrom, $dateTo)); ?>">Cancel</a><?php endif; ?></div>
                </form>
            </div>
        </section>

        <section class="finance-modal" id="finance-modal-funding" role="dialog" aria-modal="true" aria-labelledby="finance-modal-funding-title" aria-hidden="true" hidden <?php echo $ledgerForm['id'] > 0 ? 'data-finance-open-on-load="1"' : ''; ?>>
            <div class="finance-modal-backdrop" data-finance-modal-close></div>
            <div class="finance-modal-card finance-modal-card-funding">
                <div class="finance-form-title"><h2 id="finance-modal-funding-title"><?php echo $ledgerForm['id'] > 0 ? 'Edit funding' : 'Add funding'; ?></h2><div class="finance-actions"><?php echo financeHelp('Owner money, loans, assets, and opening corrections. Owner payouts go in Out.'); ?><button class="finance-modal-close" type="button" data-finance-modal-close aria-label="Close">x</button></div></div>
                <form method="POST" class="finance-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="hidden" name="tab" value="capital"><input type="hidden" name="finance_action" value="save_ledger_transaction"><input type="hidden" name="transaction_id" value="<?php echo (int) $ledgerForm['id']; ?>">
                    <label class="finance-field">Type<select name="transaction_type" id="finance-ledger-type"><?php foreach ($ledgerTypes as $typeKey => $typeMeta): ?><?php if ((string) $typeKey === 'owner_draw') { continue; } ?><option value="<?php echo htmlspecialchars((string) $typeKey); ?>" <?php echo $ledgerForm['transaction_type'] === (string) $typeKey ? 'selected' : ''; ?>><?php echo htmlspecialchars(financeLedgerTypeLabel((string) $typeKey, (array) $typeMeta)); ?></option><?php endforeach; ?></select></label>
                    <label class="finance-field">Date<input type="date" name="transaction_date" value="<?php echo htmlspecialchars($ledgerForm['transaction_date']); ?>" required></label>
                    <label class="finance-field">Account<select name="cash_account_id" required><?php echo financeAccountOptions($cashAccounts, $ledgerForm['cash_account_id']); ?></select></label>
                    <label class="finance-field">Amount<input type="number" step="0.01" min="0" name="amount" value="<?php echo htmlspecialchars($ledgerForm['amount']); ?>" required></label>
                    <?php if ($financeOwnerOptions !== []): ?><label class="finance-field">Owner<select name="owner_user_id"><option value="">None</option><?php foreach ($financeOwnerOptions as $owner): ?><option value="<?php echo (int) ($owner['user_id'] ?? 0); ?>" <?php echo (string) ($owner['user_id'] ?? '') === $ledgerForm['owner_user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($owner['display_name'] ?? $owner['email'] ?? 'Owner')); ?></option><?php endforeach; ?></select></label><?php endif; ?>
                    <label class="finance-field wide">From/To<input name="counterparty" value="<?php echo htmlspecialchars($ledgerForm['counterparty']); ?>"></label>
                    <details class="finance-more"><summary>More</summary><div class="finance-more-grid"><label class="finance-field">Currency<input name="currency" maxlength="10" value="<?php echo htmlspecialchars($ledgerForm['currency']); ?>"></label><label class="finance-field wide">Note<input name="memo" value="<?php echo htmlspecialchars($ledgerForm['memo']); ?>"></label></div></details>
                    <details class="finance-more" id="finance-opening-details" <?php echo $ledgerForm['transaction_type'] === 'opening_balance' ? 'open' : 'hidden'; ?>><summary>Opening correction</summary><div class="finance-more-grid"><label class="finance-field">Bank<input type="number" step="0.01" min="0" name="opening_cash" value="<?php echo htmlspecialchars($ledgerForm['opening_cash']); ?>"></label><label class="finance-field">Owed to us<input type="number" step="0.01" min="0" name="opening_receivables" value="<?php echo htmlspecialchars($ledgerForm['opening_receivables']); ?>"></label><label class="finance-field">Bills owed<input type="number" step="0.01" min="0" name="opening_payables" value="<?php echo htmlspecialchars($ledgerForm['opening_payables']); ?>"></label><label class="finance-field">Loans<input type="number" step="0.01" min="0" name="opening_loan_balance" value="<?php echo htmlspecialchars($ledgerForm['opening_loan_balance']); ?>"></label><label class="finance-field">Things owned<input type="number" step="0.01" min="0" name="opening_assets" value="<?php echo htmlspecialchars($ledgerForm['opening_assets']); ?>"></label><label class="finance-field">Owner value<input type="number" step="0.01" min="0" name="opening_equity" value="<?php echo htmlspecialchars($ledgerForm['opening_equity']); ?>"></label></div></details>
                    <div class="finance-actions end"><button class="finance-btn finance-btn-loud finance-btn-funding" type="submit">Save</button><?php if ($ledgerForm['id'] > 0): ?><a class="finance-btn" href="<?php echo htmlspecialchars(financeTabUrl('capital', $dateFrom, $dateTo)); ?>">Cancel</a><?php endif; ?></div>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var previouslyFocused = null;
    var focusable = 'button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])';
    var openModal = function (id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        previouslyFocused = document.activeElement;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('finance-modal-open');
        var first = modal.querySelector(focusable);
        if (first) first.focus();
    };
    var closeModal = function (modal) {
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.finance-modal:not([hidden])')) {
            document.body.classList.remove('finance-modal-open');
        }
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        }
    };

    document.querySelectorAll('[data-finance-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            openModal(String(button.getAttribute('data-finance-open') || ''));
        });
    });
    document.querySelectorAll('[data-finance-modal-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(button.closest('.finance-modal'));
        });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        closeModal(document.querySelector('.finance-modal:not([hidden])'));
    });
    document.querySelectorAll('[data-finance-open-on-load]').forEach(function (modal) {
        openModal(modal.id);
    });

    document.querySelectorAll('[data-finance-report-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var reportType = String(tab.getAttribute('data-finance-report-tab') || '');
            var widget = tab.closest('.finance-report-tabbed');
            if (!widget || reportType === '') return;

            widget.querySelectorAll('[data-finance-report-tab]').forEach(function (button) {
                var active = button.getAttribute('data-finance-report-tab') === reportType;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                button.tabIndex = active ? 0 : -1;
            });

            widget.querySelectorAll('[data-finance-report-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-finance-report-panel') !== reportType;
            });
        });
    });

    var type = document.getElementById('finance-ledger-type');
    var details = document.getElementById('finance-opening-details');
    if (type && details) {
        var sync = function () {
            var opening = type.value === 'opening_balance';
            details.hidden = !opening;
            if (opening) details.open = true;
        };
        type.addEventListener('change', sync);
        sync();
    }
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
