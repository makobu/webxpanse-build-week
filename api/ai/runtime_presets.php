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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true) ?: $_POST;
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['csrf_token'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$preset = trim((string) ($payload['preset'] ?? ''));
$reason = trim((string) ($payload['reason'] ?? ''));
$userId = (int) ($user['id'] ?? 0);
$service = new AIRuntimeControlService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);

if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required for runtime control presets.']);
    exit;
}

$presetMap = [
    'pause_all_execution' => [
        'assistant' => 'paused',
        'customer_thread' => 'paused',
        'commercial_assistant' => 'paused',
        'workflow' => 'paused',
        'task_automation' => 'paused',
        'autonomous_tuning' => 'paused',
    ],
    'safe_mode' => [
        'coach' => 'suggest_only',
        'clarity_chat' => 'suggest_only',
        'assistant' => 'suggest_only',
        'customer_thread' => 'suggest_only',
        'commercial_assistant' => 'suggest_only',
        'workflow' => 'suggest_only',
        'task_automation' => 'paused',
        'autonomous_tuning' => 'diagnostics_only',
    ],
];

try {
    if ($preset === 'restore_normal') {
        foreach ($service->listSurfaces() as $surface) {
            $service->clearControl($surface, $userId, $reason !== '' ? $reason : 'Restore normal preset', $workspaceId);
        }
    } elseif (isset($presetMap[$preset])) {
        foreach ($presetMap[$preset] as $surface => $mode) {
            $service->setControl(
                $surface,
                $mode,
                $userId,
                $reason !== '' ? $reason : ('Applied preset: ' . $preset),
                null,
                ['source' => 'runtime_preset', 'preset' => $preset],
                $workspaceId
            );
        }
    } else {
        throw new InvalidArgumentException('Unknown preset.');
    }

    echo json_encode([
        'success' => true,
        'controls' => $service->getAllEffectiveControls($workspaceId),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
