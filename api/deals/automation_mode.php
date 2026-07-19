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
use CRM\Services\AutoAdminService;
use CRM\Services\DealAutomationModeService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('settings.deal_automation', true);

$input = $_POST;
if (empty($input)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$mode = (string) ($input['mode'] ?? '');
$allowedModes = ['manual', 'suggest_only', 'auto_safe', 'full_auto'];
if (!in_array($mode, $allowedModes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid mode']);
    exit;
}

$autoAdmin = new AutoAdminService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($autoAdmin->isEnabledForWorkspace($workspaceId) && $autoAdmin->isManagedTab('deal_automation')) {
    http_response_code(423);
    echo json_encode(['success' => false, 'error' => 'Deal automation is managed by Auto Admin right now.']);
    exit;
}

try {
    $result = (new DealAutomationModeService())->updateMode($mode, (int) (Auth::user()['id'] ?? 0), $workspaceId);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

if (empty($result['success'])) {
    http_response_code((int) ($result['status'] ?? 422));
    echo json_encode([
        'success' => false,
        'error' => (string) ($result['error'] ?? 'Deal automation mode could not be updated.'),
        'state' => $result['state'] ?? [],
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => (string) ($result['message'] ?? 'Deal automation mode updated.'),
    'state' => $result['state'] ?? [],
]);
