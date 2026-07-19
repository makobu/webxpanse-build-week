<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Invoices;

class EmailAssistantExecutionService
{
    private Invoices $invoices;
    private EmailAssistantDraftService $draftService;
    private CommercialAutomationApprovalService $approvalService;
    private CommercialAutomationOrchestrator $commercialAutomation;
    private EmailAssistantPolicyBridge $policyBridge;
    private AIDecisionOutcomeService $outcomes;
    private AIOutcomeClassifier $classifier;
    private AIDemonstrationCaptureService $capture;
    private AIAutonomyScoringService $scoring;
    private CustomerThreadAutonomyService $customerThreadAutonomy;
    private AIWorkspaceScopeService $aiWorkspaceScope;
    private WorkspaceScopeService $workspaceScope;
    private string $assistantType;
    private ?bool $runsHaveAssistantType = null;
    private ?bool $queueHasAssistantType = null;
    private ?bool $queueSupportsWhatsappChannel = null;

    public function __construct(string $assistantType = AssistantActionRuntimeConfig::ASSISTANT_EMAIL)
    {
        $this->assistantType = AssistantActionRuntimeConfig::normalizeType($assistantType);
        $this->invoices = new Invoices();
        $this->draftService = new EmailAssistantDraftService();
        $this->approvalService = new CommercialAutomationApprovalService();
        $this->commercialAutomation = new CommercialAutomationOrchestrator();
        $this->policyBridge = new EmailAssistantPolicyBridge();
        $this->outcomes = new AIDecisionOutcomeService();
        $this->classifier = new AIOutcomeClassifier();
        $this->capture = new AIDemonstrationCaptureService();
        $this->scoring = new AIAutonomyScoringService();
        $this->customerThreadAutonomy = new CustomerThreadAutonomyService();
        $this->aiWorkspaceScope = new AIWorkspaceScopeService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function execute(array $plan, array $context, int $userId): array
    {
        $entities = $context['primary_entities'] ?? [];
        $invoice = $entities['invoice'] ?? null;
        $deal = $entities['deal'] ?? null;
        $contact = $entities['contact'] ?? null;
        $workspaceId = $this->resolveWorkspaceId($contact, $deal, $invoice, $context, $entities['approval'] ?? null);
        $context['workspace_id'] = $workspaceId;
        $context['tenant_key'] = $this->aiWorkspaceScope->workspaceTenantKey($workspaceId);
        $assistantContext = $this->assistantContext($context, 'admin_command', 'email_assistant_inbound');
        $context = array_merge($context, $assistantContext);
        $context['mode'] = 'admin_command';

        $runId = $this->createRun($workspaceId, 'admin_command', $assistantContext['assistant_source'], $userId, $contact, $deal, $invoice, $context, $plan);
        $this->storeResolutions($workspaceId, $runId, $context);

        $results = [];
        $resolutionStatus = (string) ($plan['resolution_status'] ?? 'resolved');
        $executionStatus = empty($plan['actions']) ? 'rejected' : 'executed';

        foreach ($plan['actions'] ?? [] as $action) {
            $key = (string) ($action['action'] ?? '');
            $decision = $this->evaluateAdminActionDecision($action, $deal, $invoice, $contact, $context, $userId);
            if (($decision['decision'] ?? '') === 'approval_required') {
                $approvalId = $this->queueAdminApproval($key, $deal, $invoice, $context, $plan, $userId, $decision);
                $results[] = ['action' => $key, 'status' => 'approval_required', 'approval_id' => $approvalId, 'policy' => $decision];
                $resolutionStatus = 'approval_required';
                continue;
            }
            if (($decision['decision'] ?? '') === 'blocked') {
                $results[] = ['action' => $key, 'status' => 'blocked', 'reason' => implode(', ', (array) ($decision['reasons'] ?? ['Blocked by policy'])), 'policy' => $decision];
                $resolutionStatus = 'blocked';
                $executionStatus = 'rejected';
                continue;
            }
            if (($decision['decision'] ?? '') === 'suggest_only') {
                $results[] = ['action' => $key, 'status' => 'suggest_only', 'reason' => implode(', ', (array) ($decision['reasons'] ?? ['Action downgraded to suggest-only'])), 'policy' => $decision];
                $resolutionStatus = 'blocked';
                $executionStatus = 'rejected';
                continue;
            }

            switch ($key) {
                case 'create_draft':
                    if ($deal) {
                        $invoiceId = $this->invoices->createDraftForStage((int) $deal['id'], (string) $deal['stage'], (string) ($action['document_type'] ?? 'quote'), 'ai', $userId);
                        $invoice = $this->invoices->getById($invoiceId);
                        $results[] = ['action' => $key, 'invoice_id' => $invoiceId, 'status' => 'created', 'policy' => $decision];
                        $this->captureAssistantAction($key, $deal, $invoice, $contact, $context, $userId, 'admin_command', 'created');
                    }
                    break;
                case 'revise_document':
                    if ($invoice) {
                        $updatedId = $this->invoices->reviseFromRecommendations((int) $invoice['id'], ['suggested_products' => $invoice['suggested_products'] ?? []], 'ai', $userId);
                        $invoice = $this->invoices->getById($updatedId);
                        $results[] = ['action' => $key, 'invoice_id' => $updatedId, 'status' => 'revised', 'policy' => $decision];
                        $this->captureAssistantAction($key, $deal, $invoice, $contact, $context, $userId, 'admin_command', 'revised');
                    }
                    break;
                case 'send_document':
                    if ($invoice) {
                        $commercialResult = $this->commercialAutomation->runAssistantPlannedCommercialAction($action, [
                            'deal' => $deal ?: [],
                            'invoice' => $invoice,
                            'surface' => 'admin_command',
                            'assistant_confidence' => (float) ($context['confidence'] ?? 1.0),
                            'contact' => $contact ?: [],
                        ], $userId);
                        $results[] = ['action' => $key, 'invoice_id' => $invoice['id'], 'status' => (string) ($commercialResult['status'] ?? 'executed'), 'policy' => $decision, 'result' => $commercialResult];
                        $this->captureAssistantAction($key, $deal, $invoice, $contact, $context, $userId, 'admin_command', (string) ($commercialResult['status'] ?? 'executed'));
                    }
                    break;
                case 'finalize_invoice':
                    if ($invoice) {
                        $this->invoices->transitionStatus((int) $invoice['id'], 'finalized', $userId, 'ai', 'Finalized by assistant');
                        $results[] = ['action' => $key, 'invoice_id' => $invoice['id'], 'status' => 'finalized', 'policy' => $decision];
                        $this->captureAssistantAction($key, $deal, $invoice, $contact, $context, $userId, 'admin_command', 'finalized');
                    }
                    break;
                case 'convert_to_invoice':
                    if ($invoice) {
                        $newId = $this->invoices->convertToInvoice((int) $invoice['id'], $userId, 'ai');
                        $invoice = $this->invoices->getById($newId);
                        $results[] = ['action' => $key, 'invoice_id' => $newId, 'status' => 'converted', 'policy' => $decision];
                        $this->captureAssistantAction($key, $deal, $invoice, $contact, $context, $userId, 'admin_command', 'converted');
                    }
                    break;
                case 'mark_invoice_paid':
                    if ($invoice) {
                        $amount = (float) ($invoice['balance_due'] ?? $invoice['grand_total'] ?? 0);
                        $this->invoices->markPaid((int) $invoice['id'], $amount, date('Y-m-d H:i:s'), $userId, 'ai', 'Marked paid by assistant');
                        $results[] = ['action' => $key, 'invoice_id' => $invoice['id'], 'status' => 'paid', 'policy' => $decision];
                        $this->captureAssistantAction($key, $deal, $invoice, $contact, $context, $userId, 'admin_command', 'paid');
                    }
                    break;
                case 'approve_commercial_action':
                    if (!empty($entities['approval']['id'])) {
                        $result = $this->commercialAutomation->executeApprovedAction((int) $entities['approval']['id'], $userId);
                        $results[] = ['action' => $key, 'status' => $result ? 'approved' : 'not_found', 'policy' => $decision];
                    }
                    break;
                case 'reject_commercial_action':
                    if (!empty($entities['approval']['id'])) {
                        $result = $this->approvalService->reject((int) $entities['approval']['id'], $userId, 'Rejected by assistant');
                        $results[] = ['action' => $key, 'status' => $result ? 'rejected' : 'not_found', 'policy' => $decision];
                    }
                    break;
                default:
                    $results[] = ['action' => $key, 'status' => 'delegated', 'policy' => $decision];
                    break;
            }
        }

        $this->updateRun($runId, $resolutionStatus, $executionStatus, $results, $workspaceId);

        return [
            'run_id' => $runId,
            'resolution_status' => $resolutionStatus,
            'execution_status' => $executionStatus,
            'results' => $results,
        ];
    }

    public function executeCustomerReply(array $plan, array $context, int $userId): array
    {
        $thread = $context['thread_context'] ?? [];
        $contact = $thread['contact'] ?? ($context['primary_entities']['contact'] ?? null);
        $deal = $thread['deal'] ?? ($context['primary_entities']['deal'] ?? null);
        $invoice = $thread['invoice'] ?? ($context['primary_entities']['invoice'] ?? null);
        $threadAssessment = $this->customerThreadAutonomy->assess($thread, $contact, $deal, $invoice);
        $workspaceId = $this->resolveWorkspaceId($contact, $deal, $invoice, $context, $context['primary_entities']['approval'] ?? null);
        $context['workspace_id'] = $workspaceId;
        $context['tenant_key'] = $this->aiWorkspaceScope->workspaceTenantKey($workspaceId);
        $assistantContext = $this->assistantContext($context, 'customer_thread', 'conversation_ui');
        $context = array_merge($context, $assistantContext);
        $context['mode'] = 'customer_thread';

        $runId = $this->createRun($workspaceId, 'customer_thread', $assistantContext['assistant_source'], $userId, $contact, $deal, $invoice, $context, $plan);
        $this->storeResolutions($workspaceId, $runId, $context);

        $draft = null;
        $results = [];
        $resolutionStatus = (string) ($plan['resolution_status'] ?? 'resolved');
        $executionStatus = 'executed';
        foreach ($plan['actions'] ?? [] as $action) {
            $key = (string) ($action['action'] ?? '');
            $decision = $this->evaluateCustomerActionDecision($action, $deal, $invoice, $contact, $thread, $context, $userId, $threadAssessment);
            if (($decision['decision'] ?? '') === 'approval_required') {
                $queueId = $this->queueForApproval($plan, $context, $userId, $runId, implode(', ', (array) ($decision['reasons'] ?? ['Approval required'])), $workspaceId);
                $results[] = ['action' => $key, 'status' => 'approval_required', 'queue_id' => $queueId, 'policy' => $decision];
                $resolutionStatus = 'approval_required';
                continue;
            }
            if (($decision['decision'] ?? '') === 'blocked') {
                $results[] = ['action' => $key, 'status' => 'blocked', 'reason' => implode(', ', (array) ($decision['reasons'] ?? ['Blocked by policy'])), 'policy' => $decision];
                $resolutionStatus = 'blocked';
                $executionStatus = 'rejected';
                continue;
            }
            if (($decision['decision'] ?? '') === 'suggest_only' && $key !== 'draft_customer_reply') {
                $results[] = ['action' => $key, 'status' => 'suggest_only', 'reason' => implode(', ', (array) ($decision['reasons'] ?? ['Suggest-only by policy'])), 'policy' => $decision];
                $resolutionStatus = 'blocked';
                $executionStatus = 'rejected';
                continue;
            }

            if ($key === 'create_draft' && $deal) {
                $invoiceId = $this->invoices->createDraftForStage((int) $deal['id'], (string) $deal['stage'], (string) ($action['document_type'] ?? 'quote'), 'ai', $userId);
                $invoice = $this->invoices->getById($invoiceId);
                $results[] = ['action' => $key, 'status' => 'created', 'invoice_id' => $invoiceId, 'policy' => $decision];
                $this->captureAssistantAction($key, $deal, $invoice, $contact, array_merge($context, ['thread_assessment' => $threadAssessment]), $userId, 'customer_thread', 'created');
            } elseif ($key === 'revise_document' && $invoice) {
                $updatedId = $this->invoices->reviseFromRecommendations((int) $invoice['id'], ['suggested_products' => $invoice['suggested_products'] ?? []], 'ai', $userId);
                $invoice = $this->invoices->getById($updatedId);
                $results[] = ['action' => $key, 'status' => 'revised', 'invoice_id' => $updatedId, 'policy' => $decision];
                $this->captureAssistantAction($key, $deal, $invoice, $contact, array_merge($context, ['thread_assessment' => $threadAssessment]), $userId, 'customer_thread', 'revised');
            } elseif ($key === 'draft_customer_reply') {
                $draftContext = array_merge($thread, ['contact' => $contact, 'deal' => $deal, 'invoice' => $invoice, 'actor_user_id' => $userId]);
                $purpose = (string) ($action['purpose'] ?? 'proposal_reply');
                $draft = match ($purpose) {
                    'invoice_reply' => $this->draftService->draftInvoiceReply($draftContext),
                    'negotiation_reply' => $this->draftService->draftNegotiationReply($draftContext),
                    'overdue_reminder' => $this->draftService->draftOverdueReminder($draftContext),
                    default => $this->draftService->draftProposalReply($draftContext),
                };
                if (($decision['decision'] ?? '') === 'suggest_only') {
                    $draft['explanation'] = trim(((string) ($draft['explanation'] ?? '')) . "\nQualification warning: " . implode(', ', (array) ($decision['reasons'] ?? [])));
                }
                $results[] = ['action' => $key, 'status' => 'drafted', 'policy' => $decision];
            } elseif ($key === 'send_customer_reply' && $draft) {
                $sendResult = $this->sendCustomerEmail($draft, $contact ?: [], $thread, $context, $userId);
                $results[] = ['action' => $key, 'status' => $sendResult['status'], 'email_uuid' => $sendResult['email_uuid'] ?? null, 'policy' => $decision];
                $this->captureAssistantAction($key, $deal, $invoice, $contact, array_merge($context, ['thread_assessment' => $threadAssessment]), $userId, 'customer_thread', (string) ($sendResult['status'] ?? 'sent'));
            }
        }

        $this->updateRun($runId, $resolutionStatus, $executionStatus, ['draft' => $draft, 'actions' => $results], $workspaceId);
        if ($draft && !empty($results)) {
            foreach ($results as $result) {
                if (($result['action'] ?? '') === 'send_customer_reply' && ($result['status'] ?? '') === 'sent') {
                    $runRow = Database::queryOne(
                        "SELECT * FROM email_assistant_runs WHERE id = ? AND workspace_id = ?",
                        [$runId, $workspaceId]
                    ) ?: [];
                    $classification = $this->classifier->classifyAssistantDraftOutcome($runRow, [
                        'draft' => $draft,
                        'sent_body' => (string) ($result['sent_body'] ?? $draft['plain_body'] ?? ''),
                    ]);
                    $this->outcomes->recordAssistantOutcome($runRow, $classification);
                }
            }
        }

        return [
            'run_id' => $runId,
            'resolution_status' => $resolutionStatus,
            'execution_status' => $executionStatus,
            'policy_decision' => (string) ($plan['policy_decision'] ?? ''),
            'policy_reasons' => (array) ($plan['policy_reasons'] ?? []),
            'policy_warnings' => (array) ($plan['policy_warnings'] ?? []),
            'draft' => $draft,
            'results' => $results,
        ];
    }

    public function queueForApproval(array $plan, array $context, int $userId, ?int $runId = null, string $reason = 'Approval required', ?int $workspaceId = null): int
    {
        $workspaceId = $workspaceId ?: $this->workspaceScope->requireActiveWorkspaceId();
        $assistantContext = $this->assistantContext($context, 'customer_thread', 'manual');
        $context = array_merge($context, $assistantContext);
        $context['mode'] = 'customer_thread';
        if ($runId === null) {
            $runId = $this->createRun($workspaceId, 'customer_thread', $assistantContext['assistant_source'], $userId, null, null, null, $context, $plan);
        }

        $payload = json_encode(['plan' => $plan, 'context' => $context, 'reason' => $reason]);
        $channel = $this->queueSupportsWhatsappChannel()
            ? (string) ($assistantContext['assistant_channel'] ?? 'email')
            : 'email';
        if ($this->queueHasAssistantTypeColumn()) {
            Database::execute(
                "INSERT INTO email_assistant_action_queue (workspace_id, assistant_type, run_id, action_key, status, channel, payload_json, scheduled_at)
                 VALUES (?, ?, ?, 'customer_reply', 'pending', ?, ?, NOW())",
                [$workspaceId, $assistantContext['assistant_type'], $runId, $channel, $payload]
            );
            return (int) Database::lastInsertId();
        }

        Database::execute(
            "INSERT INTO email_assistant_action_queue (workspace_id, run_id, action_key, status, channel, payload_json, scheduled_at)
             VALUES (?, ?, 'customer_reply', 'pending', ?, ?, NOW())",
            [$workspaceId, $runId, $channel, $payload]
        );
        return (int) Database::lastInsertId();
    }

    private function sendCustomerEmail(array $draft, array $contact, array $thread, array $context, int $userId): array
    {
        $to = trim((string) ($contact['email'] ?? ''));
        if ($to === '') {
            $to = trim((string) ($thread['reply_target_email'] ?? ''));
        }
        if ($to === '' && !empty($thread['messages']) && is_array($thread['messages'])) {
            foreach (array_reverse($thread['messages']) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $candidate = trim((string) (($message['direction'] ?? '') === 'inbound'
                    ? ($message['from_email'] ?? '')
                    : ($message['to_email'] ?? '')));
                if ($candidate !== '') {
                    $to = $candidate;
                    break;
                }
            }
        }
        if ($to === '') {
            return ['status' => 'blocked', 'reason' => 'Contact email is missing'];
        }
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId(
            isset($context['workspace_id']) ? (int) $context['workspace_id'] : null
        );
        $emailService = new EmailService();
        $emailUuid = $emailService->send((int) ($contact['id'] ?? ($context['contact_id'] ?? 0)), $to, (string) $draft['subject'], (string) $draft['plain_body'], [
            'body_html' => $draft['html_body'] ?? nl2br(htmlspecialchars((string) ($draft['plain_body'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'sender_profile' => 'assistant',
        ]);
        try {
            $emailService->processEmailByUuid($emailUuid, workspaceId: $workspaceId);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }

        $sentEmail = Database::queryOne(
            "SELECT body FROM emails WHERE uuid = ? AND workspace_id = ? LIMIT 1",
            [$emailUuid, $workspaceId]
        );
        return [
            'status' => 'sent',
            'email_uuid' => $emailUuid,
            'sent_body' => (string) ($sentEmail['body'] ?? $draft['plain_body'] ?? ''),
        ];
    }

    private function createRun(int $workspaceId, string $mode, string $source, int $userId, ?array $contact, ?array $deal, ?array $invoice, array $context, array $plan): int
    {
        $assistantContext = $this->assistantContext($context, $mode, $source);
        $threadType = (string) ($assistantContext['assistant_thread_type'] ?? ($mode === 'customer_thread' ? 'customer_email' : 'email_assistant'));
        $source = (string) ($assistantContext['assistant_source'] ?? $source);

        if ($this->runsHaveAssistantTypeColumn()) {
            Database::execute(
                "INSERT INTO email_assistant_runs
                    (workspace_id, assistant_type, mode, source, user_id, contact_id, deal_id, invoice_id, thread_type, source_message_id, intent, resolution_status, execution_status, confidence_score, context_snapshot_json, plan_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned', ?, ?, ?)",
                [
                    $workspaceId,
                    $assistantContext['assistant_type'],
                    $mode,
                    $source,
                    $userId,
                    $contact['id'] ?? null,
                    $deal['id'] ?? null,
                    $invoice['id'] ?? null,
                    $threadType,
                    $context['thread_context']['communication_id'] ?? null,
                    $context['intent'] ?? null,
                    $plan['resolution_status'] ?? 'resolved',
                    $plan['confidence'] ?? 0,
                    json_encode($context),
                    json_encode($plan),
                ]
            );
            return (int) Database::lastInsertId();
        }

        if (!in_array($source, ['email_assistant_inbound', 'conversation_ui', 'commercial_automation', 'manual'], true)) {
            $source = $mode === 'customer_thread' ? 'conversation_ui' : 'email_assistant_inbound';
        }
        if (!in_array($threadType, ['email_assistant', 'customer_email'], true)) {
            $threadType = $mode === 'customer_thread' ? 'customer_email' : 'email_assistant';
        }

        Database::execute(
            "INSERT INTO email_assistant_runs
                (workspace_id, mode, source, user_id, contact_id, deal_id, invoice_id, thread_type, source_message_id, intent, resolution_status, execution_status, confidence_score, context_snapshot_json, plan_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planned', ?, ?, ?)",
            [
                $workspaceId,
                $mode,
                $source,
                $userId,
                $contact['id'] ?? null,
                $deal['id'] ?? null,
                $invoice['id'] ?? null,
                $threadType,
                $context['thread_context']['communication_id'] ?? null,
                $context['intent'] ?? null,
                $plan['resolution_status'] ?? 'resolved',
                $plan['confidence'] ?? 0,
                json_encode($context),
                json_encode($plan),
            ]
        );
        return (int) Database::lastInsertId();
    }

    private function updateRun(int $runId, string $resolutionStatus, string $executionStatus, array $result, ?int $workspaceId = null): void
    {
        $sql = "UPDATE email_assistant_runs SET resolution_status = ?, execution_status = ?, result_json = ? WHERE id = ?";
        $params = [$resolutionStatus, $executionStatus, json_encode($result), $runId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }

        Database::execute($sql, $params);
    }

    private function storeResolutions(int $workspaceId, int $runId, array $context): void
    {
        $entities = $context['primary_entities'] ?? [];
        foreach (['contact', 'deal', 'invoice', 'approval'] as $entityType) {
            $entity = $entities[$entityType] ?? null;
            if (!$entity) {
                continue;
            }
            Database::execute(
                "INSERT INTO email_assistant_resolutions (workspace_id, run_id, entity_type, query_text, resolved_id, resolution_method, candidate_json, confidence_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $runId,
                    $entityType,
                    (string) ($context['query'] ?? ''),
                    $entity['id'] ?? null,
                    (string) ($context['resolution_method'][$entityType] ?? 'exact_id'),
                    json_encode($context['candidates'][$entityType] ?? [$entity]),
                    (float) ($context['confidence'] ?? 0),
                ]
            );
        }
    }

    private function evaluateAdminActionDecision(array $action, ?array $deal, ?array $invoice, ?array $contact, array $context, int $userId): array
    {
        return $this->evaluatePolicyDecision(
            $action,
            [
                'deal' => $deal ?: [],
                'invoice' => $invoice ?: [],
                'contact' => $contact ?: [],
                'surface' => 'admin_command',
                'assistant_confidence' => (float) ($context['confidence'] ?? 1.0),
                'recipient' => (string) ($invoice['billing_email'] ?? $invoice['contact_email'] ?? ''),
                'channel' => 'email',
            ] + $this->assistantPolicyContext($context),
            $userId
        );
    }

    private function evaluateCustomerActionDecision(array $action, ?array $deal, ?array $invoice, ?array $contact, array $thread, array $context, int $userId, array $threadAssessment = []): array
    {
        return $this->evaluatePolicyDecision(
            $action,
            $this->customerThreadAutonomy->augmentPolicyContext([
                'deal' => $deal ?: [],
                'invoice' => $invoice ?: [],
                'contact' => $contact ?: [],
                'surface' => 'customer_thread',
                'thread_context' => $thread,
                'thread_context_present' => !empty($thread),
                'assistant_confidence' => (float) ($context['confidence'] ?? 1.0),
                'recipient' => (string) ($contact['email'] ?? $invoice['billing_email'] ?? $invoice['contact_email'] ?? ''),
                'channel' => 'email',
                'requires_customer_send' => (string) ($action['action'] ?? '') === 'send_customer_reply',
            ] + $this->assistantPolicyContext($context), $threadAssessment),
            $userId
        );
    }

    private function evaluatePolicyDecision(array $action, array $baseContext, int $userId): array
    {
        if (!empty($action['policy']) && is_array($action['policy'])) {
            return $action['policy'];
        }

        $context = array_merge($baseContext, [
            'document_type' => (string) ($action['document_type'] ?? ($baseContext['invoice']['document_type'] ?? '')),
            'assistant_requested_action' => (string) ($action['action'] ?? ''),
        ]);
        $name = (string) ($action['action'] ?? '');

        if ($name === 'send_customer_reply') {
            return $this->policyBridge->evaluateCustomerReplySend($context, $userId);
        }
        if (in_array($name, ['send_document', 'revise_document', 'convert_to_invoice', 'finalize_invoice', 'create_draft'], true)) {
            return $this->policyBridge->evaluateCommercialAssistantAction($name, $context, $userId);
        }
        if (in_array($name, ['draft_customer_reply', 'summarize_thread_state', 'explain_quote_changes', 'summarize_commercial_automation_state', 'list_pending_commercial_approvals', 'show_last_assistant_action'], true)) {
            return $this->policyBridge->evaluateAssistantAdvice((string) ($context['surface'] ?? 'admin_command'), $context, $userId);
        }

        return $this->policyBridge->evaluateAssistantAction($name, $context, $userId);
    }

    private function queueAdminApproval(string $actionKey, ?array $deal, ?array $invoice, array $context, array $plan, int $userId, array $decision): int
    {
        return $this->approvalService->requestApproval(
            !empty($deal['id']) ? (int) $deal['id'] : null,
            !empty($invoice['id']) ? (int) $invoice['id'] : null,
            $actionKey,
            implode(', ', (array) ($decision['reasons'] ?? ['Approval required'])),
            [
                'context' => $context,
                'plan' => $plan,
                'policy' => $decision,
                'assistant_type' => (string) ($context['assistant_type'] ?? $this->assistantType),
                'assistant_source' => (string) ($context['assistant_source'] ?? ''),
                'assistant_channel' => (string) ($context['assistant_channel'] ?? ''),
            ],
            'user',
            $userId
        );
    }

    private function captureAssistantAction(string $actionKey, ?array $deal, ?array $invoice, ?array $contact, array $context, int $userId, string $surface, string $status): void
    {
        $workspaceId = $this->resolveWorkspaceId($contact, $deal, $invoice, $context);
        $tenantKey = $this->aiWorkspaceScope->workspaceTenantKey($workspaceId);
        $domainKey = $surface === 'customer_thread' ? 'customer_thread' : 'commercial_mvp';
        $score = $this->scoring->score($tenantKey, $domainKey, $actionKey, [
            'deal' => $deal ?: [],
            'invoice' => $invoice ?: [],
            'document_type' => $invoice['document_type'] ?? null,
            'channel' => 'email',
            'surface' => $surface,
            'assistant_confidence' => (float) ($context['confidence'] ?? 0.8),
            'thread_completeness' => (float) (($context['thread_assessment']['thread_completeness'] ?? 0.0)),
            'thread_response_latency_hours' => (int) (($context['thread_assessment']['thread_response_latency_hours'] ?? 0)),
        ]);
        $this->capture->capture([
            'tenant_key' => $tenantKey,
            'workspace_id' => $workspaceId,
            'actor_user_id' => $userId,
            'actor_type' => 'ai',
            'source_surface' => $surface,
            'domain_key' => $domainKey,
            'entity_type' => $invoice ? 'invoice' : ($deal ? 'deal' : 'contact'),
            'entity_id' => $invoice['id'] ?? $deal['id'] ?? $contact['id'] ?? null,
            'related_entity_type' => $deal ? 'deal' : null,
            'related_entity_id' => $deal['id'] ?? null,
            'action_key' => $actionKey,
            'prior_state' => ['deal' => $deal, 'invoice' => $invoice, 'contact' => $contact],
            'action_payload' => ['context' => $context],
            'outcome_state' => ['status' => $status],
            'outcome_label' => in_array($status, ['created', 'revised', 'sent', 'converted', 'finalized', 'paid'], true) ? 'accepted' : 'observed',
            'metadata' => [
                'channel' => 'email',
                'assistant_type' => (string) ($context['assistant_type'] ?? $this->assistantType),
                'assistant_source_channel' => (string) ($context['assistant_channel'] ?? ''),
                'assistant_source' => (string) ($context['assistant_source'] ?? ''),
                'document_type' => $invoice['document_type'] ?? null,
                'assistant_confidence' => $score['assistant_confidence'] ?? null,
                'confidence_basis' => $score['confidence_basis'] ?? [],
                'thread_caution_level' => $context['thread_assessment']['send_caution_level'] ?? null,
                'thread_goal' => $actionKey,
            ],
            'was_successful' => in_array($status, ['created', 'revised', 'sent', 'converted', 'finalized', 'paid'], true),
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array{assistant_type:string,assistant_source:string,assistant_channel:string,assistant_thread_type:string}
     */
    private function assistantContext(array $context, string $mode, string $defaultSource): array
    {
        $assistantType = AssistantActionRuntimeConfig::normalizeType((string) ($context['assistant_type'] ?? $this->assistantType));
        if ($defaultSource === 'email_assistant_inbound' && $assistantType === AssistantActionRuntimeConfig::ASSISTANT_WHATSAPP) {
            $defaultSource = 'whatsapp_assistant_inbound';
        }
        $source = trim((string) ($context['assistant_source'] ?? $defaultSource));
        $channel = trim((string) ($context['assistant_channel'] ?? $assistantType));
        $threadType = trim((string) ($context['assistant_thread_type'] ?? ''));
        if ($threadType === '') {
            $threadType = $mode === 'customer_thread'
                ? 'customer_email'
                : ($assistantType === AssistantActionRuntimeConfig::ASSISTANT_WHATSAPP ? 'whatsapp_assistant' : 'email_assistant');
        }

        return [
            'assistant_type' => $assistantType,
            'assistant_source' => $source !== '' ? $source : $defaultSource,
            'assistant_channel' => $channel !== '' ? $channel : $assistantType,
            'assistant_thread_type' => $threadType,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private function assistantPolicyContext(array $context): array
    {
        $assistantContext = $this->assistantContext($context, (string) ($context['mode'] ?? 'admin_command'), 'email_assistant_inbound');

        return [
            'assistant_type' => $assistantContext['assistant_type'],
            'assistant_source' => $assistantContext['assistant_source'],
            'assistant_source_channel' => $assistantContext['assistant_channel'],
        ];
    }

    private function runsHaveAssistantTypeColumn(): bool
    {
        if ($this->runsHaveAssistantType === null) {
            $this->runsHaveAssistantType = Database::columnExists('email_assistant_runs', 'assistant_type');
        }

        return $this->runsHaveAssistantType;
    }

    private function queueHasAssistantTypeColumn(): bool
    {
        if ($this->queueHasAssistantType === null) {
            $this->queueHasAssistantType = Database::columnExists('email_assistant_action_queue', 'assistant_type');
        }

        return $this->queueHasAssistantType;
    }

    private function queueSupportsWhatsappChannel(): bool
    {
        if ($this->queueSupportsWhatsappChannel !== null) {
            return $this->queueSupportsWhatsappChannel;
        }

        try {
            $row = Database::queryOne(
                "SELECT COLUMN_TYPE
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'email_assistant_action_queue'
                   AND COLUMN_NAME = 'channel'
                 LIMIT 1"
            );
            $this->queueSupportsWhatsappChannel = str_contains((string) ($row['COLUMN_TYPE'] ?? ''), "'whatsapp'");
        } catch (\Throwable $e) {
            $this->queueSupportsWhatsappChannel = false;
        }

        return $this->queueSupportsWhatsappChannel;
    }

    private function resolveWorkspaceId(?array $contact, ?array $deal, ?array $invoice, array $context = [], ?array $approval = null): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        if (!empty($contact['id'])) {
            $this->workspaceScope->assertSameWorkspace('contacts', (int) $contact['id'], $workspaceId);
        }
        if (!empty($deal['id'])) {
            $this->workspaceScope->assertSameWorkspace('deals', (int) $deal['id'], $workspaceId);
        }
        if (!empty($invoice['id'])) {
            $this->assertInvoiceWorkspace((int) $invoice['id'], $workspaceId);
        }
        if (!empty($approval['id'])) {
            $approvalRow = Database::queryOne(
                "SELECT workspace_id
                 FROM commercial_automation_approvals
                 WHERE workspace_id = ?
                   AND id = ?
                 LIMIT 1",
                [$workspaceId, (int) $approval['id']]
            );
            if (!$approvalRow) {
                throw new \RuntimeException('The requested approval does not belong to the active workspace.');
            }
        }

        return $workspaceId;
    }

    private function assertInvoiceWorkspace(int $invoiceId, int $workspaceId): void
    {
        $row = Database::queryOne(
            "SELECT COALESCE(i.workspace_id, d.workspace_id, c.workspace_id) AS workspace_id
             FROM invoices i
             LEFT JOIN deals d ON d.id = i.deal_id
             LEFT JOIN contacts c ON c.id = i.contact_id
             WHERE i.id = ?
             LIMIT 1",
            [$invoiceId]
        );
        if (!$row) {
            throw new \RuntimeException('The requested record was not found in the active workspace.');
        }
        if ((int) ($row['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('The requested record does not belong to the active workspace.');
        }
    }
}
