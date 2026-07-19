<?php
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Currencies;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;
use CRM\Modules\Products;
use CRM\Security;
use CRM\Services\InvoiceTemplateService;
use CRM\Services\UnifiedCommercialCatalogService;
use CRM\Services\WorkspaceScopeService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('invoices.edit');

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoiceModule = new Invoices();
$invoice = $invoiceModule->getById($invoiceId);
if (!$invoice) {
    header('Location: invoices.php');
    exit;
}

$invoiceSettings = (new InvoiceSettings())->get();
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$activeCurrencies = $currenciesModule->getActiveCurrencies();
$workspaceScope = new WorkspaceScopeService();
$workspaceClause = $workspaceScope->workspaceClause();
$workspaceId = $workspaceScope->requireActiveWorkspaceId();
$products = (new Products())->list();
$workspacePackages = Authorization::isSuperAdmin(Auth::user() ?: [])
    ? (new UnifiedCommercialCatalogService())->catalogForWorkspace($workspaceId)['packages']
    : [];
$invoiceTemplates = (new InvoiceTemplateService())->getAvailableTemplates();
$contacts = Database::query(
    "SELECT id, first_name, last_name, email
     FROM contacts
     WHERE {$workspaceClause['sql']}
     ORDER BY first_name, last_name",
    $workspaceClause['params']
);
$companies = Database::query(
    "SELECT id, name
     FROM companies
     WHERE {$workspaceClause['sql']}
     ORDER BY name",
    $workspaceClause['params']
);
$deals = Database::query(
    "SELECT id, title
     FROM deals
     WHERE {$workspaceClause['sql']}
     ORDER BY created_at DESC
     LIMIT 200",
    $workspaceClause['params']
);
$users = Database::query(
    "SELECT u.id, u.email
     FROM workspace_memberships wm
     INNER JOIN users u ON u.id = wm.user_id
     WHERE wm.workspace_id = ?
       AND wm.membership_status = 'active'
     ORDER BY u.email",
    [$workspaceId]
);
$prefill = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $lineItems = json_decode((string) ($_POST['line_items_json'] ?? '[]'), true);
            if (!is_array($lineItems)) {
                throw new \InvalidArgumentException('Line items must be valid JSON.');
            }
            $invoiceModule->update($invoiceId, [
                'document_type' => $_POST['document_type'] ?? null,
                'deal_id' => !empty($_POST['deal_id']) ? (int) $_POST['deal_id'] : null,
                'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
                'company_id' => !empty($_POST['company_id']) ? (int) $_POST['company_id'] : null,
                'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
                'currency' => $_POST['currency'] ?? null,
                'issue_date' => $_POST['issue_date'] ?? null,
                'due_date' => $_POST['due_date'] ?? null,
                'valid_until' => $_POST['valid_until'] ?? null,
                'payment_terms_days' => $_POST['payment_terms_days'] ?? null,
                'tax_mode' => $_POST['tax_mode'] ?? null,
                'tax_rate' => $_POST['tax_rate'] ?? null,
                'title' => $_POST['title'] ?? null,
                'intro_text' => $_POST['intro_text'] ?? null,
                'notes' => $_POST['notes'] ?? null,
                'terms' => $_POST['terms'] ?? null,
                'billing_name' => $_POST['billing_name'] ?? null,
                'billing_email' => $_POST['billing_email'] ?? null,
                'billing_phone' => $_POST['billing_phone'] ?? null,
                'billing_address' => $_POST['billing_address'] ?? null,
                'shipping_address' => $_POST['shipping_address'] ?? null,
                'template_key' => $_POST['template_key'] ?? null,
                'line_items' => $lineItems,
                'increment_revision' => !empty($_POST['increment_revision']),
                'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
            ]);
            header('Location: invoice_view.php?id=' . $invoiceId . '&success=updated');
            exit;
        } catch (\PDOException $e) {
            error_log('Invoice update database failure: ' . $e->getMessage());
            $error = 'The invoice could not be updated. Please try again.';
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Invoice update failed: ' . $e->getMessage());
            $error = 'The invoice could not be updated. Please try again.';
        }
    }
}

$pageTitle = 'Edit Invoice - ' . brandProductName();
$pageHeading = 'Edit Commercial Document';
$pageSubheading = 'Update pricing, terms, and negotiation revisions.';
$submitLabel = 'Save Changes';
ob_start();
if ($error): ?><div class="content-card" style="background:#fee2e2;border-color:#ef4444;color:#991b1b;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif;
include __DIR__ . '/../views/partials/invoice_form.php';
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
