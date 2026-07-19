<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_serializers.php';

use CRM\Modules\Targets;
use CRM\Services\TargetCoordinator;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\TargetStateConflictException;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.', 'error_code' => 'method_not_allowed'], 405);
}
$input = mobileRequestBody();
$targetId = (int) ($input['target_id'] ?? 0);
$action = strtolower(trim((string) ($input['action'] ?? 'evaluate')));
$actorId = (int) ($auth['user_id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$targets = new Targets();
$target = $targets->getById($targetId);
if (!$target || !$targets->canEditTarget($target, $user)) {
    mobileJson(['error' => 'Target not found or not editable.', 'error_code' => 'forbidden'], 403);
}

try {
    $coordinator = new TargetCoordinator();
    $changed = false;
    if ($action === 'evaluate') {
        (new TargetIntelligenceService())->syncTarget($targetId, $workspaceId);
        $changed = true;
    } elseif ($action === 'complete_recommended' || $action === 'approve_proposal') {
        $proposalId = (int) ($input['proposal_id'] ?? 0);
        if ($proposalId <= 0) {
            $row = \CRM\Database::queryOne("SELECT id FROM target_automation_proposals WHERE workspace_id=? AND target_id=? AND decision_state='pending' ORDER BY id DESC LIMIT 1", [$workspaceId, $targetId]);
            $proposalId = (int) ($row['id'] ?? 0);
        }
        $changed = $coordinator->approveProposal($proposalId, $workspaceId, $actorId);
    } elseif ($action === 'reject_proposal' || $action === 'keep_open') {
        $proposalId = (int) ($input['proposal_id'] ?? 0);
        $changed = $coordinator->rejectProposal($proposalId, $workspaceId, $actorId);
    } elseif ($action === 'complete_anyway') {
        $changed = $coordinator->completeManually($targetId, $workspaceId, $actorId, [
            'decision_source' => 'ai_review', 'explanation' => trim((string) ($input['reason'] ?? '')) ?: 'User completed the target despite the automation recommendation.',
            'expected_version' => isset($input['state_version']) ? (int) $input['state_version'] : null,
        ]);
    } elseif ($action === 'reopen') {
        $changed = $coordinator->reopen($targetId, $workspaceId, $actorId, (string) ($input['reason'] ?? ''), isset($input['state_version']) ? (int) $input['state_version'] : null);
    } elseif ($action === 'set_mode') {
        $changed = $coordinator->setMode($targetId, $workspaceId, (string) ($input['mode'] ?? 'manual'), $actorId, isset($input['state_version']) ? (int) $input['state_version'] : null);
    } else {
        mobileJson(['error' => 'Unsupported target automation action.', 'error_code' => 'unsupported_action'], 422);
    }
    $fresh = $targets->getById($targetId) ?? $target;
    mobileJson(['success' => true, 'data' => ['changed' => $changed, 'target' => array_merge(mobileTargetSummary($fresh), $coordinator->payload($fresh, true))]]);
} catch (TargetStateConflictException $e) {
    mobileJson(['error' => $e->getMessage(), 'error_code' => 'stale_state', 'data' => ['target' => $e->currentTarget()]], 409);
} catch (\InvalidArgumentException $e) {
    mobileJson(['error' => $e->getMessage(), 'error_code' => 'validation_failed'], 422);
} catch (\Throwable $e) {
    error_log('Mobile target automation failed: ' . $e->getMessage());
    mobileJson(['error' => 'Target automation could not be completed.', 'error_code' => 'automation_failed'], 500);
}

