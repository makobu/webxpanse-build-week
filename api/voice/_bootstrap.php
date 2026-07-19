<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

\CRM\Database::init(require __DIR__ . '/../../config/database.php');
\CRM\Session::start();
header('Content-Type: application/json; charset=UTF-8');

if (!\CRM\Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$voiceUser = \CRM\Auth::user();
$voiceWorkspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
$voiceUserId = (int) ($voiceUser['id'] ?? 0);
$voiceInput = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($voiceInput)) {
    $voiceInput = $_POST;
}

if (!function_exists('voiceApiCan')) {
    function voiceApiCan(string $permission): bool
    {
        global $voiceUser;
        return \CRM\Authorization::isSuperAdmin($voiceUser) || \CRM\Authorization::can($permission, $voiceUser);
    }
}

if (!function_exists('voiceApiRequire')) {
    function voiceApiRequire(string $permission): void
    {
        if (!voiceApiCan($permission)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
    }
}

if (!function_exists('voiceApiRequireCsrf')) {
    function voiceApiRequireCsrf(array $input): void
    {
        $token = (string) ($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!\CRM\Security::validateCSRF($token)) {
            http_response_code(419);
            echo json_encode(['success' => false, 'error' => 'Invalid security token']);
            exit;
        }
    }
}

$voiceInstaller = new \CRM\Services\WorkspaceSkillInstallService();
$voiceCatalog = new \CRM\Services\WorkspaceSkillCatalogService();
$voiceEntitlements = (new \CRM\Services\WorkspaceVoiceEntitlementService())->forWorkspace($voiceWorkspaceId);
if ($voiceWorkspaceId <= 0
    || !$voiceInstaller->isInstalled($voiceWorkspaceId, \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)
    || $voiceCatalog->isGloballyDeactivated(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)
    || empty($voiceEntitlements['enabled'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Voice & Call Center is not installed, entitled, or enabled.']);
    exit;
}
