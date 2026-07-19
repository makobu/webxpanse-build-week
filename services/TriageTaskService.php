<?php
/**
 * Triage Task Service
 *
 * Creates idempotent follow-up tasks from inbox triage.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tasks;

class TriageTaskService
{
    private const MARKER = '[INBOX_TRIAGE]';

    private Tasks $tasks;

    public function __construct()
    {
        $this->tasks = new Tasks();
    }

    /**
     * Create follow-up task if no active triage task exists within dedupe window.
     *
     * @return int task_id or 0
     */
    public function createFollowUpTask(
        int $contactId,
        int $ownerId,
        int $communicationId,
        string $subject,
        int $dueHours,
        int $dedupeHours
    ): int {
        if ($contactId <= 0 || $ownerId <= 0 || $communicationId <= 0) {
            return 0;
        }

        $existing = Database::queryOne(
            "SELECT id
             FROM tasks
             WHERE contact_id = ?
               AND assigned_to = ?
               AND status NOT IN ('completed','cancelled')
               AND description LIKE ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY id DESC
             LIMIT 1",
            [$contactId, $ownerId, '%' . self::MARKER . '%', max(1, $dedupeHours)]
        );
        if (!empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $dueAt = date('Y-m-d H:i:s', strtotime('+' . max(1, $dueHours) . ' hours'));
        $title = 'Follow up: ' . trim($subject !== '' ? $subject : ('Inbound message #' . $communicationId));
        if (mb_strlen($title) > 220) {
            $title = mb_substr($title, 0, 220);
        }

        return $this->tasks->create([
            'title' => $title,
            'description' => self::MARKER . ' Communication #' . $communicationId,
            'contact_id' => $contactId,
            'assigned_to' => $ownerId,
            'created_by' => $ownerId,
            'status' => 'pending',
            'priority' => 'high',
            'due_date' => $dueAt,
        ]);
    }
}

