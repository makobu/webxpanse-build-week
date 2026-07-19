<?php
/**
 * Save Workflow API
 * Saves workflow from visual builder or form
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowGraphService;
use CRM\Services\WorkflowAutomationGenerationService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

class WorkflowTriggerKeyConflictException extends \Exception
{
}

function normalizeWorkflowTriggerKeyForSave(array &$trigger): ?string
{
    $triggerType = (string) ($trigger['type'] ?? '');
    if (!in_array($triggerType, ['webhook_received', 'api_call'], true)) {
        return null;
    }

    $triggerKey = trim((string) ($trigger['trigger_key'] ?? ''));
    if ($triggerKey === '') {
        $trigger['trigger_key'] = '';
        return null;
    }

    if (strlen($triggerKey) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $triggerKey) !== 1) {
        throw new \Exception('Trigger key may contain only letters, numbers, dots, underscores, colons, and hyphens, up to 128 characters.');
    }

    $trigger['trigger_key'] = $triggerKey;
    return $triggerKey;
}

function assertWorkflowTriggerKeyIsUnique(int $workspaceId, ?int $workflowId, array &$trigger): void
{
    $triggerKey = normalizeWorkflowTriggerKeyForSave($trigger);
    if ($triggerKey === null) {
        return;
    }

    $rows = Database::query(
        "SELECT id, name, trigger_config
         FROM workflows
         WHERE workspace_id = ?",
        [$workspaceId]
    );

    foreach ($rows as $row) {
        $existingId = (int) ($row['id'] ?? 0);
        if ($workflowId !== null && $existingId === $workflowId) {
            continue;
        }

        $config = json_decode((string) ($row['trigger_config'] ?? ''), true);
        if (!is_array($config)) {
            continue;
        }

        $existingType = (string) ($config['type'] ?? '');
        $existingKey = trim((string) ($config['trigger_key'] ?? ''));
        if (in_array($existingType, ['webhook_received', 'api_call'], true) && hash_equals($triggerKey, $existingKey)) {
            throw new WorkflowTriggerKeyConflictException('Trigger key "' . $triggerKey . '" is already used by another workflow in this workspace.');
        }
    }
}

function syncWorkflowGraphTriggerKey(array &$graph, array $trigger): void
{
    $triggerType = (string) ($trigger['type'] ?? '');
    if (!in_array($triggerType, ['webhook_received', 'api_call'], true) || !isset($graph['nodes']) || !is_array($graph['nodes'])) {
        return;
    }

    foreach ($graph['nodes'] as &$node) {
        if (($node['type'] ?? '') !== 'trigger') {
            continue;
        }

        $nodeSubtype = (string) ($node['subtype'] ?? ($node['config']['type'] ?? ''));
        if ($nodeSubtype !== $triggerType) {
            continue;
        }

        if (!isset($node['config']) || !is_array($node['config'])) {
            $node['config'] = [];
        }
        $node['config']['trigger_key'] = (string) ($trigger['trigger_key'] ?? '');
        break;
    }
    unset($node);
}

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('workflows.manage', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $workflowId = isset($input['id']) ? (int)$input['id'] : null;
    $name = $input['name'] ?? '';
    $trigger = $input['trigger'] ?? ($input['trigger_config'] ?? []);
    $conditions = $input['conditions'] ?? [];
    $actions = $input['actions'] ?? [];
    $visualData = $input['visual_data'] ?? null;
    $branches = $input['branches'] ?? [];
    $graph = $input['graph'] ?? ($input['graph_json'] ?? null);
    $workflowMode = $input['workflow_mode'] ?? 'mixed';
    $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
    $isAiAssisted = !empty($input['ai_assisted']);
    $generationPrompt = trim((string) ($input['generation_prompt'] ?? ''));

    $automationEngine = new AutomationEngine();
    $graphService = new WorkflowGraphService();
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    $expectedLockVersion = Concurrency::expectedVersionFromData($input);
    $saveBranches = function (int $targetWorkflowId, array $branchList, bool $replace = false): void {
        if ($replace) {
            Database::execute("DELETE FROM workflow_branches WHERE workflow_id = ?", [$targetWorkflowId]);
        }

        foreach ($branchList as $index => $branch) {
            $branchType = $branch['type'] ?? 'success';
            $conditionValue = $branch['condition_value'] ?? null;
            if (in_array($branch['branchType'] ?? '', ['true', 'false'], true)) {
                $branchType = 'conditional';
                $conditionValue = $branch['branchType'];
            }
            Database::execute(
                "INSERT INTO workflow_branches (workflow_id, source_node_id, target_node_id, branch_type, condition_value, branch_order) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $targetWorkflowId,
                    $branch['from'] ?? '',
                    $branch['to'] ?? '',
                    $branchType,
                    $conditionValue,
                    $branch['order'] ?? $index
                ]
            );
        }
    };

    if ($graph) {
        $graph = is_string($graph) ? json_decode($graph, true) : $graph;
        if (!is_array($graph)) {
            throw new \Exception('Invalid graph payload');
        }
        $graph['meta']['name'] = $name ?: ($graph['meta']['name'] ?? 'Workflow');
        $graph['meta']['mode'] = $workflowMode;
        $graph = $graphService->normalizeForSave($graph, [
            'name' => $name,
            'workflow_mode' => $workflowMode,
        ]);
        $legacyPayload = $graphService->deriveLegacyPayload($graph);
        $trigger = $legacyPayload['trigger'];
        $conditions = $legacyPayload['conditions'];
        $actions = $legacyPayload['actions'];
        $visualData = $legacyPayload['visual_data'];
        $branches = $legacyPayload['branches'];
    }

    if (empty($name) || empty($trigger) || empty($actions)) {
        throw new \Exception('Name, trigger, and actions are required');
    }

    if ($isAiAssisted) {
        $result = (new WorkflowAutomationGenerationService())->generate([
            'prompt' => $generationPrompt !== '' ? $generationPrompt : ('Save AI-assisted workflow "' . $name . '"'),
            'target_workflow_id' => $workflowId,
            'source_surface' => 'workflow_builder_save',
            'is_active' => $isActive,
            'notes' => (string) ($input['approval_notes'] ?? ''),
        ], (int) (Auth::user()['id'] ?? 0));

        echo json_encode([
            'success' => !isset($result['error']),
            'workflow_id' => $result['workflow_id'] ?? null,
            'proposal_id' => $result['proposal_id'] ?? null,
            'status' => $result['status'] ?? 'blocked',
            'confidence' => $result['confidence'] ?? null,
            'issues' => $result['issues'] ?? [],
            'reasons' => $result['reasons'] ?? [],
            'message' => match ($result['status'] ?? 'blocked') {
                'suggested' => 'Workflow suggestion generated without saving because suggest-only mode is active.',
                'pending_approval' => 'Workflow proposal saved and is pending approval.',
                'applied' => 'Workflow proposal applied successfully.',
                default => $result['error'] ?? 'Workflow proposal could not be completed.',
            },
        ]);
        exit;
    }
    
    if ($workflowId) {
        // Update existing workflow
        if (!in_array($trigger['type'] ?? '', $automationEngine->getTriggers(), true)) {
            throw new \Exception("Invalid trigger type");
        }
        $availableActions = $automationEngine->getActions();
        foreach ($actions as $action) {
            if (!in_array($action['type'] ?? '', $availableActions, true)) {
                throw new \Exception("Invalid action type: " . ($action['type'] ?? ''));
            }
        }
        assertWorkflowTriggerKeyIsUnique($workspaceId, $workflowId, $trigger);
        if (is_array($graph)) {
            syncWorkflowGraphTriggerKey($graph, $trigger);
        }
        
        $graph = $graph ?: $graphService->migrateLegacyWorkflow([
            'id' => $workflowId,
            'name' => $name,
            'workflow_mode' => $workflowMode,
            'trigger_config' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
            'visual_data' => $visualData,
        ]);
        $validation = $graphService->validateGraph($graph);
        $workflowMode = in_array($workflowMode, ['crm', 'journey', 'mixed'], true) ? $workflowMode : 'mixed';
        $migrationSource = (($input['graph'] ?? null) || ($input['graph_json'] ?? null)) ? 'graph_v2' : ($visualData !== null ? 'visual_v1' : 'legacy_form');
        $migrationStatus = $validation['valid'] ? 'migrated' : 'failed';

        Database::beginTransaction();
        try {
            Concurrency::executeWorkspaceUpdate(
                'workflows',
                $workspaceId,
                $workflowId,
                [
                    'name = ?',
                    'trigger_config = ?',
                    'conditions = ?',
                    'actions = ?',
                    'is_active = ?',
                    'visual_data = ?',
                    'graph_json = ?',
                    'builder_version = 2',
                    'workflow_mode = ?',
                    'migration_source = ?',
                    'migration_status = ?',
                    'last_saved_at = NOW()',
                    'last_migrated_at = NOW()',
                    'last_validated_at = NOW()',
                    'updated_at = NOW()',
                ],
                [
                    $name,
                    json_encode($trigger),
                    json_encode($conditions),
                    json_encode($actions),
                    $isActive ? 1 : 0,
                    json_encode($visualData),
                    json_encode($graph),
                    $workflowMode,
                    $migrationSource,
                    $migrationStatus,
                ],
                $expectedLockVersion,
                fn() => Database::queryOne("SELECT * FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId]),
                [
                    'name' => $name,
                    'trigger_config' => $trigger,
                    'conditions' => $conditions,
                    'actions' => $actions,
                    'is_active' => $isActive ? 1 : 0,
                    'graph_json' => $graph,
                ],
                'workflow'
            );

            $saveBranches($workflowId, $branches, true);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
        
        \CRM\Services\WorkflowExecutionService::invalidateWorkflowCache($workflowId);

        $triggerWarning = null;
        try {
            $triggerService = new \CRM\Services\WorkflowTriggerService();
            if ($isActive) {
                $triggerService->subscribeWorkflow($workflowId);
            } else {
                $triggerService->unsubscribeWorkflow($workflowId);
            }
        } catch (\Throwable $e) {
            $triggerWarning = $e->getMessage();
            error_log('Workflow trigger sync failed after save: ' . $e->getMessage());
        }
        $updatedWorkflow = Database::queryOne("SELECT lock_version, last_saved_at FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId]) ?: [];
        
        echo json_encode([
            'success' => true,
            'workflow_id' => $workflowId,
            'proposal_id' => null,
            'status' => $triggerWarning ? 'saved_with_warning' : 'saved',
            'graph_valid' => $validation['valid'],
            'issues' => $validation['issues'],
            'lock_version' => (int) ($updatedWorkflow['lock_version'] ?? 0),
            'last_saved_at' => $updatedWorkflow['last_saved_at'] ?? null,
            'warning' => $triggerWarning,
            'message' => $triggerWarning ? 'Workflow saved, but trigger subscription sync needs attention.' : 'Workflow updated successfully'
        ]);
    } else {
        // Create new workflow
        assertWorkflowTriggerKeyIsUnique($workspaceId, null, $trigger);
        if (is_array($graph)) {
            syncWorkflowGraphTriggerKey($graph, $trigger);
        }

        $workflowId = $automationEngine->createWorkflow(
            $name,
            $trigger,
            $conditions,
            $actions
        );
        
        $graph = $graph ?: $graphService->migrateLegacyWorkflow([
            'id' => $workflowId,
            'name' => $name,
            'workflow_mode' => $workflowMode,
            'trigger_config' => $trigger,
            'conditions' => $conditions,
            'actions' => $actions,
            'visual_data' => $visualData,
        ]);
        $validation = $graphService->validateGraph($graph);
        Concurrency::executeWorkspaceUpdate(
            'workflows',
            $workspaceId,
            $workflowId,
            [
                'visual_data = ?',
                'graph_json = ?',
                'builder_version = 2',
                'workflow_mode = ?',
                'migration_source = ?',
                'migration_status = ?',
                'last_saved_at = NOW()',
                'last_migrated_at = NOW()',
                'last_validated_at = NOW()',
                'updated_at = NOW()',
            ],
            [
                json_encode($visualData),
                json_encode($graph),
                in_array($workflowMode, ['crm', 'journey', 'mixed'], true) ? $workflowMode : 'mixed',
                (($input['graph'] ?? null) || ($input['graph_json'] ?? null)) ? 'graph_v2' : ($visualData !== null ? 'visual_v1' : 'legacy_form'),
                $validation['valid'] ? 'migrated' : 'failed',
            ],
            null,
            fn() => Database::queryOne("SELECT * FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId]),
            ['graph_json' => $graph],
            'workflow'
        );
        
        // Save branches if provided
        if (!empty($branches)) {
            $saveBranches($workflowId, $branches);
        }
        
        if (!$isActive) {
            Database::execute(
                "UPDATE workflows SET is_active = 0 WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $workflowId]
            );
            $triggerService = new \CRM\Services\WorkflowTriggerService();
            $triggerService->unsubscribeWorkflow($workflowId);
        }
        $createdWorkflow = Database::queryOne("SELECT lock_version, last_saved_at FROM workflows WHERE workspace_id = ? AND id = ?", [$workspaceId, $workflowId]) ?: [];
        
        echo json_encode([
            'success' => true,
            'workflow_id' => $workflowId,
            'proposal_id' => null,
            'status' => 'saved',
            'graph_valid' => $validation['valid'],
            'issues' => $validation['issues'],
            'lock_version' => (int) ($createdWorkflow['lock_version'] ?? 0),
            'last_saved_at' => $createdWorkflow['last_saved_at'] ?? null,
            'message' => 'Workflow created successfully'
        ]);
    }
} catch (ConcurrencyConflictException $e) {
    http_response_code(409);
    $payload = Concurrency::conflictPayload($e);
    $payload['error'] = $payload['message'];
    echo json_encode($payload);
} catch (WorkflowTriggerKeyConflictException $e) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error_code' => 'trigger_key_conflict',
        'error' => $e->getMessage(),
        'message' => $e->getMessage(),
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
