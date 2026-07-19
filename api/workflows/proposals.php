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
use CRM\Session;
use CRM\Services\WorkflowAutomationProposalService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('workflow_automation.approvals', true);

$service = new WorkflowAutomationProposalService();
$proposalId = (int) ($_GET['id'] ?? 0);

if ($proposalId > 0) {
    $proposal = $service->getById($proposalId);
    if (!$proposal) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Proposal not found']);
        exit;
    }

    echo json_encode(['success' => true, 'proposal' => $proposal]);
    exit;
}

echo json_encode([
    'success' => true,
    'proposals' => $service->listAll([
        'status' => (string) ($_GET['status'] ?? ''),
        'proposal_type' => (string) ($_GET['proposal_type'] ?? ''),
        'workflow_id' => (int) ($_GET['workflow_id'] ?? 0),
        'search' => (string) ($_GET['search'] ?? ''),
        'limit' => (int) ($_GET['limit'] ?? 100),
    ]),
]);
