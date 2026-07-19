<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\AIAutomationDiagnosticsService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
Authorization::requirePermission('ai.operations.manage', true);

$filters = [
    'deal_id' => (int) ($_GET['deal_id'] ?? 0),
    'invoice_id' => (int) ($_GET['invoice_id'] ?? 0),
    'contact_id' => (int) ($_GET['contact_id'] ?? 0),
    'task_id' => (int) ($_GET['task_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

if (($filters['deal_id'] + $filters['invoice_id'] + $filters['contact_id'] + $filters['task_id']) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one record filter is required.']);
    exit;
}

$service = new AIAutomationDiagnosticsService();

echo json_encode([
    'success' => true,
    'record' => [
        'deal_id' => $filters['deal_id'] ?: null,
        'invoice_id' => $filters['invoice_id'] ?: null,
        'contact_id' => $filters['contact_id'] ?: null,
        'task_id' => $filters['task_id'] ?: null,
    ],
    'timeline' => $service->getRecordTimeline($filters),
    'linked' => $service->getLinkedData($filters),
]);
