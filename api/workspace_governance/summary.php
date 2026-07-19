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
use CRM\Database;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'No active workspace selected.']);
    exit;
}

try {
    $service = new WorkspaceGovernanceService();
    $historyFilter = trim((string) ($_GET['history_filter'] ?? ''));
    $data = $service->getWorkspaceGovernanceData($workspaceId, (int) (Auth::user()['id'] ?? 0), $historyFilter);

    echo json_encode([
        'success' => true,
        'data' => [
            'workspace' => $data['workspace'] ?? null,
            'members' => $data['members'] ?? [],
            'pending_invites' => $data['pending_invites'] ?? [],
            'invites' => $data['invites'] ?? [],
            'slugs' => $data['slugs'] ?? [],
            'slug_history' => $data['slug_history'] ?? [],
            'history' => $data['history'] ?? [],
            'history_filter' => $data['history_filter'] ?? 'all',
            'history_filters' => $data['history_filters'] ?? ['all'],
            'capabilities' => $data['capabilities'] ?? [],
            'actor_membership' => $data['actor_membership'] ?? null,
            'pending_invite_count' => (int) ($data['pending_invite_count'] ?? 0),
        ],
    ]);
} catch (WorkspaceLaunchReadinessException $e) {
    http_response_code(503);
    echo json_encode([
        'error' => $e->getMessage(),
        'readiness' => $e->readiness(),
    ]);
} catch (\Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => $e->getMessage()]);
}
