<?php
/**
 * Quick Reply Suggestions API
 * Returns AI-generated quick reply suggestions for WhatsApp conversation reply
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
use CRM\Modules\WhatsAppDraftGenerator;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'suggestions' => []]);
    exit;
}

$contactId = (int) ($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);
$message = trim($_GET['message'] ?? $_POST['message'] ?? '');

if (!$contactId) {
    http_response_code(400);
    echo json_encode(['error' => 'contact_id required', 'suggestions' => []]);
    exit;
}

if ($message === '') {
    echo json_encode(['success' => true, 'suggestions' => []]);
    exit;
}

try {
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $demoSession = $workspaceId > 0 ? (new DemoSessionScopeService())->activeSession($workspaceId) : null;
    if ($demoSession !== null) {
        echo json_encode([
            'success' => true,
            'protected_demo' => true,
            'suggestions' => [
                'Hi Amina, yes - I can revise the Riverside proposal with the matte stone finish, split payment terms, and WhatsApp reminders.',
                'I can hold the Friday installation slot while I send the updated numbers for your review today.',
                'I will also include Rose\'s rollout context so procurement has the full proposal package before tomorrow.',
            ],
        ]);
        exit;
    }
} catch (\Throwable $e) {
    error_log('Quick replies protected demo fallback check failed: ' . $e->getMessage());
}

try {
    $generator = new WhatsAppDraftGenerator();
    $raw = $generator->getQuickReplies($contactId, $message);

    $suggestions = [];
    if (is_array($raw)) {
        $suggestions = array_values(array_filter(array_map('trim', $raw)));
    } elseif (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $suggestions[] = trim($item);
                } elseif (is_array($item) && isset($item['message'])) {
                    $suggestions[] = trim($item['message']);
                }
            }
        } else {
            $lines = preg_split('/[\r\n]+/', trim($raw));
            foreach ($lines as $line) {
                $line = preg_replace('/^[\-\*\d\.\)]\s*/', '', trim($line));
                if (strlen($line) > 2 && strlen($line) < 500) {
                    $suggestions[] = $line;
                }
            }
        }
    }

    $suggestions = array_slice(array_unique($suggestions), 0, 5);
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
} catch (\Exception $e) {
    error_log('Quick replies API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'suggestions' => []]);
}
