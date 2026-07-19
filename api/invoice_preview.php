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
use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Modules\InvoiceSettings;
use CRM\Services\InvoiceRenderer;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
Session::closeWrite();

try {
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    $invoice = (new Invoices())->getById($invoiceId);
    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['error' => 'Invoice not found']);
        exit;
    }
    $settingsOverride = null;
    if (!empty($_GET['theme'])) {
        $settingsOverride = (new InvoiceSettings())->get();
        $settingsOverride['visual_theme'] = (string) $_GET['theme'];
    }
    echo json_encode([
        'success' => true,
        'html' => (new InvoiceRenderer())->renderHtml($invoice, $settingsOverride),
        'theme' => $settingsOverride['visual_theme'] ?? null,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
