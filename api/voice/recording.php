<?php

require_once __DIR__ . '/../../vendor/autoload.php';
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2); $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../../config/constants.php';
\CRM\Database::init(require __DIR__ . '/../../config/database.php');
\CRM\Session::start();

if (!\CRM\Auth::check()) {
    http_response_code(401); exit;
}
$user = \CRM\Auth::user();
$workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
$userId = (int) ($user['id'] ?? 0);
$can = \CRM\Authorization::isSuperAdmin($user) || \CRM\Authorization::can('voice.recordings.listen', $user);
$installer = new \CRM\Services\WorkspaceSkillInstallService();
$catalog = new \CRM\Services\WorkspaceSkillCatalogService();
$entitlements = (new \CRM\Services\WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
if (!$can
    || !$installer->isInstalled($workspaceId, \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)
    || $catalog->isGloballyDeactivated(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)
    || empty($entitlements['recording'])) {
    http_response_code(403); exit;
}

$recording = [];
try {
    $recording = (new \CRM\Services\VoiceEvidenceAccessService())->recording(
        $workspaceId,
        max(1, (int) ($_GET['id'] ?? 0)),
        $userId,
        \CRM\Authorization::isSuperAdmin($user) || \CRM\Authorization::can('voice.calls.view_all', $user)
    );
    if ($recording === []) {
        http_response_code(404); exit;
    }
    header('Content-Type: ' . $recording['mime']);
    header('Content-Length: ' . filesize($recording['path']));
    header('Content-Disposition: inline; filename="' . $recording['filename'] . '"');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($recording['path']);
} catch (Throwable $e) {
    http_response_code(422);
} finally {
    if (!empty($recording['path']) && is_file($recording['path'])) @unlink($recording['path']);
}
