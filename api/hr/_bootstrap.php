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
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\PluginRuntimeEventService;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;
use CRM\Services\WorkspaceHRAnalyticsGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

function hrAnalyticsApiFilters(array $source): array
{
    return [
        'timeframe' => $source['timeframe'] ?? 'month',
        'role' => $source['role'] ?? '',
        'department' => $source['department'] ?? '',
        'user_id' => $source['user_id'] ?? null,
    ];
}

function hrAnalyticsRequireRuntimeReady(string $capabilityKey): int
{
    $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
    $user = Auth::user();
    (new WorkspaceBusinessIntelligenceGateService())->enforceJson($workspaceId, $user, 'Organization Intelligence');

    $gate = new WorkspaceHRAnalyticsGateService();
    if ($gate->isRuntimeReady($workspaceId, $user)) {
        return $workspaceId;
    }

    (new PluginRuntimeEventService())->record([
        'workspace_id' => $workspaceId,
        'user_id' => (int) ($user['id'] ?? 0),
        'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
        'capability_key' => 'hr_analytics.' . trim($capabilityKey),
        'event_type' => 'readiness_blocked',
        'status' => 'blocked',
        'error_code' => 'hr_analytics_setup_required',
        'metadata' => ['surface' => 'api/hr', 'capability' => trim($capabilityKey)],
    ]);

    http_response_code(403);
    echo json_encode($gate->jsonBlockPayload($workspaceId, $user));
    exit;
}
