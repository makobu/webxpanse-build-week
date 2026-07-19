<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AIThreadSummarizer;
use CRM\Modules\ConversationThreads;
use CRM\Modules\Invoices;
use CRM\Services\WorkspaceContext;

class EmailAssistantThreadContextService
{
    private ConversationThreads $threads;
    private AIThreadSummarizer $summarizer;
    private Invoices $invoices;

    public function __construct()
    {
        $this->threads = new ConversationThreads();
        $this->summarizer = new AIThreadSummarizer();
        $this->invoices = new Invoices();
    }

    public function buildForCommunication(int $communicationId): array
    {
        $communication = $this->queryOneInWorkspace('communications', 'id = ?', [$communicationId]);
        if (!$communication) {
            return [];
        }

        $contactId = (int) ($communication['contact_id'] ?? 0);
        $channel = (string) ($communication['channel'] ?? 'email');
        $thread = $this->threads->findByCommunication($communicationId);
        $messages = $thread ? $this->threads->getCommunications((int) $thread['id']) : [$communication];

        return $this->buildContext($contactId, $channel, $messages, (int) ($thread['id'] ?? 0), $communicationId);
    }

    public function buildForContact(int $contactId, string $channel = 'email'): array
    {
        $thread = $this->threads->getOrCreateThread($contactId, $channel);
        $messages = !empty($thread['id']) ? $this->threads->getCommunications((int) $thread['id']) : [];
        return $this->buildContext($contactId, $channel, $messages, (int) ($thread['id'] ?? 0), 0);
    }

    public function extractCommercialSignals(array $messages): array
    {
        $latestInbound = '';
        $latestOutbound = '';
        $signals = [
            'asks_for_quote' => false,
            'asks_for_invoice' => false,
            'asks_for_discount' => false,
            'asks_for_revision' => false,
            'asks_for_terms' => false,
            'asks_for_resend' => false,
            'tone' => 'neutral',
        ];

        foreach (array_reverse($messages) as $message) {
            $body = strtolower(trim((string) ($message['clean_body'] ?? $message['body'] ?? '')));
            if ($body === '') {
                continue;
            }
            $direction = (string) ($message['direction'] ?? '');
            if ($direction === 'inbound' && $latestInbound === '') {
                $latestInbound = $body;
            }
            if ($direction === 'outbound' && $latestOutbound === '') {
                $latestOutbound = $body;
            }
        }

        $haystack = $latestInbound !== '' ? $latestInbound : $latestOutbound;
        if ($haystack !== '') {
            $signals['asks_for_quote'] = (bool) preg_match('/\b(quote|proposal|pricing|price list)\b/i', $haystack);
            $signals['asks_for_invoice'] = (bool) preg_match('/\b(invoice|bill|payment request)\b/i', $haystack);
            $signals['asks_for_discount'] = (bool) preg_match('/\b(discount|reduce|cheaper|lower|less)\b/i', $haystack);
            $signals['asks_for_revision'] = (bool) preg_match('/\b(update|revise|change|adjust|amend)\b/i', $haystack);
            $signals['asks_for_terms'] = (bool) preg_match('/\b(term|validity|deadline|payment term)\b/i', $haystack);
            $signals['asks_for_resend'] = (bool) preg_match('/\b(resend|send again|share again)\b/i', $haystack);
            $signals['tone'] = (bool) preg_match('/\b(urgent|asap|today|immediately)\b/i', $haystack) ? 'urgent' : 'neutral';
        }

        return $signals;
    }

    private function buildContext(int $contactId, string $channel, array $messages, int $threadId, int $communicationId): array
    {
        $contact = $this->queryOneInWorkspace('contacts', 'id = ?', [$contactId]) ?: [];
        $deal = $this->queryOneInWorkspace(
            'deals',
            'contact_id = ?',
            [$contactId],
            '*',
            "ORDER BY FIELD(stage, 'proposal', 'negotiation', 'closed_won', 'qualification', 'prospecting', 'closed_lost'), updated_at DESC, id DESC LIMIT 1"
        ) ?: [];
        $invoice = null;
        if (!empty($deal['id'])) {
            $invoice = $this->invoices->findLatestForDeal((int) $deal['id']);
        }
        if (!$invoice) {
            $invoiceRow = $this->queryOneInWorkspace('invoices', 'contact_id = ?', [$contactId], 'id', 'ORDER BY id DESC LIMIT 1');
            if ($invoiceRow) {
                $invoice = $this->invoices->getById((int) $invoiceRow['id']);
            }
        }
        $replyTargetEmail = $this->resolveReplyTargetEmail($contact, $messages);
        $messages = $this->sanitizeMessages($messages, $channel);

        $summary = '';
        if (!$this->shouldSkipThreadSummary() && $threadId > 0 && !empty($messages)) {
            try {
                $summary = $this->summarizer->summarizeThread($threadId);
            } catch (\Throwable $e) {
                $summary = '';
            }
        }

        return [
            'thread_id' => $threadId,
            'communication_id' => $communicationId,
            'channel' => $channel,
            'contact' => $contact,
            'deal' => $deal,
            'invoice' => $invoice,
            'reply_target_email' => $replyTargetEmail,
            'messages' => $messages,
            'thread_summary' => $summary,
            'signals' => $this->extractCommercialSignals($messages),
        ];
    }

    private function sanitizeMessages(array $messages, string $channel): array
    {
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }
            $message['clean_body'] = ConversationMessageCleaner::cleanBody((string) ($message['body'] ?? ''), $channel);
            $messages[$index] = $message;
        }

        return $messages;
    }

    private function resolveReplyTargetEmail(array $contact, array $messages): string
    {
        $contactEmail = trim((string) ($contact['email'] ?? ''));
        if ($contactEmail !== '') {
            return $contactEmail;
        }

        $workspaceId = (int) ($contact['workspace_id'] ?? 0);
        $internalCandidates = [];
        try {
            $emailIntegration = new EmailIntegrationService();
            $internalCandidates[] = $emailIntegration->getPreferredMainFromEmail('', $workspaceId > 0 ? $workspaceId : null);
            $internalCandidates[] = $emailIntegration->getStrictPreferredFromEmailForRole('outreach', $workspaceId > 0 ? $workspaceId : null);
            $internalCandidates[] = $emailIntegration->getStrictPreferredFromEmailForRole('nurture', $workspaceId > 0 ? $workspaceId : null);
            $internalCandidates[] = $emailIntegration->getStrictPreferredFromEmailForRole('assistant', $workspaceId > 0 ? $workspaceId : null);
        } catch (\Throwable $e) {
        }

        $internalEmails = array_filter(array_unique(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            $internalCandidates
        )));

        foreach (array_reverse($messages) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $direction = strtolower((string) ($message['direction'] ?? ''));
            $fromEmail = trim((string) ($message['from_email'] ?? ''));
            $toEmail = trim((string) ($message['to_email'] ?? ''));

            if ($direction === 'inbound' && $fromEmail !== '') {
                return $fromEmail;
            }

            foreach ([$fromEmail, $toEmail] as $candidate) {
                $candidate = trim((string) $candidate);
                if ($candidate === '') {
                    continue;
                }
                if (in_array(strtolower($candidate), $internalEmails, true)) {
                    continue;
                }
                return $candidate;
            }
        }

        return '';
    }

    private function queryOneInWorkspace(string $table, string $condition, array $params = [], string $select = '*', string $suffix = ''): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        $workspaceId = WorkspaceContext::currentWorkspaceId();
        $where = [$condition];
        if ($workspaceId !== null && $workspaceId > 0) {
            $where[] = 'workspace_id = ?';
            $params[] = (int) $workspaceId;
        }

        $sql = "SELECT {$select} FROM {$table} WHERE " . implode(' AND ', $where);
        if ($suffix !== '') {
            $sql .= ' ' . $suffix;
        }

        return Database::queryOne($sql, $params) ?: null;
    }

    private function shouldSkipThreadSummary(): bool
    {
        return in_array(strtolower((string) ($_ENV['DISABLE_THREAD_SUMMARY_AI'] ?? 'false')), ['1', 'true', 'yes', 'on'], true);
    }
}
