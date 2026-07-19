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
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchReadinessService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceBillingService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], Auth::user())) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'No active workspace selected.']);
    exit;
}

try {
    $readiness = (new WorkspaceLaunchReadinessService())->checkWorkspaceSaasReadiness('billing_portal');
    if (empty($readiness['ready'])) {
        throw new WorkspaceLaunchReadinessException('billing_portal', $readiness);
    }

    $saas = new SaaSBillingService();
    $portal = $saas->getWorkspaceBillingPortalData($workspaceId);
    $legacyService = new WorkspaceBillingService();
    $legacy = $legacyService->shouldExposeCompatibilityState()
        ? $legacyService->getBillingStateForUser(Auth::user())
        : null;

    echo json_encode([
        'success' => true,
        'data' => [
            'workspace_id' => $workspaceId,
            'saas_billing' => $portal,
            'workspace_billing' => $legacy,
            'readiness' => $readiness,
        ],
    ]);
} catch (WorkspaceLaunchReadinessException $e) {
    http_response_code(503);
    echo json_encode([
        'error' => $e->getMessage(),
        'readiness' => $e->readiness(),
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
