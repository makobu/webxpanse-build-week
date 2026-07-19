<?php
/**
 * Workflow Execution Service
 * Centralized workflow execution management with error handling and tracking
 */

namespace CRM\Services;

use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\AutomationEngine;
use CRM\Modules\ConditionEvaluator;
use CRM\Services\WorkspaceContext;

class WorkflowExecutionService
{
    private const CACHE_KEY_PREFIX = 'workflow_config_';
    private const CACHE_TTL = 300;

    private AutomationEngine $automationEngine;
    private ConditionEvaluator $conditionEvaluator;
    private CacheManager $cache;
    private WorkflowGraphService $graphService;
    private WorkflowExecutionPlanner $planner;
    private WorkflowRetryService $retryService;
    private WorkflowObservabilityService $observability;
    private WorkflowAutonomyExecutionService $autonomy;

    public function __construct()
    {
        $this->automationEngine = new AutomationEngine();
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->cache = new CacheManager();
        $this->graphService = new WorkflowGraphService();
        $this->planner = new WorkflowExecutionPlanner();
        $this->retryService = new WorkflowRetryService();
        $this->observability = new WorkflowObservabilityService();
        $this->autonomy = new WorkflowAutonomyExecutionService();
    }

    /**
     * Load workflow from cache or database
     */
    private function loadWorkflow(int $workflowId, ?int $workspaceId = null): ?array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $workflowId;
        $workflow = $this->cache->get($cacheKey);
        if ($workflow !== null && ($workspaceId === null || (int) ($workflow['workspace_id'] ?? 0) === $workspaceId)) {
            return $workflow;
        }
        if ($workspaceId !== null && $workspaceId > 0) {
            $workflow = Database::queryOne(
                "SELECT * FROM workflows WHERE id = ? AND workspace_id = ? AND is_active = 1",
                [$workflowId, $workspaceId]
            );
        } else {
            $workflow = Database::queryOne(
                "SELECT * FROM workflows WHERE id = ? AND is_active = 1",
                [$workflowId]
            );
        }
        if ($workflow) {
            $this->cache->set($cacheKey, $workflow, self::CACHE_TTL);
        }
        return $workflow;
    }

    /**
     * Invalidate workflow cache (call after create/update/delete)
     */
    public static function invalidateWorkflowCache(int $workflowId): void
    {
        $cache = new CacheManager();
        $cache->delete(self::CACHE_KEY_PREFIX . $workflowId);
    }

    /**
     * Execute workflow for a trigger event
     */
    public function executeWorkflow(int $workflowId, array $eventData, ?int $workspaceId = null): void
    {
        $this->executeWorkflowFromQueue($workflowId, $eventData, null, $workspaceId);
    }

    public function executeWorkflowFromQueue(int $workflowId, array $eventData, ?int $workflowQueueId, ?int $workspaceId = null): void
    {
        $startTime = microtime(true);
        $executionId = null;
        $resolvedWorkspaceId = $this->resolveWorkspaceId(
            $workflowId,
            (int) ($eventData['contact_id'] ?? 0),
            $workspaceId,
            $workflowQueueId === null
        );

        try {
            AsyncWorkspaceRunner::runWithWorkspace(
                $resolvedWorkspaceId,
                function () use (&$executionId, $eventData, $resolvedWorkspaceId, $startTime, $workflowId, $workflowQueueId): void {
                    // Load workflow (cached)
                    $workflow = $this->loadWorkflow($workflowId, $resolvedWorkspaceId > 0 ? $resolvedWorkspaceId : null);
                    
                    if (!$workflow) {
                        return;
                    }
                    
                    // Get contact_id from event data
                    $contactId = $eventData['contact_id'] ?? null;
                    if (!$contactId) {
                        error_log("Workflow execution failed: No contact_id in event data");
                        return;
                    }

                    // Check if contact is excluded from this workflow
                    try {
                        $excluded = Database::queryOne(
                            "SELECT id FROM workflow_exclusions WHERE workflow_id = ? AND contact_id = ?",
                            [$workflowId, $contactId]
                        );
                        if ($excluded) {
                            return;
                        }
                    } catch (\Exception $e) {
                        // Table may not exist yet
                    }

                    // Create execution record
                    $executionId = $this->createExecutionRecord($workflowId, $contactId, $resolvedWorkspaceId);
                    
                    // Update execution status to running
                    $this->updateExecutionRecord((int) $executionId, $resolvedWorkspaceId, [
                        'status' => 'running',
                    ]);
                    
                    // Evaluate top-level conditions (workflow entry conditions)
                    $conditions = json_decode($workflow['conditions'], true) ?? [];
                    if (!$this->conditionEvaluator->evaluateConditions($conditions, $eventData)) {
                        // Conditions not met, mark as completed (not failed)
                        $this->updateExecutionRecord((int) $executionId, $resolvedWorkspaceId, [
                            'status' => 'completed',
                            'completed_at' => '__NOW__',
                        ]);
                        return;
                    }

                    $actionsCompleted = 0;
                    $actionsFailed = 0;
                    $errors = [];
                    $variantId = null;
                    $graph = $this->graphService->loadGraphFromWorkflowRow($workflow);
                    $validation = $this->graphService->validateGraph($graph);
                    if (!$validation['valid']) {
                        throw new \RuntimeException('Workflow graph is invalid');
                    }

                    Database::execute(
                        "UPDATE workflows SET graph_json = ?, builder_version = 2, workflow_mode = COALESCE(workflow_mode, 'mixed'),
                         migration_source = COALESCE(migration_source, ?), migration_status = 'migrated',
                         last_migrated_at = COALESCE(last_migrated_at, NOW()), last_validated_at = NOW()
                         WHERE id = ?",
                        [
                            json_encode($graph),
                            !empty($workflow['visual_data']) ? 'visual_v1' : 'legacy_form',
                            $workflowId,
                        ]
                    );

                    $plan = $this->planner->planExecution($graph, $eventData);

                    foreach ($plan['nodes'] as $index => $node) {
                        $nodeType = $node['type'] ?? '';
                        if (in_array($nodeType, ['trigger', 'condition'], true)) {
                            continue;
                        }

                        $action = $this->actionFromNode($node);
                        if (empty($action['type'])) {
                            continue;
                        }

                        try {
                            $nodeRunId = $this->observability->startNodeRun($executionId, $workflowId, $node, $eventData);
                            $actionContext = array_merge($eventData, [
                                    'workflow_id' => $workflowId,
                                    'workspace_id' => $resolvedWorkspaceId,
                                    'execution_id' => $executionId,
                                    'action_index' => $index,
                                    'workflow_queue_id' => $workflowQueueId,
                                'node_id' => $node['id'],
                                'workflow_queue_state' => $workflowQueueId ? 'queued' : 'direct',
                            ]);

                            if ($this->autonomy->shouldBypassGovernance((string) $action['type'])) {
                                $this->automationEngine->executeAction($action, $actionContext);
                                $actionsCompleted++;
                                $this->observability->completeNodeRun($nodeRunId, ['action' => $action['type'], 'status' => 'executed', 'domain_key' => 'workflow_execution']);
                                continue;
                            }

                            $autonomyResult = $this->autonomy->executeAction(
                                $workflow,
                                $action,
                                $actionContext,
                                fn() => $this->automationEngine->executeAction($action, $actionContext)
                            );

                            if (($autonomyResult['status'] ?? '') === 'executed') {
                                $actionsCompleted++;
                                $this->observability->completeNodeRun($nodeRunId, array_merge(['action' => $action['type']], $autonomyResult));
                                continue;
                            }

                            $actionsFailed++;
                            $errors[] = [
                                'action_index' => $index,
                                'action_type' => $action['type'] ?? 'unknown',
                                'error' => implode(', ', (array) ($autonomyResult['decision']['reasons'] ?? ['workflow_action_blocked'])),
                                'retry_count' => 0,
                                'retryable' => false,
                                'node_id' => $node['id'],
                                'autonomy' => $autonomyResult['autonomy'] ?? [],
                            ];
                            $this->observability->completeNodeRun($nodeRunId, array_merge(['action' => $action['type']], $autonomyResult));
                            break;
                        } catch (\Exception $e) {
                            $actionsFailed++;
                            $nodeRunId = $nodeRunId ?? null;
                            if ($nodeRunId) {
                                $this->observability->failNodeRun($nodeRunId, $e->getMessage());
                            }

                            $isRetryable = $this->isRetryableError($e);
                            $errors[] = [
                                'action_index' => $index,
                                'action_type' => $action['type'] ?? 'unknown',
                                'error' => $e->getMessage(),
                                'retry_count' => 0,
                                'retryable' => $isRetryable,
                                'node_id' => $node['id'],
                            ];

                            if ($isRetryable) {
                                $this->retryService->scheduleRetry([
                                    'workflow_queue_id' => $workflowQueueId,
                                    'workflow_execution_id' => $executionId,
                                    'workflow_id' => $workflowId,
                                    'workspace_id' => $resolvedWorkspaceId,
                                    'node_id' => $node['id'],
                                    'action_index' => $index,
                                    'retry_count' => 0,
                                    'error' => substr($e->getMessage(), 0, 500),
                                    'payload' => array_merge($eventData, [
                                        'contact_id' => $contactId,
                                        'node' => $node,
                                    ]),
                                ]);
                            }
                            error_log("Workflow action failed" . ($isRetryable ? ' and was queued for retry: ' : ': ') . $e->getMessage());
                        }
                    }
                    
                    // Calculate execution time
                    $executionTime = (int)((microtime(true) - $startTime) * 1000);
                    
                    // Update execution record
                    $status = $actionsFailed > 0 ? 'failed' : 'completed';
                    $this->updateExecutionRecord((int) $executionId, $resolvedWorkspaceId, [
                        'status' => $status,
                        'completed_at' => '__NOW__',
                        'execution_time_ms' => $executionTime,
                        'actions_completed' => $actionsCompleted,
                        'actions_failed' => $actionsFailed,
                        'error_details' => !empty($errors) ? json_encode($errors) : null,
                    ]);
                    
                    // Record A/B variant result if applicable
                    if (isset($variantId) && $variantId) {
                        try {
                            Database::execute(
                                "INSERT INTO workflow_variant_results (execution_id, workflow_id, contact_id, variant_id) VALUES (?, ?, ?, ?)",
                                [$executionId, $workflowId, $contactId, $variantId]
                            );
                        } catch (\Exception $e) {
                        }
                    }

                    // Update workflow statistics
                    $this->updateWorkflowStats($workflowId, $status === 'completed', $executionTime, $resolvedWorkspaceId);
                },
                null,
                'Workflow execution is missing a valid workspace.'
            );

        } catch (\Exception $e) {
            error_log("Workflow execution error: " . $e->getMessage());
            
            if ($executionId) {
                $executionTime = (int)((microtime(true) - $startTime) * 1000);
                $this->updateExecutionRecord((int) $executionId, $resolvedWorkspaceId, [
                    'status' => 'failed',
                    'completed_at' => '__NOW__',
                    'execution_time_ms' => $executionTime,
                    'error_message' => substr($e->getMessage(), 0, 500),
                    'error_details' => json_encode(['exception' => substr($e->getMessage(), 0, 500), 'trace' => $e->getTraceAsString()]),
                ]);
            }
            
            $this->updateWorkflowStats($workflowId, false, (int)((microtime(true) - $startTime) * 1000), $resolvedWorkspaceId);
        }
    }
    
    /**
     * Select A/B test variant by contact (consistent hash)
     */
    private function selectVariant(array $variants, int $contactId): ?array
    {
        if (empty($variants)) {
            return null;
        }
        $hash = crc32((string) $contactId) & 0x7FFFFFFF;
        $bucket = $hash % 100;
        $cumulative = 0;
        foreach ($variants as $v) {
            $cumulative += (int) ($v['traffic_percent'] ?? 50);
            if ($bucket < $cumulative) {
                return $v;
            }
        }
        return $variants[0];
    }

    /**
     * Create execution record
     */
    private function createExecutionRecord(int $workflowId, int $contactId, ?int $workspaceId = null): int
    {
        Database::execute(
            "INSERT INTO workflow_executions (workflow_id, contact_id, workspace_id, status) VALUES (?, ?, ?, 'pending')",
            [$workflowId, $contactId, ($workspaceId !== null && $workspaceId > 0) ? $workspaceId : null]
        );
        
        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $updates
     */
    private function updateExecutionRecord(int $executionId, ?int $workspaceId, array $updates): void
    {
        if ($executionId <= 0 || $workspaceId === null || $workspaceId <= 0 || $updates === []) {
            return;
        }

        $set = [];
        $params = [];
        foreach ($updates as $column => $value) {
            if ($value === '__NOW__') {
                $set[] = $column . ' = NOW()';
                continue;
            }
            $set[] = $column . ' = ?';
            $params[] = $value;
        }

        $params[] = $executionId;
        $params[] = $workspaceId;

        Database::execute(
            "UPDATE workflow_executions
             SET " . implode(', ', $set) . "
             WHERE id = ?
               AND workspace_id = ?",
            $params
        );
    }
    
    /**
     * Check if error is retryable (transient vs permanent)
     */
    private function isRetryableError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        
        // Permanent errors (don't retry)
        $permanentErrors = [
            'not found',
            'invalid',
            'permission denied',
            'unauthorized',
            'forbidden',
            'required',
            'missing',
            'duplicate'
        ];
        
        foreach ($permanentErrors as $permanentError) {
            if (strpos($message, $permanentError) !== false) {
                return false;
            }
        }
        
        // Transient errors (retry)
        $transientErrors = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'unavailable',
            'busy',
            'rate limit'
        ];
        
        foreach ($transientErrors as $transientError) {
            if (strpos($message, $transientError) !== false) {
                return true;
            }
        }
        
        // Default: retry (most errors are transient)
        return true;
    }
    
    /**
     * Update workflow statistics
     */
    private function updateWorkflowStats(int $workflowId, bool $success, int $executionTime, ?int $workspaceId = null): void
    {
        $sql = "UPDATE workflows SET 
             execution_count = COALESCE(execution_count, 0) + 1,
             success_count = COALESCE(success_count, 0) + ?,
             failure_count = COALESCE(failure_count, 0) + ?,
             avg_execution_time = CASE
                WHEN COALESCE(execution_count, 0) = 0 OR avg_execution_time IS NULL THEN ?
                ELSE ((avg_execution_time * COALESCE(execution_count, 0)) + ?) / (COALESCE(execution_count, 0) + 1)
             END,
             last_executed_at = NOW()
             WHERE id = ?";
        $params = [
            $success ? 1 : 0,
            $success ? 0 : 1,
            $executionTime,
            $executionTime,
            $workflowId
        ];

        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }

        Database::execute($sql, $params);
    }

    private function resolveWorkspaceId(
        int $workflowId,
        int $contactId,
        ?int $workspaceId = null,
        bool $allowContextFallback = true
    ): int
    {
        $workflowWorkspaceId = 0;
        if ($workflowId > 0) {
            $workflow = Database::queryOne(
                "SELECT workspace_id
                 FROM workflows
                 WHERE id = ?
                 LIMIT 1",
                [$workflowId]
            );
            $workflowWorkspaceId = (int) ($workflow['workspace_id'] ?? 0);
        }

        $contactWorkspaceId = 0;
        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT workspace_id
                 FROM contacts
                 WHERE id = ?
                 LIMIT 1",
                [$contactId]
            );
            $contactWorkspaceId = (int) ($contact['workspace_id'] ?? 0);
        }

        if ($workflowWorkspaceId > 0 && $contactWorkspaceId > 0 && $workflowWorkspaceId !== $contactWorkspaceId) {
            throw new \RuntimeException('Workflow and contact belong to different workspaces.');
        }

        if ($workspaceId !== null && $workspaceId > 0) {
            if (($workflowWorkspaceId > 0 && $workflowWorkspaceId !== $workspaceId) || ($contactWorkspaceId > 0 && $contactWorkspaceId !== $workspaceId)) {
                throw new \RuntimeException('Workflow execution workspace does not match workflow or contact workspace.');
            }
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($allowContextFallback && $contextWorkspaceId > 0) {
            if (($workflowWorkspaceId > 0 && $workflowWorkspaceId !== $contextWorkspaceId) || ($contactWorkspaceId > 0 && $contactWorkspaceId !== $contextWorkspaceId)) {
                throw new \RuntimeException('Workflow execution is outside the active workspace.');
            }
            return $contextWorkspaceId;
        }

        if ($contactWorkspaceId > 0) {
            return $contactWorkspaceId;
        }

        if ($workflowWorkspaceId > 0) {
            return $workflowWorkspaceId;
        }

        return 0;
    }

    private function actionFromNode(array $node): array
    {
        $type = $node['type'] ?? '';
        $subtype = $node['subtype'] ?? '';
        $config = $node['config'] ?? [];
        if ($type === 'delay') {
            return [
                'type' => 'wait_for_days',
                'days' => (int) ($config['days'] ?? 1),
            ];
        }

        if ($type === 'action') {
            return array_merge(['type' => $subtype], $config);
        }

        return [];
    }
}
