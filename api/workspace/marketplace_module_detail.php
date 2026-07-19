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
$moduleKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($_GET['module'] ?? ''))) ?: '';
if ($workspaceId <= 0 || $moduleKey === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing workspace or module']);
    exit;
}

$cacheKey = MarketplaceCacheService::moduleDetailKey($workspaceId, $userId, $moduleKey);
$cache = new CacheManager();

try {
    $payload = $cache->get($cacheKey);
    if (!is_array($payload)) {
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $module = $catalog->findForWorkspace($moduleKey, $workspaceId, true);
        if ($module === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Marketplace module not found']);
            exit;
        }

        $readiness = $installer->buildReadinessForModule($workspaceId, $userId, $moduleKey);
        $access = (new WorkspaceMarketplaceAccessService($catalog, $installer))->accessForDefinition($workspaceId, $userId, $module);
        $payload = [
            'success' => true,
            'module' => $module,
            'installed' => $installer->isInstalled($workspaceId, $moduleKey),
            'readiness' => $readiness,
            'access' => $access,
            'generated_at' => gmdate('c'),
        ];
        $cache->set($cacheKey, $payload, 60);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Marketplace module detail API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load module detail']);
}
