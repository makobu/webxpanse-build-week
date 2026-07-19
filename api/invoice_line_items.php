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
use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('invoices.edit', true);

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invoice_id required']);
    exit;
}

$module = new Invoices();
$invoice = $module->getById($invoiceId);
if (!$invoice) {
    http_response_code(404);
    echo json_encode(['error' => 'Invoice not found']);
    exit;
}

$items = $invoice['line_items'] ?? [];
$items[] = [
    'product_id' => !empty($_POST['product_id']) ? (int) $_POST['product_id'] : null,
    'catalog_source_type' => (string) ($_POST['catalog_source_type'] ?? (!empty($_POST['product_id']) ? 'workspace_offer' : 'manual')),
    'billing_plan_price_id' => !empty($_POST['billing_plan_price_id']) ? (int) $_POST['billing_plan_price_id'] : null,
    'description' => $_POST['description'] ?? '',
    'quantity' => $_POST['quantity'] ?? 1,
    'unit_price' => $_POST['unit_price'] ?? 0,
    'discount_percent' => $_POST['discount_percent'] ?? 0,
    'tax_percent' => $_POST['tax_percent'] ?? ($invoice['tax_rate'] ?? 0),
];

try {
    $module->replaceLineItems($invoiceId, $items, (float) ($invoice['tax_rate'] ?? 0), (string) ($invoice['tax_mode'] ?? 'exclusive'));
    $module->recalculateTotals($invoiceId);
    echo json_encode(['success' => true, 'invoice' => $module->getById($invoiceId)]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
