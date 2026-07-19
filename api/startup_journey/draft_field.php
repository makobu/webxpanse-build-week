<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\StartupJourneyDraftService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input) || $input === []) {
    $input = $_POST;
}

if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user) && !Authorization::can('workspace.skills.manage', $user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Clarity Journey management access is required.']);
    exit;
}

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$userId = (int) ($user['id'] ?? 0);
$catalog = new WorkspaceSkillCatalogService();
$installer = new WorkspaceSkillInstallService($catalog);
$marketplaceAccess = new WorkspaceMarketplaceAccessService($catalog, $installer);
if ($workspaceId <= 0 || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Clarity Journey is not installed for this workspace.']);
    exit;
}
$access = $marketplaceAccess->accessForModule($workspaceId, $userId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS);
if (empty($access['can_configure'])) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => (string) ($access['message'] ?? 'Clarity Journey is locked until prerequisite setup is complete.'),
        'next_action_url' => (string) ($access['next_action_url'] ?? 'workspace_skills.php'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $result = (new StartupJourneyDraftService())->draft(
        $workspaceId,
        $userId,
        preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($input['stage_key'] ?? '')))) ?: '',
        preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($input['field_key'] ?? '')))) ?: '',
        (string) ($input['current_value'] ?? ''),
        is_array($input['responses'] ?? null) ? (array) $input['responses'] : []
    );
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Clarity Journey draft field error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not draft this answer right now.']);
}
