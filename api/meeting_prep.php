<?php
/**
 * Meeting Prep API
 * Generates AI meeting prep summary for a contact (optionally focused on a deal)
 */

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
use CRM\Modules\MeetingPrepService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$contactId = (int) ($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);
$dealId = !empty($_GET['deal_id']) || !empty($_POST['deal_id'])
    ? (int) ($_GET['deal_id'] ?? $_POST['deal_id'])
    : null;

if (!$contactId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contact_id required']);
    exit;
}

try {
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    $contact = Database::queryOne(
        "SELECT id
         FROM contacts
         WHERE id = ?
           AND workspace_id = ?
         LIMIT 1",
        [$contactId, $workspaceId]
    );
    if (!$contact) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Contact not found']);
        exit;
    }

    if ($dealId !== null) {
        $deal = Database::queryOne(
            "SELECT id
             FROM deals
             WHERE id = ?
               AND workspace_id = ?
               AND contact_id = ?
             LIMIT 1",
            [$dealId, $workspaceId, $contactId]
        );
        if (!$deal) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Contact not found']);
            exit;
        }
    }

    $service = new MeetingPrepService();
    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);
    $result = $service->getPrepSummary($contactId, $dealId, $userId);
    $summary = trim((string) ($result['summary'] ?? ''));
    $keyPoints = array_values(array_filter($result['key_points'] ?? [], static fn($v) => (is_string($v) && trim($v) !== '') || is_array($v)));
    $openQuestions = array_values(array_filter($result['open_questions'] ?? [], static fn($v) => is_string($v) && trim($v) !== ''));
    $suggestedTopics = array_values(array_filter($result['suggested_topics'] ?? [], static fn($v) => is_string($v) && trim($v) !== ''));

    if ($summary === '' && !$keyPoints && !$openQuestions && !$suggestedTopics) {
        throw new \RuntimeException('AI returned empty meeting prep');
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary !== '' ? $summary : 'Meeting prep generated.',
        'key_points' => $keyPoints,
        'open_questions' => $openQuestions,
        'suggested_topics' => $suggestedTopics,
        'used_fallback' => !empty($result['used_fallback'])
    ]);
} catch (\Exception $e) {
    error_log('Meeting prep API error: ' . $e->getMessage());
    try {
        $user = Auth::user();
        $userId = (int) ($user['id'] ?? 0);
        $service = $service ?? new MeetingPrepService();
        $fallback = $service->buildFallbackSummary($contactId, $dealId, $userId);
        echo json_encode([
            'success' => true,
            'summary' => $fallback['summary'],
            'key_points' => $fallback['key_points'],
            'open_questions' => $fallback['open_questions'],
            'suggested_topics' => $fallback['suggested_topics'],
            'used_fallback' => true,
            'fallback_reason' => 'ai_unavailable_or_failed'
        ]);
    } catch (\Throwable $fallbackError) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $fallbackError->getMessage()]);
    }
}
