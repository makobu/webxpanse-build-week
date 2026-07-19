<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;

class PlatformRuntimeOperationsService
{
    private OperatorAuditService $operatorAudit;
    private WorkflowScheduler $workflowScheduler;
    private ScheduledReportRuntimeService $scheduledReports;
    private EmailService $emailService;
    private SMSService $smsService;
    private SMSQueue $smsQueue;
    private WhatsAppQueueProcessor $whatsAppQueueProcessor;
    private CampaignQueueProcessor $campaignQueueProcessor;

    /** @var array<string,bool> */
    private static array $tableExists = [];

    public function __construct(
        ?OperatorAuditService $operatorAudit = null,
        ?WorkflowScheduler $workflowScheduler = null,
        ?ScheduledReportRuntimeService $scheduledReports = null,
        ?EmailService $emailService = null,
        ?SMSService $smsService = null,
        ?SMSQueue $smsQueue = null,
        ?WhatsAppQueueProcessor $whatsAppQueueProcessor = null,
        ?CampaignQueueProcessor $campaignQueueProcessor = null
    ) {
        $this->operatorAudit = $operatorAudit ?? new OperatorAuditService();
        $this->workflowScheduler = $workflowScheduler ?? new WorkflowScheduler();
        $this->scheduledReports = $scheduledReports ?? new ScheduledReportRuntimeService();
        $this->emailService = $emailService ?? new EmailService();
        $this->smsService = $smsService ?? new SMSService();
        $this->smsQueue = $smsQueue ?? new SMSQueue();
        $this->whatsAppQueueProcessor = $whatsAppQueueProcessor ?? new WhatsAppQueueProcessor();
        $this->campaignQueueProcessor = $campaignQueueProcessor ?? new CampaignQueueProcessor();
    }

    /**
     * @return array<string,mixed>
     */
    public function getWorkspaceHealthData(int $workspaceId): array
    {
        $this->assertWorkspaceExists($workspaceId);

        $subsystems = [
            'email_queue' => $this->emailQueueHealth($workspaceId),
            'email_fetch' => $this->emailFetchHealth($workspaceId),
            'calendar_sync' => $this->calendarSyncHealth($workspaceId),
            'sms_queue' => $this->smsQueueHealth($workspaceId),
            'whatsapp_queue' => $this->whatsAppQueueHealth($workspaceId),
            'workflow_runtime' => $this->workflowRuntimeHealth($workspaceId),
            'scheduled_reports' => $this->scheduledReportsHealth($workspaceId),
            'campaign_queue' => $this->campaignQueueHealth($workspaceId),
            'ai_autoresponder_queue' => $this->aiAutoresponderHealth($workspaceId),
        ];

        $subsystems = array_filter($subsystems, static fn(array $subsystem): bool => !empty($subsystem['available']));
        $replayableFailures = $this->listReplayableFailures($workspaceId, null, 25);

        $openFailureCount = 0;
        $oldestPendingAt = null;
        foreach ($subsystems as $subsystem) {
            $openFailureCount += (int) ($subsystem['failed_count'] ?? 0);
            $candidate = (string) ($subsystem['oldest_pending_at'] ?? '');
            if ($candidate !== '' && ($oldestPendingAt === null || strtotime($candidate) < strtotime($oldestPendingAt))) {
                $oldestPendingAt = $candidate;
            }
        }

        $lastOperatorAction = $this->operatorAudit->listForWorkspace($workspaceId, 1)[0] ?? null;

        return [
            'summary' => [
                'open_failure_count' => $openFailureCount,
                'oldest_pending_at' => $oldestPendingAt,
                'oldest_pending_age_minutes' => $oldestPendingAt !== null ? max(0, (int) floor((time() - strtotime($oldestPendingAt)) / 60)) : null,
                'last_operator_action' => $lastOperatorAction,
                'replayable_failure_count' => count($replayableFailures),
            ],
            'subsystems' => $subsystems,
            'replayable_failures' => $replayableFailures,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listReplayableFailures(int $workspaceId, ?string $subsystem = null, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $items = [];

        $push = function (string $subsystemKey, callable $loader) use ($workspaceId, $subsystem, &$items): void {
            if ($subsystem !== null && $subsystem !== $subsystemKey) {
                return;
            }
            foreach ($loader($workspaceId) as $row) {
                $items[] = $row;
            }
        };

        $push('email_queue', fn(int $wid): array => $this->loadReplayableEmailQueueFailures($wid));
        $push('sms_queue', fn(int $wid): array => $this->loadReplayableSmsQueueFailures($wid));
        $push('whatsapp_queue', fn(int $wid): array => $this->loadReplayableWhatsAppQueueFailures($wid));
        $push('campaign_queue', fn(int $wid): array => $this->loadReplayableCampaignQueueFailures($wid));
        $push('workflow_retry', fn(int $wid): array => $this->loadReplayableWorkflowRetryFailures($wid));
        $push('scheduled_report_run', fn(int $wid): array => $this->loadReplayableScheduledReportFailures($wid));

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<string,mixed>
     */
    public function replayFailure(int $workspaceId, string $subsystem, int $rowId, int $actorUserId, ?string $reason = null): array
    {
        $this->assertWorkspaceExists($workspaceId);
        $subsystem = trim($subsystem);
        if ($rowId <= 0 || $subsystem === '') {
            throw new \RuntimeException('A replayable subsystem row is required.');
        }

        try {
            $result = match ($subsystem) {
                'email_queue' => $this->replayEmailQueueFailure($workspaceId, $rowId),
                'sms_queue' => $this->replaySmsQueueFailure($workspaceId, $rowId),
                'whatsapp_queue' => $this->replayWhatsAppQueueFailure($workspaceId, $rowId),
                'campaign_queue' => $this->replayCampaignQueueFailure($workspaceId, $rowId),
                'workflow_retry' => $this->replayWorkflowRetryFailure($workspaceId, $rowId),
                'scheduled_report_run' => $this->replayScheduledReportFailure($workspaceId, $rowId),
                default => throw new \RuntimeException('Unsupported runtime replay subsystem.'),
            };

            $this->operatorAudit->log(
                'runtime_failure_replay',
                $actorUserId,
                $workspaceId,
                $reason,
                [
                    'subsystem' => $subsystem,
                    'row_id' => $rowId,
                    'result_status' => 'succeeded',
                    'result_message' => (string) ($result['message'] ?? 'Runtime replay completed.'),
                ]
            );

            return $result;
        } catch (\Throwable $e) {
            $this->operatorAudit->log(
                'runtime_failure_replay',
                $actorUserId,
                $workspaceId,
                $reason,
                [
                    'subsystem' => $subsystem,
                    'row_id' => $rowId,
                    'result_status' => 'failed',
                    'result_message' => $this->truncate($e->getMessage(), 500),
                ]
            );
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function emailQueueHealth(int $workspaceId): array
    {
        if (!$this->tableExists('email_queue')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN status = 'completed' THEN COALESCE(processed_at, queue_created_at) END) AS last_success_at,
                MAX(CASE WHEN status = 'failed' THEN COALESCE(processed_at, queue_created_at) END) AS last_failure_at,
                MIN(CASE WHEN status IN ('pending', 'processing') THEN COALESCE(scheduled_at, queue_created_at) END) AS oldest_pending_at
             FROM (
                SELECT id, workspace_id, status, scheduled_at, processed_at, created_at AS queue_created_at
                FROM email_queue
                WHERE workspace_id = ?
             ) q",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => true,
            'label' => 'Email Queue',
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'processing_count' => (int) ($counts['processing_count'] ?? 0),
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => $counts['oldest_pending_at'] ?? null,
            'replay_supported' => true,
            'recent_failures' => $this->loadReplayableEmailQueueFailures($workspaceId, 5),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emailFetchHealth(int $workspaceId): array
    {
        if (!$this->tableExists('email_fetch_log')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN status = 'success' THEN COALESCE(last_fetch_at, created_at) END) AS last_success_at,
                MAX(CASE WHEN status = 'failed' THEN COALESCE(last_fetch_at, updated_at, created_at) END) AS last_failure_at
             FROM email_fetch_log
             WHERE workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        $recentFailures = Database::query(
            "SELECT
                id AS row_id,
                'email_fetch' AS subsystem,
                source,
                status,
                COALESCE(error_message, 'Email fetch failed') AS failure_reason,
                COALESCE(last_fetch_at, updated_at, created_at) AS occurred_at
             FROM email_fetch_log
             WHERE workspace_id = ?
               AND status = 'failed'
             ORDER BY COALESCE(last_fetch_at, updated_at, created_at) DESC
             LIMIT 5",
            [$workspaceId]
        );

        return [
            'available' => true,
            'label' => 'Inbound Email Fetch',
            'pending_count' => 0,
            'processing_count' => 0,
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => null,
            'replay_supported' => false,
            'recent_failures' => $recentFailures,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function calendarSyncHealth(int $workspaceId): array
    {
        if (!$this->tableExists('calendar_integrations')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                COUNT(*) AS integration_count,
                SUM(CASE WHEN sync_enabled = 1 THEN 1 ELSE 0 END) AS enabled_count,
                MAX(last_sync_at) AS last_success_at,
                MAX(updated_at) AS last_activity_at
             FROM calendar_integrations
             WHERE workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => (int) ($counts['integration_count'] ?? 0) > 0,
            'label' => 'Calendar Sync',
            'pending_count' => 0,
            'processing_count' => (int) ($counts['enabled_count'] ?? 0),
            'failed_count' => 0,
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => null,
            'oldest_pending_at' => null,
            'replay_supported' => false,
            'recent_failures' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function smsQueueHealth(int $workspaceId): array
    {
        if (!$this->tableExists('sms_queue')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN status = 'completed' THEN COALESCE(last_attempt_at, created_at) END) AS last_success_at,
                MAX(CASE WHEN status = 'failed' THEN COALESCE(last_attempt_at, created_at) END) AS last_failure_at,
                MIN(CASE WHEN status IN ('pending', 'processing') THEN COALESCE(scheduled_at, created_at) END) AS oldest_pending_at
             FROM sms_queue
             WHERE workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => true,
            'label' => 'SMS Queue',
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'processing_count' => (int) ($counts['processing_count'] ?? 0),
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => $counts['oldest_pending_at'] ?? null,
            'replay_supported' => true,
            'recent_failures' => $this->loadReplayableSmsQueueFailures($workspaceId, 5),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function whatsAppQueueHealth(int $workspaceId): array
    {
        if (!$this->tableExists('whatsapp_queue')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN status = 'completed' THEN COALESCE(processed_at, created_at) END) AS last_success_at,
                MAX(CASE WHEN status = 'failed' THEN COALESCE(processed_at, created_at) END) AS last_failure_at,
                MIN(CASE WHEN status IN ('pending', 'processing') THEN COALESCE(scheduled_at, created_at) END) AS oldest_pending_at
             FROM whatsapp_queue
             WHERE workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => true,
            'label' => 'WhatsApp Queue',
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'processing_count' => (int) ($counts['processing_count'] ?? 0),
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => $counts['oldest_pending_at'] ?? null,
            'replay_supported' => true,
            'recent_failures' => $this->loadReplayableWhatsAppQueueFailures($workspaceId, 5),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workflowRuntimeHealth(int $workspaceId): array
    {
        if (!$this->tableExists('workflow_retry_queue') && !$this->tableExists('scheduled_workflow_actions')) {
            return ['available' => false];
        }

        $retryCounts = $this->tableExists('workflow_retry_queue')
            ? Database::queryOne(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                    MAX(CASE WHEN status = 'completed' THEN COALESCE(processed_at, retry_after, created_at) END) AS last_success_at,
                    MAX(CASE WHEN status = 'failed' THEN COALESCE(processed_at, retry_after, created_at) END) AS last_failure_at,
                    MIN(CASE WHEN status IN ('pending', 'processing') THEN retry_after END) AS oldest_pending_at
                 FROM workflow_retry_queue
                 WHERE workspace_id = ?",
                [$workspaceId]
            ) : [];

        $scheduledCounts = $this->tableExists('scheduled_workflow_actions')
            ? Database::queryOne(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                    MAX(CASE WHEN status = 'executed' THEN COALESCE(scheduled_for, created_at) END) AS last_success_at,
                    MAX(CASE WHEN status = 'failed' THEN COALESCE(scheduled_for, created_at) END) AS last_failure_at,
                    MIN(CASE WHEN status = 'pending' THEN scheduled_for END) AS oldest_pending_at
                 FROM scheduled_workflow_actions
                 WHERE workspace_id = ?",
                [$workspaceId]
            ) : [];

        $recentFailures = $this->loadReplayableWorkflowRetryFailures($workspaceId, 5);
        if ($this->tableExists('scheduled_workflow_actions')) {
            $scheduledFailures = Database::query(
                "SELECT
                    id AS row_id,
                    'scheduled_workflow_action' AS subsystem,
                    status,
                    COALESCE(error_message, 'Scheduled workflow action failed') AS failure_reason,
                    COALESCE(scheduled_for, created_at) AS occurred_at
                 FROM scheduled_workflow_actions
                 WHERE workspace_id = ?
                   AND status = 'failed'
                 ORDER BY COALESCE(scheduled_for, created_at) DESC
                 LIMIT 3",
                [$workspaceId]
            );
            $recentFailures = array_merge($recentFailures, $scheduledFailures);
        }

        usort($recentFailures, static fn(array $a, array $b): int => strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? '')));

        $lastSuccessAt = $this->maxDate($retryCounts['last_success_at'] ?? null, $scheduledCounts['last_success_at'] ?? null);
        $lastFailureAt = $this->maxDate($retryCounts['last_failure_at'] ?? null, $scheduledCounts['last_failure_at'] ?? null);
        $oldestPendingAt = $this->minDate($retryCounts['oldest_pending_at'] ?? null, $scheduledCounts['oldest_pending_at'] ?? null);

        return [
            'available' => true,
            'label' => 'Workflow Runtime',
            'pending_count' => (int) ($retryCounts['pending_count'] ?? 0) + (int) ($scheduledCounts['pending_count'] ?? 0),
            'processing_count' => (int) ($retryCounts['processing_count'] ?? 0),
            'failed_count' => (int) ($retryCounts['failed_count'] ?? 0) + (int) ($scheduledCounts['failed_count'] ?? 0),
            'last_success_at' => $lastSuccessAt,
            'last_failure_at' => $lastFailureAt,
            'oldest_pending_at' => $oldestPendingAt,
            'replay_supported' => true,
            'recent_failures' => array_slice($recentFailures, 0, 5),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scheduledReportsHealth(int $workspaceId): array
    {
        if (!$this->tableExists('scheduled_reports') || !$this->tableExists('scheduled_report_runs')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN sr.is_active = 1 AND sr.next_run_at <= NOW() THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN srr.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN srr.status = 'success' THEN srr.executed_at END) AS last_success_at,
                MAX(CASE WHEN srr.status = 'failed' THEN srr.executed_at END) AS last_failure_at,
                MIN(CASE WHEN sr.is_active = 1 THEN sr.next_run_at END) AS oldest_pending_at
             FROM scheduled_reports sr
             LEFT JOIN scheduled_report_runs srr
               ON srr.scheduled_report_id = sr.id
              AND srr.workspace_id = sr.workspace_id
             WHERE sr.workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => true,
            'label' => 'Scheduled Reports',
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'processing_count' => 0,
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => $counts['oldest_pending_at'] ?? null,
            'replay_supported' => true,
            'recent_failures' => $this->loadReplayableScheduledReportFailures($workspaceId, 5),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function campaignQueueHealth(int $workspaceId): array
    {
        if (!$this->tableExists('campaign_queue')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN status = 'completed' THEN COALESCE(processed_at, created_at) END) AS last_success_at,
                MAX(CASE WHEN status = 'failed' THEN COALESCE(processed_at, created_at) END) AS last_failure_at,
                MIN(CASE WHEN status IN ('pending', 'processing') THEN execute_at END) AS oldest_pending_at
             FROM campaign_queue
             WHERE workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => true,
            'label' => 'Campaign Queue',
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'processing_count' => (int) ($counts['processing_count'] ?? 0),
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => $counts['oldest_pending_at'] ?? null,
            'replay_supported' => true,
            'recent_failures' => $this->loadReplayableCampaignQueueFailures($workspaceId, 5),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aiAutoresponderHealth(int $workspaceId): array
    {
        if (!$this->tableExists('ai_autoresponder_queue')) {
            return ['available' => false];
        }

        $counts = Database::queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MAX(CASE WHEN status = 'completed' THEN COALESCE(processed_at, created_at) END) AS last_success_at,
                MAX(CASE WHEN status = 'failed' THEN COALESCE(processed_at, created_at) END) AS last_failure_at,
                MIN(CASE WHEN status IN ('pending', 'processing') THEN created_at END) AS oldest_pending_at
             FROM ai_autoresponder_queue
             WHERE workspace_id = ?",
            [$workspaceId]
        ) ?: [];

        return [
            'available' => ((int) ($counts['pending_count'] ?? 0) + (int) ($counts['processing_count'] ?? 0) + (int) ($counts['failed_count'] ?? 0)) > 0,
            'label' => 'AI Autoresponder Queue',
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'processing_count' => (int) ($counts['processing_count'] ?? 0),
            'failed_count' => (int) ($counts['failed_count'] ?? 0),
            'last_success_at' => $counts['last_success_at'] ?? null,
            'last_failure_at' => $counts['last_failure_at'] ?? null,
            'oldest_pending_at' => $counts['oldest_pending_at'] ?? null,
            'replay_supported' => false,
            'recent_failures' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replayEmailQueueFailure(int $workspaceId, int $rowId): array
    {
        $row = Database::queryOne(
            "SELECT q.id, q.email_id, e.subject, e.to_email
             FROM email_queue q
             INNER JOIN emails e ON e.id = q.email_id AND e.workspace_id = q.workspace_id
             WHERE q.id = ?
               AND q.workspace_id = ?
             LIMIT 1",
            [$rowId, $workspaceId]
        );

        if ($row === null) {
            throw new \RuntimeException('Email queue row not found for this workspace.');
        }

        Database::execute(
            "UPDATE emails
             SET status = 'pending', error_message = NULL
             WHERE workspace_id = ?
               AND id = ?",
            [$workspaceId, $row['email_id']]
        );

        Database::execute(
            "UPDATE email_queue
             SET status = 'pending', error_message = NULL, processed_at = NULL
             WHERE id = ?
               AND workspace_id = ?",
            [$rowId, $workspaceId]
        );

        $success = AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            fn(): bool => $this->emailService->processEmail((int) $row['email_id'], workspaceId: $workspaceId),
            null,
            'Email queue item is missing a valid workspace.'
        );

        $queueRow = Database::queryOne(
            "SELECT status, error_message, processed_at
             FROM email_queue
             WHERE id = ?
               AND workspace_id = ?",
            [$rowId, $workspaceId]
        ) ?: [];

        return [
            'success' => $success,
            'message' => $success ? 'Email queue row replayed successfully.' : 'Email queue row replay did not complete.',
            'subsystem' => 'email_queue',
            'row_id' => $rowId,
            'row_status' => (string) ($queueRow['status'] ?? ''),
            'error_message' => $queueRow['error_message'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replaySmsQueueFailure(int $workspaceId, int $rowId): array
    {
        $row = Database::queryOne(
            "SELECT q.id AS queue_id, q.message_id, q.attempts, q.max_attempts, m.to_number, m.message_body, m.media_url
             FROM sms_queue q
             INNER JOIN sms_messages m ON m.id = q.message_id AND m.workspace_id = q.workspace_id
             WHERE q.id = ?
               AND q.workspace_id = ?
             LIMIT 1",
            [$rowId, $workspaceId]
        );

        if ($row === null) {
            throw new \RuntimeException('SMS queue row not found for this workspace.');
        }

        $claimedRow = $this->smsQueue->pop($workspaceId, $rowId, true);
        if ($claimedRow === null) {
            throw new \RuntimeException('SMS queue row is not replayable in its current state.');
        }
        $row = array_merge($row, $claimedRow);

        Database::execute(
            "UPDATE sms_messages
             SET status = 'pending', error_message = NULL
             WHERE workspace_id = ?
               AND id = ?",
            [$workspaceId, $row['message_id']]
        );

        try {
            $result = AsyncWorkspaceRunner::runWithWorkspace(
                $workspaceId,
                function () use ($row, $workspaceId): array {
                    return $this->smsService->sendSMS(
                        (string) $row['to_number'],
                        (string) $row['message_body'],
                        ['workspace_id' => $workspaceId, 'media_url' => $row['media_url'] ?? null, 'rate_reserved' => true]
                    );
                },
                null,
                'SMS queue item is missing a valid workspace.'
            );

            Database::execute(
                "UPDATE sms_messages
                 SET status = 'sent', provider_message_id = ?, error_message = NULL
                 WHERE workspace_id = ?
                   AND id = ?",
                [$result['sid'] ?? '', $workspaceId, $row['message_id']]
            );
            $this->smsQueue->complete($rowId, $workspaceId, (string) ($row['claim_token'] ?? ''));

            return [
                'success' => true,
                'message' => 'SMS queue row replayed successfully.',
                'subsystem' => 'sms_queue',
                'row_id' => $rowId,
                'provider_message_id' => $result['sid'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->smsQueue->fail($rowId, $e->getMessage(), $workspaceId, (string) ($row['claim_token'] ?? ''));
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function replayWhatsAppQueueFailure(int $workspaceId, int $rowId): array
    {
        $result = $this->whatsAppQueueProcessor->processQueueItem($rowId, $workspaceId);
        return [
            'success' => ((int) ($result['sent'] ?? 0)) > 0 && ((int) ($result['failed'] ?? 0)) === 0,
            'message' => ((int) ($result['sent'] ?? 0)) > 0 ? 'WhatsApp queue row replayed successfully.' : 'WhatsApp queue row replay attempted.',
            'subsystem' => 'whatsapp_queue',
            'row_id' => $rowId,
            'stats' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replayCampaignQueueFailure(int $workspaceId, int $rowId): array
    {
        $this->campaignQueueProcessor->processQueueItem($rowId, $workspaceId);
        $row = Database::queryOne(
            "SELECT status, error_message
             FROM campaign_queue
             WHERE id = ?
               AND workspace_id = ?",
            [$rowId, $workspaceId]
        ) ?: [];

        return [
            'success' => (string) ($row['status'] ?? '') === 'completed',
            'message' => (string) ($row['status'] ?? '') === 'completed'
                ? 'Campaign queue row replayed successfully.'
                : 'Campaign queue row replay did not complete.',
            'subsystem' => 'campaign_queue',
            'row_id' => $rowId,
            'row_status' => (string) ($row['status'] ?? ''),
            'error_message' => $row['error_message'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replayWorkflowRetryFailure(int $workspaceId, int $rowId): array
    {
        $row = Database::queryOne(
            "SELECT id
             FROM workflow_retry_queue
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1",
            [$rowId, $workspaceId]
        );
        if ($row === null) {
            throw new \RuntimeException('Workflow retry row not found for this workspace.');
        }

        $success = $this->workflowScheduler->processRetryItem($rowId);
        $updated = Database::queryOne(
            "SELECT status, last_error
             FROM workflow_retry_queue
             WHERE id = ?
               AND workspace_id = ?",
            [$rowId, $workspaceId]
        ) ?: [];

        return [
            'success' => $success,
            'message' => $success ? 'Workflow retry replayed successfully.' : 'Workflow retry row could not be claimed.',
            'subsystem' => 'workflow_retry',
            'row_id' => $rowId,
            'row_status' => (string) ($updated['status'] ?? ''),
            'error_message' => $updated['last_error'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replayScheduledReportFailure(int $workspaceId, int $rowId): array
    {
        $result = $this->scheduledReports->replayFailedRun($rowId, $workspaceId);
        return [
            'success' => (bool) ($result['success'] ?? false),
            'message' => !empty($result['success'])
                ? 'Scheduled report replay completed successfully.'
                : 'Scheduled report replay failed.',
            'subsystem' => 'scheduled_report_run',
            'row_id' => $rowId,
            'run_id' => (int) ($result['run_id'] ?? 0),
            'schedule_id' => (int) ($result['schedule_id'] ?? 0),
            'error_message' => $result['error_message'] ?? null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadReplayableEmailQueueFailures(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableExists('email_queue')) {
            return [];
        }

        return Database::query(
            "SELECT
                q.id AS row_id,
                'email_queue' AS subsystem,
                q.status,
                COALESCE(q.error_message, 'Email queue failed') AS failure_reason,
                COALESCE(q.processed_at, q.queue_created_at) AS occurred_at,
                e.to_email AS subject_primary,
                e.subject AS subject_secondary
             FROM (
                SELECT id, workspace_id, email_id, status, error_message, processed_at, created_at AS queue_created_at
                FROM email_queue
                WHERE workspace_id = ?
                  AND status = 'failed'
             ) q
             LEFT JOIN emails e ON e.id = q.email_id AND e.workspace_id = q.workspace_id
             ORDER BY COALESCE(q.processed_at, q.queue_created_at) DESC
             LIMIT " . $limit,
            [$workspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadReplayableSmsQueueFailures(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableExists('sms_queue')) {
            return [];
        }

        return Database::query(
            "SELECT
                q.id AS row_id,
                'sms_queue' AS subsystem,
                q.status,
                COALESCE(m.error_message, 'SMS queue failed') AS failure_reason,
                COALESCE(q.last_attempt_at, q.created_at) AS occurred_at,
                m.to_number AS subject_primary,
                LEFT(m.message_body, 120) AS subject_secondary
             FROM sms_queue q
             LEFT JOIN sms_messages m ON m.id = q.message_id AND m.workspace_id = q.workspace_id
             WHERE q.workspace_id = ?
               AND q.status = 'failed'
             ORDER BY COALESCE(q.last_attempt_at, q.created_at) DESC
             LIMIT " . $limit,
            [$workspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadReplayableWhatsAppQueueFailures(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableExists('whatsapp_queue')) {
            return [];
        }

        return Database::query(
            "SELECT
                q.id AS row_id,
                'whatsapp_queue' AS subsystem,
                q.status,
                COALESCE(q.error_message, wm.error_message, 'WhatsApp queue failed') AS failure_reason,
                COALESCE(q.processed_at, q.created_at) AS occurred_at,
                wm.to_number AS subject_primary,
                LEFT(wm.message_body, 120) AS subject_secondary
             FROM whatsapp_queue q
             LEFT JOIN whatsapp_messages wm ON wm.id = q.message_id AND wm.workspace_id = q.workspace_id
             WHERE q.workspace_id = ?
               AND q.status = 'failed'
             ORDER BY COALESCE(q.processed_at, q.created_at) DESC
             LIMIT " . $limit,
            [$workspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadReplayableCampaignQueueFailures(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableExists('campaign_queue')) {
            return [];
        }

        return Database::query(
            "SELECT
                cq.id AS row_id,
                'campaign_queue' AS subsystem,
                cq.status,
                COALESCE(cq.error_message, 'Campaign queue failed') AS failure_reason,
                COALESCE(cq.processed_at, cq.created_at) AS occurred_at,
                c.name AS subject_primary,
                CONCAT('Enrollment #', cq.enrollment_id) AS subject_secondary
             FROM campaign_queue cq
             LEFT JOIN campaigns c ON c.id = cq.campaign_id AND c.workspace_id = cq.workspace_id
             WHERE cq.workspace_id = ?
               AND cq.status = 'failed'
             ORDER BY COALESCE(cq.processed_at, cq.created_at) DESC
             LIMIT " . $limit,
            [$workspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadReplayableWorkflowRetryFailures(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableExists('workflow_retry_queue')) {
            return [];
        }

        return Database::query(
            "SELECT
                wrq.id AS row_id,
                'workflow_retry' AS subsystem,
                wrq.status,
                COALESCE(wrq.last_error, 'Workflow retry failed') AS failure_reason,
                COALESCE(wrq.processed_at, wrq.retry_after, wrq.created_at) AS occurred_at,
                w.name AS subject_primary,
                CONCAT('Execution #', wrq.workflow_execution_id) AS subject_secondary
             FROM workflow_retry_queue wrq
             LEFT JOIN workflows w ON w.id = wrq.workflow_id AND w.workspace_id = wrq.workspace_id
             WHERE wrq.workspace_id = ?
               AND wrq.status = 'failed'
             ORDER BY COALESCE(wrq.processed_at, wrq.retry_after, wrq.created_at) DESC
             LIMIT " . $limit,
            [$workspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadReplayableScheduledReportFailures(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableExists('scheduled_report_runs')) {
            return [];
        }

        return Database::query(
            "SELECT
                srr.id AS row_id,
                'scheduled_report_run' AS subsystem,
                srr.status,
                COALESCE(srr.error_message, 'Scheduled report failed') AS failure_reason,
                srr.executed_at AS occurred_at,
                sr.schedule_name AS subject_primary,
                r.name AS subject_secondary
             FROM scheduled_report_runs srr
             INNER JOIN scheduled_reports sr
                ON sr.id = srr.scheduled_report_id
               AND sr.workspace_id = srr.workspace_id
             LEFT JOIN reports r
                ON r.id = sr.report_id
               AND r.workspace_id = sr.workspace_id
             WHERE srr.workspace_id = ?
               AND srr.status = 'failed'
             ORDER BY srr.executed_at DESC
             LIMIT " . $limit,
            [$workspaceId]
        );
    }

    private function assertWorkspaceExists(int $workspaceId): void
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required.');
        }

        $workspace = Database::queryOne(
            "SELECT id
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );

        if ($workspace === null) {
            throw new \RuntimeException('Workspace not found.');
        }
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, self::$tableExists)) {
            return self::$tableExists[$table];
        }

        $row = Database::queryOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1",
            [$table]
        );

        self::$tableExists[$table] = $row !== null;
        return self::$tableExists[$table];
    }

    private function truncate(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, max(0, $limit - 3)) . '...';
    }

    private function maxDate(?string $left, ?string $right): ?string
    {
        if (!$left) {
            return $right ?: null;
        }
        if (!$right) {
            return $left;
        }
        return strtotime($left) >= strtotime($right) ? $left : $right;
    }

    private function minDate(?string $left, ?string $right): ?string
    {
        if (!$left) {
            return $right ?: null;
        }
        if (!$right) {
            return $left;
        }
        return strtotime($left) <= strtotime($right) ? $left : $right;
    }
}
