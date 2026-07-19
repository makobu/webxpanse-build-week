<?php

require_once __DIR__ . '/../_bootstrap.php';

use CRM\Database;
use CRM\Modules\Targets;
use CRM\Services\WorkspaceContext;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.', 'error_code' => 'method_not_allowed'], 405);
}
$input = mobileRequestBody();
$targetId = (int) ($input['target_id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$targets = new Targets();
$target = $targets->getById($targetId);
if (!$target || !$targets->canEditTarget($target, $user)) {
    mobileJson(['error' => 'Target not found or not editable.', 'error_code' => 'forbidden'], 403);
}
$expectedVersion = isset($input['state_version']) ? (int) $input['state_version'] : null;
if ($expectedVersion !== null && $expectedVersion !== (int) ($target['state_version'] ?? 1)) {
    mobileJson(['error' => 'The target changed since it was loaded.', 'error_code' => 'stale_state', 'data' => ['target' => $target]], 409);
}

try {
    $action = strtolower(trim((string) ($input['action'] ?? 'toggle')));
    $milestoneId = (int) ($input['milestone_id'] ?? 0);
    Database::beginTransaction();
    if ($action === 'create') {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Milestone title is required.');
        }
        Database::execute(
            "INSERT INTO target_milestones (workspace_id,target_id,title,target_value,current_value,due_date,status,sort_order) VALUES (?,?,?,?,?,?,?,?)",
            [$workspaceId,$targetId,$title,max(0,(float) ($input['target_value'] ?? 0)),max(0,(float) ($input['current_value'] ?? 0)),
             !empty($input['due_date']) ? (string) $input['due_date'] : null,'pending',(int) ($input['sort_order'] ?? 0)]
        );
        $milestoneId = (int) Database::lastInsertId();
    } elseif ($action === 'update') {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Milestone title is required.');
        }
        if (Database::execute('UPDATE target_milestones SET title=?,target_value=?,current_value=?,due_date=?,sort_order=? WHERE workspace_id=? AND target_id=? AND id=?',
            [$title,max(0,(float) ($input['target_value'] ?? 0)),max(0,(float) ($input['current_value'] ?? 0)),!empty($input['due_date']) ? (string) $input['due_date'] : null,
             (int) ($input['sort_order'] ?? 0),$workspaceId,$targetId,$milestoneId]) !== 1) {
            throw new \RuntimeException('Milestone not found.');
        }
    } elseif ($action === 'toggle') {
        if (Database::execute("UPDATE target_milestones SET status=IF(status='completed','pending','completed') WHERE workspace_id=? AND target_id=? AND id=?", [$workspaceId,$targetId,$milestoneId]) !== 1) {
            throw new \RuntimeException('Milestone not found.');
        }
    } elseif ($action === 'delete') {
        if (Database::execute('DELETE FROM target_milestones WHERE workspace_id=? AND target_id=? AND id=?', [$workspaceId,$targetId,$milestoneId]) !== 1) {
            throw new \RuntimeException('Milestone not found.');
        }
    } else {
        throw new \InvalidArgumentException('Unsupported milestone action.');
    }
    Database::execute('UPDATE targets SET state_version=state_version+1 WHERE workspace_id=? AND id=?', [$workspaceId,$targetId]);
    Database::commit();
    $rows = Database::query('SELECT id,title,target_value,current_value,due_date,status,sort_order,updated_at FROM target_milestones WHERE workspace_id=? AND target_id=? ORDER BY sort_order,id', [$workspaceId,$targetId]);
    mobileJson(['success' => true, 'data' => ['milestone_id' => $milestoneId, 'milestones' => $rows]]);
} catch (\Throwable $e) {
    if (Database::getInstance()->inTransaction()) {
        Database::rollBack();
    }
    $status = $e instanceof \InvalidArgumentException ? 422 : 404;
    mobileJson(['error' => $e->getMessage(), 'error_code' => $status === 422 ? 'validation_failed' : 'not_found'], $status);
}

