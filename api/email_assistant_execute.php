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
use CRM\Modules\EmailAssistantHandler;
use CRM\Session;
use CRM\Security;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

Authorization::requirePermission('settings.email_assistant', true);

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$communicationId = (int) ($input['communication_id'] ?? 0);
$goal = trim((string) ($input['goal'] ?? 'send'));
$action = trim((string) ($input['action'] ?? 'send_reply'));

if ($communicationId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'communication_id is required']);
    exit;
}

$handler = new EmailAssistantHandler();
$userId = (int) (Auth::user()['id'] ?? 0);

if ($action === 'send_latest_quote') {
    $service = new \CRM\Services\EmailAssistantApplicationService();
    $result = $service->sendCustomerThreadLatestQuote($communicationId, $userId);
} else {
    $result = $handler->handleCustomerThreadSend($communicationId, $userId, ['goal' => $goal]);
}

echo json_encode([
    'success' => true,
    'decision' => $result['policy']['decision'] ?? null,
    'qualification_decision' => $result['policy']['decision'] ?? null,
    'qualification_reasons' => $result['policy']['reasons'] ?? [],
    'confidence_score' => $result['policy']['confidence_score'] ?? null,
    'context_quality_score' => $result['policy']['context_quality_score'] ?? null,
    'goal_relevance_score' => $result['policy']['goal_relevance_score'] ?? null,
    'mode' => $result['policy']['mode'] ?? null,
    'warnings' => $result['policy']['warnings'] ?? [],
    'approval_required' => (bool) ($result['policy']['approval_required'] ?? false),
    'can_execute' => (bool) ($result['policy']['can_execute'] ?? false),
] + $result);
