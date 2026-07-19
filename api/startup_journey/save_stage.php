<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Services\StartupJourneyService;
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
    Auth::jsonAuthError('', 401, 'Authentication required');
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($input) || $input === []) {
    $input = $_POST;
}

if (!Security::validateCSRF((string) ($input['csrf_token'] ?? ''))) {
    Auth::jsonAuthError('csrf_invalid', 419, 'Invalid security token.');
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
    $stageKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($input['stage_key'] ?? '')))) ?: '';
    $responses = is_array($input['responses'] ?? null) ? (array) $input['responses'] : [];
    $saveSource = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($input['save_source'] ?? 'autosave')))) ?: 'autosave';
    if (!in_array($saveSource, ['autosave', 'manual', 'ai_draft_assist', 'beacon'], true)) {
        $saveSource = 'autosave';
    }
    $completionIntent = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($input['completion_intent'] ?? 'draft')))) ?: 'draft';
    if ($saveSource !== 'manual' || $completionIntent !== 'complete') {
        $completionIntent = 'draft';
    }

    $service = new StartupJourneyService();
    $completionReadiness = $service->stageReadinessForResponses($stageKey, $responses, 'draft');
    $completionAllowed = (string) ($completionReadiness['status'] ?? '') === 'ready_to_complete';
    $completionMessage = $completionAllowed
        ? 'Ready to complete when you choose Complete stage.'
        : 'Add more detail before completing this stage.';

    $eventMetadata = [
        'save_source' => $saveSource,
        'completion_allowed' => $completionAllowed,
    ];
    if (is_array($input['ai_assist_metadata'] ?? null)) {
        $eventMetadata['ai_assist_metadata'] = (array) $input['ai_assist_metadata'];
    }
    if ($completionIntent === 'complete' && !$completionAllowed) {
        $eventMetadata['completion_blocked'] = true;
        $completionMessage = 'Draft saved. Complete is blocked until this stage has enough detail.';
    }

    $journey = $service->saveStage(
        $workspaceId,
        $userId,
        $stageKey,
        $responses,
        (string) ($input['notes'] ?? ''),
        $completionIntent === 'complete' && $completionAllowed,
        $eventMetadata
    );
    $stage = (array) (($journey['stages'] ?? [])[$stageKey] ?? []);
    $status = (string) ($stage['status'] ?? 'not_started');
    $readiness = (array) ($stage['readiness'] ?? []);
    $progress = (array) ($journey['progress'] ?? []);
    $manualCompletionRequired = $status !== 'completed' && (string) ($readiness['status'] ?? '') === 'ready_to_complete';
    if ($status === 'completed') {
        $completionMessage = 'Stage completed.';
    } elseif ($completionIntent === 'complete' && !$completionAllowed) {
        $nextMissing = trim((string) ($readiness['next_missing_item'] ?? ''));
        if ($nextMissing !== '') {
            $completionMessage .= ' Next: ' . $nextMissing;
        }
    } elseif ($manualCompletionRequired) {
        $completionMessage = 'Draft saved. This stage is ready for manual completion.';
    } else {
        $completionMessage = 'Draft saved.';
    }

    echo json_encode([
        'success' => true,
        'stage_status' => $status,
        'readiness' => $readiness,
        'progress' => $progress,
        'filled_fields' => (int) ($stage['filled_fields'] ?? 0),
        'total_fields' => (int) ($stage['total_fields'] ?? 0),
        'completed_at' => (string) ($stage['completed_at'] ?? ''),
        'message' => $status === 'completed' ? 'Stage completed.' : 'Draft saved.',
        'save_source' => $saveSource,
        'completion_allowed' => $completionAllowed,
        'completion_message' => $completionMessage,
        'manual_completion_required' => $manualCompletionRequired,
    ], JSON_UNESCAPED_SLASHES);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('Clarity Journey save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save this stage right now.']);
}
