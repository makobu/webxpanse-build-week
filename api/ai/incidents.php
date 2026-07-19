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
use CRM\Services\AIAutomationIncidentService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
Authorization::requirePermission('ai.operations.manage', true);

$service = new AIAutomationIncidentService();

echo json_encode([
    'success' => true,
    'incidents' => $service->getIncidents([
        'status' => trim((string) ($_GET['status'] ?? '')),
        'incident_key' => trim((string) ($_GET['incident_key'] ?? '')),
        'severity' => trim((string) ($_GET['severity'] ?? '')),
        'limit' => (int) ($_GET['limit'] ?? 100),
    ]),
]);
