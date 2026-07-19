<?php
/**
 * Process WhatsApp Queue (Web endpoint)
 *
 * Call this to send pending WhatsApp messages. For non-technical users:
 * - Use the "Send pending messages" button on the WhatsApp Messages page
 * - Or set up a cron job to run every minute: GET this URL
 *
 * Requires authentication (session or valid token).
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

$queueSecret = $_ENV['PROCESS_QUEUE_SECRET'] ?? '';
$token = $_SERVER['HTTP_X_QUEUE_TOKEN'] ?? $_POST['token'] ?? $_GET['token'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$workerAuthenticated = !empty($queueSecret) && is_string($token) && hash_equals((string) $queueSecret, $token);
$sessionAuthenticated = Auth::check() && Security::validateCSRF($csrfToken);
$authenticated = $workerAuthenticated || $sessionAuthenticated;

if (!$authenticated) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in to process the queue']);
    exit;
}

if ($sessionAuthenticated && !Authorization::can('whatsapp.queue.manage', Auth::user())) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$workspaceId = $workerAuthenticated ? null : (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if (!$workerAuthenticated && ($workspaceId ?? 0) <= 0) {
    ob_clean();
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required']);
    exit;
}

$limit = min(max(1, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 10)), 30);
$processor = new WhatsAppQueueProcessor();
$pendingBefore = $processor->getPendingCount();
$stats = $processor->process($limit, $workspaceId > 0 ? $workspaceId : null);
$pendingAfter = $processor->getPendingCount();

ob_clean();
echo json_encode([
    'success' => true,
    'sent' => $stats['sent'],
    'failed' => $stats['failed'],
    'processed' => $stats['processed'],
    'pending_before' => $pendingBefore,
    'pending_after' => $pendingAfter,
    'message' => $stats['sent'] > 0
        ? "Sent {$stats['sent']} message(s). " . ($pendingAfter > 0 ? "{$pendingAfter} still pending." : "All done!")
        : ($pendingAfter > 0 ? "{$pendingAfter} message(s) pending. " . implode(' ', array_slice($stats['errors'], 0, 2)) : "No pending messages."),
    'errors' => array_slice($stats['errors'], 0, 5)
], JSON_PRETTY_PRINT);
