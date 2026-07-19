<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\CacheManager;
use CRM\Database;
use CRM\Session;
use CRM\Services\MarketplaceCacheService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplacePerformanceService;

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
if (!Authorization::isSuperAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only superadmins can view Marketplace performance.']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$moduleKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($_GET['module'] ?? ''))) ?: '';
if ($workspaceId <= 0 || $moduleKey === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing workspace or module']);
    exit;
}

$cacheKey = MarketplaceCacheService::performanceKey($workspaceId, $moduleKey);
$cache = new CacheManager();

try {
    $payload = $cache->get($cacheKey);
    if (!is_array($payload)) {
        $payload = [
            'success' => true,
            'performance' => (new WorkspaceMarketplacePerformanceService())->buildModulePerformance($workspaceId, $moduleKey, 30),
            'generated_at' => gmdate('c'),
        ];
        $cache->set($cacheKey, $payload, 300);
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Marketplace performance API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load performance']);
}
