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
use CRM\Services\WorkspaceMarketplaceRecommendationService;
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
    echo json_encode(['success' => false, 'error' => 'Your access profile cannot view Marketplace recommendations.']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$userId = (int) ($user['id'] ?? 0);
if ($workspaceId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'No active workspace']);
    exit;
}

$surface = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($_GET['surface'] ?? 'marketplace'))) ?: 'marketplace';
$cacheKey = MarketplaceCacheService::recommendationsKey($workspaceId, $userId, $surface);
$cache = new CacheManager();

try {
    $payload = $cache->get($cacheKey);
    if (!is_array($payload)) {
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $access = new WorkspaceMarketplaceAccessService($catalog, $installer);
        $recommendations = (new WorkspaceMarketplaceRecommendationService($catalog, $installer, null, null, $access))
            ->recommendationsForWorkspace($workspaceId, $userId, 3, $surface);
        $payload = [
            'success' => true,
            'recommendations' => array_values($recommendations),
            'generated_at' => gmdate('c'),
        ];
        $cache->set($cacheKey, $payload, 120);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Marketplace recommendations API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load recommendations']);
}
