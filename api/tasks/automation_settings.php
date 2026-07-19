<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceTaskAutomationSettingsService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();
header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = Auth::user() ?: [];
if (!Authorization::can('settings.general', $user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$service = new WorkspaceTaskAutomationSettingsService();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'settings' => $service->get($workspaceId)]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = $_POST;
if ($input === []) {
    $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
}
if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

try {
    $settings = $service->save($workspaceId, $input, (int) ($user['id'] ?? 0));
    echo json_encode(['success' => true, 'settings' => $settings]);
} catch (\Throwable $e) {
    error_log('Task automation settings update failed: ' . $e->getMessage());
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Unable to update task automation settings']);
}
