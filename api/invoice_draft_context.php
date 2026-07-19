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
use CRM\Services\CommercialDocumentComposerService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!Authorization::canAny(['invoices.create', 'invoices.edit', 'invoices.view'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

try {
    $draft = (new CommercialDocumentComposerService())->compose($payload);
    echo json_encode([
        'success' => true,
        'autofill' => $draft['autofill'],
        'readiness' => $draft['readiness'],
        'guidance' => $draft['guidance'],
        'suggested_products' => $draft['suggested_products'],
        'deal_line_items' => $draft['deal_line_items'],
        'linked_entities' => $draft['linked_entities'],
        'invoice' => [
            'document_type' => $draft['invoice']['document_type'] ?? 'invoice',
            'title' => $draft['invoice']['title'] ?? '',
            'issue_date' => $draft['invoice']['issue_date'] ?? '',
            'due_date' => $draft['invoice']['due_date'] ?? '',
            'valid_until' => $draft['invoice']['valid_until'] ?? '',
            'grand_total' => $draft['invoice']['grand_total'] ?? 0,
            'currency' => $draft['invoice']['currency'] ?? 'USD',
        ],
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
