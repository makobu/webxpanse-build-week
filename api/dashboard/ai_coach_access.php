<?php
/**
 * Lightweight post-render AI Coach dashboard access check.
 */

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
use CRM\Session;
use CRM\Services\AICoachDashboardBriefService;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\PluginRuntimeAuthorizationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'enabled' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'enabled' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId = (int) (Auth::user()['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
Session::closeWrite();

try {
    $setup = new AICoachWorkspaceSetupService();
    $installed = $setup->isInstalled($workspaceId);
    $workspaceEnabled = $installed && $setup->isWorkspaceEnabled($workspaceId);
    $runtimeEnabled = $workspaceEnabled
        && (new PluginRuntimeAuthorizationService())->canAccessRuntimeModule(
            $workspaceId,
            $userId,
            WorkspaceSkillCatalogService::SKILL_AI_COACH
        );

    $coachBrief = null;
    if ($runtimeEnabled) {
        $readiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
        $coachBrief = (new AICoachDashboardBriefService())->build($readiness);
    }

    echo json_encode([
        'success' => true,
        'installed' => $installed,
        'enabled' => $installed && $workspaceEnabled && $runtimeEnabled,
        'coach_brief' => $coachBrief,
    ]);
} catch (\Throwable $e) {
    error_log('Dashboard AI Coach access check failed: ' . $e->getMessage());
    echo json_encode(['success' => true, 'installed' => false, 'enabled' => false]);
}
