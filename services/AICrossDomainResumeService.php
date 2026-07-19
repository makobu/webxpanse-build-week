<?php

namespace CRM\Services;

use CRM\Database;

class AICrossDomainResumeService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AICrossDomainOrchestratorService $orchestrator = null,
        private ?AICrossDomainPlanStoreService $store = null,
        private ?AIAutonomyIncidentService $incidents = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
        $this->orchestrator = $this->orchestrator ?? new AICrossDomainOrchestratorService();
        $this->store = $this->store ?? new AICrossDomainPlanStoreService($this->workspaceScope);
        $this->incidents = $this->incidents ?? new AIAutonomyIncidentService($this->workspaceScope);
    }

    public function handleSignal(array $signal): array
    {
        $workspaceId = $this->signalWorkspaceId($signal);
        $matchingRuns = $this->findWaitingRunsForSignal($signal, $workspaceId);
        $updated = [];

        foreach ($matchingRuns as $run) {
            $waitState = (array) ($run['wait_state'] ?? []);
            if ($this->isTimedOut($waitState)) {
                $this->queueTimeoutRecovery($run, $waitState);
                continue;
            }

            if ($this->matchesSignal($waitState, $signal)) {
                $updated[] = $this->orchestrator->markRunReadyToResume((int) $run['id'], [
                    'resume_source' => (string) ($signal['source_domain'] ?? 'external_event'),
                    'resume_signal' => $signal,
                ]);
            }
        }

        return ['updated_runs' => $updated];
    }

    public function resumeRun(int $runId, int $userId): array
    {
        $run = $this->store->getRun($runId);
        if (!$run) {
            throw new \InvalidArgumentException('Orchestration run not found.');
        }

        $status = (string) ($run['run_status'] ?? '');
        if (!in_array($status, ['ready_to_resume', 'planned', 'waiting'], true)) {
            return $this->orchestrator->getRun($runId) ?? [];
        }

        if ($status === 'waiting') {
            $this->orchestrator->markRunReadyToResume($runId, ['resume_source' => 'operator']);
        }

        return $this->orchestrator->executeRun($runId, $userId);
    }

    public function refreshWaitingTimeouts(string $tenantKey = 'global:default', int $limit = 50): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $runs = Database::query(
            "SELECT * FROM ai_cross_domain_runs
             WHERE workspace_id = ?
               AND run_status = 'waiting'
             ORDER BY created_at ASC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        );
        $timedOut = [];
        foreach ($runs as $row) {
            $run = $this->store->getRun((int) ($row['id'] ?? 0));
            if (!$run) {
                continue;
            }
            $waitState = (array) ($run['wait_state'] ?? []);
            if ($this->isTimedOut($waitState)) {
                $this->queueTimeoutRecovery($run, $waitState);
                $timedOut[] = $run;
            }
        }

        return ['timed_out_runs' => $timedOut];
    }

    private function findWaitingRunsForSignal(array $signal, int $workspaceId): array
    {
        $rows = Database::query(
            "SELECT * FROM ai_cross_domain_runs
             WHERE workspace_id = ?
               AND run_status = 'waiting'
             ORDER BY created_at ASC, id ASC
             LIMIT 100",
            [$workspaceId]
        );

        $runs = [];
        foreach ($rows as $row) {
            $run = $this->store->getRun((int) ($row['id'] ?? 0));
            if ($run) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    private function matchesSignal(array $waitState, array $signal): bool
    {
        $conditions = (array) ($waitState['resume_conditions'] ?? []);
        if ($conditions === []) {
            return false;
        }

        if (!empty($conditions['contact_id']) && (int) ($conditions['contact_id'] ?? 0) !== (int) ($signal['contact_id'] ?? ($signal['related_entity_ids']['contact_id'] ?? 0))) {
            return false;
        }
        if (!empty($conditions['allowed_source_domains']) && !in_array((string) ($signal['source_domain'] ?? ''), (array) $conditions['allowed_source_domains'], true)) {
            return false;
        }
        if (!empty($conditions['thread_statuses'])) {
            $threadStatus = (string) ($signal['metadata']['thread_status'] ?? '');
            if (!in_array($threadStatus, (array) $conditions['thread_statuses'], true)) {
                return false;
            }
        }

        return true;
    }

    private function isTimedOut(array $waitState): bool
    {
        $timeoutAt = trim((string) ($waitState['timeout_at'] ?? ''));
        return $timeoutAt !== '' && strtotime($timeoutAt) !== false && strtotime($timeoutAt) <= time();
    }

    private function queueTimeoutRecovery(array $run, array $waitState): void
    {
        $incidentId = $this->incidents->recordIncident([
            'tenant_key' => (string) ($run['tenant_key'] ?? $this->workspaceScope->currentTenantKey()),
            'domain_key' => 'cross_domain_orchestrator',
            'action_key' => (string) ($run['objective_key'] ?? 'cross_domain_objective'),
            'incident_key' => 'cross_domain_wait_timeout',
            'severity' => 'medium',
            'reason_codes' => ['waiting_timeout_without_resume'],
            'details' => [
                'run_id' => $run['id'] ?? null,
                'objective_key' => $run['objective_key'] ?? null,
                'wait_state' => $waitState,
            ],
            'linked_entity_type' => 'orchestration_run',
            'linked_entity_id' => !empty($run['id']) ? (int) $run['id'] : null,
        ]);

        if ($incidentId) {
            $this->incidents->queueRecovery([
                'incident_id' => $incidentId,
                'tenant_key' => (string) ($run['tenant_key'] ?? $this->workspaceScope->currentTenantKey()),
                'domain_key' => 'cross_domain_orchestrator',
                'action_key' => (string) ($run['objective_key'] ?? 'cross_domain_objective'),
                'suggested_manual_action' => 'Review timed-out waiting orchestration and decide whether to resume, suppress, or cancel it.',
                'payload' => [
                    'orchestration_run_id' => $run['id'] ?? null,
                    'wait_state' => $waitState,
                ],
            ]);
        }
    }

    private function signalWorkspaceId(array $signal): int
    {
        $activeWorkspaceId = $this->workspaceScope->requireWorkspaceId();
        $related = (array) ($signal['related_entity_ids'] ?? []);

        foreach ([
            !empty($signal['tenant_key']) ? (string) $signal['tenant_key'] : null,
            !empty($related['contact_id']) ? 'contact:' . (int) $related['contact_id'] : null,
            !empty($related['deal_id']) ? 'deal:' . (int) $related['deal_id'] : null,
            !empty($related['task_id']) ? 'task:' . (int) $related['task_id'] : null,
            !empty($related['invoice_id']) ? 'invoice:' . (int) $related['invoice_id'] : null,
            !empty($related['workflow_id']) ? 'workflow:' . (int) $related['workflow_id'] : null,
        ] as $candidate) {
            if ($candidate !== null) {
                $resolved = $this->workspaceScope->resolveWorkspaceIdFromTenantKey($candidate);
                if ($resolved !== null && $resolved > 0) {
                    if ($resolved !== $activeWorkspaceId) {
                        throw new \RuntimeException('The requested orchestration signal does not belong to the active workspace.');
                    }
                    return $resolved;
                }
            }
        }

        return $activeWorkspaceId;
    }
}
