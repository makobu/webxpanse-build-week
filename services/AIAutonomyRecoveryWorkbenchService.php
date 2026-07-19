<?php

namespace CRM\Services;

class AIAutonomyRecoveryWorkbenchService
{
    private const DOMAINS = ['commercial_mvp', 'deal_followthrough', 'task_followthrough', 'customer_thread', 'workflow_execution', 'cross_domain_orchestrator'];
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyIncidentService $incidents = null,
        private ?AIAutonomyRolloutOperationsService $rollout = null,
        private ?AIDemonstrationCaptureService $capture = null,
        private ?AICrossDomainOrchestratorService $orchestrator = null
    ) {
        $this->incidents = $this->incidents ?? new AIAutonomyIncidentService();
        $this->rollout = $this->rollout ?? new AIAutonomyRolloutOperationsService();
        $this->capture = $this->capture ?? new AIDemonstrationCaptureService();
        $this->orchestrator = $this->orchestrator ?? new AICrossDomainOrchestratorService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function getDashboardData(?string $tenantKey = null): array
    {
        $tenantKey = $this->workspaceScope->currentTenantKey($this->workspaceScope->requireWorkspaceId($tenantKey));
        $domains = [];
        $openIncidents = 0;
        $recoveryBacklog = 0;
        foreach (self::DOMAINS as $domainKey) {
            $readiness = $this->rollout->computeReadiness($tenantKey, $domainKey);
            $domains[$domainKey] = [
                'readiness' => $readiness,
                'incidents' => $this->incidents->listIncidents($tenantKey, $domainKey, 8),
                'recovery_queue' => $this->incidents->listRecoveryQueue($tenantKey, $domainKey, 8),
                'operator_actions' => $this->incidents->listOperatorActions($tenantKey, $domainKey, 5),
            ];
            $openIncidents += (int) ($readiness['operational']['open_incident_count'] ?? 0);
            $recoveryBacklog += (int) ($readiness['operational']['recovery_backlog_count'] ?? 0);
        }

        return [
            'tenant_key' => $tenantKey,
            'domain_summaries' => $domains,
            'open_incident_count' => $openIncidents,
            'recovery_backlog_count' => $recoveryBacklog,
            'orchestration_runs' => $this->orchestrator->listRuns($tenantKey, 10),
        ];
    }

    public function handleRecoveryAction(string $actionKey, array $payload, int $operatorUserId): array
    {
        $recoveryId = (int) ($payload['recovery_queue_id'] ?? 0);
        $incidentId = (int) ($payload['incident_id'] ?? 0);
        $reason = trim((string) ($payload['reason'] ?? ''));

        return match ($actionKey) {
            'assign_recovery_item' => $this->assignRecoveryItem($recoveryId, (int) ($payload['assigned_to'] ?? 0), $operatorUserId, $reason),
            'start_recovery_item' => $this->transitionRecoveryItem($recoveryId, 'in_progress', $operatorUserId, $reason),
            'resolve_recovery_item' => $this->transitionRecoveryItem($recoveryId, 'resolved', $operatorUserId, $reason),
            'suppress_recovery_item' => $this->transitionRecoveryItem($recoveryId, 'suppressed', $operatorUserId, $reason),
            'mark_incident_resolved' => $this->updateIncident($incidentId, 'resolved', $operatorUserId, $reason, 'mark_incident_resolved'),
            'suppress_incident' => $this->updateIncident($incidentId, 'suppressed', $operatorUserId, $reason, 'suppress_incident'),
            'retry_autonomous_action' => $this->retryRecoveryItem($recoveryId, $operatorUserId, $reason),
            'rerun_governed_action' => $this->rerunRecoveryItem($recoveryId, $operatorUserId, $reason),
            'cancel_orchestration_run' => $this->orchestrator->cancelRun((int) ($payload['orchestration_run_id'] ?? 0), $operatorUserId, $reason),
            'retry_orchestration_run' => $this->orchestrator->retryRun((int) ($payload['orchestration_run_id'] ?? 0), $operatorUserId, $reason),
            'review_only_orchestration_run' => $this->orchestrator->setRunReviewOnly((int) ($payload['orchestration_run_id'] ?? 0), $operatorUserId, $reason),
            'resume_orchestration_run' => (new AICrossDomainResumeService())->resumeRun((int) ($payload['orchestration_run_id'] ?? 0), $operatorUserId),
            'suppress_orchestration_run' => $this->orchestrator->suppressRun((int) ($payload['orchestration_run_id'] ?? 0), $operatorUserId, $reason),
            default => throw new \InvalidArgumentException('Unsupported recovery action.'),
        };
    }

    private function assignRecoveryItem(int $recoveryId, int $assignedTo, int $operatorUserId, string $reason): array
    {
        if ($recoveryId <= 0 || $assignedTo <= 0) {
            throw new \InvalidArgumentException('Recovery item and assignee are required.');
        }
        $before = $this->requireRecoveryItem($recoveryId);
        $after = $this->incidents->assignRecoveryItem($recoveryId, $assignedTo, $operatorUserId, $reason);
        return $this->recordOverride('assign_recovery_item', $before, $after, $operatorUserId, $reason);
    }

    private function transitionRecoveryItem(int $recoveryId, string $status, int $operatorUserId, string $reason): array
    {
        if ($recoveryId <= 0) {
            throw new \InvalidArgumentException('Recovery item is required.');
        }
        $before = $this->requireRecoveryItem($recoveryId);
        $after = $this->incidents->updateRecoveryStatus($recoveryId, $status, $operatorUserId, $reason);
        if ($status === 'resolved' && !empty($before['incident_id'])) {
            $this->incidents->updateIncidentStatus((int) $before['incident_id'], 'resolved', $operatorUserId, $reason, ['last_operator_action' => 'recovery_resolved']);
        }
        return $this->recordOverride('recovery_status_' . $status, $before, $after, $operatorUserId, $reason);
    }

    private function updateIncident(int $incidentId, string $status, int $operatorUserId, string $reason, string $actionKey): array
    {
        if ($incidentId <= 0) {
            throw new \InvalidArgumentException('Incident is required.');
        }
        $before = $this->requireIncident($incidentId);
        $after = $this->incidents->updateIncidentStatus($incidentId, $status, $operatorUserId, $reason, ['last_operator_action' => $actionKey]);
        return $this->recordOverride($actionKey, $before, $after, $operatorUserId, $reason, 'incident');
    }

    private function retryRecoveryItem(int $recoveryId, int $operatorUserId, string $reason): array
    {
        $before = $this->requireRecoveryItem($recoveryId);
        $payload = (array) ($before['payload'] ?? []);
        $domainKey = (string) ($before['domain_key'] ?? 'commercial_mvp');
        $result = ['supported' => false, 'message' => 'No safe retry path is available for this recovery item.'];

        if ($domainKey === 'workflow_execution' && !empty($payload['workflow_execution_id']) && !empty($payload['workflow_node_id'])) {
            $retryService = new WorkflowRetryService();
            $retryRow = $retryService->findPendingRetryForNode(
                (int) $payload['workflow_execution_id'],
                (string) $payload['workflow_node_id']
            );
            if ($retryRow) {
                (new WorkflowScheduler())->processRetryItem((int) $retryRow['id']);
                $result = ['supported' => true, 'message' => 'Workflow retry executed.', 'retry_id' => (int) $retryRow['id']];
                $this->incidents->updateRecoveryStatus($recoveryId, 'resolved', $operatorUserId, $reason);
                if (!empty($before['incident_id'])) {
                    $this->incidents->updateIncidentStatus((int) $before['incident_id'], 'resolved', $operatorUserId, $reason, ['last_operator_action' => 'retry_autonomous_action']);
                }
            } else {
                $this->incidents->updateRecoveryStatus($recoveryId, 'failed_retry', $operatorUserId, $reason, ['last_error' => 'No pending workflow retry matched the recovery item.']);
                $result['message'] = 'No pending workflow retry matched the recovery item.';
            }
        } else {
            $this->incidents->updateRecoveryStatus($recoveryId, 'failed_retry', $operatorUserId, $reason, ['last_error' => $result['message']]);
        }

        $after = $this->requireRecoveryItem($recoveryId);
        return $this->recordOverride('retry_autonomous_action', $before, array_merge($after, ['retry_result' => $result]), $operatorUserId, $reason);
    }

    private function rerunRecoveryItem(int $recoveryId, int $operatorUserId, string $reason): array
    {
        $before = $this->requireRecoveryItem($recoveryId);
        $payload = (array) ($before['payload'] ?? []);
        $domainKey = (string) ($before['domain_key'] ?? 'commercial_mvp');
        $result = ['supported' => false, 'message' => 'No safe rerun path is available for this recovery item.'];

        if ($domainKey === 'workflow_execution' && !empty($payload['workflow_id']) && !empty($payload['workflow_execution_id'])) {
            $retryService = new WorkflowRetryService();
            $retryRow = $retryService->findLatestRetryForExecution((int) $payload['workflow_execution_id']);
            if ($retryRow) {
                (new WorkflowScheduler())->processRetryItem((int) $retryRow['id']);
                $result = ['supported' => true, 'message' => 'Workflow governed action rerun via retry path.', 'retry_id' => (int) $retryRow['id']];
                $this->incidents->updateRecoveryStatus($recoveryId, 'resolved', $operatorUserId, $reason);
            } else {
                $result['message'] = 'No governed rerun context is currently available for this workflow action.';
                $this->incidents->updateRecoveryStatus($recoveryId, 'failed_retry', $operatorUserId, $reason, ['last_error' => $result['message']]);
            }
        } else {
            $this->incidents->updateRecoveryStatus($recoveryId, 'failed_retry', $operatorUserId, $reason, ['last_error' => $result['message']]);
        }

        $after = $this->requireRecoveryItem($recoveryId);
        return $this->recordOverride('rerun_governed_action', $before, array_merge($after, ['rerun_result' => $result]), $operatorUserId, $reason);
    }

    private function recordOverride(string $actionKey, array $before, array $after, int $operatorUserId, string $reason, string $targetType = 'recovery_queue'): array
    {
        $workspaceId = max(
            1,
            (int) ($after['workspace_id'] ?? $before['workspace_id'] ?? $this->workspaceScope->requireWorkspaceId())
        );
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);
        $domainKey = (string) ($after['domain_key'] ?? $before['domain_key'] ?? 'commercial_mvp');
        $targetId = (int) ($after['id'] ?? $before['id'] ?? 0);
        $incidentId = (int) ($after['incident_id'] ?? $before['incident_id'] ?? 0);
        $recoveryId = $targetType === 'recovery_queue' ? $targetId : (int) ($after['recovery_queue_id'] ?? 0);

        $this->incidents->logOperatorAction([
            'tenant_key' => $tenantKey,
            'domain_key' => $domainKey,
            'operator_user_id' => $operatorUserId,
            'action_key' => $actionKey,
            'incident_id' => $incidentId > 0 ? $incidentId : null,
            'recovery_queue_id' => $recoveryId > 0 ? $recoveryId : null,
            'target_type' => $targetType,
            'target_id' => $targetId > 0 ? $targetId : null,
            'reason' => $reason,
            'prior_state' => $before,
            'result_state' => $after,
            'metadata' => ['workbench_action' => true],
        ]);

        $this->capture->capture([
            'tenant_key' => $tenantKey,
            'actor_user_id' => $operatorUserId,
            'actor_type' => 'user',
            'source_surface' => 'recovery_workbench',
            'domain_key' => $domainKey,
            'entity_type' => $targetType,
            'entity_id' => $targetId > 0 ? $targetId : null,
            'action_key' => $actionKey,
            'prior_state' => $before,
            'action_payload' => ['reason' => $reason],
            'outcome_state' => $after,
            'outcome_label' => 'operator_override',
            'free_text_reason' => $reason,
            'metadata' => ['workbench_action' => true],
            'was_successful' => true,
        ]);

        return $after;
    }

    private function requireRecoveryItem(int $recoveryId): array
    {
        $item = $this->incidents->getRecoveryItem($recoveryId);
        if (!$item) {
            throw new \InvalidArgumentException('Recovery item not found.');
        }
        return $item;
    }

    private function requireIncident(int $incidentId): array
    {
        $incident = $this->incidents->getIncident($incidentId);
        if (!$incident) {
            throw new \InvalidArgumentException('Incident not found.');
        }
        return $incident;
    }
}
