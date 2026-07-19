<?php

namespace CRM\Services;

use CRM\Database;

class WorkflowAutonomyExecutionService
{
    private const DOMAIN_KEY = 'workflow_execution';

    private const ACTION_THRESHOLDS = [
        'send_email' => 0.9,
        'send_whatsapp' => 0.88,
        'send_sms' => 0.87,
        'create_task' => 0.84,
        'assign_to_user' => 0.82,
        'update_contact_field' => 0.86,
        'change_stage' => 0.83,
        'update_deal_stage' => 0.85,
        'add_tag' => 0.8,
        'remove_tag' => 0.8,
        'create_activity' => 0.78,
        'add_note' => 0.78,
        'update_lead_score' => 0.82,
        'create_deal' => 0.84,
        'add_to_deal' => 0.84,
        'send_in_app_notification' => 0.76,
        'call_webhook' => 0.8,
        'apply_smart_tags' => 0.76,
        'remove_from_workflow' => 0.7,
    ];

    private AIAutonomyScoringService $scoring;
    private AIAutonomyGovernanceService $governance;
    private AIAutonomyDomainControlService $controls;
    private AIAutonomyIncidentService $incidents;
    private AIDemonstrationCaptureService $capture;
    private AIRuntimeControlService $runtimeControls;
    private AIWorkspaceScopeService $workspaceScope;
    private WorkflowCapabilityRegistryService $workflowCapabilities;

    public function __construct()
    {
        $this->scoring = new AIAutonomyScoringService();
        $this->governance = new AIAutonomyGovernanceService();
        $this->controls = new AIAutonomyDomainControlService();
        $this->incidents = new AIAutonomyIncidentService();
        $this->capture = new AIDemonstrationCaptureService();
        $this->runtimeControls = new AIRuntimeControlService();
        $this->workspaceScope = new AIWorkspaceScopeService();
        $this->workflowCapabilities = new WorkflowCapabilityRegistryService();
    }

    public function shouldBypassGovernance(string $actionType): bool
    {
        return $actionType === 'wait_for_days';
    }

    public function executeAction(array $workflow, array $action, array $context, callable $executor, ?int $actorUserId = null): array
    {
        $actionType = (string) ($action['type'] ?? '');
        if ($actionType === '') {
            throw new \InvalidArgumentException('Workflow action type is required.');
        }

        $tenantKey = $this->resolveTenantKey($context);
        $domainControl = $this->controls->get($tenantKey, self::DOMAIN_KEY);
        $normalizedContext = $this->buildContext($workflow, $action, $context, $tenantKey, $domainControl);
        $runtimeDecision = $this->evaluateRuntimeControl($normalizedContext);
        if ($runtimeDecision !== null) {
            $this->captureDecision($tenantKey, $actionType, $normalizedContext, $runtimeDecision, $actorUserId);
            return $runtimeDecision;
        }

        $score = $this->scoring->score($tenantKey, self::DOMAIN_KEY, $actionType, array_merge($normalizedContext, [
            'heuristic_confidence' => $this->heuristicConfidence($actionType, $normalizedContext),
        ]));
        $normalizedContext['assistant_confidence'] = (float) ($score['assistant_confidence'] ?? 0.0);
        $normalizedContext['confidence_basis'] = (array) ($score['confidence_basis'] ?? []);
        $normalizedContext['similar_examples'] = (array) ($score['similar_examples'] ?? []);
        $normalizedContext['learned_signals'] = (array) ($score['tenant_policy'] ?? []);

        $policy = $this->evaluateActionPolicy($actionType, $normalizedContext);
        $governance = $this->governance->evaluate($tenantKey, self::DOMAIN_KEY, $actionType, $normalizedContext, $policy);
        $decision = $this->normalizeDecision($policy, $governance);

        if (($decision['decision'] ?? '') !== 'auto_apply') {
            $result = [
                'status' => ($decision['decision'] ?? '') === 'approval_required' ? 'approval_required' : 'blocked',
                'decision' => $decision,
                'autonomy' => $this->buildAutonomyPayload($actionType, $tenantKey, $normalizedContext, $decision),
            ];
            $this->captureDecision($tenantKey, $actionType, $normalizedContext, $result, $actorUserId);
            $this->emitOrchestrationSignal($tenantKey, $actionType, $normalizedContext, $result);
            return $result;
        }

        try {
            $executionResult = $executor();
            $result = [
                'status' => 'executed',
                'execution_result' => is_array($executionResult) ? $executionResult : ['result' => $executionResult],
                'decision' => $decision,
                'autonomy' => $this->buildAutonomyPayload($actionType, $tenantKey, $normalizedContext, $decision),
            ];
            $this->captureDecision($tenantKey, $actionType, $normalizedContext, $result, $actorUserId);
            $this->emitOrchestrationSignal($tenantKey, $actionType, $normalizedContext, $result);
            return $result;
        } catch (\Throwable $e) {
            $incidentId = $this->recordFailureIncident($tenantKey, $actionType, $normalizedContext, $decision, $e);
            $result = [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'decision' => $decision,
                'incident_id' => $incidentId,
                'autonomy' => $this->buildAutonomyPayload($actionType, $tenantKey, $normalizedContext, array_merge($decision, [
                    'incident_id' => $incidentId,
                ])),
            ];
            $this->captureDecision($tenantKey, $actionType, $normalizedContext, $result, $actorUserId);
            $this->emitOrchestrationSignal($tenantKey, $actionType, $normalizedContext, $result);
            throw $e;
        }
    }

    private function buildContext(array $workflow, array $action, array $context, string $tenantKey, array $domainControl): array
    {
        $contact = $this->loadContact((int) ($context['contact_id'] ?? 0));
        $deal = $this->loadDeal((int) ($context['deal_id'] ?? 0), (int) ($context['contact_id'] ?? 0));
        $task = $this->loadTask((int) ($context['task_id'] ?? 0));
        $actionType = (string) ($action['type'] ?? '');
        $triggerConfig = is_string($workflow['trigger_config'] ?? null)
            ? (json_decode((string) $workflow['trigger_config'], true) ?: [])
            : (array) ($workflow['trigger_config'] ?? []);
        $customerFacing = $this->isCustomerFacing($actionType);
        $channel = $this->resolveChannel($actionType);
        $recipient = $this->resolveRecipient($actionType, $action, $context, $contact);

        return [
            'tenant_key' => $tenantKey,
            'surface' => 'workflow_execution',
            'domain_key' => self::DOMAIN_KEY,
            'domain_control' => $domainControl,
            'workflow' => $workflow,
            'workflow_id' => !empty($workflow['id']) ? (int) $workflow['id'] : (int) ($context['workflow_id'] ?? 0),
            'workflow_execution_id' => !empty($context['execution_id']) ? (int) $context['execution_id'] : null,
            'workflow_queue_id' => !empty($context['workflow_queue_id']) ? (int) $context['workflow_queue_id'] : null,
            'workflow_node_id' => (string) ($context['node_id'] ?? ''),
            'workflow_trigger_type' => (string) ($triggerConfig['type'] ?? $context['trigger_type'] ?? ''),
            'workflow_action_type' => $actionType,
            'workflow_queue_state' => (string) ($context['workflow_queue_state'] ?? 'immediate'),
            'workflow_customer_facing' => $customerFacing,
            'assistant_requested_action' => $actionType,
            'requires_customer_send' => $customerFacing,
            'channel' => $channel,
            'recipient' => $recipient,
            'contact' => $contact,
            'contact_id' => !empty($contact['id']) ? (int) $contact['id'] : (!empty($context['contact_id']) ? (int) $context['contact_id'] : null),
            'deal' => $deal,
            'deal_id' => !empty($deal['id']) ? (int) $deal['id'] : null,
            'task' => $task,
            'task_id' => !empty($task['id']) ? (int) $task['id'] : null,
            'action_config' => $action,
            'event_context' => $context,
        ];
    }

    private function evaluateRuntimeControl(array $context): ?array
    {
        $control = $this->runtimeControls->getEffectiveControl('workflow');
        $mode = (string) ($control['control_mode'] ?? 'normal');
        if ($mode === 'paused' || $mode === 'diagnostics_only') {
            return [
                'status' => 'blocked',
                'decision' => [
                    'decision' => 'reject',
                    'reasons' => ['workflow_runtime_paused'],
                    'envelope_decision' => 'blocked',
                    'promotion_gate_status' => [],
                    'drift_status' => [],
                    'incident_id' => null,
                ],
                'autonomy' => [
                    'domain_key' => self::DOMAIN_KEY,
                    'runtime_control_mode' => $mode,
                ],
            ];
        }
        if ($mode === 'suggest_only') {
            return [
                'status' => 'blocked',
                'decision' => [
                    'decision' => 'suggest_only',
                    'reasons' => ['workflow_runtime_suggest_only'],
                    'envelope_decision' => 'approval_required',
                    'promotion_gate_status' => [],
                    'drift_status' => [],
                    'incident_id' => null,
                ],
                'autonomy' => [
                    'domain_key' => self::DOMAIN_KEY,
                    'runtime_control_mode' => $mode,
                ],
            ];
        }
        return null;
    }

    private function evaluateActionPolicy(string $actionType, array $context): array
    {
        $governanceMetadata = $this->workflowCapabilities->governanceForAction(
            $actionType,
            !empty($context['workspace_id']) ? (int) $context['workspace_id'] : 0,
            !empty($context['user_id']) ? (int) $context['user_id'] : 0
        );
        $threshold = (float) ($governanceMetadata['default_confidence_threshold'] ?? self::ACTION_THRESHOLDS[$actionType] ?? 0.84);
        $mode = (string) (($context['domain_control']['autonomy_mode'] ?? $context['domain_control']['promotion_status'] ?? 'suggest_only'));
        $reasons = [];

        if (($context['assistant_confidence'] ?? 0) < $threshold) {
            $reasons[] = 'confidence_below_threshold';
        }
        if (empty($context['contact_id']) && in_array($actionType, ['create_task', 'assign_to_user', 'change_stage', 'add_tag', 'remove_tag', 'update_contact_field'], true)) {
            $reasons[] = 'missing_contact_context';
        }
        if (!empty($governanceMetadata['customer_facing']) && trim((string) ($context['recipient'] ?? '')) === '') {
            $reasons[] = 'missing_recipient';
        }
        if ($actionType === 'update_deal_stage' && empty($context['deal_id'])) {
            $reasons[] = 'missing_deal_context';
        }
        if ($actionType === 'update_contact_field' && empty($context['action_config']['field'])) {
            $reasons[] = 'missing_target_field';
        }
        if (empty($context['workflow_id']) || empty($context['workflow_trigger_type'])) {
            $reasons[] = 'invalid_workflow_context';
        }

        if ($mode === 'suggest_only') {
            return ['decision' => 'suggest_only', 'reasons' => ['suggest_only_mode'], 'threshold' => $threshold, 'capability_governance' => $governanceMetadata];
        }
        if ($reasons !== []) {
            return ['decision' => $mode === 'full_auto' ? 'reject' : 'approval_required', 'reasons' => $reasons, 'threshold' => $threshold, 'capability_governance' => $governanceMetadata];
        }
        if (!empty($governanceMetadata['requires_approval']) && $mode !== 'full_auto') {
            return ['decision' => 'approval_required', 'reasons' => ['capability_requires_approval'], 'threshold' => $threshold, 'capability_governance' => $governanceMetadata];
        }

        return ['decision' => 'auto_apply', 'reasons' => [], 'threshold' => $threshold, 'capability_governance' => $governanceMetadata];
    }

    private function normalizeDecision(array $policy, array $governance): array
    {
        $policy['envelope_decision'] = $governance['decision'] ?? 'allow';
        $policy['promotion_gate_status'] = $governance['promotion'] ?? [];
        $policy['drift_status'] = $governance['drift'] ?? [];
        $policy['incident_id'] = $governance['incident_id'] ?? null;
        $policy['risk_score'] = $governance['risk_score'] ?? null;
        if (($governance['decision'] ?? 'allow') === 'allow') {
            return $policy;
        }

        return [
            'decision' => ($governance['decision'] ?? '') === 'approval_required' ? 'approval_required' : 'reject',
            'reasons' => (array) ($governance['reason_codes'] ?? ['governance_blocked']),
            'threshold' => $policy['threshold'] ?? null,
            'envelope_decision' => $governance['decision'] ?? 'blocked',
            'promotion_gate_status' => $governance['promotion'] ?? [],
            'drift_status' => $governance['drift'] ?? [],
            'incident_id' => $governance['incident_id'] ?? null,
            'risk_score' => $governance['risk_score'] ?? null,
        ];
    }

    private function buildAutonomyPayload(string $actionType, string $tenantKey, array $context, array $decision): array
    {
        return [
            'domain_key' => self::DOMAIN_KEY,
            'tenant_key' => $tenantKey,
            'action_key' => $actionType,
            'workflow_id' => $context['workflow_id'] ?? null,
            'workflow_execution_id' => $context['workflow_execution_id'] ?? null,
            'workflow_queue_id' => $context['workflow_queue_id'] ?? null,
            'workflow_node_id' => $context['workflow_node_id'] ?? null,
            'workflow_trigger_type' => $context['workflow_trigger_type'] ?? null,
            'workflow_queue_state' => $context['workflow_queue_state'] ?? null,
            'assistant_confidence' => $context['assistant_confidence'] ?? null,
            'confidence_basis' => (array) ($context['confidence_basis'] ?? []),
            'similar_examples' => (array) ($context['similar_examples'] ?? []),
            'learned_signals' => (array) ($context['learned_signals'] ?? []),
            'decision' => $decision['decision'] ?? null,
            'reasons' => (array) ($decision['reasons'] ?? []),
            'envelope_decision' => $decision['envelope_decision'] ?? null,
            'promotion_gate_status' => $decision['promotion_gate_status'] ?? [],
            'drift_status' => $decision['drift_status'] ?? [],
            'incident_id' => $decision['incident_id'] ?? null,
            'risk_score' => $decision['risk_score'] ?? null,
            'customer_facing' => !empty($context['workflow_customer_facing']),
        ];
    }

    private function captureDecision(string $tenantKey, string $actionType, array $context, array $result, ?int $actorUserId): void
    {
        $status = (string) ($result['status'] ?? 'observed');
        $decision = (array) ($result['decision'] ?? []);
        $autonomy = (array) ($result['autonomy'] ?? []);

        $this->capture->capture([
            'tenant_key' => $tenantKey,
            'actor_user_id' => $actorUserId,
            'actor_type' => $status === 'executed' ? 'system' : 'ai',
            'source_surface' => 'workflow_execution',
            'domain_key' => self::DOMAIN_KEY,
            'entity_type' => 'contact',
            'entity_id' => $context['contact_id'] ?? null,
            'related_entity_type' => !empty($context['deal_id']) ? 'deal' : (!empty($context['task_id']) ? 'task' : 'workflow'),
            'related_entity_id' => !empty($context['deal_id']) ? $context['deal_id'] : (!empty($context['task_id']) ? $context['task_id'] : ($context['workflow_id'] ?? null)),
            'action_key' => $actionType,
            'prior_state' => [
                'contact_id' => $context['contact_id'] ?? null,
                'deal_id' => $context['deal_id'] ?? null,
                'task_id' => $context['task_id'] ?? null,
                'queue_state' => $context['workflow_queue_state'] ?? null,
            ],
            'action_payload' => array_merge((array) ($context['action_config'] ?? []), [
                'workflow_id' => $context['workflow_id'] ?? null,
                'workflow_execution_id' => $context['workflow_execution_id'] ?? null,
                'workflow_queue_id' => $context['workflow_queue_id'] ?? null,
                'workflow_node_id' => $context['workflow_node_id'] ?? null,
                'workflow_queue_state' => $context['workflow_queue_state'] ?? null,
            ]),
            'outcome_state' => [
                'status' => $status,
                'decision' => $decision,
                'execution_result' => $result['execution_result'] ?? null,
                'error' => $result['error'] ?? null,
            ],
            'outcome_label' => match ($status) {
                'executed' => 'accepted',
                'approval_required' => 'approval_required',
                'failed' => 'failed',
                default => 'observed',
            },
            'metadata' => [
                'assistant_confidence' => $context['assistant_confidence'] ?? null,
                'confidence_basis' => $context['confidence_basis'] ?? [],
                'channel' => $context['channel'] ?? null,
                'workflow_id' => $context['workflow_id'] ?? null,
                'workflow_execution_id' => $context['workflow_execution_id'] ?? null,
                'workflow_node_id' => $context['workflow_node_id'] ?? null,
                'workflow_trigger_type' => $context['workflow_trigger_type'] ?? null,
                'workflow_action_type' => $actionType,
                'workflow_queue_state' => $context['workflow_queue_state'] ?? null,
                'workflow_customer_facing' => !empty($context['workflow_customer_facing']),
                'recipient' => $context['recipient'] ?? null,
                'duplicate_action' => false,
                'incident_id' => $decision['incident_id'] ?? ($result['incident_id'] ?? null),
            ],
            'was_successful' => $status === 'executed',
            'was_reversed' => false,
            'was_edited' => false,
        ]);
    }

    private function recordFailureIncident(string $tenantKey, string $actionType, array $context, array $decision, \Throwable $e): ?int
    {
        $incidentId = $this->incidents->recordIncident([
            'tenant_key' => $tenantKey,
            'domain_key' => self::DOMAIN_KEY,
            'action_key' => $actionType,
            'incident_key' => !empty($context['workflow_customer_facing']) ? 'workflow_customer_action_failed' : 'workflow_action_failed',
            'severity' => !empty($context['workflow_customer_facing']) ? 'high' : 'medium',
            'reason_codes' => array_values(array_unique(array_merge((array) ($decision['reasons'] ?? []), ['workflow_execution_failed']))),
            'details' => [
                'error' => $e->getMessage(),
                'workflow_id' => $context['workflow_id'] ?? null,
                'workflow_execution_id' => $context['workflow_execution_id'] ?? null,
                'workflow_queue_id' => $context['workflow_queue_id'] ?? null,
                'workflow_node_id' => $context['workflow_node_id'] ?? null,
                'decision' => $decision,
            ],
            'linked_entity_type' => !empty($context['deal_id']) ? 'deal' : (!empty($context['task_id']) ? 'task' : 'contact'),
            'linked_entity_id' => !empty($context['deal_id']) ? (int) $context['deal_id'] : (!empty($context['task_id']) ? (int) $context['task_id'] : (int) ($context['contact_id'] ?? 0)),
        ]);
        if ($incidentId) {
            $this->incidents->queueRecovery([
                'incident_id' => $incidentId,
                'tenant_key' => $tenantKey,
                'domain_key' => self::DOMAIN_KEY,
                'action_key' => $actionType,
                'suggested_manual_action' => 'Review workflow action failure and decide whether to retry, pause workflow autonomy, or execute manually',
                'payload' => [
                    'workflow_id' => $context['workflow_id'] ?? null,
                    'workflow_execution_id' => $context['workflow_execution_id'] ?? null,
                    'workflow_node_id' => $context['workflow_node_id'] ?? null,
                    'error' => $e->getMessage(),
                ],
            ]);
        }
        return $incidentId;
    }

    private function heuristicConfidence(string $actionType, array $context): float
    {
        $base = match ($actionType) {
            'send_email' => 0.91,
            'send_whatsapp' => 0.89,
            'send_sms' => 0.88,
            'create_task' => 0.87,
            'assign_to_user', 'add_tag', 'remove_tag' => 0.84,
            'update_contact_field', 'update_deal_stage', 'change_stage', 'create_deal', 'add_to_deal' => 0.85,
            'create_activity', 'add_note', 'update_lead_score', 'send_in_app_notification' => 0.82,
            default => 0.8,
        };
        if (!empty($context['recipient'])) {
            $base += 0.03;
        }
        if (!empty($context['contact_id'])) {
            $base += 0.03;
        }
        if (!empty($context['deal_id'])) {
            $base += 0.02;
        }
        if (!empty($context['workflow_queue_state']) && $context['workflow_queue_state'] !== 'immediate') {
            $base -= 0.01;
        }
        return max(0.0, min(0.995, round($base, 4)));
    }

    private function resolveTenantKey(array $context): string
    {
        if (!empty($context['tenant_key'])) {
            return $this->workspaceScope->workspaceTenantKey(
                $this->workspaceScope->requireWorkspaceId((string) $context['tenant_key'])
            );
        }
        if (!empty($context['contact_id'])) {
            return $this->workspaceScope->workspaceTenantKey(
                $this->workspaceScope->requireWorkspaceId('contact:' . (int) $context['contact_id'])
            );
        }
        if (!empty($context['user_id'])) {
            return $this->workspaceScope->workspaceTenantKey(
                $this->workspaceScope->requireWorkspaceId('user:' . (int) $context['user_id'])
            );
        }
        return $this->workspaceScope->currentTenantKey();
    }

    private function resolveChannel(string $actionType): string
    {
        return match ($actionType) {
            'send_email' => 'email',
            'send_whatsapp' => 'whatsapp',
            'send_sms' => 'sms',
            default => 'internal',
        };
    }

    private function resolveRecipient(string $actionType, array $action, array $context, array $contact): string
    {
        return match ($actionType) {
            'send_email' => (string) ($context['contact_email'] ?? $context['email'] ?? $contact['email'] ?? ''),
            'send_whatsapp', 'send_sms' => (string) ($context['phone'] ?? $contact['phone'] ?? ''),
            default => '',
        };
    }

    private function isCustomerFacing(string $actionType): bool
    {
        return !empty($this->workflowCapabilities->governanceForAction($actionType)['customer_facing']);
    }

    private function loadContact(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }
        return Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]) ?: [];
    }

    private function loadDeal(int $dealId, int $contactId): array
    {
        if ($dealId > 0) {
            return Database::queryOne("SELECT * FROM deals WHERE id = ?", [$dealId]) ?: [];
        }
        if ($contactId > 0) {
            return Database::queryOne("SELECT * FROM deals WHERE contact_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1", [$contactId]) ?: [];
        }
        return [];
    }

    private function loadTask(int $taskId): array
    {
        if ($taskId <= 0) {
            return [];
        }
        return Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$taskId]) ?: [];
    }

    private function emitOrchestrationSignal(string $tenantKey, string $actionType, array $context, array $result): void
    {
        if (!empty($context['event_context']['orchestration_run_id']) || !empty($context['orchestration_run_id'])) {
            return;
        }

        $triggerKey = match ((string) ($result['status'] ?? 'observed')) {
            'failed' => !empty($context['workflow_customer_facing']) ? 'workflow_customer_send_failed' : 'workflow_followup_needed',
            'approval_required', 'blocked' => 'workflow_followup_needed',
            default => ($context['workflow_queue_state'] ?? '') === 'retry' ? 'workflow_retry_pending' : 'workflow_followup_needed',
        };

        try {
            $event = [
                'tenant_key' => $tenantKey,
                'source_domain' => 'workflow_execution',
                'trigger_key' => $triggerKey,
                'trigger_entity_type' => 'workflow',
                'trigger_entity_id' => (int) ($context['workflow_id'] ?? 0),
                'related_entity_ids' => [
                    'contact_id' => !empty($context['contact_id']) ? (int) $context['contact_id'] : null,
                    'deal_id' => !empty($context['deal_id']) ? (int) $context['deal_id'] : null,
                    'task_id' => !empty($context['task_id']) ? (int) $context['task_id'] : null,
                    'workflow_id' => !empty($context['workflow_id']) ? (int) $context['workflow_id'] : null,
                ],
                'metadata' => [
                    'action_type' => $actionType,
                    'customer_facing' => !empty($context['workflow_customer_facing']),
                    'status' => (string) ($result['status'] ?? 'observed'),
                    'workflow_queue_state' => (string) ($context['workflow_queue_state'] ?? 'immediate'),
                ],
            ];
            (new AICrossDomainEventIntakeService())->processEvent($event);
            (new AICrossDomainResumeService())->handleSignal($event);
        } catch (\Throwable $e) {
            error_log('WorkflowAutonomyExecutionService orchestration signal failed: ' . $e->getMessage());
        }
    }
}
