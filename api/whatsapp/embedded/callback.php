<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$envFile = __DIR__ . '/../../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceConnectService;

Database::init(require __DIR__ . '/../../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!Security::validateCSRF((string) ($payload['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
    exit;
}

try {
    $service = new WorkspaceConnectService();
    $workspaceId = $service->requireWhatsAppManager(Auth::user());
    $state = $service->storeWhatsAppEmbeddedSignup(
        $workspaceId,
        (int) (Auth::user()['id'] ?? 0),
        $payload
    );

    echo json_encode([
        'success' => true,
        'whatsapp' => $state,
        'message' => ($state['status'] ?? '') === WorkspaceConnectService::STATUS_CONNECTED
            ? 'WhatsApp number connected.'
            : 'WhatsApp setup needs one more platform step.',
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
