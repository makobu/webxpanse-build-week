<?php
/**
 * Targets API
 * Returns JSON for AJAX requests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\Targets;
use CRM\Modules\TargetAdvice;
use CRM\Services\TargetCoordinator;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\TargetStateConflictException;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$targetsModule = new Targets();
$adviceModule = new TargetAdvice();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Parse request body for POST/PUT requests
$input = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true) ?? [];
    }
    // Also check POST data
    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }
}

function buildTargetMutationData(array $input, int $userId, bool $isCreate): array
{
    $fields = [
        'title',
        'description',
        'target_type',
        'target_value',
        'current_value',
        'scope',
        'progress_mode',
        'manual_adjustment_value',
        'unit',
        'start_date',
        'target_date',
        'reminder_frequency',
        'custom_reminder_days',
        'status',
        'metadata_json',
        'origin_type',
        'automation_mode',
        'rollup_source',
        'rollup_metric',
        'rollup_window',
        'rollup_filters_json',
        'currency_code',
    ];

    $data = [];
    if ($isCreate) {
        $data = [
            'title' => $input['title'] ?? '',
            'description' => $input['description'] ?? '',
            'target_type' => $input['target_type'] ?? 'custom',
            'target_value' => $input['target_value'] ?? null,
            'current_value' => $input['current_value'] ?? 0,
            'scope' => $input['scope'] ?? 'personal',
            'progress_mode' => $input['progress_mode'] ?? 'manual',
            'manual_adjustment_value' => $input['manual_adjustment_value'] ?? 0,
            'unit' => $input['unit'] ?? '',
            'start_date' => $input['start_date'] ?? date('Y-m-d'),
            'target_date' => $input['target_date'] ?? '',
            'reminder_frequency' => $input['reminder_frequency'] ?? 'weekly',
            'custom_reminder_days' => $input['custom_reminder_days'] ?? null,
            'user_id' => $userId,
            'automation_mode' => $input['automation_mode'] ?? 'manual',
            'rollup_source' => $input['rollup_source'] ?? null,
            'rollup_metric' => $input['rollup_metric'] ?? null,
            'rollup_window' => $input['rollup_window'] ?? 'target_period',
            'rollup_filters_json' => $input['rollup_filters_json'] ?? null,
            'currency_code' => $input['currency_code'] ?? null,
        ];
    } else {
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }
    }

    if (!array_key_exists('metadata_json', $data)) {
        $metadata = [];
        if (array_key_exists('rollup_source', $input) || array_key_exists('rollup_metric', $input)) {
            $metadata['rollup_definition'] = [
                'source' => $input['rollup_source'] ?? '',
                'metric' => $input['rollup_metric'] ?? '',
            ];
        }
        if (array_key_exists('milestones', $input) && is_array($input['milestones'])) {
            $metadata['milestones'] = $input['milestones'];
        }
        if ($metadata !== []) {
            $data['metadata_json'] = $metadata;
        }
    }

    return $data;
}

function targetApiPayload(array $target, array $user, Targets $targetsModule): array
{
    $canEdit = $targetsModule->canEditTarget($target, $user);
    $payload = array_merge($target, (new TargetCoordinator())->payload($target, $canEdit));
    $actions = [];
    if ($canEdit) {
        $actions = ['update', 'manage_milestones'];
        if ((string) ($target['status'] ?? '') === 'completed') {
            $actions[] = 'reopen';
        } else {
            if ((string) ($target['progress_mode'] ?? 'manual') !== 'auto_rollup') {
                $actions[] = 'update_progress';
            }
            array_push($actions, 'complete', 'evaluate', 'set_mode');
        }
    }
    $payload['allowed_actions'] = $actions;
    return $payload;
}

// GET /api/targets.php - List targets
if ($method === 'GET' && empty($action)) {
    $filters = [];
    if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
    if (isset($_GET['target_type'])) $filters['target_type'] = $_GET['target_type'];
    if (isset($_GET['scope'])) $filters['scope'] = $_GET['scope'];
    if (isset($_GET['progress_mode'])) $filters['progress_mode'] = $_GET['progress_mode'];
    if (isset($_GET['rollup_source'])) $filters['rollup_source'] = $_GET['rollup_source'];
    if (isset($_GET['status_band'])) $filters['status_band'] = $_GET['status_band'];
    if (isset($_GET['search'])) $filters['search'] = $_GET['search'];
    if (isset($_GET['overdue'])) $filters['overdue'] = true;
    if (isset($_GET['upcoming'])) $filters['upcoming'] = true;
    
    $filters['user_id'] = $userId;
    $limit = (int) ($_GET['limit'] ?? 50);
    $offset = (int) ($_GET['offset'] ?? 0);
    
    $targets = array_map(static fn(array $target): array => targetApiPayload($target, $user, $targetsModule), $targetsModule->getAll($filters, $limit, $offset));
    
    echo json_encode([
        'targets' => $targets,
        'count' => count($targets)
    ]);
}

// GET /api/targets.php?action=get&id=123 - Get single target
elseif ($method === 'GET' && $action === 'get') {
    $targetId = (int) ($_GET['id'] ?? 0);
    
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Target ID is required']);
        exit;
    }
    
    $target = $targetsModule->getById($targetId);
    
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        exit;
    }
    
    if (!$targetsModule->canViewTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    echo json_encode(['target' => targetApiPayload($target, $user, $targetsModule)]);
}

// POST /api/targets.php - Create target
elseif ($method === 'POST' && empty($action)) {
    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    try {
        $data = buildTargetMutationData($input, $userId, true);
        
        $targetId = $targetsModule->create($data);
        $target = $targetsModule->getById($targetId);
        
        echo json_encode([
            'success' => true,
            'target' => targetApiPayload($target, $user, $targetsModule)
        ]);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// PUT /api/targets.php?id=123 - Update target
elseif ($method === 'PUT' || ($method === 'POST' && $action === 'update')) {
    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $targetId = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Target ID is required']);
        exit;
    }
    
    $target = $targetsModule->getById($targetId);
    
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        exit;
    }
    
    if (!$targetsModule->canEditTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    try {
        $data = buildTargetMutationData($input, $userId, false);
        
        $targetsModule->update($targetId, $data);
        $target = $targetsModule->getById($targetId);
        
        echo json_encode([
            'success' => true,
            'target' => targetApiPayload($target, $user, $targetsModule)
        ]);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// PATCH /api/targets.php?action=progress&id=123 - Update progress
elseif (($method === 'PATCH' || ($method === 'POST' && $action === 'progress')) && isset($input['current_value'])) {
    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $targetId = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Target ID is required']);
        exit;
    }
    
    $target = $targetsModule->getById($targetId);
    
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        exit;
    }
    
    if (!$targetsModule->canEditTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    try {
        if (!is_numeric($input['current_value'])) {
            throw new \InvalidArgumentException('Current value must be a valid number');
        }
        $currentValue = (float) $input['current_value'];
        $targetsModule->updateProgress($targetId, $currentValue, isset($input['state_version']) ? (int) $input['state_version'] : null);
        $target = $targetsModule->getById($targetId);
        
        echo json_encode([
            'success' => true,
            'target' => targetApiPayload($target, $user, $targetsModule)
        ]);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// POST /api/targets.php?action=complete&id=123 - Mark as complete
elseif ($method === 'POST' && $action === 'complete') {
    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $targetId = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Target ID is required']);
        exit;
    }
    
    $target = $targetsModule->getById($targetId);
    
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        exit;
    }
    
    if (!$targetsModule->canEditTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    try {
        $targetsModule->markComplete($targetId);
        $target = $targetsModule->getById($targetId);
        
        echo json_encode([
            'success' => true,
            'target' => targetApiPayload($target, $user, $targetsModule)
        ]);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// POST /api/targets.php?action=evaluate|reopen|set_mode|approve_proposal|reject_proposal
elseif ($method === 'POST' && in_array($action, ['evaluate','reopen','set_mode','approve_proposal','reject_proposal','complete_anyway'], true)) {
    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token', 'error_code' => 'invalid_csrf']);
        exit;
    }
    $targetId = (int) ($_GET['id'] ?? $input['id'] ?? $input['target_id'] ?? 0);
    $target = $targetsModule->getById($targetId);
    if (!$target || !$targetsModule->canEditTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied', 'error_code' => 'forbidden']);
        exit;
    }
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    $coordinator = new TargetCoordinator();
    try {
        if ($action === 'evaluate') {
            (new TargetIntelligenceService())->syncTarget($targetId, $workspaceId);
        } elseif ($action === 'reopen') {
            $coordinator->reopen($targetId, $workspaceId, $userId, (string) ($input['reason'] ?? ''), isset($input['state_version']) ? (int) $input['state_version'] : null);
        } elseif ($action === 'set_mode') {
            $coordinator->setMode($targetId, $workspaceId, (string) ($input['mode'] ?? 'manual'), $userId, isset($input['state_version']) ? (int) $input['state_version'] : null);
        } elseif ($action === 'approve_proposal') {
            $coordinator->approveProposal((int) ($input['proposal_id'] ?? 0), $workspaceId, $userId);
        } elseif ($action === 'reject_proposal') {
            $coordinator->rejectProposal((int) ($input['proposal_id'] ?? 0), $workspaceId, $userId);
        } else {
            $coordinator->completeManually($targetId, $workspaceId, $userId, ['decision_source' => 'ai_review', 'explanation' => (string) ($input['reason'] ?? 'User completed the target despite the recommendation.')]);
        }
        $fresh = $targetsModule->getById($targetId) ?? $target;
        echo json_encode(['success' => true, 'target' => targetApiPayload($fresh, $user, $targetsModule)]);
    } catch (TargetStateConflictException $e) {
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage(), 'error_code' => 'stale_state', 'target' => $e->currentTarget()]);
    } catch (\Throwable $e) {
        http_response_code($e instanceof \InvalidArgumentException ? 422 : 400);
        echo json_encode(['error' => $e->getMessage(), 'error_code' => 'target_automation_failed']);
    }
}

// GET /api/targets.php?action=advice&id=123 - Get advice
elseif ($method === 'GET' && $action === 'advice') {
    $targetId = (int) ($_GET['id'] ?? 0);
    
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Target ID is required']);
        exit;
    }
    
    $target = $targetsModule->getById($targetId);
    
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        exit;
    }
    
    if (!$targetsModule->canViewTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    $generate = isset($_GET['generate']) && $_GET['generate'] === 'true';
    
    if ($generate) {
        if (!$targetsModule->canEditTarget($target, $user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }
        try {
            $adviceText = !empty($_GET['ai']) && $_GET['ai'] === 'true'
                ? $adviceModule->generateAiAdvice($targetId)
                : $adviceModule->generateAdvice($targetId);
            $advice = $adviceModule->getLatestAdvice($targetId);
            
            echo json_encode([
                'success' => true,
                'advice' => $advice
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        $advice = $adviceModule->getLatestAdvice($targetId);
        
        if ($advice) {
            echo json_encode(['advice' => $advice]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'No advice available']);
        }
    }
}

// DELETE /api/targets.php?id=123 - Delete target
elseif ($method === 'DELETE' || ($method === 'POST' && $action === 'delete')) {
    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }
    
    $targetId = (int) ($_GET['id'] ?? $input['id'] ?? 0);
    
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Target ID is required']);
        exit;
    }
    
    $target = $targetsModule->getById($targetId);
    
    if (!$target) {
        http_response_code(404);
        echo json_encode(['error' => 'Target not found']);
        exit;
    }
    
    if (!$targetsModule->canDeleteTarget($target, $user)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    try {
        if ($targetsModule->delete($targetId)) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Failed to delete target']);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete target']);
    }
}

else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
}
