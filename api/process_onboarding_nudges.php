<?php
/**
 * Process onboarding lifecycle nudges.
 *
 * Default automation remains disabled unless ONBOARDING_NUDGES_AUTOMATION_ENABLED
 * is enabled. A valid PROCESS_QUEUE_SECRET token or authenticated CSRF request is
 * required for manual processing.
 */

ini_set('display_errors', 0);
ob_start();

header('Content-Type: application/json');

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
use CRM\Session;
use CRM\Services\OnboardingLifecycleNudgeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$queueSecret = $_ENV['PROCESS_QUEUE_SECRET'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$authenticated = (!empty($queueSecret) && hash_equals((string) $queueSecret, (string) $token))
    || (Auth::check() && Security::validateCSRF((string) $csrfToken));

if (!$authenticated) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$action = strtolower((string) ($_GET['action'] ?? $input['action'] ?? 'both'));
$channel = (string) ($_GET['channel'] ?? $input['channel'] ?? 'in_app');
$limit = max(1, min(200, (int) ($_GET['limit'] ?? $input['limit'] ?? 50)));
$force = !empty($_GET['force']) || !empty($input['force']);
$actorUserId = Auth::check() ? (int) (Auth::user()['id'] ?? 0) : 0;
$service = new OnboardingLifecycleNudgeService();

try {
    $summary = ['queued' => null, 'processed' => null];
    if ($action === 'queue' || $action === 'both') {
        $summary['queued'] = $service->queueLifecycleNudgesForStuckWorkspaces($limit, $channel, $actorUserId, $force);
    }
    if ($action === 'send' || $action === 'both') {
        $summary['processed'] = $service->processDueQueuedNudges($limit, $actorUserId, $force);
    }
    if (!in_array($action, ['queue', 'send', 'both'], true)) {
        throw new RuntimeException('Unsupported action. Use queue, send, or both.');
    }

    ob_clean();
    echo json_encode(['success' => true, 'summary' => $summary], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Onboarding nudge processing failed',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
