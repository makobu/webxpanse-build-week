<?php

namespace CRM\Services;

class CommercialAutomationActionPlanner
{
    public function plan(array $context, array $policy): array
    {
        $actions = [];
        $deal = $context['deal'] ?? [];
        $invoice = $context['invoice'] ?? null;
        $stage = (string) ($deal['stage'] ?? $context['current_stage'] ?? '');
        $triggerType = (string) ($context['trigger_type'] ?? 'manual');
        $defaults = $policy['commercial']['default_document_by_stage'] ?? [];
        $learnedPreferences = (array) ($context['learned_preferences'] ?? []);
        $sendEligible = empty($context['recent_send_blocked']);
        $hasRecipient = !empty($context['recipient']);
        $preferredDocumentType = (string) ($learnedPreferences['preferred_document_type'] ?? '');
        $preferredChannel = (string) ($learnedPreferences['preferred_channel'] ?? '');

        if ($stage === 'proposal') {
            if (!$invoice) {
                $actions[] = [
                    'action' => 'create_draft',
                    'document_type' => $preferredDocumentType !== '' ? $preferredDocumentType : (string) ($defaults['proposal'] ?? 'quote'),
                    'reason' => $preferredDocumentType !== ''
                        ? 'Deal entered proposal with no active document; tenant preference favors ' . $preferredDocumentType . '.'
                        : 'Deal entered proposal with no active commercial document.',
                ];
            } elseif (!empty($context['stale_followup']) && !empty($learnedPreferences['action_observation']) && $sendEligible && $hasRecipient) {
                $actions[] = [
                    'action' => 'resend_document',
                    'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                    'reason' => 'Proposal follow-up is stale and prior demonstrations favor a resend.',
                ];
            } elseif ($sendEligible && $hasRecipient) {
                $actions[] = [
                    'action' => 'send_document',
                    'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                    'reason' => $preferredChannel !== ''
                        ? 'Proposal-stage document is eligible for delivery; learned preference favors ' . $preferredChannel . '.'
                        : 'Proposal-stage document is present and eligible for delivery.',
                ];
            }
        }

        if ($stage === 'negotiation') {
            if (!$invoice) {
                $actions[] = [
                    'action' => 'create_draft',
                    'document_type' => (string) ($defaults['negotiation'] ?? 'quote'),
                    'reason' => 'Negotiation started without a commercial document.',
                ];
            } else {
                $shouldRevise = $triggerType === 'communication'
                    || !empty($context['stale_revision'])
                    || !empty($context['needs_revision']);
                if ($shouldRevise) {
                    $actions[] = [
                        'action' => 'revise_document',
                        'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                        'reason' => 'Negotiation evidence suggests a safe revision.',
                    ];
                    if ($sendEligible && $hasRecipient) {
                        $actions[] = [
                            'action' => 'resend_document',
                            'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                            'reason' => !empty($context['stale_revision'])
                                ? 'Negotiation follow-up is stale and document should be resent.'
                                : 'Negotiation revision is ready to resend to the customer.',
                        ];
                    }
                } elseif (!empty($context['stale_followup']) && $sendEligible && $hasRecipient) {
                    $actions[] = [
                        'action' => 'resend_document',
                        'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                        'reason' => 'Negotiation follow-up is stale and document should be resent.',
                    ];
                }
            }
        }

        if ($stage === 'closed_won' && $invoice) {
            if (($invoice['document_type'] ?? '') !== 'invoice') {
                $actions[] = [
                    'action' => 'convert_to_invoice',
                    'document_type' => 'invoice',
                    'reason' => 'Won deal should become a final invoice.',
                ];
            } else {
                $actions[] = [
                    'action' => 'finalize_invoice',
                    'document_type' => 'invoice',
                    'reason' => 'Won deal final invoice can be finalized.',
                ];
                if ($sendEligible && $hasRecipient) {
                    $actions[] = [
                        'action' => 'send_document',
                        'document_type' => 'invoice',
                        'reason' => 'Won deal final invoice is ready for delivery.',
                    ];
                }
            }
        }

        if ($stage === 'closed_lost' && $invoice && in_array((string) ($invoice['status'] ?? ''), ['draft', 'sent', 'viewed', 'accepted', 'revised'], true)) {
            $actions[] = [
                'action' => 'cancel_document',
                'document_type' => (string) ($invoice['document_type'] ?? 'quote'),
                'reason' => 'Open commercial document should be cancelled when deal is lost.',
            ];
        }

        if (!empty($context['invoice']) && !empty($context['should_mark_overdue'])) {
            $actions[] = [
                'action' => 'mark_overdue',
                'document_type' => (string) ($invoice['document_type'] ?? 'invoice'),
                'reason' => 'Invoice is past due and unpaid.',
            ];
        }

        if (!empty($context['invoice']) && !empty($context['should_mark_paid'])) {
            $actions[] = [
                'action' => 'mark_paid',
                'document_type' => (string) ($invoice['document_type'] ?? 'invoice'),
                'reason' => 'Inbound payment evidence indicates the invoice can be marked paid.',
            ];
        }

        return $actions;
    }
}
