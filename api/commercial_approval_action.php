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
use CRM\Security;
use CRM\Services\CommercialAutomationApprovalService;
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

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$approvalId = (int) ($input['approval_id'] ?? 0);
$decision = trim((string) ($input['decision'] ?? ''));
$reason = trim((string) ($input['reason'] ?? ''));
$userId = (int) (Auth::user()['id'] ?? 0);

if ($approvalId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'approval_id and a valid decision are required']);
    exit;
}

$approvalService = new CommercialAutomationApprovalService();
$orchestrator = new CommercialAutomationOrchestrator();

try {
    if ($decision === 'approve') {
        $result = $orchestrator->executeApprovedAction($approvalId, $userId);
        if (!$result) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Approval could not be processed']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'approval' => $result['approval'] ?? $approvalService->getDetailedById($approvalId),
            'execution' => $result['execution'] ?? null,
            'deal_id' => $result['deal_id'] ?? null,
            'invoice_id' => $result['invoice_id'] ?? null,
            'commercial_run_id' => $result['commercial_run_id'] ?? null,
        ]);
        exit;
    }

    $rejectReason = $reason !== '' ? $reason : 'Rejected from approvals workbench';
    $approval = $approvalService->reject($approvalId, $userId, $rejectReason);
    if (!$approval) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Approval could not be rejected']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'approval' => $approvalService->getDetailedById($approvalId),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
