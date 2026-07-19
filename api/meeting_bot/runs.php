<?php

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
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\MeetingBotService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$service = new MeetingBotService(workspaceId: (int) (WorkspaceContext::currentWorkspaceId() ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Authorization::requirePermission('meeting_bot.view_runs', true);
    $runId = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($runId > 0) {
        $run = $service->getRunById($runId);
        if (!$run) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Run not found']);
            exit;
        }
        echo json_encode(['success' => true, 'run' => $run]);
        exit;
    }

    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    echo json_encode(['success' => true, 'runs' => $service->getRecentRuns($limit)]);
    exit;
}

Authorization::requirePermission('meeting_bot.view_runs', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$action = trim((string) ($input['action'] ?? ''));
if ($action !== 'retry_process') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unsupported action']);
    exit;
}

try {
    $runId = (int) ($input['run_id'] ?? 0);
    echo json_encode($service->retryRunProcessing($runId));
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
