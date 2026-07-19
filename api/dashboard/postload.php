<?php
/**
 * Dashboard post-load API.
 *
 * Triggers work that should not block the initial dashboard render.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Modules\UserPreferences;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\PluginRuntimeAuthorizationService;
use CRM\Services\SessionAutomationCoordinator;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = $_POST;
if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$csrfToken = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$userId = (int) (Auth::user()['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$preferences = new UserPreferences();
$aiCoachSetup = new AICoachWorkspaceSetupService();
$aiCoachInstalled = $aiCoachSetup->isInstalled($workspaceId);
$aiCoachWorkspaceEnabled = $aiCoachSetup->isWorkspaceEnabled($workspaceId);
$aiCoachBriefGate = $aiCoachSetup->getDashboardBriefGate($workspaceId, $userId);
$aiCoachRuntimeAvailable = $aiCoachInstalled
    && $aiCoachWorkspaceEnabled
    && (new PluginRuntimeAuthorizationService())->canAccessRuntimeModule($workspaceId, $userId, WorkspaceSkillCatalogService::SKILL_AI_COACH);
if (!$aiCoachRuntimeAvailable || empty($aiCoachBriefGate['recommendations_ready'])) {
    Session::closeWrite();
    echo json_encode([
        'success' => true,
        'ai_coach_enabled' => false,
        'seeded' => false,
        'changed' => false,
        'retired' => false,
        'retired_count' => 0,
    ]);
    exit;
}
Session::closeWrite();

$summary = (new SessionAutomationCoordinator())->runForUser($userId, [
    'current_page' => 'dashboard.php',
    'trigger' => 'dashboard_postload',
]);

$seedResult = null;
foreach ((array) ($summary['features'] ?? []) as $feature) {
    if (($feature['feature'] ?? '') === 'ai_task_auto_seed') {
        $seedResult = $feature;
        break;
    }
}

if (!is_array($seedResult)) {
    $seedResult = [
        'status' => 'skipped',
        'created_count' => 0,
        'skipped_count' => 0,
        'retired_count' => 0,
        'blocked_by_gate' => 0,
        'blocked_by_plan' => 0,
        'gate_redirected' => 0,
        'changed' => false,
        'retired' => false,
        'mode' => (string) $preferences->getEffectiveAIGuidanceMode($userId),
        'skipped_reason' => 'not_due',
    ];
}

$createdCount = (int) ($seedResult['created_count'] ?? 0);
$retiredCount = (int) ($seedResult['retired_count'] ?? 0);

echo json_encode([
    'success' => true,
    'ai_coach_enabled' => true,
    'seeded' => $createdCount > 0,
    'changed' => $createdCount > 0 || $retiredCount > 0,
    'retired' => $retiredCount > 0,
    'created_count' => $createdCount,
    'skipped' => (int) ($seedResult['skipped_count'] ?? 0),
    'retired_count' => $retiredCount,
    'blocked_by_gate' => (int) ($seedResult['blocked_by_gate'] ?? 0),
    'blocked_by_plan' => (int) ($seedResult['blocked_by_plan'] ?? 0),
    'gate_redirected' => (int) ($seedResult['gate_redirected'] ?? 0),
    'mode' => (string) ($seedResult['mode'] ?? ''),
    'due' => empty($seedResult['skipped_reason']),
    'summary' => $summary,
]);
