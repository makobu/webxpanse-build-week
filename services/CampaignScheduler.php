<?php
/**
 * Campaign Scheduler
 *
 * Converts due enrollments into executable campaign queue jobs.
 */

namespace CRM\Services;

use CRM\Database;

class CampaignScheduler
{
    private CampaignEnrollmentService $enrollments;

    public function __construct()
    {
        $this->enrollments = new CampaignEnrollmentService();
    }

    public function scheduleDueEnrollments(int $limit = 200): int
    {
        $rows = $this->enrollments->getActiveDueEnrollments($limit);
        $scheduled = 0;

        foreach ($rows as $enrollment) {
            $campaignId = (int) $enrollment['campaign_id'];
            $stepOrder = (int) ($enrollment['current_step_order'] ?? 1);
            $contactId = (int) $enrollment['contact_id'];
            $workspaceId = (int) ($enrollment['workspace_id'] ?? 0);

            if ($workspaceId <= 0) {
                continue;
            }

            if ($this->shouldExitEnrollment($campaignId, $contactId, $workspaceId)) {
                $this->enrollments->exitEnrollment((int) $enrollment['id'], 'exit_criteria_met');
                continue;
            }

            $step = Database::queryOne(
                "SELECT * FROM campaign_steps
                 WHERE campaign_id = ? AND step_order = ? AND is_active = 1
                 LIMIT 1",
                [$campaignId, $stepOrder]
            );

            if (!$step) {
                $this->enrollments->markCompleted((int) $enrollment['id']);
                continue;
            }

            $alreadyQueued = Database::queryOne(
                "SELECT id FROM campaign_queue
                 WHERE workspace_id = ? AND campaign_id = ? AND enrollment_id = ? AND step_id = ? AND status IN ('pending','processing')
                 LIMIT 1",
                [$workspaceId, $campaignId, $enrollment['id'], $step['id']]
            );

            if ($alreadyQueued) {
                continue;
            }

            Database::execute(
                "INSERT INTO campaign_queue (workspace_id, campaign_id, enrollment_id, step_id, contact_id, execute_at, status)
                 VALUES (?, ?, ?, ?, ?, NOW(), 'pending')",
                [$workspaceId, $campaignId, $enrollment['id'], $step['id'], $contactId]
            );

            $this->enrollments->updateProgress((int) $enrollment['id'], (int) $step['id'], $stepOrder, null);
            $scheduled++;
        }

        return $scheduled;
    }

    private function shouldExitEnrollment(int $campaignId, int $contactId, int $workspaceId): bool
    {
        $dealWon = Database::queryOne(
            "SELECT id FROM deals
             WHERE workspace_id = ? AND contact_id = ? AND stage = 'closed_won'
             ORDER BY updated_at DESC
             LIMIT 1",
            [$workspaceId, $contactId]
        );
        if ($dealWon) {
            return true;
        }

        $suppressedEmail = Database::queryOne(
            "SELECT sl.id
             FROM suppression_list sl
             JOIN contacts c ON c.email = sl.value AND c.workspace_id = ?
             WHERE sl.channel = 'email'
               AND c.id = ?
               AND (sl.campaign_id IS NULL OR sl.campaign_id = ?)
             LIMIT 1",
            [$workspaceId, $contactId, $campaignId]
        );

        $suppressedPhone = Database::queryOne(
            "SELECT sl.id
             FROM suppression_list sl
             JOIN contacts c ON c.phone = sl.value AND c.workspace_id = ?
             WHERE sl.channel IN ('sms', 'whatsapp')
               AND c.id = ?
               AND (sl.campaign_id IS NULL OR sl.campaign_id = ?)
             LIMIT 1",
            [$workspaceId, $contactId, $campaignId]
        );

        return (bool) ($suppressedEmail || $suppressedPhone);
    }
}
