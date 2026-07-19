<?php
/**
 * Send a test WhatsApp assistant digest to the current user's mapped number.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
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
use CRM\Session;
use CRM\Services\WhatsAppAssistantConfig;
use CRM\Services\WhatsAppAssistantDigestService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
if (!Authorization::can('settings.whatsapp', $user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$service = new WhatsAppAssistantDigestService();
$config = $service->validateDigestConfig();
if (empty($config['outbound_ready'])) {
    http_response_code(400);
    echo json_encode(['error' => $config['message'] ?: 'WhatsApp assistant outbound is not configured.']);
    exit;
}

$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Current user could not be resolved.']);
    exit;
}

$assistantConfig = new WhatsAppAssistantConfig();
$recipient = null;
foreach ($assistantConfig->getAuthorizedNumbers(true) as $row) {
    if ((int) ($row['user_id'] ?? 0) === $userId) {
        $recipient = $row;
        break;
    }
}

if (!$recipient) {
    http_response_code(400);
    echo json_encode(['error' => 'Current user does not have an active mapped WhatsApp assistant number.']);
    exit;
}

try {
    $result = $service->sendDigestToNumber($userId, (string) ($recipient['phone_number'] ?? ''), true);
    $status = (string) ($result['status'] ?? 'success');
    if (empty($result['success'])) {
        http_response_code($status === 'blocked' || $status === 'reopen_sent' || $status === 'reopen_pending' ? 409 : 400);
        echo json_encode([
            'success' => false,
            'error' => (string) ($result['message'] ?? $result['error'] ?? 'WhatsApp test digest was not sent.'),
            'message' => (string) ($result['message'] ?? $result['error'] ?? 'WhatsApp test digest was not sent.'),
            'phone_number' => (string) ($result['phone_number'] ?? ''),
            'task_count' => (int) ($result['task_count'] ?? 0),
            'message_count' => (int) ($result['message_count'] ?? 0),
            'sent_message_count' => (int) ($result['sent_message_count'] ?? 0),
            'status' => $status,
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'WhatsApp test digest sent.',
        'phone_number' => (string) ($result['phone_number'] ?? ''),
        'task_count' => (int) ($result['task_count'] ?? 0),
        'message_count' => (int) ($result['message_count'] ?? 0),
        'sent_message_count' => (int) ($result['sent_message_count'] ?? 0),
        'status' => $status,
    ]);
} catch (\Throwable $e) {
    error_log('WhatsApp assistant test digest error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The WhatsApp Assistant test digest could not be delivered. Check channel status and try again.']);
}
