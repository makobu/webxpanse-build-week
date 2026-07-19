<?php

namespace CRM\Services;

use CRM\Database;

class AutomationReviewQueueService
{
    private const ESCALATION_NOTIFICATION_TYPE = 'automation_escalation';

    /** @var array<string,string> */
    private const DECISION_TO_STATUS = [
        'resolve' => 'resolved',
        'dismiss' => 'rejected',
        'approve_later' => 'approved',
        'reopen' => 'open',
    ];

    /** @var array<string,int> */
    private const ESCALATION_PRIORITY_WEIGHT = [
        'normal' => 0,
        'high' => 1,
        'urgent' => 2,
    ];

    public function schemaReady(): bool
    {
        return Database::tableExists('automation_catalog_runs')
            && Database::tableExists('automation_catalog_definitions')
            && Database::columnExists('automation_catalog_runs', 'review_note')
            && Database::columnExists('automation_catalog_runs', 'reviewed_by_user_id')
            && Database::columnExists('automation_catalog_runs', 'reviewed_at');
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboard(int $limit = 25): array
    {
        if (!$this->schemaReady()) {
            return [
                'schema_ready' => false,
                'summary' => $this->emptySummary(),
                'open_findings' => [],
                'critical_findings' => [],
                'escalated_findings' => [],
                'prepared_digests' => [],
                'skipped_runs' => [],
                'recent_runs' => [],
            ];
        }

        $limit = max(1, min(100, $limit));

        return [
            'schema_ready' => true,
            'summary' => $this->summary(),
            'open_findings' => $this->listRuns(['review_status' => 'open', 'limit' => $limit]),
            'critical_findings' => $this->listRuns(['review_status' => 'open', 'severity' => 'critical', 'limit' => $limit]),
            'escalated_findings' => $this->escalationReady()
                ? $this->listRuns(['review_status' => 'open', 'escalation_status' => 'escalated', 'limit' => $limit])
                : [],
            'prepared_digests' => $this->listRuns([
                'automation_key' => 'daily_superadmin_health_digest',
                'run_status' => 'prepared',
                'limit' => min(10, $limit),
            ]),
            'skipped_runs' => $this->listRuns(['run_status' => 'skipped', 'limit' => min(10, $limit)]),
            'recent_runs' => $this->listRuns(['limit' => $limit]),
        ];
    }

    /**
     * @return array<string,int>
     */
    public function summary(): array
    {
        if (!$this->schemaReady()) {
            return $this->emptySummary();
        }

        $duplicateRefreshSelect = Database::columnExists('automation_catalog_runs', 'duplicate_count')
            ? 'COALESCE(SUM(duplicate_count), 0)'
            : '0';
        $escalatedOpenSelect = $this->escalationReady()
            ? "SUM(CASE WHEN review_status = 'open' AND escalation_status = 'escalated' THEN 1 ELSE 0 END)"
            : '0';
        $urgentEscalationSelect = $this->escalationReady()
            ? "SUM(CASE WHEN review_status = 'open' AND escalation_status = 'escalated' AND escalation_priority = 'urgent' THEN 1 ELSE 0 END)"
            : '0';
        $overdueEscalationSelect = $this->escalationReady()
            ? "SUM(CASE WHEN review_status = 'open' AND escalation_status = 'escalated' AND escalation_due_at IS NOT NULL AND escalation_due_at < NOW() THEN 1 ELSE 0 END)"
            : '0';
        $pendingNotificationSelect = $this->notificationDeliveryReady()
            ? "SUM(CASE WHEN review_status = 'open' AND escalation_status = 'escalated' AND escalation_notified_at IS NULL AND (escalation_priority = 'urgent' OR (escalation_due_at IS NOT NULL AND escalation_due_at < NOW())) THEN 1 ELSE 0 END)"
            : '0';
        $sentNotificationSelect = $this->notificationDeliveryReady()
            ? 'SUM(CASE WHEN escalation_notification_count > 0 THEN 1 ELSE 0 END)'
            : '0';
        $row = Database::queryOne(
            "SELECT
                COUNT(*) AS total_runs,
                SUM(CASE WHEN review_status = 'open' THEN 1 ELSE 0 END) AS open_review_count,
                SUM(CASE WHEN review_status = 'open' AND severity = 'critical' THEN 1 ELSE 0 END) AS critical_open_count,
                SUM(CASE WHEN run_status = 'prepared' THEN 1 ELSE 0 END) AS prepared_count,
                SUM(CASE WHEN run_status = 'skipped' THEN 1 ELSE 0 END) AS skipped_count,
                SUM(CASE WHEN review_status IN ('approved', 'rejected', 'resolved') THEN 1 ELSE 0 END) AS reviewed_count,
                SUM(CASE WHEN review_status = 'open' AND created_at < (NOW() - INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS stale_open_count,
                {$duplicateRefreshSelect} AS duplicate_refresh_count,
                {$escalatedOpenSelect} AS escalated_open_count,
                {$urgentEscalationSelect} AS urgent_escalation_count,
                {$overdueEscalationSelect} AS overdue_escalation_count,
                {$pendingNotificationSelect} AS escalation_notification_pending_count,
                {$sentNotificationSelect} AS escalation_notification_sent_count
             FROM automation_catalog_runs"
        ) ?: [];

        return [
            'total_runs' => (int) ($row['total_runs'] ?? 0),
            'open_review_count' => (int) ($row['open_review_count'] ?? 0),
            'critical_open_count' => (int) ($row['critical_open_count'] ?? 0),
            'prepared_count' => (int) ($row['prepared_count'] ?? 0),
            'skipped_count' => (int) ($row['skipped_count'] ?? 0),
            'reviewed_count' => (int) ($row['reviewed_count'] ?? 0),
            'stale_open_count' => (int) ($row['stale_open_count'] ?? 0),
            'duplicate_refresh_count' => (int) ($row['duplicate_refresh_count'] ?? 0),
            'escalated_open_count' => (int) ($row['escalated_open_count'] ?? 0),
            'urgent_escalation_count' => (int) ($row['urgent_escalation_count'] ?? 0),
            'overdue_escalation_count' => (int) ($row['overdue_escalation_count'] ?? 0),
            'escalation_notification_pending_count' => (int) ($row['escalation_notification_pending_count'] ?? 0),
            'escalation_notification_sent_count' => (int) ($row['escalation_notification_sent_count'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listRuns(array $filters = []): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $where = [];
        $params = [];

        foreach (['review_status', 'run_status', 'severity'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = 'r.' . $key . ' = ?';
                $params[] = $value;
            }
        }

        if ($this->escalationReady()) {
            foreach (['escalation_status', 'escalation_priority'] as $key) {
                $value = trim((string) ($filters[$key] ?? ''));
                if ($value !== '') {
                    $where[] = 'r.' . $key . ' = ?';
                    $params[] = $value;
                }
            }
        }

        $automationKey = trim((string) ($filters['automation_key'] ?? ''));
        if ($automationKey !== '') {
            $where[] = 'd.automation_key = ?';
            $params[] = $automationKey;
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 25)));
        $orderBy = Database::columnExists('automation_catalog_runs', 'last_seen_at')
            ? 'COALESCE(r.last_seen_at, r.created_at) DESC, r.id DESC'
            : 'r.created_at DESC, r.id DESC';

        $rows = Database::query(
            "SELECT r.*,
                    d.automation_key,
                    d.name,
                    d.category,
                    d.risk_level,
                    d.default_mode,
                    d.implementation_status,
                    u.email AS reviewed_by_email,
                    u.first_name AS reviewed_by_first_name,
                    u.last_name AS reviewed_by_last_name
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             LEFT JOIN users u ON u.id = r.reviewed_by_user_id
             {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$limit}",
            $params
        );

        return array_map(fn(array $row): array => $this->hydrateRun($row), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getRun(int $runId): ?array
    {
        if (!$this->schemaReady() || $runId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT r.*,
                    d.automation_key,
                    d.name,
                    d.category,
                    d.risk_level,
                    d.default_mode,
                    d.implementation_status,
                    u.email AS reviewed_by_email,
                    u.first_name AS reviewed_by_first_name,
                    u.last_name AS reviewed_by_last_name
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             LEFT JOIN users u ON u.id = r.reviewed_by_user_id
             WHERE r.id = ?
             LIMIT 1",
            [$runId]
        );

        return $row ? $this->hydrateRun($row) : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function applyEscalationPolicy(?int $actorUserId = null): array
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Automation review queue schema is not ready.');
        }

        if (!$this->escalationReady()) {
            return [
                'schema_ready' => false,
                'evaluated' => 0,
                'candidates' => 0,
                'newly_escalated' => 0,
                'already_escalated' => 0,
                'urgent' => 0,
                'high' => 0,
                'run_ids' => [],
            ];
        }

        $rows = Database::query(
            "SELECT r.*, d.automation_key, d.name
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             WHERE r.review_status = 'open'
             ORDER BY r.created_at ASC, r.id ASC
             LIMIT 500"
        );

        $result = [
            'schema_ready' => true,
            'evaluated' => count($rows),
            'candidates' => 0,
            'newly_escalated' => 0,
            'already_escalated' => 0,
            'urgent' => 0,
            'high' => 0,
            'run_ids' => [],
        ];

        foreach ($rows as $row) {
            $policy = $this->escalationPolicyForRun($row);
            if ($policy === null) {
                continue;
            }

            $result['candidates']++;
            $priority = (string) ($policy['priority'] ?? 'high');
            if ($priority === 'urgent') {
                $result['urgent']++;
            } else {
                $result['high']++;
            }

            if (!$this->shouldUpdateEscalation($row, $priority)) {
                $result['already_escalated']++;
                continue;
            }

            Database::execute(
                "UPDATE automation_catalog_runs
                 SET escalation_status = 'escalated',
                     escalation_priority = ?,
                     escalation_reason = ?,
                     escalated_at = COALESCE(escalated_at, NOW()),
                     escalation_due_at = DATE_ADD(NOW(), INTERVAL " . (int) ($policy['due_hours'] ?? 24) . " HOUR),
                     escalation_acknowledged_at = NULL,
                     escalation_acknowledged_by_user_id = NULL,
                     escalation_note = ?" . ($this->notificationDeliveryReady() ? ",
                     escalation_notified_at = NULL,
                     escalation_notification_count = 0,
                     escalation_last_notification_reason = NULL" : "") . "
                 WHERE id = ?",
                [
                    $priority,
                    (string) ($policy['reason'] ?? 'open_finding'),
                    $this->escalationNote($row, $policy, $actorUserId),
                    (int) ($row['id'] ?? 0),
                ]
            );

            $result['newly_escalated']++;
            $result['run_ids'][] = (int) ($row['id'] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function dispatchEscalationNotifications(?int $actorUserId = null): array
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Automation review queue schema is not ready.');
        }

        if (!$this->notificationDeliveryReady()) {
            return [
                'schema_ready' => false,
                'evaluated' => 0,
                'notified_runs' => 0,
                'notifications_created' => 0,
                'recipient_count' => 0,
                'skipped_no_recipients' => 0,
                'run_ids' => [],
            ];
        }

        $rows = Database::query(
            "SELECT r.*, d.automation_key, d.name
             FROM automation_catalog_runs r
             JOIN automation_catalog_definitions d ON d.id = r.automation_id
             WHERE r.review_status = 'open'
               AND r.escalation_status = 'escalated'
               AND r.escalation_notified_at IS NULL
               AND (
                    r.escalation_priority = 'urgent'
                    OR (r.escalation_due_at IS NOT NULL AND r.escalation_due_at < NOW())
               )
             ORDER BY
                FIELD(r.escalation_priority, 'urgent', 'high', 'normal'),
                r.escalation_due_at ASC,
                r.id ASC
             LIMIT 100"
        );

        $result = [
            'schema_ready' => true,
            'evaluated' => count($rows),
            'notified_runs' => 0,
            'notifications_created' => 0,
            'recipient_count' => 0,
            'skipped_no_recipients' => 0,
            'run_ids' => [],
        ];

        foreach ($rows as $row) {
            $workspaceId = $this->notificationWorkspaceId($row);
            $recipientIds = $this->resolveSuperAdminNotificationUserIds($workspaceId);
            if ($recipientIds === []) {
                $result['skipped_no_recipients']++;
                continue;
            }

            $reason = $this->notificationReason($row);
            foreach ($recipientIds as $userId) {
                $this->createEscalationNotification((int) $userId, $workspaceId, $row, $reason);
                $result['notifications_created']++;
            }

            Database::execute(
                "UPDATE automation_catalog_runs
                 SET escalation_notified_at = NOW(),
                     escalation_notification_count = escalation_notification_count + 1,
                     escalation_last_notification_reason = ?
                 WHERE id = ?",
                [$reason, (int) ($row['id'] ?? 0)]
            );

            $result['notified_runs']++;
            $result['recipient_count'] += count($recipientIds);
            $result['run_ids'][] = (int) ($row['id'] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function reviewRun(int $runId, string $decision, int $actorUserId, string $note = ''): array
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Automation review queue schema is not ready.');
        }
        if ($runId <= 0) {
            throw new \InvalidArgumentException('A valid automation run id is required.');
        }
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('A valid Super Admin user is required.');
        }

        $decision = trim($decision);
        if (!isset(self::DECISION_TO_STATUS[$decision])) {
            throw new \InvalidArgumentException('Unsupported automation review decision.');
        }

        $run = $this->getRun($runId);
        if ($run === null) {
            throw new \RuntimeException('Automation review run was not found.');
        }

        $newStatus = self::DECISION_TO_STATUS[$decision];
        $note = substr(trim($note), 0, 2000);
        $reviewedAt = date('Y-m-d H:i:s');
        $actionTaken = (array) ($run['action_taken'] ?? []);
        $actionTaken['review'] = [
            'decision' => $decision,
            'previous_review_status' => (string) ($run['review_status'] ?? ''),
            'new_review_status' => $newStatus,
            'reviewed_by_user_id' => $actorUserId,
            'reviewed_at' => $reviewedAt,
            'note' => $note,
            'system_mutation' => false,
            'customer_facing' => false,
        ];

        $setSql = [
            'review_status = ?',
            'review_note = ?',
            'reviewed_by_user_id = ?',
            'reviewed_at = ?',
            'action_taken_json = ?',
        ];
        $params = [
            $newStatus,
            $note !== '' ? $note : null,
            $actorUserId,
            $reviewedAt,
            $this->encodeJson($actionTaken),
        ];

        if ($this->escalationReady()) {
            if ($newStatus === 'open') {
                $setSql[] = "escalation_status = 'none'";
                $setSql[] = "escalation_priority = 'normal'";
                $setSql[] = 'escalation_reason = NULL';
                $setSql[] = 'escalated_at = NULL';
                $setSql[] = 'escalation_due_at = NULL';
                $setSql[] = 'escalation_acknowledged_at = NULL';
                $setSql[] = 'escalation_acknowledged_by_user_id = NULL';
                $setSql[] = 'escalation_note = NULL';
                if ($this->notificationDeliveryReady()) {
                    $setSql[] = 'escalation_notified_at = NULL';
                    $setSql[] = 'escalation_notification_count = 0';
                    $setSql[] = 'escalation_last_notification_reason = NULL';
                }
            } elseif ((string) ($run['escalation_status'] ?? 'none') === 'escalated') {
                $setSql[] = "escalation_status = 'acknowledged'";
                $setSql[] = 'escalation_acknowledged_at = ?';
                $setSql[] = 'escalation_acknowledged_by_user_id = ?';
                $setSql[] = 'escalation_note = ?';
                $params[] = $reviewedAt;
                $params[] = $actorUserId;
                $params[] = $note !== '' ? $note : 'Escalation acknowledged by review decision: ' . $decision;
            }
        }

        $params[] = $runId;

        Database::execute(
            "UPDATE automation_catalog_runs
             SET " . implode(",\n                 ", $setSql) . "
             WHERE id = ?",
            $params
        );

        $updated = $this->getRun($runId);
        if ($updated === null) {
            throw new \RuntimeException('Automation review run could not be reloaded.');
        }

        return $updated;
    }

    /**
     * @return array<string,int>
     */
    private function emptySummary(): array
    {
        return [
            'total_runs' => 0,
            'open_review_count' => 0,
            'critical_open_count' => 0,
            'prepared_count' => 0,
            'skipped_count' => 0,
            'reviewed_count' => 0,
            'stale_open_count' => 0,
            'duplicate_refresh_count' => 0,
            'escalated_open_count' => 0,
            'urgent_escalation_count' => 0,
            'overdue_escalation_count' => 0,
            'escalation_notification_pending_count' => 0,
            'escalation_notification_sent_count' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateRun(array $row): array
    {
        foreach (['evidence_json' => 'evidence', 'recommendation_json' => 'recommendation', 'action_taken_json' => 'action_taken'] as $jsonKey => $targetKey) {
            $row[$targetKey] = $this->decodeJson($row[$jsonKey] ?? null);
            unset($row[$jsonKey]);
        }

        foreach (['id', 'automation_id', 'workspace_id', 'actor_user_id', 'reviewed_by_user_id', 'duplicate_count', 'escalation_acknowledged_by_user_id', 'escalation_notification_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }
        }

        $row['reviewed_by_name'] = trim((string) ($row['reviewed_by_first_name'] ?? '') . ' ' . (string) ($row['reviewed_by_last_name'] ?? ''));
        if ($row['reviewed_by_name'] === '') {
            $row['reviewed_by_name'] = (string) ($row['reviewed_by_email'] ?? '');
        }

        return $row;
    }

    private function escalationReady(): bool
    {
        return Database::columnExists('automation_catalog_runs', 'escalation_status')
            && Database::columnExists('automation_catalog_runs', 'escalation_priority')
            && Database::columnExists('automation_catalog_runs', 'escalation_reason')
            && Database::columnExists('automation_catalog_runs', 'escalated_at')
            && Database::columnExists('automation_catalog_runs', 'escalation_due_at')
            && Database::columnExists('automation_catalog_runs', 'escalation_acknowledged_at')
            && Database::columnExists('automation_catalog_runs', 'escalation_acknowledged_by_user_id')
            && Database::columnExists('automation_catalog_runs', 'escalation_note');
    }

    private function notificationDeliveryReady(): bool
    {
        return $this->escalationReady()
            && Database::columnExists('automation_catalog_runs', 'escalation_notified_at')
            && Database::columnExists('automation_catalog_runs', 'escalation_notification_count')
            && Database::columnExists('automation_catalog_runs', 'escalation_last_notification_reason')
            && Database::tableExists('notifications')
            && Database::columnExists('notifications', 'workspace_id')
            && Database::columnExists('notifications', 'user_id')
            && Database::columnExists('notifications', 'type')
            && Database::columnExists('notifications', 'title')
            && Database::columnExists('notifications', 'message')
            && Database::columnExists('notifications', 'entity_type')
            && Database::columnExists('notifications', 'entity_id')
            && Database::columnExists('notifications', 'link')
            && Database::columnExists('notifications', 'severity');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function notificationWorkspaceId(array $row): int
    {
        $workspaceId = (int) ($row['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        try {
            return (new DefaultWorkspaceService())->id();
        } catch (\Throwable $e) {
            return DefaultWorkspaceService::DEFAULT_ID;
        }
    }

    /**
     * @return array<int,int>
     */
    private function resolveSuperAdminNotificationUserIds(int $workspaceId): array
    {
        $roleJoin = Database::tableExists('user_roles') && Database::tableExists('roles')
            ? "LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id"
            : "";
        $roleWhere = Database::tableExists('user_roles') && Database::tableExists('roles')
            ? "r.slug = 'superadmin'"
            : '0 = 1';
        $membershipJoin = Database::tableExists('workspace_memberships')
            ? "LEFT JOIN workspace_memberships wm ON wm.user_id = u.id AND wm.workspace_id = ? AND wm.membership_status = 'active'"
            : "";
        $membershipWhere = Database::tableExists('workspace_memberships')
            ? " OR wm.role_slug = 'superadmin'"
            : "";

        $params = Database::tableExists('workspace_memberships') ? [$workspaceId] : [];
        $rows = Database::query(
            "SELECT DISTINCT u.id
             FROM users u
             {$roleJoin}
             {$membershipJoin}
             WHERE ({$roleWhere}{$membershipWhere})
             ORDER BY u.id ASC",
            $params
        );

        return array_values(array_filter(
            array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows),
            static fn(int $id): bool => $id > 0
        ));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function notificationReason(array $row): string
    {
        if ((string) ($row['escalation_priority'] ?? '') === 'urgent') {
            return 'urgent';
        }

        $dueAt = strtotime((string) ($row['escalation_due_at'] ?? ''));
        if ($dueAt !== false && $dueAt < time()) {
            return 'overdue';
        }

        return 'escalated';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createEscalationNotification(int $userId, int $workspaceId, array $row, string $reason): void
    {
        $detectorName = trim((string) ($row['name'] ?? $row['automation_key'] ?? 'Automation detector'));
        $priority = strtoupper((string) ($row['escalation_priority'] ?? 'high'));
        $title = $reason === 'overdue'
            ? 'Overdue automation escalation'
            : 'Urgent automation escalation';
        $message = $priority . ': ' . $detectorName . ' needs Super Admin review.';
        $dueAt = trim((string) ($row['escalation_due_at'] ?? ''));
        if ($dueAt !== '') {
            $message .= ' Due: ' . $dueAt . '.';
        }

        Database::execute(
            "INSERT INTO notifications
                (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $userId,
                self::ESCALATION_NOTIFICATION_TYPE,
                substr($title, 0, 255),
                substr($message, 0, 2000),
                'automation_catalog_run',
                (int) ($row['id'] ?? 0),
                $this->automationReviewLink(),
                $reason === 'urgent' ? 'critical' : 'warning',
            ]
        );
    }

    private function automationReviewLink(): string
    {
        if (function_exists('publicUrl')) {
            return publicUrl('system_health.php');
        }

        return 'system_health.php';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{priority:string,reason:string,due_hours:int}|null
     */
    private function escalationPolicyForRun(array $row): ?array
    {
        $severity = (string) ($row['severity'] ?? 'info');
        $createdAt = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        $ageHours = max(0, (time() - $createdAt) / 3600);
        $isCritical = $severity === 'critical';
        $isStale = $ageHours >= 24;

        if ($isCritical && $isStale) {
            return ['priority' => 'urgent', 'reason' => 'critical_stale_open', 'due_hours' => 4];
        }
        if ($isCritical) {
            return ['priority' => 'high', 'reason' => 'critical_open', 'due_hours' => 8];
        }
        if ($isStale) {
            return ['priority' => 'high', 'reason' => 'stale_open', 'due_hours' => 24];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function shouldUpdateEscalation(array $row, string $priority): bool
    {
        if ((string) ($row['escalation_status'] ?? 'none') !== 'escalated') {
            return true;
        }

        $currentPriority = (string) ($row['escalation_priority'] ?? 'normal');
        return (self::ESCALATION_PRIORITY_WEIGHT[$priority] ?? 0) > (self::ESCALATION_PRIORITY_WEIGHT[$currentPriority] ?? 0);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $policy
     */
    private function escalationNote(array $row, array $policy, ?int $actorUserId): string
    {
        $parts = [
            'Automation escalation policy marked this open finding for Super Admin follow-through.',
            'Detector: ' . (string) ($row['automation_key'] ?? 'unknown'),
            'Reason: ' . (string) ($policy['reason'] ?? 'open_finding'),
        ];

        if ($actorUserId !== null && $actorUserId > 0) {
            $parts[] = 'Applied by user ID ' . $actorUserId . '.';
        }

        return substr(implode(' ', $parts), 0, 2000);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: null;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
