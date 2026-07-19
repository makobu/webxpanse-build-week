<?php
/**
 * Email Scheduler Module
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\EmailService;

class EmailScheduler
{
    private EmailService $emailService;
    
    public function __construct()
    {
        $this->emailService = new EmailService();
    }
    
    /**
     * Schedule email
     */
    public function schedule(int $contactId, string $to, string $subject, string $body, string $scheduledAt, array $options = []): string
    {
        $options['scheduled_at'] = $scheduledAt;
        $options['sender_profile'] = $options['sender_profile'] ?? $options['smtp_profile'] ?? 'outreach';
        return $this->emailService->send($contactId, $to, $subject, $body, $options);
    }
    
    /**
     * Get scheduled emails
     */
    public function getScheduled(?string $date = null, ?int $workspaceId = null): array
    {
        $date = $date ?? date('Y-m-d');

        $sql = "SELECT e.*, eq.id AS queue_id, eq.workspace_id AS queue_workspace_id, eq.scheduled_at
             FROM emails e
             JOIN email_queue eq
               ON e.id = eq.email_id
              AND (eq.workspace_id IS NULL OR eq.workspace_id = e.workspace_id)
             WHERE eq.status = 'pending'
             AND DATE(eq.scheduled_at) = ?";
        $params = [$date];

        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND e.workspace_id = ?";
            $params[] = $workspaceId;
        }

        $sql .= " ORDER BY eq.scheduled_at ASC";
        
        return Database::query(
            $sql,
            $params
        );
    }
}
