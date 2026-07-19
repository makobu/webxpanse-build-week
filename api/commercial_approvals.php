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
use CRM\Services\CommercialAutomationApprovalService;
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

$service = new CommercialAutomationApprovalService();
$approvalId = (int) ($_GET['id'] ?? 0);

try {
    if ($approvalId > 0) {
        $approval = $service->getDetailedById($approvalId);
        if (!$approval) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Approval not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'approval' => $approval,
        ]);
        exit;
    }

    $approvals = $service->listAll([
        'status' => trim((string) ($_GET['status'] ?? '')),
        'action_key' => trim((string) ($_GET['action_key'] ?? '')),
        'requested_by_type' => trim((string) ($_GET['requested_by_type'] ?? '')),
        'deal_id' => (int) ($_GET['deal_id'] ?? 0),
        'invoice_id' => (int) ($_GET['invoice_id'] ?? 0),
        'search' => trim((string) ($_GET['search'] ?? '')),
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        'limit' => (int) ($_GET['limit'] ?? 100),
    ]);

    echo json_encode([
        'success' => true,
        'approvals' => $approvals,
        'metrics' => $service->buildSummaryMetrics($approvals),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
