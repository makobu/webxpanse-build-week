<?php
/**
 * Inbox Triage Action API
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
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Modules\UnifiedInbox;
use CRM\Services\InboxTriageService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}
$action = (string) ($payload['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '');
$communicationId = (int) ($payload['communication_id'] ?? $_POST['communication_id'] ?? $_GET['communication_id'] ?? 0);

if ($action === '' || $communicationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'action and communication_id are required']);
    exit;
}

$user = Auth::user();
$actorId = (int) ($user['id'] ?? 0);
$inbox = new UnifiedInbox();
$canViewAllConversations = Authorization::can('conversations.view_all', $user);
$ownerScope = $inbox->resolveOwnerScope(
    $payload['owner_scope'] ?? $_POST['owner_scope'] ?? $_GET['owner_scope'] ?? null,
    $canViewAllConversations
);

if (!$inbox->canUserAccessCommunication($communicationId, $actorId, $canViewAllConversations, $ownerScope)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Conversation not accessible for the current view scope']);
    exit;
}

$service = new InboxTriageService();

try {
    $ok = false;
    if ($action === 'revert_auto_apply') {
        $ok = $service->revertAutoApply($communicationId, $actorId);
    } elseif ($action === 'accept_suggestion') {
        $ok = $service->acceptSuggestion($communicationId, $actorId);
    } elseif ($action === 'snooze_task') {
        $hours = (int) ($payload['hours'] ?? $_POST['hours'] ?? 24);
        $ok = $service->snoozeTask($communicationId, $hours, $actorId);
    } elseif ($action === 'reassign_owner') {
        $newOwnerId = (int) ($payload['owner_id'] ?? $_POST['owner_id'] ?? 0);
        $ok = $service->reassignOwner($communicationId, $newOwnerId, $actorId);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unsupported action']);
        exit;
    }

    if (!$ok) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Action could not be applied']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
