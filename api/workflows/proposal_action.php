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
use CRM\Security;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$proposalId = (int) ($input['proposal_id'] ?? 0);
$decision = trim((string) ($input['decision'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));
$service = new WorkflowAutomationProposalService();
$userId = (int) (Auth::user()['id'] ?? 0);

try {
    if ($decision === 'approve') {
        if ($notes !== '') {
            $service->approve($proposalId, $userId, $notes);
        }
        $result = $service->applyProposal($proposalId, $userId);
        if (!$result) {
            throw new RuntimeException('Proposal could not be applied.');
        }
        echo json_encode(['success' => true, 'proposal' => $result['proposal'] ?? null, 'workflow_id' => $result['workflow_id'] ?? null]);
        exit;
    }

    if ($decision === 'reject') {
        $proposal = $service->reject($proposalId, $userId, $notes !== '' ? $notes : 'Rejected from workflow approvals');
        if (!$proposal) {
            throw new RuntimeException('Proposal could not be rejected.');
        }
        echo json_encode(['success' => true, 'proposal' => $proposal]);
        exit;
    }

    throw new RuntimeException('Unsupported proposal decision.');
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
