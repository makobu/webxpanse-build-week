<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\Invoices;
use CRM\Modules\Tasks;

class CommercialAutomationOrchestrator
{
    private CommercialAutomationConfig $config;
    private CommercialAutomationPolicyService $policyService;
    private CommercialAutomationActionPlanner $planner;
    private CommercialAutomationApprovalService $approvalService;
    private CommercialAutomationMessagingService $messagingService;
    private DealAutomationEvidenceBuilder $evidenceBuilder;
    private Invoices $invoices;
    private EmailAssistantPolicyBridge $assistantPolicyBridge;
    private AIDecisionOutcomeService $outcomes;
    private AIOutcomeClassifier $classifier;
    private AIOperatorDemonstrationService $demonstrations;
    private AITenantPolicyMemoryService $tenantPolicyMemory;
    private AITenantPolicyResolverService $tenantPolicyResolver;
    private AIAutonomyScoringService $autonomyScoring;
    private AIAutonomyDomainControlService $domainControls;
    private AIAutonomyGovernanceService $governance;
    private TaskAssignmentAccessService $taskAssignmentAccess;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->config = new CommercialAutomationConfig();
        $this->policyService = new CommercialAutomationPolicyService();
        $this->planner = new CommercialAutomationActionPlanner();
        $this->approvalService = new CommercialAutomationApprovalService();
        $this->messagingService = new CommercialAutomationMessagingService();
        $this->evidenceBuilder = new DealAutomationEvidenceBuilder();
        $this->invoices = new Invoices();
        $this->assistantPolicyBridge = new EmailAssistantPolicyBridge();
        $this->outcomes = new AIDecisionOutcomeService();
        $this->classifier = new AIOutcomeClassifier();
        $this->demonstrations = new AIOperatorDemonstrationService();
        $this->tenantPolicyMemory = new AITenantPolicyMemoryService();
        $this->tenantPolicyResolver = new AITenantPolicyResolverService();
        $this->autonomyScoring = new AIAutonomyScoringService();
        $this->domainControls = new AIAutonomyDomainControlService();
        $this->governance = new AIAutonomyGovernanceService();
        $this->taskAssignmentAccess = new TaskAssignmentAccessService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function runForStageChange(int $dealId, string $fromStage, string $toStage): ?array
    {
        return $this->runForDeal($dealId, 'stage_change', null, ['from_stage' => $fromStage, 'to_stage' => $toStage]);
    }

    public function runForCommunication(int $contactId, int $communicationId): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $deals = Database::query(
            "SELECT id
             FROM deals
             WHERE workspace_id = ?
               AND contact_id = ?
               AND stage IN ('proposal','negotiation','closed_won')
             ORDER BY updated_at DESC",
            [$workspaceId, $contactId]
        );
        $result = null;
        foreach ($deals as $deal) {
            $result = $this->runForDeal((int) $deal['id'], 'communication', $communicationId);
        }
        return $result;
    }

    public function runForDealSweep(int $dealId): ?array
    {
        return $this->runForDeal($dealId, 'scheduled_sweep');
    }

    public function runForInvoiceStatus(int $invoiceId, string $fromStatus, string $toStatus): ?array
    {
        $invoice = $this->invoices->getById($invoiceId);
        if (!$invoice || empty($invoice['deal_id'])) {
            return null;
        }
        return $this->runForDeal((int) $invoice['deal_id'], 'invoice_status', $invoiceId, [
            'invoice_status_from' => $fromStatus,
            'invoice_status_to' => $toStatus,
        ]);
    }

    public function runForDeal(int $dealId, string $triggerType = 'manual', ?int $triggerRefId = null, array $extra = []): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $deal = Database::queryOne(
            "SELECT * FROM deals WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $dealId]
        );
        if (!$deal) {
            return null;
        }

        $invoice = $this->invoices->findLatestForDeal($dealId);
        $evidence = $this->evidenceBuilder->buildForDeal($dealId, 14);
        $policy = $this->policyService->getPolicySnapshot();
        $tenantKey = $this->aiWorkspaceScope->workspaceTenantKey((int) ($deal['workspace_id'] ?? $workspaceId));
        $domainControl = $this->domainControls->get($tenantKey, 'commercial_mvp');
        $learnedPreferences = $this->tenantPolicyResolver->resolve($tenantKey, 'commercial_mvp', ['deal' => $deal, 'invoice' => $invoice]);
        $context = array_merge($extra, [
            'deal' => $deal,
            'invoice' => $invoice,
            'workspace_id' => (int) ($deal['workspace_id'] ?? $workspaceId),
            'evidence' => $evidence,
            'tenant_key' => $tenantKey,
            'domain_key' => 'commercial_mvp',
            'domain_control' => $domainControl,
            'learned_preferences' => $learnedPreferences,
            'trigger_type' => $triggerType,
            'trigger_ref_id' => $triggerRefId,
            'needs_revision' => $this->shouldRevise($deal, $invoice, $evidence),
            'stale_revision' => $this->isNegotiationStale($deal, $invoice, $policy),
            'stale_followup' => $this->isProposalFollowupStale($deal, $invoice, $policy),
            'should_mark_overdue' => $this->shouldMarkOverdue($invoice),
            'should_mark_paid' => $this->shouldMarkPaid($deal, $invoice, $evidence),
        ]);
        $actions = $this->planner->plan($context, $policy);

        $finalDecision = 'reject';
        $results = [];
        $executedActions = [];
        foreach ($actions as $action) {
            $actionContext = $this->buildActionContext($context, $action);
            $decision = $this->policyService->evaluateAction($action['action'], $actionContext);
            $governance = $this->governance->evaluate($tenantKey, 'commercial_mvp', (string) ($action['action'] ?? ''), $actionContext, $decision);
            $actionContext['governance'] = $governance;
            $normalizedDecision = $this->applyGovernanceToPolicyDecision($decision, $governance);
            if (($normalizedDecision['decision'] ?? 'reject') === 'auto_apply') {
                $execution = $this->executeAction($action, $actionContext, 'system', null);
                $execution['policy'] = $normalizedDecision;
                $results[] = $execution;
                $executedActions[] = [
                    'action' => $action,
                    'context' => $actionContext,
                    'result' => $execution,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'approval_id' => null,
                ];
                $finalDecision = 'auto_apply';
            } elseif (($normalizedDecision['decision'] ?? 'reject') === 'approval_required') {
                $approvalId = $this->approvalService->requestApproval(
                    !empty($deal['id']) ? (int) $deal['id'] : null,
                    !empty($invoice['id']) ? (int) $invoice['id'] : null,
                    (string) $action['action'],
                    (string) ($normalizedDecision['reason'] ?? 'Approval required'),
                    ['action' => $action, 'context' => $actionContext, 'policy' => $normalizedDecision],
                    'system',
                    null
                );
                $this->createExceptionTask($deal, $invoice, $normalizedDecision['reason'] ?? 'Approval required');
                $results[] = ['approval_id' => $approvalId, 'decision' => 'approval_required'];
                $finalDecision = 'approval_required';
            } elseif (($normalizedDecision['decision'] ?? 'reject') === 'suggest_only' && $finalDecision === 'reject') {
                $finalDecision = 'suggest_only';
            }
        }

        $runId = $this->logRun(
            (int) ($deal['workspace_id'] ?? $workspaceId),
            !empty($deal['id']) ? (int) $deal['id'] : null,
            !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
            !empty($invoice['id']) ? (int) $invoice['id'] : null,
            $triggerType,
            $triggerRefId,
            $finalDecision,
            $actions,
            $evidence,
            $policy
        );
        $this->recordLearningArtifacts($runId, $executedActions);

        if (($extra['orchestration_run_id'] ?? 0) <= 0 && $triggerType !== 'cross_domain') {
            try {
                (new AICrossDomainEventIntakeService())->processEvent([
                    'tenant_key' => $tenantKey,
                    'source_domain' => 'commercial_mvp',
                    'trigger_key' => match ($triggerType) {
                        'communication' => 'communication',
                        'scheduled_sweep' => 'proposal_followup_due',
                        'stage_change', 'invoice_status' => 'send_readiness',
                        default => 'negotiation_activity',
                    },
                    'trigger_entity_type' => !empty($triggerRefId) && $triggerType === 'communication' ? 'communication' : 'deal',
                    'trigger_entity_id' => !empty($triggerRefId) && $triggerType === 'communication' ? (int) $triggerRefId : $dealId,
                    'related_entity_ids' => [
                        'deal_id' => !empty($deal['id']) ? (int) $deal['id'] : null,
                        'invoice_id' => !empty($invoice['id']) ? (int) $invoice['id'] : null,
                        'contact_id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
                        'communication_id' => $triggerType === 'communication' ? $triggerRefId : null,
                    ],
                    'metadata' => [
                        'decision' => $finalDecision,
                        'run_id' => $runId,
                        'trigger_type' => $triggerType,
                    ],
                ]);
            } catch (\Throwable $e) {
                error_log('CommercialAutomationOrchestrator orchestration intake failed: ' . $e->getMessage());
            }
        }

        return ['decision' => $finalDecision, 'actions' => $results];
    }

    public function executeApprovedAction(int $approvalId, int $userId): ?array
    {
        $approval = $this->approvalService->approve($approvalId, $userId);
        if (!$approval) {
            return null;
        }
        $payload = $approval['payload'] ?? [];
        $action = $payload['action'] ?? null;
        $context = $payload['context'] ?? [];
        if (!is_array($action) || !is_array($context)) {
            return $approval;
        }
        $result = $this->executeAction($action, $context, 'user', $userId);
        $runId = $this->logRun(
            !empty($approval['workspace_id']) ? (int) $approval['workspace_id'] : $this->workspaceScope->requireActiveWorkspaceId(),
            !empty($context['deal']['id']) ? (int) $context['deal']['id'] : (!empty($approval['deal_id']) ? (int) $approval['deal_id'] : null),
            !empty($context['deal']['contact_id']) ? (int) $context['deal']['contact_id'] : null,
            !empty($context['invoice']['id']) ? (int) $context['invoice']['id'] : (!empty($approval['invoice_id']) ? (int) $approval['invoice_id'] : null),
            'manual',
            $approvalId,
            !empty($result['status']) && !in_array($result['status'], ['skipped', 'blocked', 'suggest_only'], true) ? 'auto_apply' : 'reject',
            [$action],
            $context['evidence'] ?? [],
            $this->policyService->getPolicySnapshot()
        );
        if ($runId > 0) {
            $run = Database::queryOne("SELECT * FROM commercial_automation_runs WHERE id = ?", [$runId]) ?: [];
            $this->outcomes->recordCommercialOutcome($run, [
                'action_type' => (string) ($action['action'] ?? 'commercial_action'),
                'outcome_label' => in_array((string) ($result['status'] ?? ''), ['failed', 'blocked'], true) ? 'failed' : 'approved',
                'outcome_score' => $this->classifier->scoreOutcomeLabel(in_array((string) ($result['status'] ?? ''), ['failed', 'blocked'], true) ? 'failed' : 'approved'),
                'metadata' => ['result' => $result, 'approval_id' => $approvalId],
            ]);
        }
        $updatedApproval = $this->approvalService->getDetailedById($approvalId);
        if ($updatedApproval) {
            $this->outcomes->recordApprovalOutcome($updatedApproval, $this->classifier->classifyApprovalOutcome($updatedApproval, ['execution' => $result]));
        }
        if ($runId > 0) {
            $this->recordLearningArtifacts($runId, [[
                'action' => $action,
                'context' => $context,
                'result' => $result,
                'actor_type' => 'user',
                'actor_id' => $userId,
                'approval_id' => $approvalId,
            ]]);
        }

        return [
            'approval' => $updatedApproval,
            'execution' => $result,
            'deal_id' => !empty($context['deal']['id']) ? (int) $context['deal']['id'] : (!empty($approval['deal_id']) ? (int) $approval['deal_id'] : null),
            'invoice_id' => !empty($context['invoice']['id']) ? (int) $context['invoice']['id'] : (!empty($approval['invoice_id']) ? (int) $approval['invoice_id'] : null),
            'commercial_run_id' => $runId > 0 ? $runId : null,
        ];
    }

    public function previewApprovedAction(int $approvalId): ?array
    {
        $approval = $this->approvalService->getDetailedById($approvalId);
        if (!$approval) {
            return null;
        }

        return [
            'approval' => $approval,
            'preview' => $this->approvalService->getPreview($approval),
            'diagnostics' => $this->approvalService->getDiagnostics($approval),
        ];
    }

    public function getRecentRuns(?int $dealId = null, ?int $invoiceId = null, int $limit = 10): array
    {
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceScope->requireActiveWorkspaceId()];
        if ($dealId) {
            $where[] = 'deal_id = ?';
            $params[] = $dealId;
        }
        if ($invoiceId) {
            $where[] = 'invoice_id = ?';
            $params[] = $invoiceId;
        }
        $sql = "SELECT * FROM commercial_automation_runs";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . max(1, $limit);
        return Database::query($sql, $params);
    }

    public function runAssistantPlannedCommercialAction(array $action, array $context, int $userId): array
    {
        $policyDecision = $this->assistantPolicyBridge->evaluateCommercialAssistantAction(
            (string) ($action['action'] ?? ''),
            array_merge($context, [
                'surface' => (string) ($context['surface'] ?? 'admin_command'),
                'assistant_requested_action' => (string) ($action['action'] ?? ''),
                'document_type' => (string) ($action['document_type'] ?? ($context['invoice']['document_type'] ?? '')),
                'recipient' => (string) (($context['invoice']['billing_email'] ?? $context['invoice']['contact_email'] ?? $context['contact']['email'] ?? '')),
                'channel' => (string) ($context['channel'] ?? 'email'),
                'assistant_confidence' => (float) ($context['assistant_confidence'] ?? 1.0),
            ]),
            $userId
        );

        if (($policyDecision['decision'] ?? '') === 'approval_required') {
            return ['action' => $action['action'] ?? '', 'status' => 'approval_required', 'policy' => $policyDecision];
        }
        if (($policyDecision['decision'] ?? '') === 'suggest_only') {
            return ['action' => $action['action'] ?? '', 'status' => 'suggest_only', 'policy' => $policyDecision];
        }
        if (($policyDecision['decision'] ?? '') === 'blocked') {
            return ['action' => $action['action'] ?? '', 'status' => 'blocked', 'policy' => $policyDecision];
        }

        $result = $this->executeAction($action, $context, 'ai', $userId);
        $deal = $context['deal'] ?? [];
        $invoice = $context['invoice'] ?? [];
        $runId = $this->logRun(
            $this->workspaceScope->requireActiveWorkspaceId(),
            !empty($deal['id']) ? (int) $deal['id'] : null,
            !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
            !empty($invoice['id']) ? (int) $invoice['id'] : null,
            'manual',
            null,
            !empty($result['status']) && !in_array($result['status'], ['skipped', 'blocked', 'suggest_only'], true) ? 'auto_apply' : 'reject',
            [$action],
            $context['evidence'] ?? [],
            $this->policyService->getPolicySnapshot()
        );
        if ($runId > 0) {
            $run = Database::queryOne("SELECT * FROM commercial_automation_runs WHERE id = ?", [$runId]) ?: [];
            $label = in_array((string) ($result['status'] ?? ''), ['sent', 'converted', 'finalized', 'created', 'revised', 'overdue'], true) ? 'accepted' : 'failed';
            $this->outcomes->recordCommercialOutcome($run, [
                'action_type' => (string) ($action['action'] ?? 'commercial_action'),
                'outcome_label' => $label,
                'outcome_score' => $this->classifier->scoreOutcomeLabel($label),
                'metadata' => ['result' => $result],
            ]);
            $this->recordLearningArtifacts($runId, [[
                'action' => $action,
                'context' => $context,
                'result' => $result,
                'actor_type' => 'ai',
                'actor_id' => $userId,
                'approval_id' => null,
            ]]);
        }
        $result['policy'] = $policyDecision;
        return $result;
    }

    public function summarizeForAssistant(?int $dealId = null, ?int $invoiceId = null): array
    {
        return [
            'pending_approvals' => $this->approvalService->listPending($dealId, $invoiceId),
            'recent_runs' => $this->getRecentRuns($dealId, $invoiceId, 5),
        ];
    }

    private function buildActionContext(array $context, array $action): array
    {
        $invoice = $context['invoice'] ?? null;
        $deal = $context['deal'] ?? [];
        $recipientEmail = (string) ($invoice['billing_email'] ?? $invoice['contact_email'] ?? '');
        $recipientWhatsApp = '';
        if ($recipientEmail === '' && !empty($invoice['contact_phone'])) {
            $recipientWhatsApp = (string) $invoice['contact_phone'];
        }
        $suggestions = $this->invoices->getSuggestedProducts(
            !empty($deal['id']) ? (int) $deal['id'] : null,
            !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
            !empty($deal['company_id']) ? (int) $deal['company_id'] : null,
            (string) ($action['document_type'] ?? ($invoice['document_type'] ?? 'quote')),
            !empty($invoice['id']) ? (int) $invoice['id'] : null
        );
        $channel = $recipientEmail !== '' ? 'email' : 'whatsapp';
        $recipient = $recipientEmail !== '' ? $recipientEmail : $recipientWhatsApp;
        $actionContext = array_merge($context, [
            'document_type' => (string) ($action['document_type'] ?? ''),
            'recipient' => $recipient,
            'channel' => $channel,
            'suggested_products' => $suggestions,
            'has_zero_priced_recommendation' => $this->hasZeroPricedRecommendation($suggestions),
            'requested_discount_percent' => $this->extractRequestedDiscountPercent($invoice),
            'requested_total_change_percent' => 0.0,
            'recent_send_blocked' => $this->hasRecentSend($invoice, $this->policyService->getPolicySnapshot()),
            'within_whatsapp_window' => $this->isWithinWhatsAppWindow($invoice),
            'whatsapp_provider_document_supported' => true,
            'payment_signal_detected' => $this->detectPaymentSignal($context['evidence'] ?? []),
            'payment_amount' => (float) ($invoice['balance_due'] ?? 0),
            'assistant_requested_action' => (string) ($action['action'] ?? ''),
        ]);
        $actionContext['heuristic_confidence'] = $this->estimateActionConfidence((string) ($action['action'] ?? ''), $actionContext);
        $learning = $this->autonomyScoring->score(
            (string) ($actionContext['tenant_key'] ?? $this->demonstrations->resolveTenantKey($actionContext)),
            'commercial_mvp',
            (string) ($action['action'] ?? ''),
            $actionContext
        );
        $actionContext['assistant_confidence'] = (float) ($learning['assistant_confidence'] ?? $actionContext['heuristic_confidence']);
        $actionContext['similar_examples'] = (array) ($learning['similar_examples'] ?? []);
        $actionContext['learned_signals'] = (array) ($learning['tenant_policy'] ?? []);
        $actionContext['confidence_basis'] = (array) ($learning['confidence_basis'] ?? []);
        return $actionContext;
    }

    private function applyGovernanceToPolicyDecision(array $policyDecision, array $governance): array
    {
        $policyDecision['envelope_decision'] = $governance['decision'] ?? 'allow';
        $policyDecision['promotion_gate_status'] = $governance['promotion'] ?? [];
        $policyDecision['drift_status'] = $governance['drift'] ?? [];
        $policyDecision['incident_id'] = $governance['incident_id'] ?? null;
        $policyDecision['risk_score'] = $governance['risk_score'] ?? null;

        if (($governance['decision'] ?? 'allow') === 'allow') {
            return $policyDecision;
        }

        $reasonCodes = (array) ($governance['reason_codes'] ?? []);
        return [
            'decision' => ($governance['decision'] ?? 'blocked') === 'approval_required' ? 'approval_required' : 'reject',
            'reason' => implode(', ', $reasonCodes ?: ['governance_blocked']),
            'reasons' => $reasonCodes ?: ['governance_blocked'],
            'policy' => $policyDecision['policy'] ?? [],
            'threshold' => $policyDecision['threshold'] ?? null,
            'learned_signals' => $policyDecision['learned_signals'] ?? [],
            'envelope_decision' => $governance['decision'] ?? 'blocked',
            'promotion_gate_status' => $governance['promotion'] ?? [],
            'drift_status' => $governance['drift'] ?? [],
            'incident_id' => $governance['incident_id'] ?? null,
            'risk_score' => $governance['risk_score'] ?? null,
        ];
    }

    private function executeAction(array $action, array $context, string $actorType, ?int $actorId): array
    {
        $deal = $context['deal'] ?? [];
        $invoice = $context['invoice'] ?? null;
        $documentType = (string) ($action['document_type'] ?? 'quote');

        if (!$this->acquireIdempotencyLock($deal, $invoice, (string) $action['action'], $action)) {
            return ['action' => $action['action'], 'status' => 'duplicate'];
        }

        switch ($action['action']) {
            case 'create_draft':
                $invoiceId = $this->invoices->createDraftForStage((int) $deal['id'], (string) $deal['stage'], $documentType, $actorType, $actorId);
                $this->invoices->logActivity($invoiceId, $documentType === 'proforma' ? 'draft_proforma_created' : 'draft_quote_created', $actorType, $actorId, 'Commercial draft created by automation', ['deal_id' => $deal['id']]);
                $gaps = $this->invoices->hasBlockingCommercialGaps($invoiceId);
                if (!empty($gaps['missing_pricing']) || !empty($gaps['missing_recipient'])) {
                    $this->createExceptionTask($deal, $this->invoices->getById($invoiceId), 'Commercial draft needs pricing or recipient details before delivery.');
                }
                return ['action' => 'create_draft', 'status' => 'created', 'invoice_id' => $invoiceId];

            case 'revise_document':
                if ($invoice) {
                    $updated = $this->invoices->reviseFromRecommendations((int) $invoice['id'], ['suggested_products' => $context['suggested_products'] ?? []], $actorType, $actorId);
                    return ['action' => 'revise_document', 'status' => 'revised', 'invoice_id' => $updated];
                }
                break;

            case 'send_document':
            case 'resend_document':
                if ($invoice) {
                    try {
                        $send = $this->messagingService->sendInvoice((int) $invoice['id'], (string) ($context['channel'] ?? 'email'), (string) ($context['recipient'] ?? ''), $actorId, $actorType);
                        return ['action' => $action['action'], 'status' => !empty($send['deferred']) ? 'deferred' : 'sent', 'invoice_id' => $invoice['id']];
                    } catch (\Throwable $e) {
                        $this->createExceptionTask($deal, $invoice, 'Commercial delivery failed: ' . $e->getMessage());
                        $this->invoices->logActivity((int) $invoice['id'], 'invoice_send_failed', $actorType, $actorId, 'Commercial automation delivery failed', [
                            'recipient' => (string) ($context['recipient'] ?? ''),
                            'channel' => (string) ($context['channel'] ?? 'email'),
                            'reason' => $e->getMessage(),
                        ]);
                        return [
                            'action' => $action['action'],
                            'status' => 'failed',
                            'invoice_id' => $invoice['id'],
                            'reason' => $e->getMessage(),
                        ];
                    }
                }
                break;

            case 'convert_to_invoice':
                if ($invoice) {
                    $newInvoiceId = $this->invoices->convertToInvoice((int) $invoice['id'], $actorId, $actorType);
                    return ['action' => 'convert_to_invoice', 'status' => 'converted', 'invoice_id' => $newInvoiceId];
                }
                break;

            case 'finalize_invoice':
                if ($invoice) {
                    $this->invoices->transitionStatus((int) $invoice['id'], 'finalized', $actorId, $actorType, 'Finalized by commercial automation');
                    return ['action' => 'finalize_invoice', 'status' => 'finalized', 'invoice_id' => $invoice['id']];
                }
                break;

            case 'cancel_document':
                if ($invoice) {
                    $this->invoices->transitionStatus((int) $invoice['id'], 'cancelled', $actorId, $actorType, 'Cancelled after deal was marked lost');
                    return ['action' => 'cancel_document', 'status' => 'cancelled', 'invoice_id' => $invoice['id']];
                }
                break;

            case 'mark_overdue':
                if ($invoice) {
                    $marked = $this->invoices->markOverdueIfEligible((int) $invoice['id'], $actorType, $actorId);
                    return ['action' => 'mark_overdue', 'status' => $marked ? 'overdue' : 'unchanged', 'invoice_id' => $invoice['id']];
                }
                break;

            case 'mark_paid':
                if ($invoice) {
                    $marked = $this->invoices->markPaid(
                        (int) $invoice['id'],
                        (float) max(0, (float) ($context['payment_amount'] ?? ($invoice['balance_due'] ?? 0))),
                        date('Y-m-d H:i:s'),
                        $actorId,
                        $actorType,
                        'Marked paid by commercial automation'
                    );
                    return ['action' => 'mark_paid', 'status' => $marked ? 'paid' : 'unchanged', 'invoice_id' => $invoice['id']];
                }
                break;
        }

        return ['action' => $action['action'], 'status' => 'skipped'];
    }

    private function logRun(int $workspaceId, ?int $dealId, ?int $contactId, ?int $invoiceId, string $triggerType, ?int $triggerRefId, string $decision, array $actions, array $evidence, array $policy): int
    {
        Database::execute(
            "INSERT INTO commercial_automation_runs
                (workspace_id, deal_id, contact_id, invoice_id, trigger_type, trigger_ref_id, decision, action_plan_json, evidence_json, policy_snapshot_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $dealId, $contactId, $invoiceId, $triggerType, $triggerRefId, $decision, json_encode($actions), json_encode($evidence), json_encode($policy)]
        );
        return (int) Database::lastInsertId();
    }

    private function recordLearningArtifacts(int $runId, array $executions): void
    {
        if ($runId <= 0 || $executions === []) {
            return;
        }

        foreach ($executions as $execution) {
            $action = (array) ($execution['action'] ?? []);
            $context = (array) ($execution['context'] ?? []);
            $result = (array) ($execution['result'] ?? []);
            $actorType = (string) ($execution['actor_type'] ?? 'system');
            $actorId = !empty($execution['actor_id']) ? (int) $execution['actor_id'] : null;
            $approvalId = !empty($execution['approval_id']) ? (int) $execution['approval_id'] : null;

            $demoId = $this->demonstrations->recordCommercialAction(
                $action,
                $context,
                $result,
                $actorType,
                $actorId,
                $runId,
                $approvalId
            );

            $this->tenantPolicyMemory->recordActionObservation([
                'tenant_key' => $this->demonstrations->resolveTenantKey($context, $actorId),
                'scope_key' => 'commercial_mvp',
                'action_key' => (string) ($action['action'] ?? 'commercial_action'),
                'channel' => (string) ($context['channel'] ?? ''),
                'document_type' => (string) ($context['document_type'] ?? ($context['invoice']['document_type'] ?? '')),
                'actor_user_id' => $actorId,
                'was_successful' => in_array((string) ($result['status'] ?? ''), [
                    'sent',
                    'converted',
                    'finalized',
                    'created',
                    'revised',
                    'paid',
                    'overdue',
                    'cancelled',
                ], true),
                'was_reversed' => false,
                'was_edited' => false,
                'observed_at' => date('Y-m-d H:i:s'),
                'demonstration_id' => $demoId,
            ]);
        }
    }

    private function acquireIdempotencyLock(array $deal, ?array $invoice, string $actionKey, array $action): bool
    {
        $scopeKey = 'deal:' . (int) ($deal['id'] ?? 0) . ':invoice:' . (int) (($invoice['id'] ?? 0));
        $fingerprint = sha1(json_encode([$actionKey, $action]));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        try {
            Database::execute(
                "INSERT INTO commercial_automation_idempotency (scope_key, action_key, fingerprint, expires_at)
                 VALUES (?, ?, ?, ?)",
                [$scopeKey, $actionKey, $fingerprint, $expiresAt]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function createExceptionTask(array $deal, ?array $invoice, string $reason): void
    {
        $taskTitle = 'Review commercial automation exception';
        if ($invoice) {
            $taskTitle .= ': ' . ($invoice['invoice_number'] ?? ('invoice #' . $invoice['id']));
        }
        $assignment = $this->taskAssignmentAccess->resolveAiAssignee([
            (int) ($deal['assigned_to'] ?? 0),
            (int) ($deal['created_by'] ?? 0),
        ], [
            'title' => $taskTitle,
            'description' => $reason,
            'metadata_json' => [
                'source_surface' => 'commercial_automation',
                'task_intent' => 'review',
            ],
        ]);
        (new Tasks())->create([
            'title' => $taskTitle,
            'description' => $reason,
            'contact_id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
            'assigned_to' => $assignment['assigned_to'],
            'assignment_mode' => 'ai',
            'created_by' => (int) ($deal['created_by'] ?? 0),
            'priority' => 'high',
            'status' => 'pending',
            'origin_type' => 'automation',
            'completion_mode' => 'review',
            'automation_dedupe_key' => 'commercial_exception:' . hash('sha256', implode('|', [
                (int) ($deal['id'] ?? 0),
                (int) ($invoice['id'] ?? 0),
                $reason,
            ])),
            'metadata_json' => [
                'source_surface' => 'commercial_automation',
                'task_intent' => 'review',
                'assignment_resolution' => $assignment,
            ],
        ]);
    }

    private function shouldRevise(array $deal, ?array $invoice, array $evidence): bool
    {
        if (($deal['stage'] ?? '') !== 'negotiation' || !$invoice) {
            return false;
        }
        return !empty($evidence['communications']) || !empty($evidence['quote_sent']);
    }

    private function isNegotiationStale(array $deal, ?array $invoice, array $policy): bool
    {
        if (($deal['stage'] ?? '') !== 'negotiation' || !$invoice) {
            return false;
        }
        $hours = (int) ($policy['commercial']['negotiation_stale_hours'] ?? 48);
        $lastSent = $invoice['last_sent_at'] ?? $invoice['updated_at'] ?? null;
        return $lastSent ? (time() - strtotime((string) $lastSent)) >= ($hours * 3600) : false;
    }

    private function isProposalFollowupStale(array $deal, ?array $invoice, array $policy): bool
    {
        if (!in_array((string) ($deal['stage'] ?? ''), ['proposal', 'negotiation'], true) || !$invoice) {
            return false;
        }
        $hours = (int) ($policy['commercial']['proposal_followup_hours'] ?? 24);
        $lastSent = $invoice['last_sent_at'] ?? null;
        return $lastSent ? (time() - strtotime((string) $lastSent)) >= ($hours * 3600) : false;
    }

    private function shouldMarkOverdue(?array $invoice): bool
    {
        if (!$invoice || ($invoice['document_type'] ?? '') !== 'invoice') {
            return false;
        }
        if (in_array((string) ($invoice['status'] ?? ''), ['paid', 'cancelled', 'overdue'], true)) {
            return false;
        }
        $dueDate = $invoice['due_date'] ?? null;
        return $dueDate && strtotime((string) $dueDate) < strtotime(date('Y-m-d')) && (float) ($invoice['balance_due'] ?? 0) > 0;
    }

    private function shouldMarkPaid(array $deal, ?array $invoice, array $evidence): bool
    {
        if (!$invoice || ($invoice['document_type'] ?? '') !== 'invoice') {
            return false;
        }
        if (!in_array((string) ($deal['stage'] ?? ''), ['closed_won'], true)) {
            return false;
        }
        if ((float) ($invoice['balance_due'] ?? 0) <= 0) {
            return false;
        }
        return $this->detectPaymentSignal($evidence);
    }

    private function hasZeroPricedRecommendation(array $suggestions): bool
    {
        foreach ($suggestions as $suggestion) {
            if ((float) ($suggestion['unit_price'] ?? 0) <= 0) {
                return true;
            }
        }
        return false;
    }

    private function extractRequestedDiscountPercent(?array $invoice): float
    {
        if (!$invoice) {
            return 0.0;
        }
        $discounts = [];
        foreach (($invoice['line_items'] ?? []) as $lineItem) {
            $discounts[] = (float) ($lineItem['discount_percent'] ?? 0);
        }
        return $discounts ? max($discounts) : 0.0;
    }

    private function hasRecentSend(?array $invoice, array $policy): bool
    {
        if (!$invoice) {
            return false;
        }
        $cooldownMinutes = (int) (($policy['commercial']['resend_cooldown_minutes'] ?? 180));
        if ($cooldownMinutes <= 0) {
            return false;
        }
        $lastSent = $invoice['last_sent_at'] ?? null;
        if (!$lastSent) {
            return false;
        }
        return (time() - strtotime((string) $lastSent)) < ($cooldownMinutes * 60);
    }

    private function isWithinWhatsAppWindow(?array $invoice): bool
    {
        $contactId = (int) ($invoice['contact_id'] ?? 0);
        if ($contactId <= 0) {
            return false;
        }
        try {
            return (new WhatsAppService())->isWithin24HourWindow($contactId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function estimateActionConfidence(string $action, array $context): float
    {
        $evidence = (array) ($context['evidence'] ?? []);
        $score = 0.78;

        if (!empty($context['recipient'])) {
            $score += 0.04;
        }
        if (empty($evidence['has_missing_pricing']) && empty($context['has_zero_priced_recommendation'])) {
            $score += 0.03;
        }
        if (!empty($evidence['bidirectional_exchange'])) {
            $score += 0.03;
        }
        if (!empty($evidence['delivery_failure_count'])) {
            $score -= 0.05;
        }

        switch ($action) {
            case 'create_draft':
                $score += 0.06;
                break;
            case 'revise_document':
                if (!empty($context['needs_revision']) || !empty($context['stale_revision'])) {
                    $score += 0.08;
                }
                break;
            case 'send_document':
                if (!empty($evidence['quote_created']) || !empty($evidence['invoice_sent']) || !empty($context['invoice']['id'])) {
                    $score += 0.08;
                }
                break;
            case 'resend_document':
                if (!empty($context['stale_followup']) || !empty($context['stale_revision'])) {
                    $score += 0.10;
                }
                break;
            case 'convert_to_invoice':
                if (in_array((string) ($context['invoice']['status'] ?? ''), ['accepted', 'sent', 'viewed'], true)) {
                    $score += 0.10;
                }
                break;
            case 'finalize_invoice':
                if (($context['invoice']['document_type'] ?? '') === 'invoice') {
                    $score += 0.10;
                }
                break;
            case 'mark_paid':
                if (!empty($context['payment_signal_detected'])) {
                    $score += 0.18;
                }
                break;
        }

        return max(0.0, min(0.995, round($score, 3)));
    }

    private function detectPaymentSignal(array $evidence): bool
    {
        foreach ((array) ($evidence['communications'] ?? []) as $communication) {
            if (($communication['direction'] ?? '') !== 'inbound') {
                continue;
            }
            $intent = strtolower((string) ($communication['intent'] ?? ''));
            $text = strtolower((string) (($communication['subject'] ?? '') . ' ' . ($communication['body_preview'] ?? '')));
            if (str_contains($intent, 'payment') || str_contains($intent, 'paid')) {
                return true;
            }
            foreach (['paid', 'payment sent', 'proof of payment', 'bank transfer', 'transaction receipt', 'we have paid'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return true;
                }
            }
        }
        return false;
    }
}
