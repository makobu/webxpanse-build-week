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

$service = new AIRuntimeControlService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required for runtime controls.']);
    exit;
}

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'controls' => $service->getAllEffectiveControls($workspaceId),
        'configured_controls' => $service->getConfiguredControls($workspaceId),
        'surfaces' => $service->listSurfaces(),
        'modes' => $service->listModes(),
    ]);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$surface = trim((string) ($payload['surface'] ?? ''));
$reason = trim((string) ($payload['reason'] ?? ''));
$userId = (int) ($user['id'] ?? 0);

try {
    if ($method === 'DELETE') {
        if ($surface === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Surface is required']);
            exit;
        }

        $ok = $service->clearControl($surface, $userId, $reason !== '' ? $reason : 'Cleared from API', $workspaceId);
        echo json_encode([
            'success' => $ok,
            'control' => $service->getEffectiveControl($surface, $workspaceId),
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $mode = trim((string) ($payload['mode'] ?? ''));
    $expiresAt = trim((string) ($payload['expires_at'] ?? ''));
    if ($surface === '' || $mode === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Surface and mode are required']);
        exit;
    }

    $service->setControl(
        $surface,
        $mode,
        $userId,
        $reason !== '' ? $reason : 'Updated from API',
        $expiresAt !== '' ? $expiresAt : null,
        ['source' => 'runtime_controls_api'],
        $workspaceId
    );

    echo json_encode([
        'success' => true,
        'control' => $service->getEffectiveControl($surface, $workspaceId),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
