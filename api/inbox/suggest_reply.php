<?php
/**
 * Inbox Suggest Reply API
 * Compatibility shim over the unified customer reply assistant.
 */

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
use CRM\Database;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$communicationId = (int) ($input['communication_id'] ?? $_GET['communication_id'] ?? 0);
$channel = trim((string) ($input['channel'] ?? $_GET['channel'] ?? ''));
$contactId = (int) ($input['contact_id'] ?? $_GET['contact_id'] ?? 0);
$userId = (int) (Auth::user()['id'] ?? 0);

try {
    $service = new CustomerReplyAssistantService();
    if ($communicationId > 0) {
        $result = $service->generateDraftFromCommunication($communicationId, $userId, [
            'surface' => 'inbox',
            'goal' => 'draft',
            'channel' => $channel,
            'contact_id' => $contactId,
        ]);
    } else {
        $result = $service->generateDraftFromContact($contactId, $channel !== '' ? $channel : 'email', $userId, [
            'surface' => 'inbox',
            'goal' => 'draft',
        ]);
    }

    $compat = (array) ($result['compat'] ?? []);
    $policy = (array) ($result['policy'] ?? []);

    echo json_encode([
        'success' => true,
        'channel' => $result['channel'] ?? ($channel !== '' ? $channel : 'email'),
        'subject' => $compat['subject'] ?? '',
        'body' => $compat['body'] ?? '',
        'source' => $result['source'] ?? 'fallback',
        'fallback' => (bool) ($result['fallback'] ?? false),
        'warning' => $result['warning'] ?? null,
        'mode' => $result['mode'] ?? 'inbox_suggest',
        'intent' => $result['intent'] ?? 'draft_customer_reply',
        'communication_id' => $result['communication_id'] ?? $communicationId,
        'contact_id' => $result['contact_id'] ?? $contactId,
        'thread_id' => $result['thread_id'] ?? null,
        'run_id' => $result['run_id'] ?? null,
        'resolution_status' => $result['resolution_status'] ?? null,
        'execution_status' => $result['execution_status'] ?? null,
        'policy' => $policy,
        'draft' => $result['draft'] ?? [],
        'summary_text' => $result['summary_text'] ?? '',
        'decision' => $policy['decision'] ?? null,
        'qualification_reasons' => $policy['reasons'] ?? [],
        'warnings' => $policy['warnings'] ?? [],
        'can_execute' => (bool) ($policy['can_execute'] ?? false),
        'approval_required' => (bool) ($policy['approval_required'] ?? false),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate reply draft',
        'details' => $e->getMessage(),
    ]);
}
