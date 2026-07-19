<?php

namespace CRM\Services;

class EmailAssistantActionPlanner
{
    private EmailAssistantPolicyBridge $policyBridge;
    private CustomerThreadAutonomyService $customerThreadAutonomy;

    public function __construct()
    {
        $this->policyBridge = new EmailAssistantPolicyBridge();
        $this->customerThreadAutonomy = new CustomerThreadAutonomyService();
    }

    public function planAdminCommand(array $resolvedContext, string $intent, string $body, int $userId): array
    {
        $entities = $resolvedContext['primary_entities'] ?? [];
        $invoice = $entities['invoice'] ?? null;
        $deal = $entities['deal'] ?? null;
        $contact = $entities['contact'] ?? null;
        $actions = [];
        $blocked = [];
        $explanations = [];
        $confidence = (float) ($resolvedContext['confidence'] ?? 0.5);
        $policySnapshots = [];

        if (!empty($resolvedContext['ambiguities'])) {
            return [
                'actions' => [],
                'requires_approval' => false,
                'blocked_reasons' => $resolvedContext['ambiguities'],
                'drafts' => [],
                'explanations' => ['The request is ambiguous and needs clarification before acting.'],
                'confidence' => $confidence,
                'resolution_status' => 'ambiguous',
                'policy_decision' => 'blocked',
                'policy_reasons' => $resolvedContext['ambiguities'],
                'policy_warnings' => [],
                'qualification_snapshot' => null,
                'summary_goal' => 'clarify_request',
                'render_mode' => 'text_reply',
            ];
        }

        switch ($intent) {
            case 'send_invoice':
                $invoice ? $actions[] = ['action' => 'send_document', 'document_type' => (string) ($invoice['document_type'] ?? 'invoice')] : $blocked[] = 'No commercial document was resolved for sending.';
                break;
            case 'finalize_invoice':
                $invoice ? $actions[] = ['action' => 'finalize_invoice', 'document_type' => (string) ($invoice['document_type'] ?? 'invoice')] : $blocked[] = 'No invoice was resolved for finalization.';
                break;
            case 'convert_quote_to_invoice':
                $invoice ? $actions[] = ['action' => 'convert_to_invoice', 'document_type' => (string) ($invoice['document_type'] ?? 'quote')] : $blocked[] = 'No quote or proforma was resolved for conversion.';
                break;
            case 'update_invoice':
            case 'revise_quote_with_context':
                if ($invoice) {
                    $actions[] = ['action' => 'revise_document', 'document_type' => (string) ($invoice['document_type'] ?? 'quote')];
                    $explanations[] = 'The assistant will apply safe revisions before responding.';
                } else {
                    $blocked[] = 'No quote or invoice was resolved for revision.';
                }
                break;
            case 'create_invoice':
                if ($deal) {
                    $documentType = preg_match('/\bproforma\b/i', $body) ? 'proforma' : (preg_match('/\bquote\b/i', $body) ? 'quote' : 'invoice');
                    $actions[] = ['action' => 'create_draft', 'document_type' => $documentType];
                } else {
                    $blocked[] = 'A deal is required to create a commercial document automatically.';
                }
                break;
            case 'approve_commercial_action':
            case 'reject_commercial_action':
            case 'summarize_commercial_automation_state':
            case 'list_pending_commercial_approvals':
            case 'show_last_assistant_action':
            case 'mark_invoice_paid':
                $actions[] = ['action' => $intent];
                break;
            default:
                $actions[] = ['action' => 'legacy_handler'];
                $explanations[] = 'This request stays on the existing assistant execution path.';
                break;
        }

        $requiresApproval = false;
        foreach ($actions as &$action) {
            if ($action['action'] === 'legacy_handler') {
                continue;
            }
            $actionContext = [
                'deal' => $deal ?: [],
                'invoice' => $invoice ?: [],
                'contact' => $contact ?: [],
                'document_type' => $action['document_type'] ?? ($invoice['document_type'] ?? ''),
                'surface' => 'admin_command',
                'assistant_confidence' => $confidence,
                'recipient' => (string) ($invoice['billing_email'] ?? $invoice['contact_email'] ?? ''),
                'channel' => 'email',
                'assistant_requested_action' => $action['action'],
            ];
            $decision = $this->evaluatePlannedAction($action['action'], $actionContext, $userId, false);
            $action['policy'] = $decision;
            $policySnapshots[] = $decision;
            if (($decision['decision'] ?? '') === 'approval_required') {
                $requiresApproval = true;
                $blocked[] = implode(', ', (array) ($decision['reasons'] ?? ['Approval required']));
            } elseif (in_array((string) ($decision['decision'] ?? ''), ['blocked', 'suggest_only'], true)) {
                $blocked[] = implode(', ', (array) ($decision['reasons'] ?? ['Action rejected by policy']));
            }
            unset($actionContext);
        }
        unset($action);

        $policySummary = $this->summarizePolicySnapshots($policySnapshots);

        return [
            'actions' => $actions,
            'requires_approval' => $requiresApproval,
            'blocked_reasons' => array_values(array_unique(array_filter($blocked))),
            'drafts' => [],
            'explanations' => $explanations,
            'confidence' => $confidence,
            'resolution_status' => empty($blocked) ? 'resolved' : ($requiresApproval ? 'approval_required' : 'blocked'),
            'policy_decision' => $policySummary['decision'],
            'policy_reasons' => $policySummary['reasons'],
            'policy_warnings' => $policySummary['warnings'],
            'qualification_snapshot' => $policySummary['snapshot'],
            'summary_goal' => $intent,
            'render_mode' => empty($actions) ? 'text_reply' : 'mutation_result',
        ];
    }

    public function planCustomerReply(array $threadContext, array $resolvedContext, string $goal, int $userId): array
    {
        $invoice = $resolvedContext['primary_entities']['invoice'] ?? ($threadContext['invoice'] ?? null);
        $deal = $resolvedContext['primary_entities']['deal'] ?? ($threadContext['deal'] ?? null);
        $contact = $resolvedContext['primary_entities']['contact'] ?? ($threadContext['contact'] ?? null);
        $signals = $threadContext['signals'] ?? [];
        $threadAssessment = $this->customerThreadAutonomy->assess($threadContext, $contact, $deal, $invoice);
        $actions = [];
        $draftPurpose = 'proposal_reply';
        $confidence = (float) ($resolvedContext['confidence'] ?? 0.6);
        $policySnapshots = [];
        $blocked = [];
        $requiresApproval = false;

        if (!empty($resolvedContext['ambiguities'])) {
            return [
                'actions' => [],
                'requires_approval' => false,
                'blocked_reasons' => $resolvedContext['ambiguities'],
                'drafts' => [],
                'explanations' => ['The thread is not linked clearly enough to a single commercial record.'],
                'confidence' => $confidence,
                'resolution_status' => 'ambiguous',
                'policy_decision' => 'blocked',
                'policy_reasons' => $resolvedContext['ambiguities'],
                'policy_warnings' => [],
                'qualification_snapshot' => null,
                'summary_goal' => 'clarify_thread',
                'render_mode' => 'draft_preview',
            ];
        }

        if (!empty($signals['asks_for_invoice'])) {
            $draftPurpose = 'invoice_reply';
        } elseif (!empty($signals['asks_for_discount']) || !empty($signals['asks_for_revision'])) {
            $draftPurpose = 'negotiation_reply';
            if ($invoice) {
                $actions[] = ['action' => 'revise_document', 'document_type' => (string) ($invoice['document_type'] ?? 'quote')];
            }
        }

        if (!$invoice && $deal && in_array((string) ($deal['stage'] ?? ''), ['proposal', 'negotiation'], true)) {
            $actions[] = ['action' => 'create_draft', 'document_type' => (string) ($deal['stage'] === 'negotiation' ? 'proforma' : 'quote')];
        }

        $actions[] = ['action' => 'draft_customer_reply', 'purpose' => $draftPurpose];
        if (($goal === 'send' || preg_match('/\bsend\b/i', $goal)) && ($invoice || $deal)) {
            $actions[] = ['action' => 'send_customer_reply', 'document_type' => (string) ($invoice['document_type'] ?? 'quote')];
        }

        foreach ($actions as &$action) {
            $actionContext = [
                'deal' => $deal ?: [],
                'invoice' => $invoice ?: [],
                'contact' => $contact ?: [],
                'thread_context' => $threadContext,
                'thread_context_present' => !empty($threadContext),
                'document_type' => $action['document_type'] ?? ($invoice['document_type'] ?? ''),
                'surface' => 'customer_thread',
                'assistant_confidence' => $confidence,
                'recipient' => (string) ($contact['email'] ?? $invoice['billing_email'] ?? $invoice['contact_email'] ?? ''),
                'channel' => 'email',
                'assistant_requested_action' => $action['action'],
                'requires_customer_send' => $action['action'] === 'send_customer_reply',
            ];
            $actionContext = $this->customerThreadAutonomy->augmentPolicyContext($actionContext, $threadAssessment);
            $decision = $this->evaluatePlannedAction($action['action'], $actionContext, $userId, $action['action'] === 'draft_customer_reply');
            $action['policy'] = $decision;
            $policySnapshots[] = $decision;

            if (($decision['decision'] ?? '') === 'approval_required') {
                $requiresApproval = true;
                $blocked[] = implode(', ', (array) ($decision['reasons'] ?? ['Approval required']));
            } elseif (in_array((string) ($decision['decision'] ?? ''), ['blocked', 'suggest_only'], true) && $action['action'] !== 'draft_customer_reply') {
                $blocked[] = implode(', ', (array) ($decision['reasons'] ?? ['Action blocked']));
            }
        }
        unset($action);

        $policySummary = $this->summarizePolicySnapshots($policySnapshots);

        return [
            'actions' => $actions,
            'requires_approval' => $requiresApproval,
            'blocked_reasons' => array_values(array_unique(array_filter($blocked))),
            'drafts' => [],
            'explanations' => [],
            'confidence' => $confidence,
            'resolution_status' => empty($blocked) ? 'resolved' : ($requiresApproval ? 'approval_required' : 'blocked'),
            'policy_decision' => $policySummary['decision'],
            'policy_reasons' => $policySummary['reasons'],
            'policy_warnings' => $policySummary['warnings'],
            'qualification_snapshot' => $policySummary['snapshot'],
            'summary_goal' => $goal,
            'render_mode' => ($goal === 'send' || preg_match('/\bsend\b/i', $goal)) ? 'mutation_result' : 'draft_preview',
        ];
    }

    private function evaluatePlannedAction(string $action, array $context, int $userId, bool $isAdvice): array
    {
        if ($action === 'send_customer_reply') {
            return $this->policyBridge->evaluateCustomerReplySend($context, $userId);
        }

        if (in_array($action, ['send_document', 'revise_document', 'convert_to_invoice', 'finalize_invoice', 'create_draft'], true)) {
            return $this->policyBridge->evaluateCommercialAssistantAction($action, $context, $userId);
        }

        if ($isAdvice || in_array($action, ['draft_customer_reply', 'summarize_commercial_automation_state', 'list_pending_commercial_approvals', 'show_last_assistant_action'], true)) {
            return $this->policyBridge->evaluateAssistantAdvice((string) ($context['surface'] ?? 'admin_command'), $context, $userId);
        }

        return $this->policyBridge->evaluateAssistantAction($action, $context, $userId);
    }

    private function summarizePolicySnapshots(array $snapshots): array
    {
        $decision = 'allow';
        $reasons = [];
        $warnings = [];
        $priority = [
            'blocked' => 5,
            'approval_required' => 4,
            'suggest_only' => 3,
            'allow_with_warning' => 2,
            'allow' => 1,
        ];
        $selected = null;

        foreach ($snapshots as $snapshot) {
            $current = (string) ($snapshot['decision'] ?? 'allow');
            if ($selected === null || ($priority[$current] ?? 0) > ($priority[$selected] ?? 0)) {
                $selected = $current;
            }
            $reasons = array_merge($reasons, (array) ($snapshot['reasons'] ?? []));
            $warnings = array_merge($warnings, (array) ($snapshot['warnings'] ?? []));
        }

        if ($selected !== null) {
            $decision = $selected;
        }

        return [
            'decision' => $decision,
            'reasons' => array_values(array_unique(array_filter($reasons))),
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'snapshot' => $snapshots[0] ?? null,
        ];
    }
}
