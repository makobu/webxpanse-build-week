<?php
/**
 * Dismiss celebration milestone - marks as celebrated in user preferences.
 * POST: { csrf_token, celebration: 'first_contact'|'first_task'|'first_deal' }
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\UserPreferences;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

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

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$aiCoachInstaller = new WorkspaceSkillInstallService();
if ((new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::SKILL_AI_COACH)
    || (!Authorization::isSuperAdmin($user) && !$aiCoachInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH))) {
    http_response_code(403);
    echo json_encode(['error' => 'AI Coach is not installed for this workspace']);
    exit;
}

$input = $_POST;
if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}

$csrfToken = $input['csrf_token'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

$celebration = $input['celebration'] ?? '';
if (!in_array($celebration, ['first_contact', 'first_task', 'first_deal'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid celebration type']);
    exit;
}

$preferences = new UserPreferences();
$celebratedRaw = $preferences->getPreference($userId, 'celebrated_milestones') ?? '';
$celebrated = $celebratedRaw !== '' ? array_filter(explode(',', $celebratedRaw)) : [];
if (!in_array($celebration, $celebrated, true)) {
    $celebrated[] = $celebration;
}
$preferences->setPreference($userId, 'celebrated_milestones', implode(',', $celebrated));

echo json_encode(['success' => true]);
