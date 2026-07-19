<?php
/**
 * Outcome events API
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\OutcomeEventService;
use CRM\Services\OutcomeRolloutService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!Security::validateCSRF((string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$eventKey = trim((string) ($payload['event_key'] ?? ''));
if ($eventKey === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'event_key is required']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

try {
    $rollout = new OutcomeRolloutService();
    if (!$rollout->isEnabledForUser($userId)) {
        echo json_encode(['success' => true, 'enabled' => false]);
        exit;
    }

    $service = new OutcomeEventService();
    $service->track($eventKey, [
        'user_id' => $userId,
        'contact_id' => !empty($payload['contact_id']) ? (int) $payload['contact_id'] : null,
        'deal_id' => !empty($payload['deal_id']) ? (int) $payload['deal_id'] : null,
        'event_source' => 'api.outcomes.events',
        'event_at' => date('Y-m-d H:i:s'),
        'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
    ]);

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
