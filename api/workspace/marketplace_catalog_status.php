<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\CacheManager;
use CRM\Database;
use CRM\Session;
use CRM\Services\MarketplaceCacheService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user = Auth::user() ?: [];
$canViewMarketplace = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user);
if (!$canViewMarketplace) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your access profile cannot view Marketplace modules.']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$userId = (int) ($user['id'] ?? 0);
if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'No active workspace']);
    exit;
}

$cache = new CacheManager();
$cacheKey = MarketplaceCacheService::catalogStatusKey($workspaceId, $userId);

$lockedRequirement = static function (array $access): string {
    if ((string) ($access['state'] ?? '') === WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN) {
        return 'Requires a paid subscription';
    }

    $pending = array_values((array) ($access['pending_requirements'] ?? []));
    $direct = (array) ($pending[0] ?? []);
    $label = trim((string) ($direct['module_label'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($direct['label'] ?? ''));
        $label = trim((string) preg_replace('/\s+(?:ready|installed)$/i', '', $label));
    }
    if ($label === '') {
        $label = trim((string) ($access['root_blocker_label'] ?? ''));
    }

    return $label !== '' ? 'Requires ' . $label : 'Prerequisite required';
};

try {
    $payload = $cache->get($cacheKey);
    if (!is_array($payload)) {
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $accessService = new WorkspaceMarketplaceAccessService($catalog, $installer);
        $canEditCatalog = Authorization::isSuperAdmin($user);
        $installed = $installer->installedForWorkspace($workspaceId);
        $installedByKey = [];
        foreach ($installed as $module) {
            $key = (string) ($module['key'] ?? '');
            if ($key !== '') {
                $installedByKey[$key] = true;
            }
        }

        $modules = [];
        $installedCount = 0;
        $availablePluginCount = 0;
        $setupNeededCount = 0;
        foreach ($catalog->availableForWorkspace($workspaceId, $canEditCatalog) as $module) {
            $skillKey = (string) ($module['key'] ?? '');
            if ($skillKey === '') {
                continue;
            }

            $access = $accessService->accessForDefinition($workspaceId, $userId, $module);
            $readiness = (array) ($access['readiness'] ?? []);
            if ($readiness === [] && !empty($installedByKey[$skillKey])) {
                $readiness = $installer->buildReadinessForModule($workspaceId, $userId, $skillKey);
            }

            $isInstalled = !empty($installedByKey[$skillKey]);
            $isLocked = !empty($access['is_locked']);
            $isReady = !empty($readiness['ready']);
            $readinessStatus = (string) ($readiness['status'] ?? '');
            $isSendingReady = $isReady && in_array($readinessStatus, ['warning', 'sending_ready'], true);
            $isRequired = !empty($module['plugin_metadata']['protected_install'])
                || !empty($module['plugin_metadata']['required_core'])
                || !empty($module['capabilities']['protected_install'])
                || !empty($module['capabilities']['required_core']);
            $catalogStatus = (string) ($module['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE);
            $statusClass = '';
            $statusText = 'Available';

            if ($canEditCatalog && $catalogStatus !== WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE) {
                $statusClass = 'is-muted';
                $statusText = $catalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED ? 'Deactivated' : 'Hidden';
            } elseif ($isLocked && $isInstalled) {
                $statusClass = 'is-locked';
                $statusText = 'Installed - locked';
            } elseif ($isLocked) {
                $statusClass = 'is-locked';
                $statusText = 'Locked';
            } elseif ($isRequired) {
                $statusClass = 'is-required';
                $statusText = 'Required';
            } elseif ($isInstalled && !$isReady) {
                $statusClass = 'is-needs-setup';
                $statusText = 'Needs setup';
            } elseif ($isInstalled && $isSendingReady) {
                $statusClass = 'is-warning';
                $statusText = 'Sending ready';
            } elseif ($isInstalled) {
                $statusClass = 'is-installed';
                $statusText = 'Ready';
            }

            $modules[$skillKey] = [
                'skill_key' => $skillKey,
                'is_installed' => $isInstalled,
                'access_state' => (string) ($access['state'] ?? ''),
                'is_locked' => $isLocked,
                'status_text' => $statusText,
                'status_class' => $statusClass,
                'readiness_text' => $isLocked ? $lockedRequirement($access) : ($isReady ? ($isSendingReady ? 'Inbox setup optional' : 'Ready to run') : ($isInstalled ? 'Setup path open' : '')),
                'readiness_class' => $isLocked || ($isInstalled && (!$isReady || $isSendingReady)) ? 'is-warning' : 'is-good',
                'show_readiness' => $isInstalled || $isLocked,
                'readiness' => [
                    'ready' => $isReady,
                    'status' => $readinessStatus,
                    'message' => (string) ($readiness['message'] ?? ''),
                    'blockers' => array_values((array) ($readiness['blockers'] ?? [])),
                ],
                'access' => $access,
            ];

            if ($isInstalled) {
                $installedCount++;
            }
            if (
                (string) ($module['module_type'] ?? 'skill') === 'plugin'
                && !$isInstalled
                && (string) ($access['state'] ?? '') === WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL
                && $catalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE
            ) {
                $availablePluginCount++;
            }
            if ($isInstalled && ($isLocked || !$isReady)) {
                $setupNeededCount++;
            }
        }

        $payload = [
            'success' => true,
            'modules' => $modules,
            'counts' => [
                'installed' => $installedCount,
                'available_plugins' => $availablePluginCount,
                'setup_needed' => $setupNeededCount,
            ],
            'generated_at' => gmdate('c'),
        ];
        $cache->set($cacheKey, $payload, 60);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Marketplace catalog status API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load Marketplace catalog status']);
}
