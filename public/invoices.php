<?php
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Modules\Currencies;
use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Modules\InvoiceSettings;
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspacePackageBillingInvoiceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;
use CRM\Session;

function invoiceUiLabel(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

function invoiceStatusBadgeClass(string $status): string
{
    return match ($status) {
        'paid', 'accepted', 'finalized' => 'badge-success',
        'sent', 'viewed', 'partially_paid' => 'badge-primary',
        'draft', 'revised' => 'badge-default',
        'overdue', 'cancelled' => 'badge-danger',
        default => 'badge-warning',
    };
}

function invoiceTypeBadgeClass(string $type): string
{
    return match ($type) {
        'invoice' => 'badge-primary',
        'quote' => 'badge-warning',
        'proforma' => 'badge-default',
        'credit_note' => 'badge-success',
        default => 'badge-default',
    };
}

function invoiceCustomerName(array $invoice): string
{
    $contactName = trim((string) (($invoice['contact_first_name'] ?? '') . ' ' . ($invoice['contact_last_name'] ?? '')));

    return $contactName !== ''
        ? $contactName
        : (string) ($invoice['company_name'] ?? $invoice['billing_name'] ?? '-');
}

function invoiceFormatDate(?string $value): string
{
    if (!$value) {
        return '-';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('M j, Y', $timestamp) : (string) $value;
}

function invoiceFormatDocumentAmount(array $invoice): string
{
    return (string) (($invoice['currency'] ?? 'USD') . ' ' . number_format((float) ($invoice['grand_total'] ?? 0), 2));
}

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('invoices.view');

$currenciesModule = new Currencies();
$invoiceSettings = (new InvoiceSettings())->get();
$module = new Invoices();
$workspaceScope = new WorkspaceScopeService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$currentUser = Auth::user() ?: [];
$currentUserId = (int) ($currentUser['id'] ?? 0);
Session::closeWrite();
$workspaceClause = $workspaceScope->workspaceClause();
$filters = [
    'document_type' => $_GET['document_type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'search' => trim((string) ($_GET['search'] ?? '')),
];
$documentView = (string) ($_GET['view'] ?? 'sales');
if (!in_array($documentView, ['sales', 'receipts'], true)) {
    $documentView = 'sales';
}
$invoices = $module->list($filters, 100, 0);
$packageReceipts = (new WorkspacePackageBillingInvoiceService())->listForWorkspace($workspaceId, 100);
$hasFilters = $filters['document_type'] !== '' || $filters['status'] !== '' || $filters['search'] !== '';
$stats = [
    'all_documents' => $module->count(),
    'quotes' => $module->count(['document_type' => 'quote']),
    'invoices' => $module->count(['document_type' => 'invoice']),
    'drafts' => $module->count(['status' => 'draft']),
    'paid' => $module->count(['status' => 'paid']),
    'overdue' => $module->count(['status' => 'overdue']),
];
$valueSummary = Database::queryOne(
    "SELECT
        COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN grand_total ELSE 0 END), 0) AS pipeline_total,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN grand_total ELSE 0 END), 0) AS paid_total
     FROM invoices
     WHERE {$workspaceClause['sql']}",
    $workspaceClause['params']
);
$pipelineTotal = (float) ($valueSummary['pipeline_total'] ?? 0);
$paidTotal = (float) ($valueSummary['paid_total'] ?? 0);
$currentDefaultCurrency = $currenciesModule->getDefault();
$defaultCurrency = (string) (($currentDefaultCurrency['code'] ?? '') ?: ($invoiceSettings['default_currency'] ?? 'USD'));
$pipelineDisplay = $currenciesModule->formatAmount($pipelineTotal, $defaultCurrency);
$paidDisplay = $currenciesModule->formatAmount($paidTotal, $defaultCurrency);

$pageTitle = 'Invoices - ' . brandProductName();
$invoicesGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_INVOICES);
$invoiceReady = !empty($invoiceSettings['enabled'])
    && trim((string) ($invoiceSettings['bank_instructions'] ?? $invoiceSettings['bank_account_number'] ?? '')) !== '';
$invoicesExperienceMode = (new UIExperienceService())->modeForUser($currentUser, $workspaceId);
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $currentUserId, [
    'mode' => $invoicesExperienceMode,
    'surface' => 'invoices',
    'current_page' => 'invoices.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'invoices' => $invoices,
    'invoice_ready' => $invoiceReady,
    'total_count' => count($invoices),
]);
$guidedDemoPreparedInvoice = null;
$guidedDemoPreparedInvoiceId = 0;
$guidedDemoPreparedInvoiceUrl = '';
$guidedDemoPreparedFromResult = (string) ($_GET['guided_demo_result'] ?? '') === 'prepared_document';
if ((string) ($_GET['guided_demo'] ?? '') === '1') {
    try {
        $guidedDemoState = (new GuidedDemoSessionService())->state($workspaceId, $currentUserId);
        $guidedDemoSessionMetadata = (array) ($guidedDemoState['session']['metadata'] ?? []);
        $guidedDemoPreparedInvoiceId = (int) ($guidedDemoSessionMetadata['simulated_invoice_id'] ?? 0);
        if ($guidedDemoPreparedInvoiceId > 0) {
            $candidate = $module->getById($guidedDemoPreparedInvoiceId);
            if ($candidate !== null) {
                $guidedDemoPreparedInvoice = $candidate;
                $guidedDemoPreparedInvoiceUrl = 'invoice_view.php?id=' . $guidedDemoPreparedInvoiceId . '&guided_demo=1';
            }
        }
    } catch (Throwable) {
        $guidedDemoPreparedInvoice = null;
        $guidedDemoPreparedInvoiceId = 0;
        $guidedDemoPreparedInvoiceUrl = '';
    }
}
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">
<?php echo PageGuideVideoUi::assets(); ?>

<style>
.invoice-overview-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.85fr);
    gap: 1rem;
    margin-bottom: 2rem;
}

.invoice-highlight-card {
    padding: 1.75rem;
    border-radius: 18px;
    color: #e2e8f0;
    background:
        radial-gradient(circle at top right, rgba(125, 211, 252, 0.22), transparent 34%),
        linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
}

.invoice-highlight-card h2 {
    margin: 0 0 0.6rem;
    font-size: 1.55rem;
    color: #ffffff;
}

.invoice-highlight-card p {
    margin: 0;
    max-width: 640px;
    color: rgba(226, 232, 240, 0.86);
    line-height: 1.6;
}

.invoice-highlight-metrics {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1.4rem;
}

.invoice-highlight-metric {
    min-width: 155px;
    padding: 0.95rem 1rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(4px);
}

.invoice-highlight-metric-label {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: rgba(226, 232, 240, 0.7);
}

.invoice-highlight-metric-value {
    margin-top: 0.45rem;
    font-size: 1.5rem;
    font-weight: 700;
    color: #ffffff;
}

.invoice-insight-card {
    display: grid;
    gap: 1rem;
}

.invoice-insight-panel {
    padding: 1.25rem;
    border-radius: 16px;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.2);
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
}

.invoice-insight-label {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #64748b;
}

.invoice-insight-value {
    margin-top: 0.5rem;
    font-size: 1.7rem;
    font-weight: 700;
    color: #0f172a;
}

.invoice-insight-note {
    margin-top: 0.35rem;
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.5;
}

.invoice-guided-demo-state {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin: 0 0 1rem;
    padding: 1rem 1.1rem;
    border: 1px solid #bbf7d0;
    border-radius: 14px;
    background: linear-gradient(135deg, #ecfdf5 0%, #f8fafc 100%);
    color: #166534;
    box-shadow: 0 14px 28px rgba(22, 101, 52, 0.1);
}

.invoice-guided-demo-state[hidden] {
    display: none;
}

.invoice-guided-demo-state strong {
    display: block;
    color: #14532d;
    font-size: 1rem;
}

.invoice-guided-demo-state__eyebrow {
    margin: 0 0 0.25rem;
    color: #15803d;
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-transform: uppercase;
}

.invoice-guided-demo-state__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.4rem;
}

.invoice-guided-demo-state__meta span {
    display: inline-flex;
    align-items: center;
    min-height: 1.55rem;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #166534;
    font-size: 0.78rem;
    font-weight: 700;
}

.invoice-guided-demo-state__safety {
    margin: 0.45rem 0 0;
    color: #166534;
    font-size: 0.88rem;
}

.invoice-guided-demo-state__actions {
    flex: 0 0 auto;
}

.invoice-table-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem 1.5rem 0;
    flex-wrap: wrap;
}

.invoice-table-header h2 {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
}

.invoice-table-header p {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.875rem;
}

.invoice-table-wrap {
    overflow-x: auto;
}

.invoice-title-link {
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
}

.invoice-title-link:hover {
    color: #1d4ed8;
}

.invoice-cell-title {
    min-width: 220px;
}

.invoice-primary-text {
    color: #0f172a;
    font-weight: 600;
}

.invoice-meta-text {
    display: block;
    margin-top: 0.25rem;
    color: #64748b;
    font-size: 0.78rem;
    line-height: 1.45;
}

.invoice-number-stack {
    display: grid;
    gap: 0.25rem;
}

.invoice-filter-note {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #4338ca;
    font-size: 0.82rem;
    font-weight: 600;
}

.invoice-total-cell {
    min-width: 135px;
}

.invoice-action-cell {
    text-align: right;
}

.premium-table tbody tr.is-guided-demo-prepared {
    background: linear-gradient(90deg, #ecfdf5 0%, #ffffff 58%);
    box-shadow: inset 4px 0 0 #22c55e;
}

.invoice-guided-demo-row-chip {
    display: inline-flex;
    margin-top: 0.35rem;
    padding: 0.22rem 0.48rem;
    border-radius: 999px;
    background: #dcfce7;
    color: #166534;
    font-size: 0.72rem;
    font-weight: 800;
}

@media (max-width: 960px) {
    .invoice-overview-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .invoice-table-header {
        padding: 1.25rem 1rem 0;
    }

    .invoice-table-wrap {
        overflow: visible;
    }

    .premium-table thead {
        display: none;
    }

    .premium-table,
    .premium-table tbody,
    .premium-table tr,
    .premium-table td {
        display: block;
        width: 100%;
    }

    .premium-table tbody {
        padding: 0.75rem;
    }

    .premium-table tbody tr {
        margin-bottom: 0.9rem;
        border: 1px solid rgba(148, 163, 184, 0.18);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .premium-table tbody tr.is-guided-demo-prepared {
        background: linear-gradient(180deg, #ecfdf5 0%, #ffffff 58%);
        box-shadow: inset 4px 0 0 #22c55e, 0 10px 24px rgba(15, 23, 42, 0.06);
    }

    .premium-table td {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        padding: 0.85rem 1rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        text-align: right;
    }

    .premium-table td:last-child {
        border-bottom: none;
    }

    .premium-table td::before {
        content: attr(data-label);
        color: #475569;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        text-align: left;
    }

    .invoice-cell-title,
    .invoice-total-cell,
    .invoice-action-cell {
        min-width: 0;
    }

    .invoice-action-cell {
        text-align: right;
    }

    .invoice-guided-demo-state {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Invoices & Quotes</h1>
                <p>Manage proposals, invoices, and follow-through from first quote to final payment.</p>
            </div>
            <div class="page-header-actions">
                <a href="invoice_create.php?document_type=quote" class="btn-premium-secondary">
                    <i class="fas fa-file-signature"></i>
                    New Quote
                </a>
                <a href="invoice_create.php?document_type=invoice" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Invoice
                </a>
            </div>
        </div>
        <?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>

        <div class="invoice-overview-grid" data-guided-demo-target="invoice-demo-documents">
            <div
                class="invoice-guided-demo-state"
                data-guided-demo-prepared-document
                <?php echo $guidedDemoPreparedInvoice !== null ? 'data-guided-demo-prepared-document-visible="1"' : 'hidden'; ?>
                <?php echo $guidedDemoPreparedFromResult ? 'data-guided-demo-result-marker="prepared_document"' : ''; ?>
            >
                <?php if ($guidedDemoPreparedInvoice !== null): ?>
                    <div>
                        <p class="invoice-guided-demo-state__eyebrow">Prepared demo document</p>
                        <strong data-guided-demo-document-title>
                            <?php echo htmlspecialchars((string) ($guidedDemoPreparedInvoice['title'] ?? 'Demo document')); ?>
                        </strong>
                        <div class="invoice-guided-demo-state__meta">
                            <span data-guided-demo-document-label>
                                <?php echo htmlspecialchars(invoiceUiLabel((string) ($guidedDemoPreparedInvoice['document_type'] ?? 'draft'))); ?>
                            </span>
                            <span><?php echo htmlspecialchars(invoiceUiLabel((string) ($guidedDemoPreparedInvoice['status'] ?? 'draft'))); ?></span>
                            <span><?php echo htmlspecialchars(invoiceFormatDocumentAmount($guidedDemoPreparedInvoice)); ?></span>
                        </div>
                        <p class="invoice-guided-demo-state__safety">Nothing was sent. This is a draft you can inspect from the document list.</p>
                    </div>
                    <div class="invoice-guided-demo-state__actions">
                        <a href="<?php echo htmlspecialchars($guidedDemoPreparedInvoiceUrl); ?>" class="btn-premium-secondary btn-premium-sm">
                            Open document
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <strong data-guided-demo-document-title>Demo document</strong>
                        <p class="invoice-guided-demo-state__safety">
                            Prepared as a <span data-guided-demo-document-label>draft</span>. Nothing was sent.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="invoice-highlight-card">
                <h2>Commercial documents at a glance</h2>
                <p>
                    Keep quotes, proformas, invoices, and credits in one clean workspace with fast filtering and clearer
                    status visibility for the team.
                </p>
                <div class="invoice-highlight-metrics">
                    <div class="invoice-highlight-metric">
                        <div class="invoice-highlight-metric-label">Open Pipeline</div>
                        <div class="invoice-highlight-metric-value"><?php echo htmlspecialchars($pipelineDisplay); ?></div>
                    </div>
                    <div class="invoice-highlight-metric">
                        <div class="invoice-highlight-metric-label">Paid Value</div>
                        <div class="invoice-highlight-metric-value"><?php echo htmlspecialchars($paidDisplay); ?></div>
                    </div>
                    <div class="invoice-highlight-metric">
                        <div class="invoice-highlight-metric-label">Filtered Results</div>
                        <div class="invoice-highlight-metric-value"><?php echo number_format($documentView === 'receipts' ? count($packageReceipts) : count($invoices)); ?></div>
                    </div>
                </div>
            </div>
            <div class="invoice-insight-card">
                <div class="invoice-insight-panel">
                    <div class="invoice-insight-label">Drafts Needing Attention</div>
                    <div class="invoice-insight-value"><?php echo number_format($stats['drafts']); ?></div>
                    <div class="invoice-insight-note">Documents still being prepared before sending or approval.</div>
                </div>
                <div class="invoice-insight-panel">
                    <div class="invoice-insight-label">Overdue Invoices</div>
                    <div class="invoice-insight-value" style="color:#b91c1c;"><?php echo number_format($stats['overdue']); ?></div>
                    <div class="invoice-insight-note">Outstanding invoices that likely need follow-up from finance or sales.</div>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <a href="invoices.php?view=sales" class="stat-card">
                <div class="stat-label">Sales Documents</div>
                <div class="stat-value"><?php echo number_format($stats['all_documents']); ?></div>
            </a>
            <a href="invoices.php?document_type=quote" class="stat-card">
                <div class="stat-label">Quotes</div>
                <div class="stat-value"><?php echo number_format($stats['quotes']); ?></div>
            </a>
            <a href="invoices.php?document_type=invoice" class="stat-card">
                <div class="stat-label">Invoices</div>
                <div class="stat-value"><?php echo number_format($stats['invoices']); ?></div>
            </a>
            <a href="invoices.php?status=paid" class="stat-card">
                <div class="stat-label">Paid</div>
                <div class="stat-value"><?php echo number_format($stats['paid']); ?></div>
            </a>
            <a href="invoices.php?status=overdue" class="stat-card">
                <div class="stat-label">Overdue</div>
                <div class="stat-value" style="color:#b91c1c;"><?php echo number_format($stats['overdue']); ?></div>
            </a>
            <a href="invoices.php?view=receipts" class="stat-card">
                <div class="stat-label">Package Receipts</div>
                <div class="stat-value"><?php echo number_format(count($packageReceipts)); ?></div>
            </a>
        </div>

        <?php if ($documentView === 'receipts'): ?>
        <div class="table-card">
            <div class="invoice-table-header">
                <div>
                    <h2>Package Receipts</h2>
                    <p>Immutable receipts issued by Clarity for successful workspace package charges and audited operator activations.</p>
                </div>
                <div class="invoice-filter-note"><i class="fas fa-receipt"></i><?php echo number_format(count($packageReceipts)); ?> receipt<?php echo count($packageReceipts) === 1 ? '' : 's'; ?> · Billing records</div>
            </div>
            <?php if ($packageReceipts === []): ?>
                <div class="empty-state"><p>No package receipts have been issued for this workspace yet.</p><a href="billing_payment_required.php">Review workspace packages</a></div>
            <?php else: ?>
                <div class="invoice-table-wrap">
                    <table class="premium-table">
                        <thead><tr><th>Number</th><th>Type</th><th>Package</th><th>Status</th><th>Workspace</th><th>Total</th><th>Issued</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($packageReceipts as $receipt): ?>
                                <?php $receiptStatus = (string) ($receipt['provider'] ?? '') === 'operator' ? 'Activated' : 'Paid'; ?>
                                <tr>
                                    <td data-label="Number"><span class="invoice-primary-text"><?php echo htmlspecialchars((string) ($receipt['document_number'] ?? '-')); ?></span></td>
                                    <td data-label="Type"><span class="badge badge-success">Package Receipt</span></td>
                                    <td data-label="Package"><span class="invoice-primary-text"><?php echo htmlspecialchars((string) ($receipt['package_name'] ?? 'Workspace package')); ?></span><span class="invoice-meta-text"><?php echo htmlspecialchars((string) ($receipt['package_code'] ?? '')); ?></span></td>
                                    <td data-label="Status"><span class="badge badge-success"><?php echo htmlspecialchars($receiptStatus); ?></span></td>
                                    <td data-label="Workspace"><span class="invoice-primary-text"><?php echo htmlspecialchars((string) ($receipt['buyer_workspace_name'] ?? 'Workspace')); ?></span><span class="invoice-meta-text"><?php echo htmlspecialchars((string) ($receipt['buyer_email'] ?? '')); ?></span></td>
                                    <td data-label="Total" class="invoice-total-cell"><span class="invoice-primary-text"><?php echo htmlspecialchars((string) (($receipt['currency'] ?? 'KES') . ' ' . number_format((float) ($receipt['grand_total'] ?? $receipt['amount'] ?? 0), 2))); ?></span><span class="invoice-meta-text">Platform purchase</span></td>
                                    <td data-label="Issued"><span class="invoice-primary-text"><?php echo htmlspecialchars(invoiceFormatDate((string) ($receipt['issued_at'] ?? ''))); ?></span></td>
                                    <td data-label="Action" class="invoice-action-cell"><a href="billing_invoice.php?id=<?php echo (int) ($receipt['id'] ?? 0); ?>" class="btn-premium-secondary btn-premium-sm">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="filters-card">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group" style="min-width:280px;">
                    <label for="invoice-search">Search</label>
                    <input
                        type="text"
                        id="invoice-search"
                        name="search"
                        value="<?php echo htmlspecialchars($filters['search']); ?>"
                        placeholder="Number, title, customer, or billing name"
                    >
                </div>
                <div class="filter-group">
                    <label for="invoice-document-type">Type</label>
                    <select id="invoice-document-type" name="document_type">
                        <option value="">All types</option>
                        <?php foreach (\CRM\Modules\Invoices::TYPES as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $filters['document_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(invoiceUiLabel($type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="invoice-status">Status</label>
                    <select id="invoice-status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (\CRM\Modules\Invoices::STATUSES as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $filters['status'] === $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(invoiceUiLabel($status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($hasFilters): ?>
                        <a href="invoices.php" class="btn-premium-secondary">Clear</a>
                    <?php endif; ?>
                    <?php if ($invoicesGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_INVOICES, 'Invoices & Quotes page guide', 'btn-premium-secondary'); ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-card">
            <div class="invoice-table-header">
                <div>
                    <h2>Document List</h2>
                    <p>Review every quote and invoice with clearer context on customer, status, value, and next action.</p>
                </div>
                <div class="invoice-filter-note">
                    <i class="fas fa-layer-group"></i>
                    <?php echo number_format(count($invoices)); ?> result<?php echo count($invoices) === 1 ? '' : 's'; ?>
                    <?php echo $hasFilters ? ' matching your filters' : ' in view'; ?>
                </div>
            </div>

            <?php if (empty($invoices)): ?>
                <div class="empty-state">
                    <p>No commercial documents found for the current filters.</p>
                    <a href="<?php echo $hasFilters ? 'invoices.php' : 'invoice_create.php?document_type=invoice'; ?>">
                        <?php echo $hasFilters ? 'Clear filters and browse all documents' : 'Create your first invoice'; ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="invoice-table-wrap">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Type</th>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <?php
                                $customerName = invoiceCustomerName($invoice);
                                $createdAt = invoiceFormatDate(isset($invoice['created_at']) ? (string) $invoice['created_at'] : null);
                                $issueDate = invoiceFormatDate(isset($invoice['issue_date']) ? (string) $invoice['issue_date'] : null);
                                $statusLabel = invoiceUiLabel((string) ($invoice['status'] ?? 'draft'));
                                $typeLabel = invoiceUiLabel((string) ($invoice['document_type'] ?? 'invoice'));
                                $isGuidedDemoPreparedRow = (int) ($invoice['id'] ?? 0) === $guidedDemoPreparedInvoiceId;
                                $invoiceViewUrl = 'invoice_view.php?id=' . (int) $invoice['id'] . ($isGuidedDemoPreparedRow ? '&guided_demo=1' : '');
                                ?>
                                <tr class="<?php echo $isGuidedDemoPreparedRow ? 'is-guided-demo-prepared' : ''; ?>"<?php echo $isGuidedDemoPreparedRow ? ' data-guided-demo-prepared-row="1"' : ''; ?>>
                                    <td data-label="Number">
                                        <div class="invoice-number-stack">
                                            <span class="invoice-primary-text"><?php echo htmlspecialchars((string) ($invoice['invoice_number'] ?? '-')); ?></span>
                                            <span class="invoice-meta-text">Issued <?php echo htmlspecialchars($issueDate); ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Type">
                                        <span class="badge <?php echo invoiceTypeBadgeClass((string) ($invoice['document_type'] ?? '')); ?>">
                                            <?php echo htmlspecialchars($typeLabel); ?>
                                        </span>
                                    </td>
                                    <td data-label="Title" class="invoice-cell-title">
                                        <a href="<?php echo htmlspecialchars($invoiceViewUrl); ?>" class="invoice-title-link">
                                            <?php echo htmlspecialchars((string) ($invoice['title'] ?? 'Untitled document')); ?>
                                        </a>
                                        <?php if ($isGuidedDemoPreparedRow): ?>
                                            <span class="invoice-guided-demo-row-chip">Demo draft ready</span>
                                        <?php endif; ?>
                                        <?php if (!empty($invoice['deal_title'])): ?>
                                            <span class="invoice-meta-text">Deal: <?php echo htmlspecialchars((string) $invoice['deal_title']); ?></span>
                                        <?php elseif (!empty($invoice['billing_email'])): ?>
                                            <span class="invoice-meta-text"><?php echo htmlspecialchars((string) $invoice['billing_email']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge <?php echo invoiceStatusBadgeClass((string) ($invoice['status'] ?? '')); ?>">
                                            <?php echo htmlspecialchars($statusLabel); ?>
                                        </span>
                                    </td>
                                    <td data-label="Customer">
                                        <span class="invoice-primary-text"><?php echo htmlspecialchars($customerName); ?></span>
                                        <?php if (!empty($invoice['contact_email'])): ?>
                                            <span class="invoice-meta-text"><?php echo htmlspecialchars((string) $invoice['contact_email']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Total" class="invoice-total-cell">
                                        <span class="invoice-primary-text">
                                            <?php echo htmlspecialchars(invoiceFormatDocumentAmount($invoice)); ?>
                                        </span>
                                        <span class="invoice-meta-text">
                                            Balance <?php echo htmlspecialchars((string) (($invoice['currency'] ?? 'USD') . ' ' . number_format((float) ($invoice['balance_due'] ?? 0), 2))); ?>
                                        </span>
                                    </td>
                                    <td data-label="Created">
                                        <span class="invoice-primary-text"><?php echo htmlspecialchars($createdAt); ?></span>
                                    </td>
                                    <td data-label="Action" class="invoice-action-cell">
                                        <a href="<?php echo htmlspecialchars($invoiceViewUrl); ?>" class="btn-premium-secondary btn-premium-sm">
                                            Open
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_INVOICES, 'How to use Invoices & Quotes', $invoicesGuideVideoUrl); ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
