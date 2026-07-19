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
use CRM\Modules\MeetingBotConfig;
use CRM\Security;
use CRM\Session;
use CRM\Services\MeetingBotService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$configModule = new MeetingBotConfig();
$headerSecret = trim((string) ($_SERVER['HTTP_X_MEETING_BOT_SCHEDULE_KEY'] ?? ''));
$querySecret = !empty($_ENV['ALLOW_MEETING_QUERY_SECRET_AUTH'])
    ? trim((string) ($_GET['key'] ?? ''))
    : '';
$providedSecret = $headerSecret !== '' ? $headerSecret : $querySecret;
$secretConfig = $providedSecret !== '' ? $configModule->findBySchedulingSecret($providedSecret) : null;
$secretAuthorized = $secretConfig !== null;
$workspaceId = $secretAuthorized ? (int) ($secretConfig['workspace_id'] ?? 0) : 0;
$actorUserId = null;

if ($providedSecret !== '' && !$secretAuthorized) {
    error_log('Meeting bot schedule unauthorized secret attempt from ' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($querySecret !== '' && $headerSecret === '' && $secretAuthorized) {
    error_log('Meeting bot schedule used deprecated query-string secret for workspace ' . (string) $workspaceId);
}

if ($secretAuthorized) {
    WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    if (trim((string) ($input['join_request_source'] ?? '')) === '') {
        $input['join_request_source'] = 'api';
    }
} else {
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    Authorization::requirePermission('meeting_bot.schedule', true);
    $user = Auth::user();
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $calendarInstaller = new WorkspaceSkillInstallService();
    if ((new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)
        || (!Authorization::isSuperAdmin($user) && !$calendarInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Calendar & Meetings is not installed for this workspace.']);
        exit;
    }

    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Security::validateCSRF($csrfToken)) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }
    $actorUserId = (int) (Auth::user()['id'] ?? 0);
}

try {
    $result = (new MeetingBotService(workspaceId: $workspaceId))->registerMeeting($input, $actorUserId);
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
