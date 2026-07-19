<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Invoices;

class EmailAssistantApplicationService
{
    private AIService $aiService;
    private AIContextAssemblyService $contextAssembly;
    private AIRetrievalQualityService $retrievalQuality;
    private EmailAssistantResolver $resolver;
    private EmailAssistantActionPlanner $planner;
    private EmailAssistantExecutionService $executionService;
    private EmailAssistantPolicyBridge $policyBridge;
    private EmailAssistantDraftService $draftService;
    private EmailAssistantRuntimeConfig $runtimeConfig;
    private CommercialAutomationOrchestrator $commercialAutomation;
    private Invoices $invoices;
    private string $assistantType;

    public function __construct(string $assistantType = AssistantActionRuntimeConfig::ASSISTANT_EMAIL)
    {
        $this->assistantType = AssistantActionRuntimeConfig::normalizeType($assistantType);
        $this->aiService = new AIService();
        $this->contextAssembly = new AIContextAssemblyService();
        $this->retrievalQuality = new AIRetrievalQualityService();
        $this->resolver = new EmailAssistantResolver();
        $this->planner = new EmailAssistantActionPlanner();
        $this->executionService = new EmailAssistantExecutionService($this->assistantType);
        $this->policyBridge = new EmailAssistantPolicyBridge();
        $this->draftService = new EmailAssistantDraftService();
        $this->runtimeConfig = new EmailAssistantRuntimeConfig($this->assistantType);
        $this->commercialAutomation = new CommercialAutomationOrchestrator();
        $this->invoices = new Invoices();
    }

    /**
     * @param array<string,mixed> $options
     * @return array{assistant_type:string,assistant_source:string,assistant_channel:string,assistant_thread_type:string}
     */
    private function assistantContext(string $mode, array $options = []): array
    {
        $assistantType = AssistantActionRuntimeConfig::normalizeType((string) ($options['assistant_type'] ?? $this->assistantType));
        $defaultSource = $mode === 'customer_thread'
            ? 'conversation_ui'
            : ($assistantType === AssistantActionRuntimeConfig::ASSISTANT_WHATSAPP ? 'whatsapp_assistant_inbound' : 'email_assistant_inbound');
        $source = trim((string) ($options['assistant_source'] ?? $defaultSource));
        $channel = trim((string) ($options['assistant_channel'] ?? $assistantType));
        $defaultThreadType = $mode === 'customer_thread'
            ? 'customer_email'
            : ($assistantType === AssistantActionRuntimeConfig::ASSISTANT_WHATSAPP ? 'whatsapp_assistant' : 'email_assistant');

        return [
            'assistant_type' => $assistantType,
            'assistant_source' => $source !== '' ? $source : $defaultSource,
            'assistant_channel' => $channel !== '' ? $channel : $assistantType,
            'assistant_thread_type' => (string) ($options['assistant_thread_type'] ?? $defaultThreadType),
        ];
    }

    public function handleAdminCommand(string $body, string $intent, int $userId, array $options = []): array
    {
        $assistantContext = $this->assistantContext('admin_command', $options);
        $resolved = $this->resolver->resolveFromAdminEmail($body, $userId);
        $resolved['query'] = $body;
        $resolved['intent'] = $intent;
        $resolved = array_merge($resolved, $assistantContext);

        if ($intent === 'summarize_thread_state') {
            $communicationId = $this->extractIntegerAfterKeyword($body, 'communication');
            if ($communicationId > 0) {
                return $this->handleThreadSummary($communicationId, $userId, $body, $assistantContext);
            }
        }

        if ($intent === 'draft_customer_reply' || $intent === 'send_customer_reply') {
            $communicationId = $this->extractIntegerAfterKeyword($body, 'communication');
            if ($communicationId > 0) {
                return $intent === 'send_customer_reply'
                    ? $this->handleCustomerThreadSend($communicationId, $userId, $assistantContext + ['goal' => 'send'])
                    : $this->handleCustomerThreadDraft($communicationId, $userId, $assistantContext + ['goal' => 'draft']);
            }
        }

        if (in_array($intent, ['summarize_commercial_automation_state', 'list_pending_commercial_approvals', 'show_last_assistant_action', 'explain_quote_changes', 'question'], true)) {
            return $this->handleAdviceRequest($resolved + ['body' => $body], $userId);
        }

        $plan = $this->planner->planAdminCommand($resolved, $intent, $body, $userId);
        if (($plan['actions'][0]['action'] ?? '') === 'legacy_handler') {
            return $this->buildResponse([
                'mode' => 'admin_command',
                'intent' => $intent,
                'resolution_status' => 'blocked',
                'execution_status' => 'rejected',
                'policy' => [
                    'decision' => 'blocked',
                    'reasons' => ['legacy_handler_fallback'],
                    'warnings' => [],
                    'approval_required' => false,
                    'can_execute' => false,
                ],
                'summary_text' => 'No structured assistant action was available for this request.',
            ]);
        }

        $execution = $this->executionService->execute($plan, $resolved, $userId);

        return $this->finalizeResponse($this->buildResponse([
            'mode' => 'admin_command',
            'intent' => $intent,
            'run_id' => $execution['run_id'] ?? null,
            'resolution_status' => $execution['resolution_status'] ?? ($plan['resolution_status'] ?? 'resolved'),
            'execution_status' => $execution['execution_status'] ?? 'executed',
            'policy' => $this->extractPrimaryPolicy($execution, $plan),
            'draft' => $execution['draft'] ?? [],
            'results' => $execution['results'] ?? [],
            'summary_text' => $this->formatUserFacingSummary([
                'mode' => 'admin_command',
                'intent' => $intent,
                'policy' => $this->extractPrimaryPolicy($execution, $plan),
                'results' => $execution['results'] ?? [],
                'resolution_status' => $execution['resolution_status'] ?? ($plan['resolution_status'] ?? 'resolved'),
                'execution_status' => $execution['execution_status'] ?? 'executed',
            ]),
        ]), $plan);
    }

    public function handleCustomerThreadDraft(int $communicationId, int $userId, array $options = []): array
    {
        $assistantContext = $this->assistantContext('customer_thread', $options);
        $goal = (string) ($options['goal'] ?? 'draft');
        $resolved = $this->resolver->resolveFromCustomerThread($communicationId);
        $resolved['query'] = $goal;
        $resolved['intent'] = 'draft_customer_reply';
        $resolved = array_merge($resolved, $assistantContext);
        $plan = $this->planner->planCustomerReply($resolved['thread_context'] ?? [], $resolved, $goal, $userId);
        $execution = $this->executionService->executeCustomerReply($plan, $resolved, $userId);

        return $this->finalizeResponse($this->buildResponse([
            'mode' => 'customer_thread',
            'intent' => 'draft_customer_reply',
            'run_id' => $execution['run_id'] ?? null,
            'resolution_status' => $execution['resolution_status'] ?? ($plan['resolution_status'] ?? 'resolved'),
            'execution_status' => $execution['execution_status'] ?? 'executed',
            'policy' => $this->extractPrimaryPolicy($execution, $plan),
            'draft' => $execution['draft'] ?? [],
            'results' => $execution['results'] ?? [],
            'summary_text' => $this->formatUserFacingSummary([
                'mode' => 'customer_thread',
                'intent' => 'draft_customer_reply',
                'policy' => $this->extractPrimaryPolicy($execution, $plan),
                'draft' => $execution['draft'] ?? [],
                'results' => $execution['results'] ?? [],
                'resolution_status' => $execution['resolution_status'] ?? ($plan['resolution_status'] ?? 'resolved'),
                'execution_status' => $execution['execution_status'] ?? 'executed',
            ]),
        ]), $plan);
    }

    public function handleCustomerThreadSend(int $communicationId, int $userId, array $options = []): array
    {
        $assistantContext = $this->assistantContext('customer_thread', $options);
        $goal = (string) ($options['goal'] ?? 'send');
        $resolved = $this->resolver->resolveFromCustomerThread($communicationId);
        $resolved['query'] = $goal;
        $resolved['intent'] = 'send_customer_reply';
        $resolved = array_merge($resolved, $assistantContext);
        $plan = $this->planner->planCustomerReply($resolved['thread_context'] ?? [], $resolved, $goal, $userId);
        $execution = $this->executionService->executeCustomerReply($plan, $resolved, $userId);

        return $this->finalizeResponse($this->buildResponse([
            'mode' => 'customer_thread',
            'intent' => 'send_customer_reply',
            'run_id' => $execution['run_id'] ?? null,
            'resolution_status' => $execution['resolution_status'] ?? ($plan['resolution_status'] ?? 'resolved'),
            'execution_status' => $execution['execution_status'] ?? 'executed',
            'policy' => $this->extractPrimaryPolicy($execution, $plan),
            'draft' => $execution['draft'] ?? [],
            'results' => $execution['results'] ?? [],
            'summary_text' => $this->formatUserFacingSummary([
                'mode' => 'customer_thread',
                'intent' => 'send_customer_reply',
                'policy' => $this->extractPrimaryPolicy($execution, $plan),
                'draft' => $execution['draft'] ?? [],
                'results' => $execution['results'] ?? [],
                'resolution_status' => $execution['resolution_status'] ?? ($plan['resolution_status'] ?? 'resolved'),
                'execution_status' => $execution['execution_status'] ?? 'executed',
            ]),
        ]), $plan);
    }

    public function previewCustomerThreadCommercialReply(int $communicationId, int $userId, array $options = []): array
    {
        $goal = (string) ($options['goal'] ?? 'draft');
        $reviseDocument = !empty($options['revise_document']);
        $applyDocumentRevision = !empty($options['apply_document_revision']);
        $resolved = $this->resolver->resolveFromCustomerThread($communicationId);
        $thread = (array) ($resolved['thread_context'] ?? []);
        $contact = $thread['contact'] ?? ($resolved['primary_entities']['contact'] ?? null);
        $deal = $thread['deal'] ?? ($resolved['primary_entities']['deal'] ?? null);
        $invoice = $thread['invoice'] ?? ($resolved['primary_entities']['invoice'] ?? null);

        if (!$contact) {
            return $this->buildBlockedCustomerThreadCommercialResponse(
                $goal,
                'Contact could not be linked to the conversation thread.',
                ['missing_thread_contact_identity']
            );
        }

        $results = [];
        if ($reviseDocument) {
            if (!$invoice) {
                return $this->buildBlockedCustomerThreadCommercialResponse(
                    $goal,
                    'No latest quote or commercial document is linked to this conversation yet.',
                    ['missing_latest_quote']
                );
            }

            $decision = $this->policyBridge->evaluateCommercialAssistantAction('revise_document', [
                'deal' => $deal ?: [],
                'invoice' => $invoice ?: [],
                'contact' => $contact ?: [],
                'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                'surface' => 'customer_thread',
                'assistant_confidence' => 0.92,
                'recipient' => (string) ($contact['email'] ?? $thread['reply_target_email'] ?? ''),
                'channel' => 'email',
                'assistant_requested_action' => 'revise_document',
            ], $userId);

            if (($decision['decision'] ?? '') === 'approval_required') {
                return $this->buildBlockedCustomerThreadCommercialResponse(
                    $goal,
                    'Approval is required before revising the latest quote for this thread.',
                    (array) ($decision['reasons'] ?? ['approval_required']),
                    $decision
                );
            }
            if (in_array((string) ($decision['decision'] ?? ''), ['blocked', 'suggest_only'], true)) {
                return $this->buildBlockedCustomerThreadCommercialResponse(
                    $goal,
                    'The latest quote could not be revised automatically for this thread.',
                    (array) ($decision['reasons'] ?? ['revise_document_blocked']),
                    $decision
                );
            }

            if ($applyDocumentRevision) {
                $updatedId = $this->invoices->reviseFromRecommendations(
                    (int) $invoice['id'],
                    ['suggested_products' => $invoice['suggested_products'] ?? []],
                    'ai',
                    $userId
                );
                $invoice = $this->invoices->getById($updatedId) ?: $invoice;
                $thread['invoice'] = $invoice;
                $results[] = [
                    'action' => 'revise_document',
                    'status' => 'revised',
                    'invoice_id' => (int) ($invoice['id'] ?? $updatedId),
                    'policy' => $decision,
                ];
            } else {
                $results[] = [
                    'action' => 'revise_document',
                    'status' => 'previewed',
                    'invoice_id' => (int) ($invoice['id'] ?? 0),
                    'policy' => $decision,
                ];
            }
        }

        $purpose = $this->determineCommercialReplyPurpose($thread, $invoice);
        $draftContext = array_merge($thread, [
            'contact' => $contact ?: [],
            'deal' => $deal ?: [],
            'invoice' => $invoice ?: [],
            'actor_user_id' => $userId,
        ]);
        $draft = match ($purpose) {
            'invoice_reply' => $this->draftService->draftInvoiceReply($draftContext),
            'negotiation_reply' => $this->draftService->draftNegotiationReply($draftContext),
            'overdue_reminder' => $this->draftService->draftOverdueReminder($draftContext),
            default => $this->draftService->draftProposalReply($draftContext),
        };

        $response = $this->buildResponse([
            'mode' => 'customer_thread',
            'intent' => $reviseDocument ? 'revise_quote_with_context' : 'draft_customer_reply',
            'resolution_status' => 'resolved',
            'execution_status' => 'executed',
            'policy' => [
                'decision' => 'allow',
                'reasons' => [],
                'warnings' => [],
                'approval_required' => false,
                'can_execute' => true,
            ],
            'draft' => $draft,
            'results' => $results,
            'summary_text' => $reviseDocument
                ? ($applyDocumentRevision ? 'Latest quote revised and reply draft prepared.' : 'Latest quote revision preview prepared with a reply draft.')
                : 'Assistant draft ready.',
        ]);

        return $this->finalizeResponse($response);
    }

    public function sendCustomerThreadLatestQuote(int $communicationId, int $userId): array
    {
        if (!$this->runtimeConfig->customerThreadEnabled()) {
            return $this->buildBlockedCustomerThreadCommercialResponse(
                'send',
                'Customer-thread assistant is disabled.',
                ['customer_thread_disabled']
            );
        }
        if (!$this->runtimeConfig->customerSendEnabled()) {
            return $this->buildBlockedCustomerThreadCommercialResponse(
                'send',
                'Assistant customer sending is disabled.',
                ['customer_send_disabled']
            );
        }

        $resolved = $this->resolver->resolveFromCustomerThread($communicationId);
        $thread = (array) ($resolved['thread_context'] ?? []);
        $contact = $thread['contact'] ?? ($resolved['primary_entities']['contact'] ?? null);
        $deal = $thread['deal'] ?? ($resolved['primary_entities']['deal'] ?? null);
        $invoice = $thread['invoice'] ?? ($resolved['primary_entities']['invoice'] ?? null);

        if (!$invoice) {
            return $this->buildBlockedCustomerThreadCommercialResponse(
                'send',
                'No latest quote or commercial document is linked to this conversation yet.',
                ['missing_latest_quote']
            );
        }

        $context = [
            'deal' => $deal ?: [],
            'invoice' => $invoice ?: [],
            'surface' => 'customer_thread',
            'assistant_confidence' => 0.92,
            'contact' => $contact ?: [],
            'channel' => 'email',
            'recipient' => (string) ($contact['email'] ?? $invoice['billing_email'] ?? $invoice['contact_email'] ?? $thread['reply_target_email'] ?? ''),
            'requires_customer_send' => true,
            'thread_context' => $thread,
            'thread_context_present' => true,
        ];

        $result = $this->commercialAutomation->runAssistantPlannedCommercialAction([
            'action' => 'send_document',
            'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
        ], $context, $userId);

        $status = (string) ($result['status'] ?? 'blocked');
        $success = in_array($status, ['sent', 'executed'], true);
        $policy = (array) ($result['policy'] ?? []);

        return $this->finalizeResponse($this->buildResponse([
            'mode' => 'customer_thread',
            'intent' => 'send_invoice',
            'resolution_status' => $success ? 'resolved' : 'blocked',
            'execution_status' => $success ? 'executed' : 'rejected',
            'policy' => $policy ?: [
                'decision' => $success ? 'allow' : 'blocked',
                'reasons' => !$success ? [(string) ($result['reason'] ?? 'send_document_failed')] : [],
                'warnings' => [],
                'approval_required' => (($policy['decision'] ?? '') === 'approval_required'),
                'can_execute' => $success,
            ],
            'results' => [[
                'action' => 'send_document',
                'status' => $status,
                'invoice_id' => (int) ($invoice['id'] ?? 0),
                'policy' => $policy,
                'result' => $result,
            ]],
            'summary_text' => $success
                ? 'Latest quote sent successfully.'
                : (string) ($result['reason'] ?? 'Failed to send the latest quote.'),
        ]));
    }

    public function handleAdviceRequest(array $context, int $userId): array
    {
        $context = array_merge($context, $this->assistantContext(
            !empty($context['thread_context']) ? 'customer_thread' : 'admin_command',
            $context
        ));
        $intent = (string) ($context['intent'] ?? 'question');
        $surface = (string) (($context['thread_context'] ?? null) ? 'customer_thread' : 'admin_command');
        $advicePolicy = $this->policyBridge->evaluateAssistantAdvice($surface, [
            'surface' => $surface,
            'assistant_confidence' => (float) ($context['confidence'] ?? 1.0),
            'assistant_requested_action' => $intent,
            'assistant_type' => (string) ($context['assistant_type'] ?? $this->assistantType),
            'assistant_source' => (string) ($context['assistant_source'] ?? ''),
            'assistant_source_channel' => (string) ($context['assistant_channel'] ?? ''),
            'thread_context' => $context['thread_context'] ?? [],
            'thread_context_present' => !empty($context['thread_context']),
            'invoice' => (array) ($context['primary_entities']['invoice'] ?? []),
        ], $userId);

        $response = [
            'mode' => $surface,
            'intent' => $intent,
            'resolution_status' => ($context['resolved'] ?? true) ? 'resolved' : (!empty($context['ambiguities']) ? 'ambiguous' : 'blocked'),
            'execution_status' => in_array((string) ($advicePolicy['decision'] ?? ''), ['allow', 'allow_with_warning', 'suggest_only'], true) ? 'executed' : 'rejected',
            'policy' => $advicePolicy,
            'draft' => [],
            'results' => [],
        ];

        if (($advicePolicy['decision'] ?? '') === 'blocked') {
            $response['summary_text'] = match ($intent) {
                'summarize_thread_state' => 'I need clearer thread context before summarizing that conversation confidently.',
                'summarize_commercial_automation_state' => 'I need a clearer deal or invoice reference before summarizing commercial automation confidently.',
                'list_pending_commercial_approvals' => 'I need a clearer context before listing pending commercial approvals confidently.',
                'show_last_assistant_action' => 'I need more reliable context before summarizing the last assistant action.',
                'explain_quote_changes' => 'I need a more specific quote or invoice reference before explaining changes confidently.',
                default => 'I need more reliable context before answering that confidently. Please refer to a specific record, thread, deal, or invoice.',
            };

            return $this->finalizeResponse($this->buildResponse($response));
        }

        $summaryResult = match ($intent) {
            'summarize_thread_state' => $this->summarizeThread($context),
            'summarize_commercial_automation_state' => $this->summarizeCommercialAutomationState($context),
            'list_pending_commercial_approvals' => $this->listPendingCommercialApprovals($context),
            'show_last_assistant_action' => $this->showLastAssistantAction((string) ($context['assistant_type'] ?? $this->assistantType)),
            'explain_quote_changes' => $this->explainQuoteChanges($context),
            default => $this->answerQuestion((string) ($context['body'] ?? $context['query'] ?? ''), $userId, $context),
        };
        $summary = is_array($summaryResult) ? (string) ($summaryResult['text'] ?? '') : (string) $summaryResult;

        if (in_array((string) ($advicePolicy['decision'] ?? ''), ['allow_with_warning', 'suggest_only'], true) && !empty($advicePolicy['reasons'])) {
            $summary = 'Qualification note: ' . implode(', ', (array) $advicePolicy['reasons']) . "\n\n" . $summary;
        }

        $response['summary_text'] = $summary;
        if (is_array($summaryResult)) {
            $response['prompt_key'] = $summaryResult['prompt_key'] ?? null;
            $response['prompt_version'] = $summaryResult['prompt_version'] ?? null;
            $response['context_bundle_quality'] = $summaryResult['context_bundle_quality'] ?? null;
            $response['retrieval_warnings'] = $summaryResult['retrieval_warnings'] ?? [];
        }

        return $this->finalizeResponse($this->buildResponse($response));
    }

    public function formatUserFacingSummary(array $result): string
    {
        $policy = (array) ($result['policy'] ?? []);
        $draft = (array) ($result['draft'] ?? []);
        $results = (array) ($result['results'] ?? []);

        if (!empty($result['summary_text'])) {
            return (string) $result['summary_text'];
        }

        $lines = [];
        if (!empty($policy['reasons']) && in_array((string) ($policy['decision'] ?? ''), ['blocked', 'suggest_only'], true)) {
            $lines[] = ucfirst((string) ($policy['decision'] ?? 'blocked')) . ': ' . implode(', ', (array) $policy['reasons']);
        }
        if (!empty($policy['warnings'])) {
            $lines[] = 'Warnings: ' . implode(', ', (array) $policy['warnings']);
        }
        if (!empty($draft['plain_body'])) {
            $lines[] = trim((string) $draft['plain_body']);
        }
        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $action = ucfirst(str_replace('_', ' ', (string) ($item['action'] ?? 'action')));
            $status = (string) ($item['status'] ?? 'done');
            $line = $action . ': ' . $status;
            if (!empty($item['invoice_id'])) {
                $line .= ' (Invoice ID: ' . (int) $item['invoice_id'] . ')';
            }
            if (!empty($item['approval_id'])) {
                $line .= ' (Approval ID: ' . (int) $item['approval_id'] . ')';
            }
            $lines[] = $line;
        }

        return $lines ? implode("\n", $lines) : 'No assistant action was executed.';
    }

    private function extractPrimaryPolicy(array $execution, array $plan): array
    {
        $firstPolicy = [];
        foreach ((array) ($execution['results'] ?? []) as $item) {
            if (is_array($item) && !empty($item['policy']) && is_array($item['policy'])) {
                $firstPolicy = $item['policy'];
                break;
            }
        }

        if (!$firstPolicy) {
            $firstPolicy = [
                'decision' => (string) ($execution['policy_decision'] ?? $plan['policy_decision'] ?? 'blocked'),
                'reasons' => (array) ($execution['policy_reasons'] ?? $plan['policy_reasons'] ?? []),
                'warnings' => (array) ($execution['policy_warnings'] ?? $plan['policy_warnings'] ?? []),
                'approval_required' => (($execution['policy_decision'] ?? $plan['policy_decision'] ?? '') === 'approval_required'),
                'can_execute' => in_array((string) ($execution['policy_decision'] ?? $plan['policy_decision'] ?? ''), ['allow', 'allow_with_warning'], true),
                'confidence_score' => (float) ($plan['confidence'] ?? 0.0),
                'context_quality_score' => (float) (($plan['qualification_snapshot']['context_quality_score'] ?? 0.0)),
                'goal_relevance_score' => (float) (($plan['qualification_snapshot']['goal_relevance_score'] ?? 0.0)),
                'mode' => (string) (($plan['qualification_snapshot']['mode'] ?? '1')),
                'threshold' => (float) (($plan['qualification_snapshot']['confidence_threshold'] ?? 0.0)),
            ];
        }

        return $firstPolicy;
    }

    private function buildResponse(array $data): array
    {
        $policy = array_merge([
            'decision' => 'blocked',
            'confidence_score' => 0.0,
            'context_quality_score' => 0.0,
            'goal_relevance_score' => 0.0,
            'mode' => '1',
            'threshold' => 0.0,
            'reasons' => [],
            'warnings' => [],
            'approval_required' => false,
            'can_execute' => false,
        ], (array) ($data['policy'] ?? []));

        return [
            'success' => (bool) ($data['success'] ?? true),
            'mode' => (string) ($data['mode'] ?? 'admin_command'),
            'intent' => (string) ($data['intent'] ?? ''),
            'run_id' => $data['run_id'] ?? null,
            'resolution_status' => (string) ($data['resolution_status'] ?? 'resolved'),
            'execution_status' => (string) ($data['execution_status'] ?? 'executed'),
            'policy' => $policy,
            'draft' => array_merge([
                'subject' => '',
                'plain_body' => '',
                'html_body' => '',
                'explanation' => '',
            ], (array) ($data['draft'] ?? [])),
            'results' => (array) ($data['results'] ?? []),
            'summary_text' => (string) ($data['summary_text'] ?? ''),
            'prompt_key' => $data['prompt_key'] ?? null,
            'prompt_version' => $data['prompt_version'] ?? null,
            'context_bundle_quality' => $data['context_bundle_quality'] ?? null,
            'retrieval_warnings' => (array) ($data['retrieval_warnings'] ?? []),
        ];
    }

    private function finalizeResponse(array $response, array $plan = []): array
    {
        $response = $this->applySenderIdentityToResponse($response);

        $runId = (int) ($response['run_id'] ?? 0);
        if ($runId > 0) {
            try {
                $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
                if ($workspaceId > 0) {
                    Database::execute(
                        "UPDATE email_assistant_runs SET plan_json = ?, result_json = ? WHERE id = ? AND workspace_id = ?",
                        [json_encode($plan), json_encode($response), $runId, $workspaceId]
                    );
                    return $response;
                }

                Database::execute(
                    "UPDATE email_assistant_runs SET plan_json = ?, result_json = ? WHERE id = ?",
                    [json_encode($plan), json_encode($response), $runId]
                );
            } catch (\Throwable $e) {
            }
        }

        return $response;
    }

    private function applySenderIdentityToResponse(array $response): array
    {
        $draft = (array) ($response['draft'] ?? []);
        if ($draft === []) {
            return $response;
        }

        $identity = $this->resolveSenderIdentity();
        if (($identity['full_name'] ?? '') === '' && ($identity['company_name'] ?? '') === '') {
            return $response;
        }

        $plainBody = trim((string) ($draft['plain_body'] ?? ''));
        $htmlBody = trim((string) ($draft['html_body'] ?? ''));

        if ($plainBody === '' && $htmlBody !== '') {
            $plainBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        }
        if ($plainBody === '') {
            return $response;
        }

        $normalizedPlain = $this->normalizeSenderSignature($plainBody, $identity);
        $draft['plain_body'] = $normalizedPlain;
        $draft['html_body'] = nl2br(htmlspecialchars($normalizedPlain, ENT_QUOTES, 'UTF-8'));
        $response['draft'] = $draft;

        return $response;
    }

    private function resolveSenderIdentity(): array
    {
        $authUser = Auth::user() ?? [];
        $userId = (int) ($authUser['id'] ?? 0);
        $user = $authUser;

        if (
            $userId > 0
            && (
                trim((string) ($user['first_name'] ?? '')) === ''
                || trim((string) ($user['last_name'] ?? '')) === ''
                || !array_key_exists('job_title', $user)
            )
        ) {
            $user = Database::queryOne(
                "SELECT first_name, last_name, job_title
                 FROM users
                 WHERE id = ?",
                [$userId]
            ) ?: $user;
        }

        $firstName = trim((string) ($user['first_name'] ?? ''));
        $lastName = trim((string) ($user['last_name'] ?? ''));
        $fullName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        $jobTitle = trim((string) ($user['job_title'] ?? ''));

        $profile = (new CompanyProfile())->get() ?: [];
        $companyName = trim((string) ($profile['company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($_ENV['COMPANY_NAME'] ?? ''));
        }
        if ($companyName === '' && function_exists('brandProductName')) {
            $companyName = trim((string) brandProductName());
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'job_title' => $jobTitle,
            'company_name' => $companyName,
        ];
    }

    private function normalizeSenderSignature(string $body, array $identity): string
    {
        $fullName = trim((string) ($identity['full_name'] ?? ''));
        $companyName = trim((string) ($identity['company_name'] ?? ''));
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);

        if ($fullName !== '') {
            $normalized = str_replace(['[Your Name]', '{{your_name}}', '{your_name}', '[Sender Name]'], $fullName, $normalized);
        }
        if ($companyName !== '') {
            $normalized = str_replace(['[Company Name]', '{{company_name}}', '{company_name}', '[Sender Company]'], $companyName, $normalized);
            $normalized = preg_replace('/\bDella\b/i', $companyName, $normalized) ?? $normalized;
        }

        $lines = explode("\n", $normalized);
        $signoffIndex = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (preg_match('/^(best regards|regards|kind regards|thanks(?: and regards)?),?$/i', $line)) {
                $signoffIndex = $i;
                break;
            }
        }

        if ($signoffIndex !== null) {
            $replacement = [rtrim($lines[$signoffIndex], ',') . ','];
            if ($fullName !== '') {
                $replacement[] = $fullName;
            }
            if ($companyName !== '') {
                $replacement[] = $companyName;
            }
            array_splice($lines, $signoffIndex, count($lines) - $signoffIndex, $replacement);
            return trim(implode("\n", $lines));
        }

        $lines = array_values(array_filter($lines, static function (string $line): bool {
            $trimmed = trim($line);
            return !in_array($trimmed, ['[Your Name]', 'Della'], true);
        }));

        if ($fullName !== '' || $companyName !== '') {
            if (!empty($lines) && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }
            $lines[] = 'Best regards,';
            if ($fullName !== '') {
                $lines[] = $fullName;
            }
            if ($companyName !== '') {
                $lines[] = $companyName;
            }
        }

        return trim(implode("\n", $lines));
    }

    private function handleThreadSummary(int $communicationId, int $userId, string $body, array $assistantContext = []): array
    {
        $threadResolved = $this->resolver->resolveFromCustomerThread($communicationId);
        $threadResolved['query'] = $body;
        $threadResolved['intent'] = 'summarize_thread_state';
        $threadResolved = array_merge($threadResolved, $assistantContext);
        return $this->handleAdviceRequest($threadResolved + ['body' => $body], $userId);
    }

    private function summarizeThread(array $context): string
    {
        $threadSummary = (string) (($context['thread_context']['thread_summary'] ?? ''));
        return $threadSummary !== '' ? $threadSummary : 'No thread summary available.';
    }

    private function summarizeCommercialAutomationState(array $context): string
    {
        $body = (string) ($context['body'] ?? $context['query'] ?? '');
        $dealId = $this->extractIntegerAfterKeyword($body, 'deal');
        $invoiceId = $this->extractIntegerAfterKeyword($body, 'invoice');
        $approvalService = new CommercialAutomationApprovalService();
        $orchestrator = new CommercialAutomationOrchestrator();
        $approvals = $approvalService->listPending($dealId > 0 ? $dealId : null, $invoiceId > 0 ? $invoiceId : null);
        $runs = $orchestrator->getRecentRuns($dealId > 0 ? $dealId : null, $invoiceId > 0 ? $invoiceId : null, 5);
        if (empty($approvals) && empty($runs)) {
            return 'No commercial automation activity found.';
        }
        $lines = [];
        if (!empty($approvals)) {
            $lines[] = 'Pending approvals:';
            foreach ($approvals as $approval) {
                $lines[] = "- #{$approval['id']} {$approval['action_key']} ({$approval['reason']})";
            }
        }
        if (!empty($runs)) {
            if ($lines) {
                $lines[] = '';
            }
            $lines[] = 'Recent runs:';
            foreach ($runs as $run) {
                $lines[] = "- {$run['trigger_type']} => {$run['decision']} at {$run['created_at']}";
            }
        }

        return implode("\n", $lines);
    }

    private function listPendingCommercialApprovals(array $context): string
    {
        $summary = (new CommercialAutomationOrchestrator())->summarizeForAssistant();
        $approvals = $summary['pending_approvals'] ?? [];
        if (empty($approvals)) {
            return 'There are no pending commercial approvals.';
        }
        $lines = ['Pending commercial approvals:', ''];
        foreach ($approvals as $approval) {
            $lines[] = "- #{$approval['id']} {$approval['action_key']} ({$approval['reason']})";
        }

        return implode("\n", $lines);
    }

    private function showLastAssistantAction(string $assistantType = AssistantActionRuntimeConfig::ASSISTANT_EMAIL): string
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return 'No active workspace is available for assistant action history.';
        }

        $params = [$workspaceId];
        $where = 'workspace_id = ?';
        if (Database::columnExists('email_assistant_runs', 'assistant_type')) {
            $where .= ' AND assistant_type = ?';
            $params[] = AssistantActionRuntimeConfig::normalizeType($assistantType);
        }

        $run = Database::queryOne(
            "SELECT *
             FROM email_assistant_runs
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            $params
        );
        if (!$run) {
            return 'No assistant actions have been recorded yet.';
        }

        return "Last assistant action: {$run['intent']} ({$run['execution_status']}) at {$run['created_at']}.";
    }

    private function explainQuoteChanges(array $context): array
    {
        $invoice = $context['primary_entities']['invoice'] ?? null;
        if (!$invoice) {
            return [
                'text' => 'No quote or invoice was resolved to explain changes.',
                'prompt_key' => null,
                'prompt_version' => null,
                'context_bundle_quality' => null,
                'retrieval_warnings' => [],
            ];
        }

        $bundle = $this->contextAssembly->buildContextBundle('assistant', 'assistant_change_explanation', [
            'invoice' => $invoice,
            'resolved_entities' => (array) ($context['primary_entities'] ?? []),
            'thread_context' => (array) ($context['thread_context'] ?? []),
            'commercial_context' => [
                'invoice' => $invoice,
                'line_items' => $invoice['line_items'] ?? [],
            ],
        ]);
        $bundleQuality = $this->retrievalQuality->scoreBundle($bundle);
        $resolvedPrompt = $this->aiService->buildPromptFromRegistry('assistant', 'assistant_change_explanation', $bundle, [
            'invoice' => $invoice,
            'line_items' => $invoice['line_items'] ?? [],
        ]);
        $explanation = $this->aiService->processWithPrompt('email_assistant_change_explanation', $resolvedPrompt);
        $parsed = json_decode((string) $explanation, true);

        return [
            'text' => trim((string) ($parsed['customer_friendly'] ?? $parsed['summary'] ?? $explanation)),
            'prompt_key' => 'assistant_change_explanation',
            'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
            'context_bundle_quality' => $bundleQuality,
            'retrieval_warnings' => (array) ($bundleQuality['warnings'] ?? []),
        ];
    }

    private function answerQuestion(string $body, int $userId, array $context = []): array
    {
        $bundle = $this->contextAssembly->buildContextBundle('assistant', 'assistant_question', [
            'user_id' => $userId,
            'question' => $body,
            'resolved_entities' => (array) ($context['primary_entities'] ?? []),
            'thread_context' => (array) ($context['thread_context'] ?? []),
            'operating_context' => (array) ($context['operating_context'] ?? []),
            'policy_context' => (array) ($context['policy'] ?? []),
            'commercial_context' => [
                'invoice' => (array) (($context['primary_entities']['invoice'] ?? [])),
                'deal' => (array) (($context['primary_entities']['deal'] ?? [])),
            ],
        ]);
        $bundleQuality = $this->retrievalQuality->scoreBundle($bundle);
        $resolvedPrompt = $this->aiService->buildPromptFromRegistry('assistant', 'assistant_question', $bundle, [
            'question' => $body,
            'context' => $context,
        ]);
        $result = $this->aiService->processWithPrompt('email_assistant_question', $resolvedPrompt);

        return [
            'text' => trim((string) $result) ?: "I couldn't generate an answer. Try rephrasing your question.",
            'prompt_key' => 'assistant_question',
            'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
            'context_bundle_quality' => $bundleQuality,
            'retrieval_warnings' => (array) ($bundleQuality['warnings'] ?? []),
        ];
    }

    private function extractIntegerAfterKeyword(string $body, string $keyword): int
    {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\s*#?\s*(\d+)\b/i', $body, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function determineCommercialReplyPurpose(array $thread, ?array $invoice): string
    {
        $signals = (array) ($thread['signals'] ?? []);
        if (!empty($signals['asks_for_invoice'])) {
            return 'invoice_reply';
        }
        if (!empty($signals['asks_for_discount']) || !empty($signals['asks_for_revision'])) {
            return 'negotiation_reply';
        }
        if ($invoice && in_array((string) ($invoice['document_type'] ?? ''), ['invoice'], true)) {
            return 'invoice_reply';
        }

        return 'proposal_reply';
    }

    private function buildBlockedCustomerThreadCommercialResponse(string $goal, string $summary, array $reasons, array $policy = []): array
    {
        return $this->finalizeResponse($this->buildResponse([
            'mode' => 'customer_thread',
            'intent' => $goal === 'send' ? 'send_invoice' : 'draft_customer_reply',
            'resolution_status' => 'blocked',
            'execution_status' => 'rejected',
            'policy' => array_merge([
                'decision' => 'blocked',
                'reasons' => $reasons,
                'warnings' => [],
                'approval_required' => false,
                'can_execute' => false,
            ], $policy),
            'draft' => [],
            'results' => [],
            'summary_text' => $summary,
        ]));
    }
}
