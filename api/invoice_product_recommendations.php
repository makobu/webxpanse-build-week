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
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('invoices.view');

try {
    $invoiceModule = new Invoices();
    $recommendations = $invoiceModule->getSuggestedProducts(
        !empty($_GET['deal_id']) ? (int) $_GET['deal_id'] : null,
        !empty($_GET['contact_id']) ? (int) $_GET['contact_id'] : null,
        !empty($_GET['company_id']) ? (int) $_GET['company_id'] : null,
        !empty($_GET['document_type']) ? (string) $_GET['document_type'] : null,
        !empty($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : null
    );

    echo json_encode(['success' => true, 'recommendations' => $recommendations]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
