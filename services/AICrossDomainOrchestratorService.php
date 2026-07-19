<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Invoices;

class AICrossDomainOrchestratorService
{
    private const DOMAIN_KEY = 'cross_domain_orchestrator';
    private const MAX_STEPS = 4;

    private AIWorkspaceScopeService $workspaceScope;
    private AICrossDomainPlanStoreService $store;
    private AIAutonomyIncidentService $incidents;
    private AIAutonomyRolloutOperationsService $rollout;
    private AIDemonstrationCaptureService $capture;
    private ?DealFollowthroughAutonomyService $dealFollowthrough = null;
    private ?TaskFollowthroughAutonomyService $taskFollowthrough = null;
    private ?CommercialAutomationOrchestrator $commercial = null;
    private ?EmailAssistantApplicationService $assistantApplication = null;
    private EmailAssistantResolver $assistantResolver;
    private ?WorkflowExecutionService $workflowExecution = null;
    private Invoices $invoices;

    public function __construct()
    {
        $this->workspaceScope = new AIWorkspaceScopeService();
        $this->store = new AICrossDomainPlanStoreService($this->workspaceScope);
        $this->incidents = new AIAutonomyIncidentService();
        $this->rollout = new AIAutonomyRolloutOperationsService();
        $this->capture = new AIDemonstrationCaptureService();
        $this->assistantResolver = new EmailAssistantResolver();
        $this->invoices = new Invoices();
    }

    public function startObjective(array $request, int $userId): array
    {
        $objectiveKey = (string) ($request['objective_key'] ?? '');
        if (!in_array($objectiveKey, $this->supportedObjectives(), true)) {
            throw new \InvalidArgumentException('Unsupported objective.');
        }

        $context = $this->resolveObjectiveContext($request);
        $workspaceId = (int) ($context['workspace_id'] ?? $this->workspaceScope->requireWorkspaceId());
        $tenantKey = (string) ($context['tenant_key'] ?? $this->workspaceScope->workspaceTenantKey($workspaceId));
        $steps = $this->planSteps($objectiveKey, $context);
        $plan = [
            'objective_key' => $objectiveKey,
            'tenant_key' => $tenantKey,
            'workspace_id' => $workspaceId,
            'primary_entity_type' => (string) ($request['primary_entity_type'] ?? 'unknown'),
            'primary_entity_id' => (int) ($request['primary_entity_id'] ?? 0),
            'steps' => $steps,
        ];

        $runId = $this->store->createRun([
            'workspace_id' => $workspaceId,
            'tenant_key' => $tenantKey,
            'objective_key' => $objectiveKey,
            'primary_entity_type' => (string) ($request['primary_entity_type'] ?? 'unknown'),
            'primary_entity_id' => (int) ($request['primary_entity_id'] ?? 0),
            'related_entities' => [
                'deal_id' => $context['deal']['id'] ?? null,
                'invoice_id' => $context['invoice']['id'] ?? null,
                'contact_id' => $context['contact']['id'] ?? null,
                'task_id' => $context['task']['id'] ?? null,
                'communication_id' => $context['communication_id'] ?? null,
                'workflow_id' => $context['workflow_id'] ?? null,
            ],
            'execution_mode' => !empty($request['execute_now']) ? 'sequential' : 'plan_only',
            'run_status' => 'planned',
            'reason_note' => (string) ($request['reason_note'] ?? ''),
            'plan' => $plan,
            'summary' => ['blocked_candidates' => array_values(array_filter(array_map(fn(array $step): ?array => ($step['precheck_status'] ?? '') === 'blocked' ? $step : null, $steps)))],
            'created_by' => $userId,
            'origin_type' => (string) ($request['origin_type'] ?? 'operator'),
            'trigger_source_domain' => !empty($request['trigger_source_domain']) ? (string) $request['trigger_source_domain'] : null,
            'trigger_key' => !empty($request['trigger_key']) ? (string) $request['trigger_key'] : null,
            'trigger_entity_type' => !empty($request['trigger_entity_type']) ? (string) $request['trigger_entity_type'] : null,
            'trigger_entity_id' => !empty($request['trigger_entity_id']) ? (int) $request['trigger_entity_id'] : null,
            'trigger_metadata' => (array) ($request['trigger_metadata'] ?? []),
            'wait_state' => (array) ($request['wait_state'] ?? []),
            'suppression_reason' => !empty($request['suppression_reason']) ? (string) $request['suppression_reason'] : null,
        ]);

        $persistedSteps = [];
        $previousStepId = null;
        foreach ($steps as $index => $step) {
            $step['step_order'] = $index + 1;
            $step['depends_on_step_id'] = $previousStepId;
            $stepId = $this->store->addStep($runId, $step);
            $persistedSteps[] = $this->store->getStep($stepId);
            $previousStepId = $stepId;
        }

        if (!empty($request['execute_now'])) {
            return $this->executeRun($runId, $userId);
        }

        $run = $this->store->getRun($runId) ?? [];
        $run['steps'] = $persistedSteps;
        return $run;
    }

    public function prepareRun(int $runId): ?array
    {
        $run = $this->requireRun($runId);
        $context = $this->resolveObjectiveContext([
            'objective_key' => $run['objective_key'] ?? '',
            'primary_entity_type' => $run['primary_entity_type'] ?? '',
            'primary_entity_id' => $run['primary_entity_id'] ?? 0,
            'related_entity_ids' => (array) ($run['related_entities'] ?? []),
        ]);

        foreach ($this->store->listSteps($runId) as $step) {
            $precheck = $this->precheckStep($this->tenantKeyForRun($run), $step, $context);
            $this->store->updateStep((int) $step['id'], [
                'precheck_status' => (string) ($precheck['precheck_status'] ?? 'pending'),
                'assistant_confidence' => $precheck['assistant_confidence'] ?? null,
                'result' => ['precheck' => $precheck],
                'step_status' => in_array((string) ($precheck['step_status'] ?? ''), ['blocked', 'approval_required'], true)
                    ? (string) $precheck['step_status']
                    : 'planned',
            ]);
        }

        $this->store->updateRun($runId, ['summary' => $this->buildRunSummary($runId)]);
        return $this->hydrateRunWithSteps($runId);
    }

    public function executeRun(int $runId, int $userId): array
    {
        $run = $this->requireRun($runId);
        if (in_array((string) ($run['run_status'] ?? ''), ['canceled', 'suppressed'], true)) {
            return $this->hydrateRunWithSteps($runId);
        }

        $this->store->updateRun($runId, [
            'run_status' => 'running',
            'execution_mode' => 'sequential',
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        $steps = $this->store->listSteps($runId);
        $context = $this->resolveObjectiveContext([
            'objective_key' => $run['objective_key'] ?? '',
            'primary_entity_type' => $run['primary_entity_type'] ?? '',
            'primary_entity_id' => $run['primary_entity_id'] ?? 0,
            'related_entity_ids' => (array) ($run['related_entities'] ?? []),
        ]);
        $runStatus = 'completed';

        foreach ($steps as $step) {
            if (($this->store->getRun($runId)['run_status'] ?? '') === 'canceled') {
                $this->store->updateStep((int) $step['id'], ['step_status' => 'canceled']);
                $runStatus = 'canceled';
                break;
            }

            $precheck = $this->precheckStep($this->tenantKeyForRun($run), $step, $context);
            $stepStatus = (string) ($precheck['step_status'] ?? 'planned');
            $this->store->updateStep((int) $step['id'], [
                'precheck_status' => (string) ($precheck['precheck_status'] ?? 'ready'),
                'assistant_confidence' => $precheck['assistant_confidence'] ?? null,
                'result' => ['precheck' => $precheck],
                'step_status' => $stepStatus === 'planned' ? 'running' : $stepStatus,
            ]);

            if (in_array($stepStatus, ['blocked', 'approval_required'], true)) {
                $queued = $this->queueRecoveryForStep($run, $step, $precheck);
                $this->store->updateStep((int) $step['id'], [
                    'linked_incident_id' => $queued['incident_id'] ?? null,
                    'linked_recovery_queue_id' => $queued['recovery_queue_id'] ?? null,
                    'step_status' => $stepStatus,
                    'result' => ['precheck' => $precheck, 'recovery' => $queued],
                ]);
                $runStatus = $stepStatus;
                break;
            }

            $execution = $this->executeStep($run, $step, $context, $userId);
            $status = $this->normalizeExecutionStatus($execution);
            $patch = [
                'step_status' => $status,
                'linked_incident_id' => $execution['linked_incident_id'] ?? null,
                'linked_recovery_queue_id' => $execution['linked_recovery_queue_id'] ?? null,
                'linked_domain_run_id' => $execution['linked_domain_run_id'] ?? null,
                'result' => $execution,
            ];
            $this->store->updateStep((int) $step['id'], $patch);

            if ($status !== 'completed') {
                $runStatus = in_array($status, ['blocked', 'approval_required'], true) ? $status : 'failed';
                break;
            }
        }

        $this->store->updateRun($runId, [
            'run_status' => $runStatus,
            'completed_at' => in_array($runStatus, ['completed', 'blocked', 'approval_required', 'failed', 'canceled'], true)
                ? date('Y-m-d H:i:s')
                : null,
            'summary' => $this->buildRunSummary($runId),
        ]);

        return $this->hydrateRunWithSteps($runId);
    }

    public function cancelRun(int $runId, int $userId, string $reason = ''): array
    {
        $run = $this->requireRun($runId);
        $this->store->updateRun($runId, [
            'run_status' => 'canceled',
            'completed_at' => date('Y-m-d H:i:s'),
            'reason_note' => trim($reason) !== '' ? $reason : (string) ($run['reason_note'] ?? ''),
        ]);
        $this->captureOperatorRunAction($run, 'cancel_orchestration_run', $userId, $reason);
        return $this->hydrateRunWithSteps($runId);
    }

    public function setRunReviewOnly(int $runId, int $userId, string $reason = ''): array
    {
        $run = $this->requireRun($runId);
        $this->store->updateRun($runId, [
            'execution_mode' => 'plan_only',
            'reason_note' => trim($reason) !== '' ? $reason : (string) ($run['reason_note'] ?? ''),
        ]);
        $this->captureOperatorRunAction($run, 'review_only_orchestration_run', $userId, $reason);
        return $this->hydrateRunWithSteps($runId);
    }

    public function retryRun(int $runId, int $userId, string $reason = ''): array
    {
        $run = $this->requireRun($runId);
        foreach ($this->store->listSteps($runId) as $step) {
            if (in_array((string) ($step['step_status'] ?? ''), ['blocked', 'approval_required', 'failed'], true)) {
                $this->store->updateStep((int) $step['id'], [
                    'step_status' => 'planned',
                    'precheck_status' => 'pending',
                    'linked_incident_id' => null,
                    'linked_recovery_queue_id' => null,
                    'result' => [],
                ]);
            }
        }
        $this->store->updateRun($runId, [
            'run_status' => 'planned',
            'completed_at' => null,
            'reason_note' => trim($reason) !== '' ? $reason : (string) ($run['reason_note'] ?? ''),
        ]);
        $this->captureOperatorRunAction($run, 'retry_orchestration_run', $userId, $reason);
        return $this->executeRun($runId, $userId);
    }

    public function markRunWaiting(int $runId, array $waitState): array
    {
        $run = $this->requireRun($runId);
        $summary = (array) ($run['summary'] ?? []);
        $summary['waiting_reason'] = (string) ($waitState['waiting_reason'] ?? '');
        $summary['waiting_on_type'] = (string) ($waitState['waiting_on_type'] ?? '');
        $this->store->updateRun($runId, [
            'run_status' => 'waiting',
            'wait_state' => $waitState,
            'summary' => $summary,
        ]);
        return $this->hydrateRunWithSteps($runId);
    }

    public function markRunReadyToResume(int $runId, array $resumeMetadata = []): array
    {
        $run = $this->requireRun($runId);
        $summary = (array) ($run['summary'] ?? []);
        $summary['resume_source'] = (string) ($resumeMetadata['resume_source'] ?? 'external_event');
        $summary['resume_signal'] = (array) ($resumeMetadata['resume_signal'] ?? []);
        $waitState = (array) ($run['wait_state'] ?? []);
        $waitState['last_resume_source'] = $summary['resume_source'];
        $waitState['last_resume_signal'] = $summary['resume_signal'];
        $this->store->updateRun($runId, [
            'run_status' => 'ready_to_resume',
            'wait_state' => $waitState,
            'summary' => $summary,
            'last_resume_attempt_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->hydrateRunWithSteps($runId);
    }

    public function suppressRun(int $runId, int $userId, string $reason = ''): array
    {
        $run = $this->requireRun($runId);
        $this->store->updateRun($runId, [
            'run_status' => 'suppressed',
            'suppression_reason' => $reason !== '' ? $reason : 'suppressed_by_operator',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->captureOperatorRunAction($run, 'suppress_orchestration_run', $userId, $reason);
        return $this->hydrateRunWithSteps($runId);
    }

    public function getRun(int $runId): ?array
    {
        $run = $this->store->getRun($runId);
        if (!$run) {
            return null;
        }
        return $this->hydrateRunWithSteps($runId);
    }

    public function listRuns(?string $tenantKey = null, int $limit = 20): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $runs = $this->store->listRuns($this->workspaceScope->workspaceTenantKey($workspaceId), $limit);
        foreach ($runs as &$run) {
            $run['steps'] = $this->store->listSteps((int) $run['id']);
        }
        return $runs;
    }

    public function listAllRuns(int $limit = 20): array
    {
        $runs = $this->store->listAllRuns($limit);
        foreach ($runs as &$run) {
            $run['steps'] = $this->store->listSteps((int) $run['id']);
        }
        return $runs;
    }

    public function supportedObjectives(): array
    {
        return [
            'progress_deal_to_next_stage',
            'move_commercial_thread_to_sent_document',
            'recover_stalled_customer_thread',
            'close_task_followthrough_after_milestone',
        ];
    }

    private function resolveObjectiveContext(array $request): array
    {
        $primaryType = (string) ($request['primary_entity_type'] ?? '');
        $primaryId = (int) ($request['primary_entity_id'] ?? 0);
        $related = (array) ($request['related_entity_ids'] ?? []);
        $context = [
            'deal' => [],
            'invoice' => [],
            'contact' => [],
            'task' => [],
            'communication_id' => !empty($related['communication_id']) ? (int) $related['communication_id'] : 0,
            'workflow_id' => !empty($related['workflow_id']) ? (int) $related['workflow_id'] : 0,
        ];

        if ($primaryType === 'deal' && $primaryId > 0) {
            $context['deal'] = Database::queryOne("SELECT * FROM deals WHERE id = ?", [$primaryId]) ?: [];
        } elseif ($primaryType === 'task' && $primaryId > 0) {
            $context['task'] = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$primaryId]) ?: [];
        } elseif ($primaryType === 'communication' && $primaryId > 0) {
            $context['communication_id'] = $primaryId;
        }

        if ($context['deal'] !== [] && empty($related['contact_id']) && !empty($context['deal']['contact_id'])) {
            $related['contact_id'] = (int) $context['deal']['contact_id'];
        }
        if (!empty($related['deal_id']) && $context['deal'] === []) {
            $context['deal'] = Database::queryOne("SELECT * FROM deals WHERE id = ?", [(int) $related['deal_id']]) ?: [];
        }
        if (!empty($related['task_id']) && $context['task'] === []) {
            $context['task'] = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [(int) $related['task_id']]) ?: [];
        }
        if (!empty($related['contact_id'])) {
            $context['contact'] = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [(int) $related['contact_id']]) ?: [];
        } elseif (!empty($context['task']['contact_id'])) {
            $context['contact'] = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [(int) $context['task']['contact_id']]) ?: [];
        }
        if (!empty($related['invoice_id'])) {
            $context['invoice'] = $this->invoices->getById((int) $related['invoice_id']) ?: [];
        } elseif (!empty($context['deal']['id'])) {
            $context['invoice'] = $this->invoices->findLatestForDeal((int) $context['deal']['id']) ?: [];
        }
        if ($context['communication_id'] > 0) {
            $resolved = $this->assistantResolver->resolveFromCustomerThread((int) $context['communication_id']);
            $context['thread_context'] = (array) ($resolved['thread_context'] ?? []);
            $context['contact'] = $context['contact'] ?: (array) ($resolved['primary_entities']['contact'] ?? []);
            $context['deal'] = $context['deal'] ?: (array) ($resolved['primary_entities']['deal'] ?? []);
            $context['invoice'] = $context['invoice'] ?: (array) ($resolved['primary_entities']['invoice'] ?? []);
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $candidateWorkspaceId = $this->inferWorkspaceIdFromContext($request, $context, $related);
        if ($candidateWorkspaceId !== null && $candidateWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The requested orchestration context does not belong to the active workspace.');
        }
        $tenantWorkspaceId = $this->workspaceScope->resolveWorkspaceIdFromTenantKey((string) ($request['tenant_key'] ?? ''));
        if ($tenantWorkspaceId !== null && $tenantWorkspaceId > 0 && $tenantWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('The requested orchestration context does not belong to the active workspace.');
        }
        $context['workspace_id'] = $workspaceId;
        $context['tenant_key'] = $this->workspaceScope->workspaceTenantKey($workspaceId);

        return $context;
    }

    private function planSteps(string $objectiveKey, array $context): array
    {
        $steps = match ($objectiveKey) {
            'progress_deal_to_next_stage' => $this->planProgressDeal($context),
            'move_commercial_thread_to_sent_document' => $this->planCommercialThread($context),
            'recover_stalled_customer_thread' => $this->planRecoverThread($context),
            'close_task_followthrough_after_milestone' => $this->planTaskFollowthrough($context),
            default => [],
        };

        return array_slice($steps, 0, self::MAX_STEPS);
    }

    private function inferWorkspaceIdFromContext(array $request, array $context, array $related): ?int
    {
        foreach ([
            (int) ($context['contact']['workspace_id'] ?? 0),
            (int) ($context['deal']['workspace_id'] ?? 0),
            (int) ($context['task']['workspace_id'] ?? 0),
            (int) ($context['invoice']['workspace_id'] ?? 0),
            !empty($request['workspace_id']) ? (int) $request['workspace_id'] : 0,
        ] as $workspaceId) {
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        foreach ([
            ['contact', !empty($related['contact_id']) ? (int) $related['contact_id'] : 0],
            ['deal', !empty($related['deal_id']) ? (int) $related['deal_id'] : 0],
            ['task', !empty($related['task_id']) ? (int) $related['task_id'] : 0],
            ['invoice', !empty($related['invoice_id']) ? (int) $related['invoice_id'] : 0],
            ['workflow', !empty($related['workflow_id']) ? (int) $related['workflow_id'] : 0],
            [
                !empty($request['primary_entity_type']) ? (string) $request['primary_entity_type'] : '',
                !empty($request['primary_entity_id']) ? (int) $request['primary_entity_id'] : 0,
            ],
        ] as [$entityType, $entityId]) {
            $workspaceId = $this->resolveEntityWorkspaceId((string) $entityType, (int) $entityId);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        return null;
    }

    private function resolveEntityWorkspaceId(string $entityType, int $entityId): int
    {
        if ($entityId <= 0) {
            return 0;
        }

        return match ($entityType) {
            'contact' => (int) ((Database::queryOne("SELECT workspace_id FROM contacts WHERE id = ?", [$entityId]) ?: [])['workspace_id'] ?? 0),
            'deal' => (int) ((Database::queryOne("SELECT workspace_id FROM deals WHERE id = ?", [$entityId]) ?: [])['workspace_id'] ?? 0),
            'task' => (int) ((Database::queryOne("SELECT workspace_id FROM tasks WHERE id = ?", [$entityId]) ?: [])['workspace_id'] ?? 0),
            'invoice' => (int) (($this->invoices->getById($entityId) ?: [])['workspace_id'] ?? 0),
            'workflow' => (int) ((Database::queryOne("SELECT workspace_id FROM workflows WHERE id = ?", [$entityId]) ?: [])['workspace_id'] ?? 0),
            default => 0,
        };
    }

    private function planProgressDeal(array $context): array
    {
        $steps = [];
        if (!empty($context['deal']['id'])) {
            $steps[] = $this->makeStep('deal_followthrough', 'progress_stage', 'deal', (int) $context['deal']['id'], false, [
                'trigger' => 'cross_domain',
            ]);
            if (!empty($context['invoice']['id']) || in_array((string) ($context['deal']['stage'] ?? ''), ['proposal', 'negotiation', 'closed_won'], true)) {
                $steps[] = $this->makeStep('commercial_mvp', 'send_document', 'deal', (int) $context['deal']['id'], true, [
                    'communication_id' => $context['communication_id'] ?? null,
                ]);
            }
        } else {
            $steps[] = $this->makeBlockedStep('deal_followthrough', 'progress_stage', 'deal', 0, false, ['missing_deal_context']);
        }

        return $steps;
    }

    private function planCommercialThread(array $context): array
    {
        $steps = [];
        if (!empty($context['contact']['id']) && !empty($context['communication_id'])) {
            $steps[] = $this->makeStep('commercial_mvp', 'send_document', 'contact', (int) $context['contact']['id'], true, [
                'communication_id' => (int) $context['communication_id'],
            ]);
            $steps[] = $this->makeStep('customer_thread', 'send_customer_reply', 'communication', (int) $context['communication_id'], true, [
                'goal' => 'send',
            ]);
        } else {
            $steps[] = $this->makeBlockedStep('customer_thread', 'send_customer_reply', 'communication', (int) ($context['communication_id'] ?? 0), true, ['missing_thread_context']);
        }

        return $steps;
    }

    private function planRecoverThread(array $context): array
    {
        $steps = [];
        if (!empty($context['communication_id'])) {
            $steps[] = $this->makeStep('customer_thread', 'send_customer_reply', 'communication', (int) $context['communication_id'], true, [
                'goal' => 'send',
            ]);
            if (!empty($context['deal']['id'])) {
                $steps[] = $this->makeStep('deal_followthrough', 'assign_followup_task', 'deal', (int) $context['deal']['id'], false, [
                    'trigger' => 'cross_domain',
                ]);
            }
        } else {
            $steps[] = $this->makeBlockedStep('customer_thread', 'send_customer_reply', 'communication', 0, true, ['missing_thread_context']);
        }
        return $steps;
    }

    private function planTaskFollowthrough(array $context): array
    {
        $steps = [];
        if (!empty($context['task']['id'])) {
            $steps[] = $this->makeStep('task_followthrough', 'mark_complete', 'task', (int) $context['task']['id'], false, [
                'trigger' => 'cross_domain',
            ]);
            if (!empty($context['deal']['id'])) {
                $steps[] = $this->makeStep('commercial_mvp', 'send_document', 'deal', (int) $context['deal']['id'], true, []);
            }
        } else {
            $steps[] = $this->makeBlockedStep('task_followthrough', 'mark_complete', 'task', 0, false, ['missing_task_context']);
        }
        return $steps;
    }

    private function makeStep(string $domainKey, string $actionKey, string $targetType, int $targetId, bool $customerFacing, array $planContext): array
    {
        return [
            'domain_key' => $domainKey,
            'action_key' => $actionKey,
            'target_entity_type' => $targetType,
            'target_entity_id' => $targetId,
            'customer_facing' => $customerFacing,
            'assistant_confidence' => 0.85,
            'precheck_status' => 'pending',
            'step_status' => 'planned',
            'plan_context' => $planContext,
        ];
    }

    private function makeBlockedStep(string $domainKey, string $actionKey, string $targetType, int $targetId, bool $customerFacing, array $reasons): array
    {
        return [
            'domain_key' => $domainKey,
            'action_key' => $actionKey,
            'target_entity_type' => $targetType,
            'target_entity_id' => $targetId,
            'customer_facing' => $customerFacing,
            'assistant_confidence' => 0.0,
            'precheck_status' => 'blocked',
            'step_status' => 'blocked',
            'plan_context' => ['reasons' => $reasons],
        ];
    }

    private function precheckStep(string $tenantKey, array $step, array $context): array
    {
        if (($step['precheck_status'] ?? '') === 'blocked') {
            return [
                'precheck_status' => 'blocked',
                'step_status' => 'blocked',
                'reasons' => (array) (($step['plan_context']['reasons'] ?? ['planning_blocked'])),
                'assistant_confidence' => 0.0,
            ];
        }

        $readiness = $this->rollout->computeReadiness($tenantKey, (string) ($step['domain_key'] ?? ''));
        if (in_array((string) ($readiness['rollout_state'] ?? ''), ['paused', 'manually_frozen', 'degraded'], true) && ($step['customer_facing'] ?? false)) {
            return [
                'precheck_status' => 'approval_required',
                'step_status' => 'approval_required',
                'reasons' => (array) ($readiness['reasons'] ?? ['domain_not_ready']),
                'assistant_confidence' => (float) ($step['assistant_confidence'] ?? 0.0),
            ];
        }
        if ((int) ($step['target_entity_id'] ?? 0) <= 0) {
            return [
                'precheck_status' => 'blocked',
                'step_status' => 'blocked',
                'reasons' => ['missing_target_entity'],
                'assistant_confidence' => 0.0,
            ];
        }

        return [
            'precheck_status' => 'ready',
            'step_status' => 'planned',
            'reasons' => [],
            'assistant_confidence' => (float) ($step['assistant_confidence'] ?? 0.85),
            'readiness' => $readiness,
        ];
    }

    private function executeStep(array $run, array $step, array $context, int $userId): array
    {
        return match ((string) ($step['domain_key'] ?? '')) {
            'deal_followthrough' => $this->executeDealStep($run, $step, $context, $userId),
            'task_followthrough' => $this->executeTaskStep($run, $step, $context, $userId),
            'commercial_mvp' => $this->executeCommercialStep($run, $step, $context, $userId),
            'customer_thread' => $this->executeCustomerThreadStep($run, $step, $context, $userId),
            'workflow_execution' => $this->executeWorkflowStep($run, $step, $context, $userId),
            default => ['status' => 'failed', 'reason' => 'unsupported_domain'],
        };
    }

    private function executeDealStep(array $run, array $step, array $context, int $userId): array
    {
        $result = $this->dealFollowthrough()->runForDeal((int) ($context['deal']['id'] ?? 0), 'cross_domain', $userId);
        return [
            'status' => $this->mapDomainResultStatus($result),
            'result' => $result,
            'orchestration' => ['run_id' => (int) $run['id'], 'step_id' => (int) $step['id']],
        ];
    }

    private function executeTaskStep(array $run, array $step, array $context, int $userId): array
    {
        $result = $this->taskFollowthrough()->runForTask((int) ($context['task']['id'] ?? 0), 'cross_domain', $userId);
        return [
            'status' => $this->mapDomainResultStatus($result),
            'result' => $result,
            'orchestration' => ['run_id' => (int) $run['id'], 'step_id' => (int) $step['id']],
        ];
    }

    private function executeCommercialStep(array $run, array $step, array $context, int $userId): array
    {
        $result = !empty($context['communication_id']) && !empty($context['contact']['id'])
            ? $this->commercial()->runForCommunication((int) $context['contact']['id'], (int) $context['communication_id'])
            : $this->commercial()->runForDeal((int) ($context['deal']['id'] ?? 0), 'cross_domain', null, [
                'orchestration_run_id' => (int) $run['id'],
                'orchestration_step_id' => (int) $step['id'],
            ]);
        $latestRun = Database::queryOne("SELECT id FROM commercial_automation_runs ORDER BY id DESC LIMIT 1");

        return [
            'status' => $this->mapCommercialStatus($result),
            'result' => $result,
            'linked_domain_run_id' => !empty($latestRun['id']) ? (int) $latestRun['id'] : null,
            'orchestration' => ['run_id' => (int) $run['id'], 'step_id' => (int) $step['id']],
        ];
    }

    private function executeCustomerThreadStep(array $run, array $step, array $context, int $userId): array
    {
        $communicationId = (int) ($context['communication_id'] ?? 0);
        $result = $this->assistantApplication()->handleCustomerThreadSend($communicationId, $userId, ['goal' => 'send']);
        return [
            'status' => $this->mapAssistantStatus($result),
            'result' => $result,
            'linked_domain_run_id' => !empty($result['run_id']) ? (int) $result['run_id'] : null,
            'orchestration' => ['run_id' => (int) $run['id'], 'step_id' => (int) $step['id']],
        ];
    }

    private function executeWorkflowStep(array $run, array $step, array $context, int $userId): array
    {
        try {
            $this->workflowExecution()->executeWorkflow((int) ($context['workflow_id'] ?? 0), [
                'contact_id' => (int) ($context['contact']['id'] ?? 0),
                'deal_id' => (int) ($context['deal']['id'] ?? 0),
                'task_id' => (int) ($context['task']['id'] ?? 0),
                'orchestration_run_id' => (int) $run['id'],
                'orchestration_step_id' => (int) $step['id'],
            ]);
            $latest = Database::queryOne("SELECT id FROM workflow_executions ORDER BY id DESC LIMIT 1");
            return [
                'status' => 'completed',
                'linked_domain_run_id' => !empty($latest['id']) ? (int) $latest['id'] : null,
                'result' => ['workflow_id' => (int) ($context['workflow_id'] ?? 0)],
            ];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    private function queueRecoveryForStep(array $run, array $step, array $precheck): array
    {
        $incidentId = $this->incidents->recordIncident([
            'tenant_key' => $this->tenantKeyForRun($run),
            'domain_key' => self::DOMAIN_KEY,
            'action_key' => (string) ($step['action_key'] ?? 'orchestration_step'),
            'incident_key' => 'cross_domain_plan_stalled',
            'severity' => (($precheck['step_status'] ?? '') === 'blocked') ? 'high' : 'medium',
            'reason_codes' => (array) ($precheck['reasons'] ?? ['orchestration_blocked']),
            'details' => [
                'objective_key' => $run['objective_key'] ?? null,
                'orchestration_run_id' => $run['id'] ?? null,
                'orchestration_step_id' => $step['id'] ?? null,
                'step' => $step,
                'precheck' => $precheck,
            ],
            'linked_entity_type' => 'orchestration_run',
            'linked_entity_id' => !empty($run['id']) ? (int) $run['id'] : null,
        ]);
        $recoveryId = $incidentId ? $this->incidents->queueRecovery([
            'incident_id' => $incidentId,
            'tenant_key' => $this->tenantKeyForRun($run),
            'domain_key' => self::DOMAIN_KEY,
            'action_key' => (string) ($step['action_key'] ?? 'orchestration_step'),
            'suggested_manual_action' => 'Review cross-domain orchestration blocker and decide whether to retry, cancel, or downgrade execution.',
            'payload' => [
                'orchestration_run_id' => $run['id'] ?? null,
                'orchestration_step_id' => $step['id'] ?? null,
                'objective_key' => $run['objective_key'] ?? null,
            ],
        ]) : null;

        return ['incident_id' => $incidentId, 'recovery_queue_id' => $recoveryId];
    }

    private function buildRunSummary(int $runId): array
    {
        $steps = $this->store->listSteps($runId);
        $completed = count(array_filter($steps, static fn(array $step): bool => ($step['step_status'] ?? '') === 'completed'));
        $blocked = count(array_filter($steps, static fn(array $step): bool => in_array((string) ($step['step_status'] ?? ''), ['blocked', 'approval_required'], true)));
        $waiting = count(array_filter($steps, static fn(array $step): bool => ($step['step_status'] ?? '') === 'skipped'));
        return [
            'step_count' => count($steps),
            'completed_steps' => $completed,
            'blocked_steps' => $blocked,
            'waiting_steps' => $waiting,
            'completion_rate' => count($steps) > 0 ? round($completed / count($steps), 4) : 0.0,
        ];
    }

    private function captureOperatorRunAction(array $run, string $actionKey, int $userId, string $reason): void
    {
        $resultState = $this->store->getRun((int) ($run['id'] ?? 0)) ?? [];
        $this->incidents->logOperatorAction([
            'tenant_key' => $this->tenantKeyForRun($run),
            'domain_key' => self::DOMAIN_KEY,
            'operator_user_id' => $userId,
            'action_key' => $actionKey,
            'target_type' => 'orchestration_run',
            'target_id' => !empty($run['id']) ? (int) $run['id'] : null,
            'reason' => $reason,
            'prior_state' => $run,
            'result_state' => $resultState,
            'metadata' => ['objective_key' => $run['objective_key'] ?? null],
        ]);

        $this->capture->capture([
            'tenant_key' => $this->tenantKeyForRun($run),
            'actor_user_id' => $userId,
            'actor_type' => 'user',
            'source_surface' => 'cross_domain_orchestrator',
            'domain_key' => self::DOMAIN_KEY,
            'entity_type' => 'orchestration_run',
            'entity_id' => (int) ($run['id'] ?? 0),
            'action_key' => $actionKey,
            'prior_state' => $run,
            'action_payload' => ['reason' => $reason],
            'outcome_state' => $resultState,
            'outcome_label' => 'operator_override',
            'free_text_reason' => $reason,
            'metadata' => ['objective_key' => $run['objective_key'] ?? null],
            'was_successful' => true,
        ]);
    }

    private function hydrateRunWithSteps(int $runId): array
    {
        $run = $this->requireRun($runId);
        $run['steps'] = $this->store->listSteps($runId);
        return $run;
    }

    private function requireRun(int $runId): array
    {
        $run = $this->store->getRun($runId);
        if (!$run) {
            throw new \InvalidArgumentException('Orchestration run not found.');
        }
        return $run;
    }

    private function tenantKeyForRun(array $run): string
    {
        $workspaceId = (int) ($run['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = $this->workspaceScope->requireWorkspaceId();
        }

        return $this->workspaceScope->workspaceTenantKey($workspaceId);
    }

    private function normalizeExecutionStatus(array $execution): string
    {
        return match ((string) ($execution['status'] ?? 'failed')) {
            'completed', 'executed', 'sent', 'auto_apply' => 'completed',
            'approval_required' => 'approval_required',
            'blocked', 'suggest_only' => 'blocked',
            default => 'failed',
        };
    }

    private function mapDomainResultStatus(?array $result): string
    {
        if (!$result) {
            return 'failed';
        }
        return match ((string) ($result['decision'] ?? 'failed')) {
            'auto_apply' => 'completed',
            'approval_required' => 'approval_required',
            'suggest_only', 'reject' => 'blocked',
            default => 'failed',
        };
    }

    private function mapCommercialStatus(?array $result): string
    {
        if (!$result) {
            return 'failed';
        }
        return match ((string) ($result['decision'] ?? 'reject')) {
            'auto_apply' => 'completed',
            'approval_required' => 'approval_required',
            'suggest_only', 'reject' => 'blocked',
            default => 'failed',
        };
    }

    private function mapAssistantStatus(array $result): string
    {
        $policy = (array) ($result['policy'] ?? []);
        $decision = (string) ($policy['decision'] ?? '');
        if (in_array($decision, ['blocked', 'suggest_only'], true) || ($result['execution_status'] ?? '') === 'rejected') {
            return 'blocked';
        }
        if ($decision === 'approval_required' || ($result['resolution_status'] ?? '') === 'approval_required') {
            return 'approval_required';
        }
        return 'completed';
    }

    private function dealFollowthrough(): DealFollowthroughAutonomyService
    {
        return $this->dealFollowthrough ??= new DealFollowthroughAutonomyService();
    }

    private function taskFollowthrough(): TaskFollowthroughAutonomyService
    {
        return $this->taskFollowthrough ??= new TaskFollowthroughAutonomyService();
    }

    private function commercial(): CommercialAutomationOrchestrator
    {
        return $this->commercial ??= new CommercialAutomationOrchestrator();
    }

    private function assistantApplication(): EmailAssistantApplicationService
    {
        return $this->assistantApplication ??= new EmailAssistantApplicationService();
    }

    private function workflowExecution(): WorkflowExecutionService
    {
        return $this->workflowExecution ??= new WorkflowExecutionService();
    }
}
