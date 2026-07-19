<?php

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
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\AutomationDetectorExecutionService;
use CRM\Services\AutomationReviewQueueService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Super Admin access is required.']);
    exit;
}

$queue = new AutomationReviewQueueService();
$detectors = new AutomationDetectorExecutionService();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'dashboard'));
        if ($action === 'dry_run') {
            echo json_encode(['success' => true, 'result' => $detectors->evaluateProductionReadinessDetectors()], JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode(['success' => true, 'dashboard' => $queue->dashboard((int) ($_GET['limit'] ?? 25))], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $action = trim((string) ($input['action'] ?? ''));
    $userId = (int) ($user['id'] ?? 0);

    if ($action === 'run_detectors') {
        $result = $detectors->runProductionReadinessDetectors($userId);
        $escalation = $queue->applyEscalationPolicy($userId);
        $notifications = $queue->dispatchEscalationNotifications($userId);
        echo json_encode([
            'success' => true,
            'result' => $result,
            'escalation' => $escalation,
            'notifications' => $notifications,
            'dashboard' => $queue->dashboard(25),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'apply_escalation_policy') {
        $result = $queue->applyEscalationPolicy($userId);
        $notifications = $queue->dispatchEscalationNotifications($userId);
        echo json_encode([
            'success' => true,
            'result' => $result,
            'notifications' => $notifications,
            'dashboard' => $queue->dashboard(25),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'dispatch_escalation_notifications') {
        echo json_encode([
            'success' => true,
            'result' => $queue->dispatchEscalationNotifications($userId),
            'dashboard' => $queue->dashboard(25),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'review_run') {
        $run = $queue->reviewRun(
            (int) ($input['run_id'] ?? 0),
            (string) ($input['decision'] ?? ''),
            $userId,
            (string) ($input['note'] ?? '')
        );

        echo json_encode([
            'success' => true,
            'run' => $run,
            'dashboard' => $queue->dashboard(25),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unsupported automation review action.']);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
