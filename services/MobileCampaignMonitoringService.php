<?php

namespace CRM\Services;

use CRM\Database;

class MobileCampaignMonitoringService
{
    private WorkspaceScopeService $workspaceScope;
    private CampaignOrchestrator $campaigns;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->campaigns = new CampaignOrchestrator();
    }

    /** @return array{items:array<int,array<string,mixed>>,summary:array<string,int>,generated_at:string} */
    public function dashboard(): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $rows = Database::query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM campaign_enrollments ce
                     WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id) AS enrollment_count,
                    (SELECT COUNT(*) FROM campaign_enrollments ce
                     WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'active') AS active_enrollments,
                    (SELECT COUNT(*) FROM campaign_enrollments ce
                     WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'completed') AS completed_enrollments,
                    (SELECT COUNT(*) FROM campaign_enrollments ce
                     WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'failed') AS failed_enrollments,
                    (SELECT MIN(ce.next_run_at) FROM campaign_enrollments ce
                     WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id
                       AND ce.status = 'active' AND ce.next_run_at IS NOT NULL) AS next_run_at,
                    (SELECT COUNT(*) FROM campaign_step_executions cse
                     WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id) AS execution_count,
                    (SELECT COUNT(*) FROM campaign_step_executions cse
                     WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id
                       AND cse.status IN ('completed', 'skipped')) AS completed_executions,
                    (SELECT COUNT(*) FROM campaign_step_executions cse
                     WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id AND cse.status = 'failed') AS failed_executions,
                    (SELECT MAX(cse.created_at) FROM campaign_step_executions cse
                     WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id AND cse.status = 'failed') AS last_failure_at,
                    (SELECT COUNT(*) FROM marketing_tracking_events mte
                     WHERE mte.workspace_id = c.workspace_id AND mte.campaign_id = c.id) AS tracking_events,
                    (SELECT COUNT(*) FROM marketing_conversion_events mce
                     INNER JOIN marketing_tracking_events mte ON mte.id = mce.tracking_event_id
                     WHERE mce.workspace_id = c.workspace_id AND mte.workspace_id = c.workspace_id
                       AND mte.campaign_id = c.id) AS conversions
             FROM campaigns c
             WHERE c.workspace_id = ?
               AND c.status IN ('active', 'paused', 'completed')
             ORDER BY FIELD(c.status, 'active', 'paused', 'completed'), c.updated_at DESC",
            [$workspaceId]
        );

        $acknowledgedSignals = [];
        foreach (Database::query(
            "SELECT entity_id, signal_key
             FROM mobile_decision_acknowledgements
             WHERE workspace_id = ? AND entity_type = 'campaign'",
            [$workspaceId]
        ) as $acknowledgement) {
            $acknowledgedSignals[(int) $acknowledgement['entity_id'] . ':' . (string) $acknowledgement['signal_key']] = true;
        }
        $items = array_map(
            fn(array $row): array => $this->serializeCampaign($row, $acknowledgedSignals),
            $rows
        );
        $summary = [
            'active' => 0,
            'paused' => 0,
            'attention' => 0,
            'unacknowledged' => 0,
        ];
        foreach ($items as $item) {
            if ($item['status'] === 'active') {
                $summary['active']++;
            } elseif ($item['status'] === 'paused') {
                $summary['paused']++;
            }
            if ($item['health'] === 'attention') {
                $summary['attention']++;
                if (!$item['acknowledged']) {
                    $summary['unacknowledged']++;
                }
            }
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'generated_at' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function control(int $campaignId, string $action, int $userId, string $note = ''): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $campaign = $this->campaigns->getCampaignById($campaignId);
        if (!$campaign || (int) ($campaign['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Campaign not found in the active workspace.');
        }

        $status = (string) ($campaign['status'] ?? '');
        if ($action === 'pause') {
            if ($status !== 'active') {
                throw new \RuntimeException('Only an active campaign can be paused.');
            }
            $this->campaigns->pauseCampaign($campaignId);
        } elseif ($action === 'resume') {
            if ($status !== 'paused') {
                throw new \RuntimeException('Only a paused campaign can be resumed.');
            }
            $this->campaigns->resumeCampaign($campaignId);
        } elseif ($action === 'acknowledge') {
            $row = $this->campaignRow($campaignId);
            $failed = (int) ($row['failed_enrollments'] ?? 0) + (int) ($row['failed_executions'] ?? 0);
            if ($failed <= 0) {
                throw new \RuntimeException('This campaign has no current failure signal to acknowledge.');
            }
            $signalKey = $this->signalKey($row);
            Database::execute(
                "INSERT INTO mobile_decision_acknowledgements
                 (workspace_id, entity_type, entity_id, signal_key, acknowledged_by_user_id, note, acknowledged_at)
                 VALUES (?, 'campaign', ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    acknowledged_by_user_id = VALUES(acknowledged_by_user_id),
                    note = VALUES(note),
                    acknowledged_at = NOW()",
                [
                    $workspaceId,
                    $campaignId,
                    $signalKey,
                    $userId > 0 ? $userId : null,
                    trim($note) !== '' ? substr(trim($note), 0, 1000) : null,
                ]
            );
        } else {
            throw new \InvalidArgumentException('Unsupported campaign action.');
        }

        return $this->serializeCampaign($this->campaignRow($campaignId));
    }

    /** @return array<string,mixed> */
    private function campaignRow(int $campaignId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $row = Database::queryOne(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM campaign_enrollments ce WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id) AS enrollment_count,
                    (SELECT COUNT(*) FROM campaign_enrollments ce WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'active') AS active_enrollments,
                    (SELECT COUNT(*) FROM campaign_enrollments ce WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'completed') AS completed_enrollments,
                    (SELECT COUNT(*) FROM campaign_enrollments ce WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'failed') AS failed_enrollments,
                    (SELECT MIN(ce.next_run_at) FROM campaign_enrollments ce WHERE ce.workspace_id = c.workspace_id AND ce.campaign_id = c.id AND ce.status = 'active') AS next_run_at,
                    (SELECT COUNT(*) FROM campaign_step_executions cse WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id) AS execution_count,
                    (SELECT COUNT(*) FROM campaign_step_executions cse WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id AND cse.status IN ('completed', 'skipped')) AS completed_executions,
                    (SELECT COUNT(*) FROM campaign_step_executions cse WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id AND cse.status = 'failed') AS failed_executions,
                    (SELECT MAX(cse.created_at) FROM campaign_step_executions cse WHERE cse.workspace_id = c.workspace_id AND cse.campaign_id = c.id AND cse.status = 'failed') AS last_failure_at,
                    (SELECT COUNT(*) FROM marketing_tracking_events mte WHERE mte.workspace_id = c.workspace_id AND mte.campaign_id = c.id) AS tracking_events,
                    (SELECT COUNT(*) FROM marketing_conversion_events mce INNER JOIN marketing_tracking_events mte ON mte.id = mce.tracking_event_id WHERE mce.workspace_id = c.workspace_id AND mte.workspace_id = c.workspace_id AND mte.campaign_id = c.id) AS conversions
             FROM campaigns c
             WHERE c.workspace_id = ? AND c.id = ?
             LIMIT 1",
            [$workspaceId, $campaignId]
        );
        if (!$row) {
            throw new \RuntimeException('Campaign not found in the active workspace.');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function serializeCampaign(array $row, ?array $acknowledgedSignals = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $failedExecutions = (int) ($row['failed_executions'] ?? 0);
        $failedEnrollments = (int) ($row['failed_enrollments'] ?? 0);
        $failureCount = $failedExecutions + $failedEnrollments;
        $executionCount = (int) ($row['execution_count'] ?? 0);
        $completedExecutions = (int) ($row['completed_executions'] ?? 0);
        $signalKey = $this->signalKey($row);
        $acknowledged = false;
        if ($failureCount > 0) {
            if ($acknowledgedSignals !== null) {
                $acknowledged = !empty($acknowledgedSignals[(int) ($row['id'] ?? 0) . ':' . $signalKey]);
            } else {
                $acknowledged = Database::queryOne(
                    "SELECT id FROM mobile_decision_acknowledgements
                     WHERE workspace_id = ? AND entity_type = 'campaign' AND entity_id = ? AND signal_key = ?
                     LIMIT 1",
                    [$workspaceId, (int) ($row['id'] ?? 0), $signalKey]
                ) !== null;
            }
        }
        $channels = json_decode((string) ($row['channel_mix'] ?? '[]'), true);
        if (!is_array($channels)) {
            $channels = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? 'Campaign'),
            'objective' => (string) ($row['objective'] ?? ''),
            'status' => (string) ($row['status'] ?? 'draft'),
            'health' => $failureCount > 0 ? 'attention' : 'healthy',
            'acknowledged' => $acknowledged,
            'channels' => array_values(array_filter(array_map('strval', $channels))),
            'enrollment_count' => (int) ($row['enrollment_count'] ?? 0),
            'active_enrollments' => (int) ($row['active_enrollments'] ?? 0),
            'completed_enrollments' => (int) ($row['completed_enrollments'] ?? 0),
            'failed_enrollments' => $failedEnrollments,
            'execution_count' => $executionCount,
            'completed_executions' => $completedExecutions,
            'failed_executions' => $failedExecutions,
            'progress_percent' => $executionCount > 0 ? round(($completedExecutions / $executionCount) * 100, 1) : 0.0,
            'tracking_events' => (int) ($row['tracking_events'] ?? 0),
            'conversions' => (int) ($row['conversions'] ?? 0),
            'next_run_at' => $row['next_run_at'] ?? null,
            'last_failure_at' => $row['last_failure_at'] ?? null,
            'scheduled_start_at' => $row['scheduled_start_at'] ?? null,
            'scheduled_end_at' => $row['scheduled_end_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'web_url' => 'campaign_view.php?id=' . (int) ($row['id'] ?? 0),
            'allowed_actions' => array_values(array_filter([
                (string) ($row['status'] ?? '') === 'active' ? 'pause' : null,
                (string) ($row['status'] ?? '') === 'paused' ? 'resume' : null,
                $failureCount > 0 && !$acknowledged ? 'acknowledge' : null,
            ])),
        ];
    }

    private function signalKey(array $row): string
    {
        return hash('sha256', implode(':', [
            (int) ($row['id'] ?? 0),
            (int) ($row['failed_enrollments'] ?? 0),
            (int) ($row['failed_executions'] ?? 0),
            (string) ($row['last_failure_at'] ?? ''),
        ]));
    }
}
