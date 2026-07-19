<?php
/**
 * Workflow Scheduler
 * Processes scheduled workflow actions
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AutomationEngine;

class WorkflowScheduler
{
    private AutomationEngine $automationEngine;
    private WorkflowRetryService $retryService;
    private WorkflowAutonomyExecutionService $autonomy;
    
    public function __construct()
    {
        $this->automationEngine = new AutomationEngine();
        $this->retryService = new WorkflowRetryService();
        $this->autonomy = new WorkflowAutonomyExecutionService();
    }
    
    /**
     * Process scheduled actions that are due
     */
    public function processScheduledActions(): int
    {
        $processed = 0;
        
        // Get all pending scheduled actions that are due
        $scheduledActions = Database::query(
            "SELECT * FROM scheduled_workflow_actions 
             WHERE status = 'pending' 
             AND scheduled_for <= NOW()
             ORDER BY scheduled_for ASC
             LIMIT 100",
            []
        );
        
        foreach ($scheduledActions as $scheduledAction) {
            $workspaceId = 0;
            try {
                $workspaceId = $this->resolveScheduledActionWorkspaceId($scheduledAction, false);
                if ($workspaceId > 0 && (int) ($scheduledAction['workspace_id'] ?? 0) <= 0) {
                    Database::execute(
                        "UPDATE scheduled_workflow_actions
                         SET workspace_id = ?
                         WHERE id = ?
                           AND workspace_id IS NULL",
                        [$workspaceId, $scheduledAction['id']]
                    );
                    $scheduledAction['workspace_id'] = $workspaceId;
                }

                AsyncWorkspaceRunner::runWithWorkspace(
                    $workspaceId,
                    function () use ($scheduledAction): void {
                        $this->executeScheduledAction($scheduledAction);
                    },
                    null,
                    'Scheduled workflow action is missing a valid workspace.'
                );
                $processed++;
            } catch (\Exception $e) {
                error_log("Failed to process scheduled action #{$scheduledAction['id']}: " . $e->getMessage());
                
                // Update retry count
                $retryCount = (int)$scheduledAction['retry_count'] + 1;
                $maxRetries = 3;
                
                if ($retryCount >= $maxRetries) {
                    // Mark as failed after max retries
                    $this->updateScheduledActionState(
                        (int) $scheduledAction['id'],
                        $workspaceId,
                        [
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                            'retry_count' => $retryCount,
                        ]
                    );
                } else {
                    // Schedule retry with exponential backoff
                    $backoffMinutes = [1, 5, 15][$retryCount - 1] ?? 60;
                    $retryTime = date('Y-m-d H:i:s', strtotime("+{$backoffMinutes} minutes"));
                    
                    $this->updateScheduledActionState(
                        (int) $scheduledAction['id'],
                        $workspaceId,
                        [
                            'scheduled_for' => $retryTime,
                            'retry_count' => $retryCount,
                            'error_message' => $e->getMessage(),
                        ]
                    );
                }
            }
        }
        
        return $processed;
    }

    public function processRetryQueue(int $limit = 100): int
    {
        $processed = 0;
        $items = $this->retryService->claimDueRetries($limit);

        foreach ($items as $item) {
            $workspaceId = 0;
            try {
                $workspaceId = $this->resolveRetryWorkspaceId($item, false);
                $this->ensureRetryWorkspaceId($item, $workspaceId);
                $this->runRetryItem($item, $workspaceId);

                $this->retryService->finalizeRetry([
                    'id' => (int) $item['id'],
                    'workspace_id' => $workspaceId,
                    'status' => 'completed',
                    'error' => null,
                ]);
                $processed++;
            } catch (\Exception $e) {
                $this->retryService->finalizeRetry([
                    'id' => (int) $item['id'],
                    'workspace_id' => $workspaceId,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    public function processRetryItem(int $retryId): bool
    {
        $item = $this->retryService->claimRetryById($retryId);
        if (!$item) {
            return false;
        }

        try {
            $workspaceId = $this->resolveRetryWorkspaceId($item, false);
            $this->ensureRetryWorkspaceId($item, $workspaceId);
            $this->runRetryItem($item, $workspaceId);

            $this->retryService->finalizeRetry([
                'id' => (int) $item['id'],
                'workspace_id' => $workspaceId,
                'status' => 'completed',
                'error' => null,
            ]);
            return true;
        } catch (\Exception $e) {
            $this->retryService->finalizeRetry([
                'id' => (int) $item['id'],
                'workspace_id' => (int) ($item['workspace_id'] ?? 0),
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Execute a scheduled action
     */
    private function executeScheduledAction(array $scheduledAction): void
    {
        $workflowId = (int)$scheduledAction['workflow_id'];
        $executionId = (int)$scheduledAction['execution_id'];
        $actionIndex = (int)$scheduledAction['action_index'];
        
        // Get workflow
        $workflow = Database::queryOne(
            "SELECT * FROM workflows WHERE workspace_id = ? AND id = ? AND is_active = 1",
            [(int) ($scheduledAction['workspace_id'] ?? 0), $workflowId]
        );
        
        if (!$workflow) {
            throw new \Exception("Workflow not found or inactive");
        }
        
        // Get execution context
        $execution = Database::queryOne(
            "SELECT * FROM workflow_executions WHERE workspace_id = ? AND id = ?",
            [(int) ($scheduledAction['workspace_id'] ?? 0), $executionId]
        );
        
        if (!$execution) {
            throw new \Exception("Workflow execution not found");
        }
        
        // Get contact data
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
            [(int) ($scheduledAction['workspace_id'] ?? 0), $execution['contact_id']]
        );
        
        if (!$contact) {
            throw new \Exception("Contact not found");
        }
        
        // Get actions from workflow
        $actions = json_decode($workflow['actions'], true) ?? [];
        
        if (!isset($actions[$actionIndex])) {
            throw new \Exception("Action index out of bounds");
        }
        
        $action = $this->normalizeWorkflowAction($actions[$actionIndex]);
        
        // Build context
        $context = [
            'contact_id' => $contact['id'],
            'contact_email' => $contact['email'],
            'email' => $contact['email'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'phone' => $contact['phone'],
            'company' => $contact['company'],
            'workflow_id' => $workflowId,
            'workspace_id' => (int) ($scheduledAction['workspace_id'] ?? 0),
            'execution_id' => $executionId,
            'action_index' => $actionIndex,
            'workflow_queue_state' => 'scheduled',
        ];

        if ($this->autonomy->shouldBypassGovernance((string) ($action['type'] ?? ''))) {
            $this->automationEngine->executeAction($action, $context);
        } else {
            $result = $this->autonomy->executeAction(
                $workflow,
                $action,
                $context,
                fn() => $this->automationEngine->executeAction($action, $context)
            );
            if (($result['status'] ?? '') !== 'executed') {
                throw new \RuntimeException(implode(', ', (array) ($result['decision']['reasons'] ?? ['scheduled_workflow_action_blocked'])));
            }
        }
        
        // Mark as executed
        $this->updateScheduledActionState(
            (int) $scheduledAction['id'],
            (int) ($scheduledAction['workspace_id'] ?? 0),
            [
                'status' => 'executed',
                'error_message' => null,
            ]
        );
    }
    
    /**
     * Schedule a workflow action
     */
    public function scheduleAction(int $workflowId, int $executionId, int $actionIndex, \DateTime $scheduledFor, string $timezone = 'UTC', ?int $workspaceId = null): int
    {
        $workspaceId = $workspaceId ?: $this->resolveWorkflowWorkspaceId($workflowId, $executionId);
        Database::execute(
            "INSERT INTO scheduled_workflow_actions 
             (workflow_id, execution_id, workspace_id, action_index, scheduled_for, timezone, status) 
             VALUES (?, ?, ?, ?, ?, ?, 'pending')",
            [
                $workflowId,
                $executionId,
                $workspaceId > 0 ? $workspaceId : null,
                $actionIndex,
                $scheduledFor->format('Y-m-d H:i:s'),
                $timezone
            ]
        );
        
        return (int) Database::lastInsertId();
    }

    private function actionFromRetryNode(array $node): array
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

    private function normalizeWorkflowAction(array $action): array
    {
        if (isset($action['type']) && isset($action['config']) && is_array($action['config'])) {
            return array_merge(['type' => $action['type']], $action['config']);
        }
        return $action;
    }

    private function resolveScheduledActionWorkspaceId(array $scheduledAction, bool $allowContextFallback = true): int
    {
        $workspaceId = (int) ($scheduledAction['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        return $this->resolveWorkflowWorkspaceId(
            (int) ($scheduledAction['workflow_id'] ?? 0),
            (int) ($scheduledAction['execution_id'] ?? 0),
            $allowContextFallback
        );
    }

    private function resolveRetryWorkspaceId(array $item, bool $allowContextFallback = true): int
    {
        $workspaceId = (int) ($item['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        return $this->resolveWorkflowWorkspaceId(
            (int) ($item['workflow_id'] ?? 0),
            (int) ($item['workflow_execution_id'] ?? 0),
            $allowContextFallback
        );
    }

    private function resolveWorkflowWorkspaceId(int $workflowId, int $executionId = 0, bool $allowContextFallback = true): int
    {
        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($allowContextFallback && $contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        if ($executionId > 0) {
            $execution = Database::queryOne(
                "SELECT workspace_id
                 FROM workflow_executions
                 WHERE id = ?
                 LIMIT 1",
                [$executionId]
            );
            $workspaceId = (int) ($execution['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        if ($workflowId > 0) {
            $workflow = Database::queryOne(
                "SELECT workspace_id
                 FROM workflows
                 WHERE id = ?
                 LIMIT 1",
                [$workflowId]
            );
            return (int) ($workflow['workspace_id'] ?? 0);
        }

        return 0;
    }

    private function ensureRetryWorkspaceId(array &$item, int $workspaceId): void
    {
        if ($workspaceId <= 0 || (int) ($item['workspace_id'] ?? 0) > 0) {
            return;
        }

        Database::execute(
            "UPDATE workflow_retry_queue
             SET workspace_id = ?
             WHERE id = ?
               AND workspace_id IS NULL",
            [$workspaceId, $item['id']]
        );
        $item['workspace_id'] = $workspaceId;
    }

    private function runRetryItem(array $item, int $workspaceId): void
    {
        AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($item, $workspaceId): void {
                $payload = json_decode($item['payload_json'] ?? '[]', true) ?? [];
                $node = $payload['node'] ?? [];
                $action = $this->actionFromRetryNode($node);
                if (empty($action['type'])) {
                    throw new \RuntimeException('Retry payload is missing action data');
                }

                $actionContext = array_merge($payload, [
                    'workflow_id' => (int) $item['workflow_id'],
                    'execution_id' => (int) $item['workflow_execution_id'],
                    'action_index' => $item['action_index'] ?? null,
                    'node_id' => $item['node_id'],
                    'workflow_queue_state' => 'retry',
                ]);

                if ($this->autonomy->shouldBypassGovernance((string) $action['type'])) {
                    $this->automationEngine->executeAction($action, $actionContext);
                    return;
                }

                $workflow = Database::queryOne(
                    "SELECT * FROM workflows WHERE workspace_id = ? AND id = ? AND is_active = 1",
                    [$workspaceId, (int) $item['workflow_id']]
                );
                if (!$workflow) {
                    throw new \RuntimeException('Workflow not found or inactive');
                }
                $result = $this->autonomy->executeAction(
                    $workflow,
                    $action,
                    $actionContext,
                    fn() => $this->automationEngine->executeAction($action, $actionContext)
                );
                if (($result['status'] ?? '') !== 'executed') {
                    throw new \RuntimeException(implode(', ', (array) ($result['decision']['reasons'] ?? ['workflow_retry_blocked'])));
                }
            },
            null,
            'Workflow retry item is missing a valid workspace.'
        );
    }

    /**
     * @param array<string,mixed> $updates
     */
    private function updateScheduledActionState(int $scheduledActionId, int $workspaceId, array $updates): void
    {
        if ($scheduledActionId <= 0 || $updates === []) {
            return;
        }

        $set = [];
        $params = [];
        foreach ($updates as $column => $value) {
            $set[] = $column . ' = ?';
            $params[] = $value;
        }

        if ($workspaceId > 0) {
            $params[] = $scheduledActionId;
            $params[] = $workspaceId;
            Database::execute(
                "UPDATE scheduled_workflow_actions
                 SET " . implode(', ', $set) . "
                 WHERE id = ?
                   AND workspace_id = ?",
                $params
            );
            return;
        }

        $params[] = $scheduledActionId;
        Database::execute(
            "UPDATE scheduled_workflow_actions
             SET " . implode(', ', $set) . "
             WHERE id = ?
               AND workspace_id IS NULL",
            $params
        );
    }
}
