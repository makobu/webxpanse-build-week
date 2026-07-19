<?php

namespace CRM\Services;

use CRM\Modules\CompanyProfile;

class EmailAssistantDraftService
{
    private AIService $aiService;
    private AIContextAssemblyService $contextAssembly;
    private AIRetrievalQualityService $retrievalQuality;
    private CompanyProfile $companyProfile;
    private ?CustomerReplyAssistantService $replyAssistantService = null;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->contextAssembly = new AIContextAssemblyService();
        $this->retrievalQuality = new AIRetrievalQualityService();
        $this->companyProfile = new CompanyProfile();
    }

    public function draftProposalReply(array $context): array
    {
        return $this->buildCommercialDraft('proposal_reply', $context, 'Proposal and pricing details');
    }

    public function draftNegotiationReply(array $context): array
    {
        return $this->buildCommercialDraft('negotiation_reply', $context, 'Updated commercial terms');
    }

    public function draftInvoiceReply(array $context): array
    {
        return $this->buildCommercialDraft('invoice_reply', $context, 'Invoice details');
    }

    public function draftOverdueReminder(array $context): array
    {
        return $this->buildCommercialDraft('overdue_reminder', $context, 'Invoice payment reminder');
    }

    public function draftInternalSummary(array $context): array
    {
        $lines = [];
        if (!empty($context['blocked_reasons'])) {
            $lines[] = 'Blocked reasons: ' . implode(', ', $context['blocked_reasons']);
        }
        if (!empty($context['actions'])) {
            $lines[] = 'Planned actions: ' . implode(', ', array_map(static fn(array $action): string => (string) ($action['action'] ?? ''), $context['actions']));
        }
        if (!empty($context['explanations'])) {
            $lines[] = implode("\n", array_map('strval', $context['explanations']));
        }
        $body = trim(implode("\n\n", array_filter($lines)));
        return [
            'subject' => 'Assistant action summary',
            'plain_body' => $body !== '' ? $body : 'No action summary available.',
            'html_body' => nl2br(htmlspecialchars($body !== '' ? $body : 'No action summary available.', ENT_QUOTES, 'UTF-8')),
            'tone' => 'internal',
            'purpose' => 'summary',
            'explanation' => 'Internal assistant summary',
        ];
    }

    private function buildCommercialDraft(string $purpose, array $context, string $defaultSubject): array
    {
        $contact = $context['contact'] ?? [];
        $deal = $context['deal'] ?? [];
        $invoice = $context['invoice'] ?? [];
        $threadSummary = trim((string) ($context['thread_summary'] ?? ''));
        $messages = $this->sanitizeMessages((array) ($context['messages'] ?? []));
        $contactName = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
        $invoiceNumber = (string) ($invoice['invoice_number'] ?? '');
        $profile = $this->companyProfile->get() ?: [];
        $companyName = trim((string) ($profile['company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = brandProductName();
        }
        $subjectUserId = (int) ($context['actor_user_id'] ?? $context['subject_user_id'] ?? 0);
        if ($subjectUserId <= 0) {
            $subjectUserId = (int) ($contact['assigned_to'] ?? 0);
        }
        $draftStyle = $this->replyAssistantService()->resolveDraftStyleContext($subjectUserId, 'email', [], [
            'thread_context' => ['messages' => $messages],
            'company_profile' => $profile,
            'contact' => $contact,
        ]);

        $bundle = $this->contextAssembly->buildContextBundle('commercial_assistant', 'assistant_commercial_reply', [
            'purpose' => $purpose,
            'contact' => $contact,
            'deal' => $deal,
            'invoice' => $invoice,
            'thread_summary' => $threadSummary,
            'messages' => $messages,
            'pricing_settings' => $context['pricing_settings'] ?? [],
            'approval_context' => $context['approval_context'] ?? [],
            'commercial_policy_snapshot' => $context['commercial_policy_snapshot'] ?? [],
            'draft_style' => $draftStyle,
        ]);
        $bundleQuality = $this->retrievalQuality->scoreBundle($bundle);
        $resolvedPrompt = $this->aiService->buildPromptFromRegistry('commercial_assistant', 'assistant_commercial_reply', $bundle, [
            'purpose' => $purpose,
            'contact_name' => $contactName,
              'deal_title' => (string) ($deal['title'] ?? ''),
              'deal_stage' => (string) ($deal['stage'] ?? ''),
              'invoice_number' => $invoiceNumber,
              'thread_summary' => $threadSummary,
              'latest_customer_message' => $this->latestInboundMessage($messages),
              'company_name' => $companyName,
              'draft_style' => $draftStyle,
          ]);
        $raw = $this->aiService->processWithPrompt('email_assistant_commercial_reply', $resolvedPrompt);
        $parsed = json_decode((string) $raw, true);

        $plain = trim((string) ($parsed['plain_body'] ?? $parsed['body'] ?? ''));
        if ($plain === '') {
            $plain = $this->buildFallbackBody($purpose, $contactName, $invoiceNumber, $deal);
        }
        $subject = trim((string) ($parsed['subject'] ?? ''));
        if ($subject === '') {
            $subject = $defaultSubject . ($invoiceNumber !== '' ? ' - ' . $invoiceNumber : '');
        }

        return $this->replyAssistantService()->formatDraftForChannel([
            'subject' => $subject,
            'plain_body' => $plain,
            'html_body' => nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')),
            'tone' => 'professional',
            'purpose' => $purpose,
            'explanation' => trim((string) ($parsed['explanation'] ?? 'Commercial reply drafted from thread and deal context.')),
            'prompt_key' => 'assistant_commercial_reply',
            'prompt_version' => (int) ($resolvedPrompt['prompt_version'] ?? 0),
            'context_bundle_quality' => $bundleQuality,
        ], $draftStyle, 'email', [
            'contact' => $contact,
            'thread_context' => ['messages' => $messages],
        ]);
    }

    private function replyAssistantService(): CustomerReplyAssistantService
    {
        if (!$this->replyAssistantService instanceof CustomerReplyAssistantService) {
            $this->replyAssistantService = new CustomerReplyAssistantService();
        }

        return $this->replyAssistantService;
    }

    private function latestInboundMessage(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['direction'] ?? '') === 'inbound') {
                $body = trim((string) ($message['clean_body'] ?? ''));
                if ($body === '') {
                    $body = ConversationMessageCleaner::cleanBody((string) ($message['body'] ?? ''), 'email');
                }
                if ($body !== '') {
                    return $body;
                }
            }
        }
        return '';
    }

    private function sanitizeMessages(array $messages): array
    {
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            if (trim((string) ($message['clean_body'] ?? '')) === '') {
                $message['clean_body'] = ConversationMessageCleaner::cleanBody((string) ($message['body'] ?? ''), 'email');
            }
            $messages[$index] = $message;
        }

        return $messages;
    }

    private function buildFallbackBody(string $purpose, string $contactName, string $invoiceNumber, array $deal): string
    {
        $name = $contactName !== '' ? $contactName : 'there';
        return match ($purpose) {
            'invoice_reply' => "Hi {$name},\n\nPlease find the requested invoice details" . ($invoiceNumber !== '' ? " for {$invoiceNumber}" : '') . ". Let me know if you need anything adjusted.\n\nBest regards,",
            'overdue_reminder' => "Hi {$name},\n\nThis is a reminder that the outstanding invoice" . ($invoiceNumber !== '' ? " {$invoiceNumber}" : '') . " is due. Please let me know if you need a copy resent or have any questions.\n\nBest regards,",
            'negotiation_reply' => "Hi {$name},\n\nThanks for the feedback. I have reviewed the commercial terms" . (!empty($deal['title']) ? " for {$deal['title']}" : '') . " and prepared the next update.\n\nBest regards,",
            default => "Hi {$name},\n\nThanks for your message. I have prepared the requested commercial details and can share the latest version with you.\n\nBest regards,",
        };
    }
}
