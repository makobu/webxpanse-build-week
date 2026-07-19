<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_feature_helpers.php';

use CRM\Authorization;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceVoiceEntitlementService;

$mobileVoiceAuth = mobileRequireAuth();
$mobileVoiceUser = mobileCurrentUser($mobileVoiceAuth);
$mobileVoiceWorkspaceId = mobileWorkspaceId($mobileVoiceAuth);
$mobileVoiceUserId = (int) ($mobileVoiceAuth['user_id'] ?? 0);
$mobileVoiceInput = mobileRequestBody();

function mobileVoiceCan(string $permission): bool
{
    global $mobileVoiceUser;

    return Authorization::isSuperAdmin($mobileVoiceUser)
        || Authorization::can($permission, $mobileVoiceUser);
}

function mobileVoiceRequire(string $permission): void
{
    if (mobileVoiceCan($permission)) {
        return;
    }

    mobileJson([
        'success' => false,
        'error' => 'You do not have permission to use this Voice feature.',
        'error_code' => 'permission_denied',
    ], 403);
}

function mobileVoiceRequireMethod(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === strtoupper($method)) {
        return;
    }

    mobileJson([
        'success' => false,
        'error' => 'Method not allowed.',
        'error_code' => 'method_not_allowed',
    ], 405);
}

function mobileVoiceRequireModule(): array
{
    global $mobileVoiceWorkspaceId;

    $catalog = new WorkspaceSkillCatalogService();
    $installer = new WorkspaceSkillInstallService($catalog);
    $skillKey = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
    $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($mobileVoiceWorkspaceId);
    if ($mobileVoiceWorkspaceId <= 0
        || !$installer->isInstalled($mobileVoiceWorkspaceId, $skillKey)
        || $catalog->isGloballyDeactivated($skillKey)
        || empty($entitlements['enabled'])) {
        mobileJson([
            'success' => false,
            'error' => 'Voice & Call Center is not installed, entitled, or enabled.',
            'error_code' => 'voice_unavailable',
            'setup_required' => true,
            'setup_url' => 'workspace_skills.php?module=' . $skillKey . '#setup',
        ], 403);
    }

    return $entitlements;
}

$mobileVoiceEntitlements = mobileVoiceRequireModule();
