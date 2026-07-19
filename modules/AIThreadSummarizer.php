<?php
/**
 * AI Thread Summarizer
 *
 * Generates AI summary of email/WhatsApp conversation threads.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\WorkspaceContext;

class AIThreadSummarizer
{
    private AIService $aiService;
    private ConversationThreads $threads;
    private UnifiedInbox $inbox;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->threads = new ConversationThreads();
        $this->inbox = new UnifiedInbox();
    }

    /**
     * Summarize a thread by thread ID.
     */
    public function summarizeThread(int $threadId): string
    {
        $communications = $this->threads->getCommunications($threadId);
        return $this->summarizeCommunications($communications);
    }

    /**
     * Summarize a thread by communication ID (resolves thread from that message).
     */
    public function summarizeByCommunication(int $communicationId): string
    {
        $thread = $this->threads->findByCommunication($communicationId);
        if ($thread) {
            return $this->summarizeThread((int) $thread['id']);
        }
        // Fallback: single communication
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $comm = $workspaceId > 0
            ? Database::queryOne("SELECT * FROM communications WHERE workspace_id = ? AND id = ?", [$workspaceId, $communicationId])
            : null;
        return $comm ? $this->summarizeCommunications([$comm]) : '';
    }

    /**
     * Summarize an array of communications.
     */
    public function summarizeCommunications(array $communications): string
    {
        if (empty($communications)) {
            return 'No messages in this thread.';
        }

        $lines = [];
        foreach ($communications as $c) {
            $dir = ($c['direction'] ?? '') === 'inbound' ? 'Contact' : 'Us';
            $date = date('M j, H:i', strtotime($c['created_at'] ?? 'now'));
            $subject = $c['subject'] ?? '';
            $body = strip_tags($c['body'] ?? '');
            $body = mb_substr($body, 0, 500);
            $lines[] = "[{$date}] {$dir}: " . ($subject ? "Subject: {$subject}\n" : '') . $body;
        }

        $text = implode("\n\n---\n\n", $lines);
        $text = mb_substr($text, 0, 6000); // Limit token usage

        return $this->aiService->process('thread_summary', [
            'text' => $text,
        ], ['surface' => 'customer_thread']);
    }

    /** @return array<string,mixed> */
    public function getLastProviderStatus(): array
    {
        return $this->aiService->getLastProviderStatus();
    }
}
