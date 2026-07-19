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
use CRM\Services\AIRuntimeControlService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
Authorization::requirePermission('ai.operations.manage', true);

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
$service = new AIRuntimeControlService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);

if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required for runtime control logs.']);
    exit;
}

echo json_encode([
    'success' => true,
    'log' => $service->getRecentLog($limit, $workspaceId),
]);
