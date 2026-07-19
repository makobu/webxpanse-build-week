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
use CRM\Services\BeginnerWorkSurfaceGuidanceService;
use CRM\Services\InvoiceTemplateService;
use CRM\Services\UnifiedCommercialCatalogService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('invoices.create');

$invoiceModule = new Invoices();
$invoiceSettings = (new InvoiceSettings())->get();
$currenciesModule = new Currencies();
$defaultCurrency = $currenciesModule->getDefault();
$activeCurrencies = $currenciesModule->getActiveCurrencies();
$workspaceScope = new WorkspaceScopeService();
$workspaceClause = $workspaceScope->workspaceClause();
$workspaceId = $workspaceScope->requireActiveWorkspaceId();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$products = (new Products())->list();
$workspacePackages = Authorization::isSuperAdmin($user)
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
$prefill = [
    'document_type' => $_GET['document_type'] ?? 'invoice',
    'deal_id' => !empty($_GET['deal_id']) ? (int) $_GET['deal_id'] : null,
    'contact_id' => !empty($_GET['contact_id']) ? (int) $_GET['contact_id'] : null,
    'company_id' => !empty($_GET['company_id']) ? (int) $_GET['company_id'] : null,
];
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
            $id = $invoiceModule->create([
                'document_type' => $_POST['document_type'] ?? 'invoice',
                'deal_id' => !empty($_POST['deal_id']) ? (int) $_POST['deal_id'] : null,
                'contact_id' => !empty($_POST['contact_id']) ? (int) $_POST['contact_id'] : null,
                'company_id' => !empty($_POST['company_id']) ? (int) $_POST['company_id'] : null,
                'assigned_to' => !empty($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null,
                'created_by' => (int) (Auth::user()['id'] ?? 0),
                'currency' => $_POST['currency'] ?? 'USD',
                'issue_date' => $_POST['issue_date'] ?? date('Y-m-d'),
                'due_date' => $_POST['due_date'] ?? null,
                'valid_until' => $_POST['valid_until'] ?? null,
                'payment_terms_days' => $_POST['payment_terms_days'] ?? 14,
                'tax_mode' => $_POST['tax_mode'] ?? 'exclusive',
                'tax_rate' => $_POST['tax_rate'] ?? 0,
                'title' => $_POST['title'] ?? '',
                'intro_text' => $_POST['intro_text'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'terms' => $_POST['terms'] ?? '',
                'billing_name' => $_POST['billing_name'] ?? '',
                'billing_email' => $_POST['billing_email'] ?? '',
                'billing_phone' => $_POST['billing_phone'] ?? '',
                'billing_address' => $_POST['billing_address'] ?? '',
                'shipping_address' => $_POST['shipping_address'] ?? '',
                'template_key' => $_POST['template_key'] ?? ($invoiceSettings['default_template_key'] ?? 'classic'),
                'line_items' => $lineItems,
            ]);
            header('Location: invoice_view.php?id=' . $id . '&success=created');
            exit;
        } catch (\PDOException $e) {
            error_log('Invoice creation database failure: ' . $e->getMessage());
            $error = 'The invoice could not be created. Please try again.';
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Invoice creation failed: ' . $e->getMessage());
            $error = 'The invoice could not be created. Please try again.';
        }
    }
}

$pageTitle = 'Create Commercial Document - ' . brandProductName();
$pageHeading = 'Create Commercial Document';
$pageSubheading = 'Compose a quote, proforma invoice, invoice, or credit note with live preview and commercialization guidance.';
$submitLabel = 'Create Document';
$invoiceCreateExperienceMode = (new UIExperienceService())->modeForUser($user, (int) (WorkspaceContext::currentWorkspaceId() ?? $workspaceId));
$workSurfaceGuidance = (new BeginnerWorkSurfaceGuidanceService())->guidanceFor($workspaceId, $userId, [
    'mode' => $invoiceCreateExperienceMode,
    'surface' => 'invoice_create',
    'current_page' => 'invoice_create.php',
    'source' => $_GET['source'] ?? 'direct',
    'action' => $_GET['action'] ?? '',
    'gap' => $_GET['gap'] ?? '',
    'document_type' => $prefill['document_type'] ?? 'invoice',
]);
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/work-surface-guidance.css">
<?php include __DIR__ . '/../views/partials/beginner_work_surface_guidance.php'; ?>
<?php
if ($error): ?><div class="content-card" style="background:#fee2e2;border-color:#ef4444;color:#991b1b;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif;
include __DIR__ . '/../views/partials/invoice_form.php';
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
