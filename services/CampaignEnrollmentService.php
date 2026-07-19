<?php
/**
 * Campaign Enrollment Service
 *
 * Manages contact enrollment lifecycle for campaigns.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class CampaignEnrollmentService
{
    public function enrollContacts(int $campaignId, array $contactIds, array $options = []): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $nextRunAt = $options['next_run_at'] ?? date('Y-m-d H:i:s');

        foreach (array_unique(array_map('intval', $contactIds)) as $contactId) {
            if ($contactId <= 0) {
                continue;
            }

            $existing = Database::queryOne(
                "SELECT id, status
                 FROM campaign_enrollments
                 WHERE workspace_id = ?
                   AND campaign_id = ?
                   AND contact_id = ?",
                [$this->resolveWorkspaceId($campaignId, $contactId), $campaignId, $contactId]
            );

            if ($existing) {
                if ($existing['status'] === 'active') {
                    $skipped++;
                    continue;
                }

                Database::execute(
                    "UPDATE campaign_enrollments
                     SET status = 'active',
                         current_step_order = 1,
                         current_step_id = NULL,
                         next_run_at = ?,
                         completed_at = NULL,
                         exit_reason = NULL
                     WHERE id = ?",
                    [$nextRunAt, $existing['id']]
                );
                $updated++;
                continue;
            }

            Database::execute(
                "INSERT INTO campaign_enrollments
                 (workspace_id, campaign_id, contact_id, status, current_step_order, next_run_at, metadata)
                 VALUES (?, ?, ?, 'active', 1, ?, ?)",
                [$this->resolveWorkspaceId($campaignId, $contactId), $campaignId, $contactId, $nextRunAt, json_encode($options['metadata'] ?? [])]
            );
            $created++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped
        ];
    }

    public function markCompleted(int $enrollmentId, string $reason = 'sequence_completed'): void
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        Database::execute(
            "UPDATE campaign_enrollments
             SET status = 'completed', completed_at = NOW(), exit_reason = ?, next_run_at = NULL
             WHERE id = ?" . ($workspaceId > 0 ? " AND workspace_id = ?" : ""),
            $workspaceId > 0 ? [$reason, $enrollmentId, $workspaceId] : [$reason, $enrollmentId]
        );
    }

    public function exitEnrollment(int $enrollmentId, string $reason): void
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        Database::execute(
            "UPDATE campaign_enrollments
             SET status = 'exited', completed_at = NOW(), exit_reason = ?, next_run_at = NULL
             WHERE id = ?" . ($workspaceId > 0 ? " AND workspace_id = ?" : ""),
            $workspaceId > 0 ? [$reason, $enrollmentId, $workspaceId] : [$reason, $enrollmentId]
        );
    }

    public function updateProgress(int $enrollmentId, ?int $stepId, int $stepOrder, ?string $nextRunAt): void
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        Database::execute(
            "UPDATE campaign_enrollments
             SET current_step_id = ?, current_step_order = ?, next_run_at = ?
             WHERE id = ?" . ($workspaceId > 0 ? " AND workspace_id = ?" : ""),
            $workspaceId > 0
                ? [$stepId, $stepOrder, $nextRunAt, $enrollmentId, $workspaceId]
                : [$stepId, $stepOrder, $nextRunAt, $enrollmentId]
        );
    }

    public function getActiveDueEnrollments(int $limit = 100): array
    {
        $limit = min(max((int) $limit, 1), 500);
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $sql = "SELECT ce.*, c.email, c.phone, c.first_name, c.last_name, c.company
             FROM campaign_enrollments ce
             JOIN contacts c ON c.id = ce.contact_id AND c.workspace_id = ce.workspace_id
             WHERE ce.status = 'active'
               AND (ce.next_run_at IS NULL OR ce.next_run_at <= NOW())";
        $params = [];
        if ($workspaceId > 0) {
            $sql .= " AND ce.workspace_id = ?";
            $params[] = $workspaceId;
        }
        $sql .= " ORDER BY ce.next_run_at ASC, ce.id ASC
             LIMIT " . $limit;
        return Database::query($sql, $params);
    }

    private function resolveWorkspaceId(int $campaignId, int $contactId): int
    {
        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $contact = Database::queryOne(
            "SELECT workspace_id
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        );
        $workspaceId = (int) ($contact['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $campaign = Database::queryOne(
            "SELECT workspace_id
             FROM campaigns
             WHERE id = ?
             LIMIT 1",
            [$campaignId]
        );

        return (int) ($campaign['workspace_id'] ?? 0);
    }
}
