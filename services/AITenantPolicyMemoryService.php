<?php

namespace CRM\Services;

use CRM\Database;

class AITenantPolicyMemoryService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function remember(
        string $tenantKey,
        string $scopeKey,
        string $memoryKey,
        array $memoryValue = [],
        array $stats = []
    ): int {
        if (!$this->tableExists('ai_tenant_policy_memory')) {
            return 0;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        $scopeKey = trim($scopeKey) !== '' ? trim($scopeKey) : 'commercial_mvp';
        $memoryKey = trim($memoryKey);
        if ($memoryKey === '') {
            throw new \InvalidArgumentException('Memory key is required.');
        }

        $row = Database::queryOne(
            "SELECT *
             FROM ai_tenant_policy_memory
             WHERE workspace_id = ?
               AND scope_key = ?
               AND memory_key = ?
             LIMIT 1",
            [$workspaceId, $scopeKey, $memoryKey]
        );

        $evidenceDelta = max(0, (int) ($stats['evidence_delta'] ?? 1));
        $successDelta = max(0, (int) ($stats['success_delta'] ?? 0));
        $reversalDelta = max(0, (int) ($stats['reversal_delta'] ?? 0));
        $editDelta = max(0, (int) ($stats['edit_delta'] ?? 0));
        $observedAt = (string) ($stats['observed_at'] ?? date('Y-m-d H:i:s'));

        if ($row) {
            $mergedValue = array_merge($this->decodeJson($row['memory_value_json'] ?? null), $memoryValue);
            Database::execute(
                "UPDATE ai_tenant_policy_memory
                 SET memory_value_json = ?,
                     evidence_count = evidence_count + ?,
                     success_count = success_count + ?,
                     reversal_count = reversal_count + ?,
                     edit_count = edit_count + ?,
                     last_observed_at = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [
                    json_encode($mergedValue),
                    $evidenceDelta,
                    $successDelta,
                    $reversalDelta,
                    $editDelta,
                    $observedAt,
                    (int) $row['id'],
                ]
            );
            return (int) $row['id'];
        }

        Database::execute(
            "INSERT INTO ai_tenant_policy_memory
                (workspace_id, tenant_key, scope_key, memory_key, memory_value_json, evidence_count, success_count,
                 reversal_count, edit_count, last_observed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $tenantKey,
                $scopeKey,
                $memoryKey,
                json_encode($memoryValue),
                $evidenceDelta,
                $successDelta,
                $reversalDelta,
                $editDelta,
                $observedAt,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function recordActionObservation(array $observation): void
    {
        $tenantKey = trim((string) ($observation['tenant_key'] ?? 'global:default'));
        $scopeKey = trim((string) ($observation['scope_key'] ?? 'commercial_mvp'));
        $actionKey = trim((string) ($observation['action_key'] ?? 'unknown'));
        $channel = trim((string) ($observation['channel'] ?? ''));
        $documentType = trim((string) ($observation['document_type'] ?? ''));
        $dealStage = trim((string) ($observation['deal_stage'] ?? ''));
        $taskIntent = trim((string) ($observation['task_intent'] ?? ''));
        $threadCautionLevel = trim((string) ($observation['thread_caution_level'] ?? ''));
        $nextStepTimingHours = !empty($observation['next_step_timing_hours']) ? (int) $observation['next_step_timing_hours'] : 0;
        $workflowTriggerType = trim((string) ($observation['workflow_trigger_type'] ?? ''));
        $workflowQueueState = trim((string) ($observation['workflow_queue_state'] ?? ''));
        $workflowCustomerFacing = array_key_exists('workflow_customer_facing', $observation)
            ? (!empty($observation['workflow_customer_facing']) ? 'customer_facing' : 'internal')
            : '';
        $actorUserId = !empty($observation['actor_user_id']) ? (int) $observation['actor_user_id'] : null;
        $successful = !empty($observation['was_successful']);
        $reversed = !empty($observation['was_reversed']);
        $edited = !empty($observation['was_edited']);
        $observedAt = (string) ($observation['observed_at'] ?? date('Y-m-d H:i:s'));

        $baseValue = [
            'last_action' => $actionKey,
            'last_channel' => $channel !== '' ? $channel : null,
            'last_document_type' => $documentType !== '' ? $documentType : null,
            'last_actor_user_id' => $actorUserId,
        ];

        $this->remember($tenantKey, $scopeKey, 'action:' . $actionKey, $baseValue, [
            'evidence_delta' => 1,
            'success_delta' => $successful ? 1 : 0,
            'reversal_delta' => $reversed ? 1 : 0,
            'edit_delta' => $edited ? 1 : 0,
            'observed_at' => $observedAt,
        ]);

        if ($channel !== '') {
            $this->remember($tenantKey, $scopeKey, 'preferred_channel:' . $channel, [
                'channel' => $channel,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($documentType !== '') {
            $this->remember($tenantKey, $scopeKey, 'document_type:' . $documentType, [
                'document_type' => $documentType,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($dealStage !== '') {
            $this->remember($tenantKey, $scopeKey, 'deal_stage:' . $dealStage, [
                'deal_stage' => $dealStage,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($taskIntent !== '') {
            $this->remember($tenantKey, $scopeKey, 'task_intent:' . $taskIntent, [
                'task_intent' => $taskIntent,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($threadCautionLevel !== '') {
            $this->remember($tenantKey, $scopeKey, 'thread_caution:' . $threadCautionLevel, [
                'thread_caution_level' => $threadCautionLevel,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($nextStepTimingHours > 0) {
            $this->remember($tenantKey, $scopeKey, 'next_step_timing_hours:' . $nextStepTimingHours, [
                'next_step_timing_hours' => $nextStepTimingHours,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($workflowTriggerType !== '') {
            $this->remember($tenantKey, $scopeKey, 'workflow_trigger:' . $workflowTriggerType, [
                'workflow_trigger_type' => $workflowTriggerType,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($workflowQueueState !== '') {
            $this->remember($tenantKey, $scopeKey, 'workflow_queue_state:' . $workflowQueueState, [
                'workflow_queue_state' => $workflowQueueState,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($workflowCustomerFacing !== '') {
            $this->remember($tenantKey, $scopeKey, 'workflow_customer_facing:' . $workflowCustomerFacing, [
                'workflow_customer_facing' => $workflowCustomerFacing,
                'last_action' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }

        if ($actorUserId !== null && $actorUserId > 0) {
            $this->remember($tenantKey, $scopeKey, 'operator:' . $actorUserId . ':action:' . $actionKey, [
                'actor_user_id' => $actorUserId,
                'action_key' => $actionKey,
            ], [
                'evidence_delta' => 1,
                'success_delta' => $successful ? 1 : 0,
                'reversal_delta' => $reversed ? 1 : 0,
                'edit_delta' => $edited ? 1 : 0,
                'observed_at' => $observedAt,
            ]);
        }
    }

    public function getScopeMemory(string $tenantKey, string $scopeKey = 'commercial_mvp'): array
    {
        if (!$this->tableExists('ai_tenant_policy_memory')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);

        $rows = Database::query(
            "SELECT *
             FROM ai_tenant_policy_memory
             WHERE workspace_id = ?
               AND scope_key = ?
             ORDER BY memory_key ASC",
            [$workspaceId, $scopeKey]
        );

        $memory = [];
        foreach ($rows as $row) {
            $memory[(string) $row['memory_key']] = [
                'value' => $this->decodeJson($row['memory_value_json'] ?? null),
                'evidence_count' => (int) ($row['evidence_count'] ?? 0),
                'success_count' => (int) ($row['success_count'] ?? 0),
                'reversal_count' => (int) ($row['reversal_count'] ?? 0),
                'edit_count' => (int) ($row['edit_count'] ?? 0),
                'last_observed_at' => $row['last_observed_at'] ?? null,
            ];
        }

        return $memory;
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
