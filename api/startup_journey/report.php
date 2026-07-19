<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\StartupJourneyReportService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$user = Auth::user();
$canViewJourney = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user);
if (!$canViewJourney) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Clarity Journey view access is required.']);
    exit;
}

$canManageJourney = Authorization::isSuperAdmin($user) || Authorization::can('workspace.skills.manage', $user);
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

$service = new StartupJourneyReportService();

if ($method === 'GET') {
    $latest = $service->latest($workspaceId, $userId);
    echo json_encode([
        'success' => true,
        'latest_report' => $latest,
        'availability' => [
            'has_report' => $latest !== null,
            'can_generate' => $canManageJourney,
            'csrf_required' => true,
            'artifact_type' => 'journey_report',
        ],
    ], JSON_UNESCAPED_SLASHES);
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

if (!$canManageJourney) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Clarity Journey management access is required to generate a report.']);
    exit;
}

try {
    $report = $service->build($workspaceId, $userId, true);
    $artifactId = $service->saveArtifact($workspaceId, $userId, $report);
    $report['report_meta'] = array_merge((array) ($report['report_meta'] ?? []), [
        'artifact_id' => $artifactId,
    ]);

    echo json_encode([
        'success' => true,
        'report' => $report,
        'artifact_id' => $artifactId,
        'availability' => [
            'has_report' => true,
            'can_generate' => true,
            'csrf_required' => true,
            'artifact_type' => 'journey_report',
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('Clarity Journey report generation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not generate the Journey Report right now.']);
}
