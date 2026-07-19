<?php

namespace CRM\Services;

use CRM\Database;

class AICrossDomainEventIntakeService
{
    private AIWorkspaceScopeService $workspaceScope;
    private AICrossDomainOrchestratorService $orchestrator;
    private AIAutonomyRolloutOperationsService $rollout;
    private AICrossDomainTriggerDedupeService $dedupe;
    private AIAutonomyIncidentService $incidents;

    public function __construct(
        ?AICrossDomainOrchestratorService $orchestrator = null,
        ?AIAutonomyRolloutOperationsService $rollout = null,
        ?AICrossDomainTriggerDedupeService $dedupe = null,
        ?AIAutonomyIncidentService $incidents = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
        $this->orchestrator = $orchestrator ?? new AICrossDomainOrchestratorService();
        $this->rollout = $rollout ?? new AIAutonomyRolloutOperationsService();
        $this->dedupe = $dedupe ?? new AICrossDomainTriggerDedupeService($this->workspaceScope);
        $this->incidents = $incidents ?? new AIAutonomyIncidentService($this->workspaceScope);
    }

    public function processEvent(array $event, ?int $userId = null): array
    {
        $normalized = $this->normalizeEvent($event);
        if (!empty($normalized['skip_orchestration'])) {
            return $this->logDecision($normalized, 'ignored', 'orchestration_origin_ignored');
        }

        $objective = $this->resolveObjective($normalized);
        if (!$objective) {
            return $this->logDecision($normalized, 'ignored', 'no_matching_objective');
        }

        $readiness = $this->rollout->computeReadiness((string) $objective['tenant_key'], 'cross_domain_orchestrator');
        if (in_array((string) ($readiness['rollout_state'] ?? ''), ['paused', 'manually_frozen'], true)) {
            return $this->logDecision($normalized, 'suppressed', 'orchestrator_contained');
        }

        if (!empty($objective['customer_facing']) && !empty(($readiness['control']['metadata']['pause_customer_facing_only'] ?? false))) {
            return $this->logDecision($normalized, 'suppressed', 'customer_facing_contained');
        }

        $existing = $this->dedupe->findActiveMatchingRun(
            (string) $objective['tenant_key'],
            (string) $objective['objective_key'],
            (string) $objective['primary_entity_type'],
            (int) $objective['primary_entity_id']
        );
        if ($this->dedupe->isCoveredByMoreAdvancedState($existing)) {
            return $this->logDecision($normalized, 'duplicate_rejected', 'active_run_exists', ['existing_run_id' => (int) ($existing['id'] ?? 0)]);
        }

        $run = $this->orchestrator->startObjective([
            'tenant_key' => (string) $objective['tenant_key'],
            'workspace_id' => (int) $objective['workspace_id'],
            'objective_key' => (string) $objective['objective_key'],
            'primary_entity_type' => (string) $objective['primary_entity_type'],
            'primary_entity_id' => (int) $objective['primary_entity_id'],
            'related_entity_ids' => (array) ($objective['related_entity_ids'] ?? []),
            'reason_note' => (string) ($objective['reason_note'] ?? ''),
            'execute_now' => false,
            'origin_type' => 'event',
            'trigger_source_domain' => (string) $normalized['source_domain'],
            'trigger_key' => (string) $normalized['trigger_key'],
            'trigger_entity_type' => (string) ($normalized['trigger_entity_type'] ?? ''),
            'trigger_entity_id' => (int) ($normalized['trigger_entity_id'] ?? 0),
            'trigger_metadata' => (array) ($normalized['metadata'] ?? []),
        ], (int) ($userId ?? 0));

        $runId = (int) ($run['id'] ?? 0);
        if ($runId > 0) {
            $prepared = $this->orchestrator->prepareRun($runId);
            $run = $prepared ?: $run;
        }

        if (!empty($objective['wait_state']) && $runId > 0) {
            $run = $this->orchestrator->markRunWaiting($runId, (array) $objective['wait_state']);
        }

        return $this->logDecision($normalized, !empty($objective['wait_state']) ? 'run_waiting' : 'run_created', (string) ($objective['reason_note'] ?? ''), [
            'linked_run_id' => $runId,
            'objective_key' => (string) $objective['objective_key'],
            'run_status' => (string) ($run['run_status'] ?? 'planned'),
        ]);
    }

    private function resolveObjective(array $event): ?array
    {
        $source = (string) ($event['source_domain'] ?? '');
        $trigger = (string) ($event['trigger_key'] ?? '');
        $related = (array) ($event['related_entity_ids'] ?? []);
        $tenantKey = (string) ($event['tenant_key'] ?? $this->workspaceScope->currentTenantKey());
        $workspaceId = (int) ($event['workspace_id'] ?? $this->workspaceScope->requireWorkspaceId($tenantKey));

        return match ($source) {
            'deal_followthrough' => $this->resolveDealObjective($trigger, $related, $tenantKey, $workspaceId),
            'commercial_mvp' => $this->resolveCommercialObjective($trigger, $related, $tenantKey, $workspaceId),
            'task_followthrough' => $this->resolveTaskObjective($trigger, $related, $tenantKey, $workspaceId),
            'customer_thread' => $this->resolveCustomerThreadObjective($trigger, $related, $tenantKey, $workspaceId, (array) ($event['metadata'] ?? [])),
            'workflow_execution' => $this->resolveWorkflowObjective($trigger, $related, $tenantKey, $workspaceId, (array) ($event['metadata'] ?? [])),
            default => null,
        };
    }

    private function resolveDealObjective(string $trigger, array $related, string $tenantKey, int $workspaceId): ?array
    {
        $dealId = (int) ($related['deal_id'] ?? 0);
        if ($dealId <= 0) {
            return null;
        }

        if (in_array($trigger, ['stage_change', 'deal_progression_stalled', 'deal_milestone'], true)) {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'progress_deal_to_next_stage',
                'primary_entity_type' => 'deal',
                'primary_entity_id' => $dealId,
                'related_entity_ids' => $related,
                'customer_facing' => false,
                'reason_note' => 'Deal event created a bounded orchestration plan.',
            ];
        }

        return null;
    }

    private function resolveCommercialObjective(string $trigger, array $related, string $tenantKey, int $workspaceId): ?array
    {
        $communicationId = (int) ($related['communication_id'] ?? 0);
        $dealId = (int) ($related['deal_id'] ?? 0);
        $contactId = (int) ($related['contact_id'] ?? 0);
        if ($communicationId <= 0 && $dealId <= 0) {
            return null;
        }

        if (in_array($trigger, ['communication', 'negotiation_activity', 'send_readiness', 'proposal_followup_due'], true)) {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'move_commercial_thread_to_sent_document',
                'primary_entity_type' => $communicationId > 0 ? 'communication' : 'deal',
                'primary_entity_id' => $communicationId > 0 ? $communicationId : $dealId,
                'related_entity_ids' => array_merge($related, ['contact_id' => $contactId]),
                'customer_facing' => true,
                'reason_note' => 'Commercial automation signaled document follow-through.',
            ];
        }

        return null;
    }

    private function resolveTaskObjective(string $trigger, array $related, string $tenantKey, int $workspaceId): ?array
    {
        $taskId = (int) ($related['task_id'] ?? 0);
        if ($taskId <= 0) {
            return null;
        }

        $objective = [
            'workspace_id' => $workspaceId,
            'tenant_key' => $tenantKey,
            'objective_key' => 'close_task_followthrough_after_milestone',
            'primary_entity_type' => 'task',
            'primary_entity_id' => $taskId,
            'related_entity_ids' => $related,
            'customer_facing' => false,
            'reason_note' => 'Task milestone created a bounded orchestration plan.',
        ];

        if (empty($related['deal_id']) && in_array($trigger, ['task_completed', 'task_milestone'], true)) {
            $objective['wait_state'] = [
                'waiting_reason' => 'waiting_for_related_deal_or_commercial_context',
                'waiting_on_type' => 'task_followthrough',
                'resume_conditions' => [
                    'contact_id' => (int) ($related['contact_id'] ?? 0),
                    'allowed_source_domains' => ['commercial_mvp', 'deal_followthrough'],
                ],
                'timeout_at' => date('Y-m-d H:i:s', strtotime('+72 hours')),
            ];
        }

        return $objective;
    }

    private function resolveCustomerThreadObjective(string $trigger, array $related, string $tenantKey, int $workspaceId, array $metadata): ?array
    {
        $communicationId = (int) ($related['communication_id'] ?? 0);
        $contactId = (int) ($related['contact_id'] ?? 0);
        if ($communicationId <= 0 && $contactId <= 0) {
            return null;
        }

        if (in_array($trigger, ['thread_waiting_on_us', 'thread_overdue', 'reply_needed'], true)) {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'recover_stalled_customer_thread',
                'primary_entity_type' => $communicationId > 0 ? 'communication' : 'contact',
                'primary_entity_id' => $communicationId > 0 ? $communicationId : $contactId,
                'related_entity_ids' => $related,
                'customer_facing' => true,
                'reason_note' => 'Customer thread requires bounded follow-through.',
            ];
        }

        if (($metadata['thread_status'] ?? '') === 'waiting_on_contact') {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'recover_stalled_customer_thread',
                'primary_entity_type' => $communicationId > 0 ? 'communication' : 'contact',
                'primary_entity_id' => $communicationId > 0 ? $communicationId : $contactId,
                'related_entity_ids' => $related,
                'customer_facing' => true,
                'reason_note' => 'Customer thread entered a waiting state.',
                'wait_state' => [
                    'waiting_reason' => 'waiting_on_contact_response',
                    'waiting_on_type' => 'thread_state',
                    'resume_conditions' => [
                        'contact_id' => $contactId,
                        'thread_statuses' => ['waiting_on_us', 'open'],
                    ],
                    'timeout_at' => date('Y-m-d H:i:s', strtotime('+72 hours')),
                ],
            ];
        }

        return null;
    }

    private function resolveWorkflowObjective(string $trigger, array $related, string $tenantKey, int $workspaceId, array $metadata): ?array
    {
        if (!in_array($trigger, ['workflow_followup_needed', 'workflow_customer_send_failed', 'workflow_retry_pending'], true)) {
            return null;
        }

        if (!empty($related['deal_id'])) {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'progress_deal_to_next_stage',
                'primary_entity_type' => 'deal',
                'primary_entity_id' => (int) $related['deal_id'],
                'related_entity_ids' => $related,
                'customer_facing' => false,
                'reason_note' => 'Workflow execution requested deal follow-through.',
            ];
        }

        if (!empty($related['task_id'])) {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'close_task_followthrough_after_milestone',
                'primary_entity_type' => 'task',
                'primary_entity_id' => (int) $related['task_id'],
                'related_entity_ids' => $related,
                'customer_facing' => false,
                'reason_note' => 'Workflow execution requested task follow-through.',
            ];
        }

        if (!empty($related['contact_id']) && !empty($metadata['customer_facing'])) {
            return [
                'workspace_id' => $workspaceId,
                'tenant_key' => $tenantKey,
                'objective_key' => 'recover_stalled_customer_thread',
                'primary_entity_type' => 'contact',
                'primary_entity_id' => (int) $related['contact_id'],
                'related_entity_ids' => $related,
                'customer_facing' => true,
                'reason_note' => 'Workflow customer-facing action needs bounded follow-through.',
            ];
        }

        return null;
    }

    private function normalizeEvent(array $event): array
    {
        $related = (array) ($event['related_entity_ids'] ?? []);
        $contactId = (int) ($related['contact_id'] ?? ($event['contact_id'] ?? 0));
        $dealId = (int) ($related['deal_id'] ?? ($event['deal_id'] ?? 0));
        $taskId = (int) ($related['task_id'] ?? ($event['task_id'] ?? 0));
        $invoiceId = (int) ($related['invoice_id'] ?? ($event['invoice_id'] ?? 0));
        $workflowId = (int) ($related['workflow_id'] ?? ($event['workflow_id'] ?? 0));

        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $candidateWorkspaceId = $this->derivedWorkspaceId($contactId, $dealId, $taskId, $invoiceId, $workflowId);
        if ($candidateWorkspaceId !== null && $candidateWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The requested orchestration event does not belong to the active workspace.');
        }
        $tenantWorkspaceId = $this->workspaceScope->resolveWorkspaceIdFromTenantKey((string) ($event['tenant_key'] ?? ''));
        if ($tenantWorkspaceId !== null && $tenantWorkspaceId > 0 && $tenantWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The requested orchestration event does not belong to the active workspace.');
        }

        return [
            'workspace_id' => $workspaceId,
            'tenant_key' => $this->workspaceScope->workspaceTenantKey($workspaceId),
            'source_domain' => (string) ($event['source_domain'] ?? ''),
            'trigger_key' => (string) ($event['trigger_key'] ?? ''),
            'trigger_entity_type' => (string) ($event['trigger_entity_type'] ?? ''),
            'trigger_entity_id' => (int) ($event['trigger_entity_id'] ?? 0),
            'related_entity_ids' => array_filter([
                'contact_id' => $contactId ?: null,
                'deal_id' => $dealId ?: null,
                'invoice_id' => $invoiceId ?: null,
                'task_id' => $taskId ?: null,
                'communication_id' => (int) ($related['communication_id'] ?? ($event['communication_id'] ?? 0)) ?: null,
                'workflow_id' => $workflowId ?: null,
            ], static fn($value): bool => $value !== null),
            'metadata' => (array) ($event['metadata'] ?? []),
            'skip_orchestration' => !empty($event['orchestration_run_id']) || !empty($event['metadata']['orchestration_run_id']),
        ];
    }

    private function logDecision(array $event, string $decision, string $reason, array $extra = []): array
    {
        $row = [
            'workspace_id' => (int) ($event['workspace_id'] ?? $this->workspaceScope->requireWorkspaceId()),
            'tenant_key' => (string) ($event['tenant_key'] ?? $this->workspaceScope->currentTenantKey()),
            'source_domain' => (string) ($event['source_domain'] ?? ''),
            'trigger_key' => (string) ($event['trigger_key'] ?? ''),
            'trigger_entity_type' => !empty($event['trigger_entity_type']) ? (string) $event['trigger_entity_type'] : null,
            'trigger_entity_id' => !empty($event['trigger_entity_id']) ? (int) $event['trigger_entity_id'] : null,
            'objective_key' => $extra['objective_key'] ?? null,
            'intake_decision' => $decision,
            'linked_run_id' => $extra['linked_run_id'] ?? null,
            'reason_text' => $reason,
            'metadata_json' => json_encode(array_merge([
                'event' => $event,
            ], $extra)),
        ];

        if ($this->tableExists('ai_cross_domain_intake_events')) {
            Database::execute(
                "INSERT INTO ai_cross_domain_intake_events
                    (workspace_id, tenant_key, source_domain, trigger_key, trigger_entity_type, trigger_entity_id, objective_key, intake_decision, linked_run_id, reason_text, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $row['workspace_id'],
                    $row['tenant_key'],
                    $row['source_domain'],
                    $row['trigger_key'],
                    $row['trigger_entity_type'],
                    $row['trigger_entity_id'],
                    $row['objective_key'],
                    $row['intake_decision'],
                    $row['linked_run_id'],
                    $row['reason_text'],
                    $row['metadata_json'],
                ]
            );
        }

        if (in_array($decision, ['duplicate_rejected', 'suppressed'], true) && $this->countRecentIntakeDecision((int) $row['workspace_id'], $row['source_domain'], $row['trigger_key'], $decision) >= 3) {
            $this->incidents->recordIncident([
                'tenant_key' => $row['tenant_key'],
                'domain_key' => 'cross_domain_orchestrator',
                'action_key' => $row['trigger_key'],
                'incident_key' => $decision === 'duplicate_rejected' ? 'cross_domain_duplicate_trigger_storm' : 'cross_domain_trigger_suppressed',
                'severity' => 'medium',
                'reason_codes' => [$reason],
                'details' => ['event' => $event, 'decision' => $decision],
            ]);
        }

        return array_merge([
            'intake_decision' => $decision,
            'reason' => $reason,
        ], $extra);
    }

    private function countRecentIntakeDecision(int $workspaceId, string $sourceDomain, string $triggerKey, string $decision): int
    {
        if (!$this->tableExists('ai_cross_domain_intake_events')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS aggregate_count
             FROM ai_cross_domain_intake_events
             WHERE workspace_id = ?
               AND source_domain = ?
               AND trigger_key = ?
               AND intake_decision = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$workspaceId, $sourceDomain, $triggerKey, $decision]
        );

        return (int) ($row['aggregate_count'] ?? 0);
    }

    private function derivedWorkspaceId(int $contactId, int $dealId, int $taskId, int $invoiceId, int $workflowId): ?int
    {
        foreach ([
            $contactId > 0 ? 'contact:' . $contactId : null,
            $dealId > 0 ? 'deal:' . $dealId : null,
            $taskId > 0 ? 'task:' . $taskId : null,
            $invoiceId > 0 ? 'invoice:' . $invoiceId : null,
            $workflowId > 0 ? 'workflow:' . $workflowId : null,
        ] as $tenantKey) {
            if ($tenantKey === null) {
                continue;
            }
            $resolved = $this->workspaceScope->resolveWorkspaceIdFromTenantKey($tenantKey);
            if ($resolved !== null && $resolved > 0) {
                return $resolved;
            }
        }

        return null;
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
}
