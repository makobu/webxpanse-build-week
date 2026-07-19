<?php
/**
 * Clear WhatsApp Queue (Web endpoint)
 *
 * Removes all pending messages from the queue without sending them.
 * Marks related whatsapp_messages as failed (cancelled).
 *
 * Requires authentication (session + CSRF).
 */

ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\WhatsAppQueueProcessor;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Auth::check() || !Security::validateCSRF($csrfToken)) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in to clear the queue']);
    exit;
}

if (!Authorization::can('whatsapp.queue.manage', Auth::user())) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

if ((int) (WorkspaceContext::currentWorkspaceId() ?? 0) <= 0) {
    ob_clean();
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required']);
    exit;
}

$processor = new WhatsAppQueueProcessor();
$cleared = $processor->clearPending();

ob_clean();
echo json_encode([
    'success' => true,
    'cleared' => $cleared,
    'message' => $cleared > 0 ? "Cleared {$cleared} pending message(s)." : "No pending messages to clear."
], JSON_PRETTY_PRINT);
