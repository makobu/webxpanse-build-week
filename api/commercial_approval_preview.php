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
use CRM\Services\CommercialAutomationOrchestrator;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('commercial_automation.approvals', true);

$approvalId = (int) ($_GET['approval_id'] ?? 0);
if ($approvalId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'approval_id is required']);
    exit;
}

try {
    $preview = (new CommercialAutomationOrchestrator())->previewApprovedAction($approvalId);
    if (!$preview) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Approval not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'approval' => $preview['approval'],
        'preview' => $preview['preview'],
        'diagnostics' => $preview['diagnostics'],
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
