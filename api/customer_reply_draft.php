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
use CRM\Security;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$payload = $method === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?? $_POST)
    : $_GET;

if ($method === 'POST') {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::validateCSRF($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

$communicationId = (int) ($payload['communication_id'] ?? 0);
$contactId = (int) ($payload['contact_id'] ?? 0);
$channel = trim((string) ($payload['channel'] ?? 'email'));
$surface = trim((string) ($payload['surface'] ?? 'conversation'));
$goal = trim((string) ($payload['goal'] ?? 'draft'));
$draftOnly = filter_var($payload['draft_only'] ?? true, FILTER_VALIDATE_BOOLEAN);

try {
    $service = new CustomerReplyAssistantService();
    $userId = (int) (Auth::user()['id'] ?? 0);

    if ($communicationId > 0) {
        $result = $service->generateDraftFromCommunication($communicationId, $userId, [
            'surface' => $surface,
            'goal' => $goal,
            'channel' => $channel,
            'contact_id' => $contactId,
            'draft_only' => $draftOnly,
        ]);
    } else {
        $result = $service->generateDraftFromContact($contactId, $channel, $userId, [
            'surface' => $surface,
            'goal' => $goal,
            'draft_only' => $draftOnly,
        ]);
    }

    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate customer reply draft',
        'details' => $e->getMessage(),
    ]);
}
