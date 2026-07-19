<?php

namespace CRM\Services;

use CRM\Database;

class TaskCompletionScanQueueService
{
    private const FRESHNESS_WINDOW_SECONDS = 300;
    private const RUN_LOCK_TIMEOUT_SECONDS = 600;
    private const MAX_BATCH_SIZE = 50;
    private const TASK_PAGE_SIZE = 100;

    private AITaskCompletionService $completionService;
    private static ?bool $tableReady = null;

    public function __construct(?AITaskCompletionService $completionService = null)
    {
        $this->completionService = $completionService ?? new AITaskCompletionService();
    }

    public function enqueueForUser(int $userId, ?int $requestedBy = null, string $source = 'manual', bool $force = false, ?int $workspaceId = null): array
    {
        $this->ensureTable();
        $userId = max(0, $userId);
        $workspaceId = $this->resolveWorkspaceId($workspaceId, $userId);
        if ($userId <= 0) {
            return $this->buildStatusPayload(0, $workspaceId, null, 0);
        }
        if ($workspaceId <= 0) {
            return $this->buildStatusPayload($userId, 0, null, 0);
        }

        $this->requeueStaleRunningJobs();
        $eligibleCount = $this->countOpenAutoCompletableTasks($userId, $workspaceId);
        if ($eligibleCount <= 0) {
            return $this->buildStatusPayload($userId, $workspaceId, $this->getLatestTerminalRow($userId, $workspaceId), 0);
        }

        $activeRow = $this->getActiveRow($userId, $workspaceId);
        if ($activeRow) {
            return $this->buildStatusPayload($userId, $workspaceId, $activeRow, $eligibleCount);
        }

        $latestCompleted = $this->getLatestCompletedRow($userId, $workspaceId);
        if (!$force && $latestCompleted && $this->rowIsFresh($latestCompleted)) {
            return $this->buildStatusPayload($userId, $workspaceId, $latestCompleted, $eligibleCount);
        }

        Database::execute(
            "INSERT INTO task_completion_scan_queue (workspace_id, user_id, status, requested_by, request_source, active_dedupe_key, queued_at)
             VALUES (?, ?, 'queued', ?, ?, ?, NOW())",
            [$workspaceId, $userId, $requestedBy ?: null, $this->normalizeSource($source), $this->activeDedupeKey($workspaceId, $userId)]
        );

        $row = Database::queryOne("SELECT * FROM task_completion_scan_queue WHERE id = ?", [(int) Database::lastInsertId()]);
        return $this->buildStatusPayload($userId, $workspaceId, $row ?: null, $eligibleCount);
    }

    public function enqueueForActiveUsers(string $source = 'cron', ?int $requestedBy = null): array
    {
        $this->ensureTable();
        $this->requeueStaleRunningJobs();

        $users = Database::query(
            "SELECT DISTINCT user_id, workspace_id
             FROM (
                 SELECT assigned_to AS user_id, workspace_id
                 FROM tasks
                 WHERE assigned_to IS NOT NULL
                   AND workspace_id IS NOT NULL
                   AND status NOT IN ('completed', 'cancelled')
                   AND (completion_mode = 'auto'
                        OR (JSON_VALID(COALESCE(metadata_json, '{}'))
                            AND JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.auto_complete_allowed') = true))
                 UNION
                 SELECT created_by AS user_id, workspace_id
                 FROM tasks
                 WHERE created_by IS NOT NULL
                   AND workspace_id IS NOT NULL
                   AND status NOT IN ('completed', 'cancelled')
                   AND (completion_mode = 'auto'
                        OR (JSON_VALID(COALESCE(metadata_json, '{}'))
                            AND JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.auto_complete_allowed') = true))
             ) eligible_users
             WHERE user_id IS NOT NULL
               AND workspace_id IS NOT NULL
             ORDER BY workspace_id ASC, user_id ASC"
        );

        $summary = [
            'eligible_users' => count($users),
            'queued_users' => 0,
            'skipped_users' => 0,
        ];

        foreach ($users as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $workspaceId = (int) ($row['workspace_id'] ?? 0);
            if ($userId <= 0 || $workspaceId <= 0) {
                continue;
            }
            $result = $this->enqueueForUser($userId, $requestedBy, $source, false, $workspaceId);
            if (in_array((string) ($result['status'] ?? ''), ['queued', 'running'], true)) {
                $summary['queued_users']++;
            } else {
                $summary['skipped_users']++;
            }
        }

        return $summary;
    }

    public function processQueuedScans(int $limit = 10): array
    {
        $this->ensureTable();
        $this->requeueStaleRunningJobs();

        $limit = max(1, min(self::MAX_BATCH_SIZE, $limit));
        $jobs = $this->claimQueuedBatch($limit);
        return $this->processClaimedJobs($jobs);
    }

    public function processQueuedScansForUser(int $userId, int $limit = 10, ?int $workspaceId = null): array
    {
        $this->ensureTable();
        $this->requeueStaleRunningJobs();

        $userId = max(0, $userId);
        if ($userId <= 0) {
            return [
                'processed_jobs' => 0,
                'completed_jobs' => 0,
                'failed_jobs' => 0,
                'completed_tasks' => 0,
                'results' => [],
            ];
        }

        $limit = max(1, min(self::MAX_BATCH_SIZE, $limit));
        $jobs = $this->claimQueuedBatch($limit, $userId, $workspaceId);
        return $this->processClaimedJobs($jobs);
    }

    private function processClaimedJobs(array $jobs): array
    {
        $summary = [
            'processed_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'continued_jobs' => 0,
            'completed_tasks' => 0,
            'results' => [],
        ];

        foreach ($jobs as $job) {
            $jobId = (int) ($job['id'] ?? 0);
            $userId = (int) ($job['user_id'] ?? 0);
            $workspaceId = (int) ($job['workspace_id'] ?? 0);
            if ($jobId <= 0 || $userId <= 0 || $workspaceId <= 0) {
                continue;
            }

            $summary['processed_jobs']++;
            $snapshot = WorkspaceContext::runtimeSnapshot();
            try {
                if (WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId) === null) {
                    throw new \RuntimeException('Queued task scan workspace could not be activated.');
                }
                $cursor = (int) ($job['cursor_task_id'] ?? 0);
                $taskIds = $this->eligibleTaskIdsPage($workspaceId, $userId, $cursor, self::TASK_PAGE_SIZE);
                $results = [];
                foreach ($taskIds as $eligibleTaskId) {
                    foreach ($this->completionService->scanForCompletionEvidence($userId, $eligibleTaskId, [
                        'workspace_id' => $workspaceId,
                        'workspace_context_applied' => true,
                        'actor_user_id' => (int) ($job['requested_by'] ?? 0),
                    ]) as $result) {
                        $results[] = $result;
                    }
                }
                $matchedTasks = count($results);
                $completedTasks = count(array_filter($results, static fn(array $result): bool => !empty($result['completed'])));
                $previous = $this->decodeJsonField($job['results_json'] ?? null);
                $payload = [
                    'matched_tasks' => (int) ($previous['matched_tasks'] ?? 0) + $matchedTasks,
                    'completed_tasks' => (int) ($previous['completed_tasks'] ?? 0) + $completedTasks,
                    'refresh_recommended' => !empty($previous['refresh_recommended']) || $completedTasks > 0,
                    'processed_at' => date('Y-m-d H:i:s'),
                ];
                $lastTaskId = $taskIds !== [] ? (int) end($taskIds) : $cursor;
                if (count($taskIds) === self::TASK_PAGE_SIZE) {
                    $this->markContinuation($jobId, $lastTaskId, $payload, $this->countEligibleTasksAfterCursor($workspaceId, $userId, $lastTaskId));
                    $summary['continued_jobs']++;
                } else {
                    $this->markCompleted($jobId, $payload);
                    $summary['completed_jobs']++;
                }
                $summary['completed_tasks'] += $completedTasks;
                $summary['results'][] = [
                    'queue_id' => $jobId,
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'matched_tasks' => $matchedTasks,
                    'completed_tasks' => $completedTasks,
                ];
            } catch (\Throwable $e) {
                $this->markFailed($jobId, $e->getMessage());
                $summary['failed_jobs']++;
                $summary['results'][] = [
                    'queue_id' => $jobId,
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'error' => 'Scan failed',
                ];
            } finally {
                WorkspaceContext::restoreRuntimeWorkspace($snapshot);
            }
        }

        return $summary;
    }

    /** @return array<int,int> */
    private function eligibleTaskIdsPage(int $workspaceId, int $userId, int $cursor, int $limit): array
    {
        $rows = Database::query(
            "SELECT id
             FROM tasks
             WHERE workspace_id = ?
               AND id > ?
               AND (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')
               AND (completion_mode = 'auto'
                    OR (JSON_VALID(COALESCE(metadata_json, '{}'))
                        AND JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.auto_complete_allowed') = true))
             ORDER BY id ASC
             LIMIT {$limit}",
            [$workspaceId, $cursor, $userId, $userId]
        );
        return array_values(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
    }

    private function countEligibleTasksAfterCursor(int $workspaceId, int $userId, int $cursor): int
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM tasks
             WHERE workspace_id = ?
               AND id > ?
               AND (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')
               AND (completion_mode = 'auto'
                    OR (JSON_VALID(COALESCE(metadata_json, '{}'))
                        AND JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.auto_complete_allowed') = true))",
            [$workspaceId, $cursor, $userId, $userId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function getUserScanStatus(int $userId, ?int $workspaceId = null): array
    {
        $this->ensureTable();
        $this->requeueStaleRunningJobs();
        $workspaceId = $this->resolveWorkspaceId($workspaceId, $userId);
        $eligibleCount = $this->countOpenAutoCompletableTasks($userId, $workspaceId);
        $row = $this->getActiveRow($userId, $workspaceId) ?: $this->getLatestTerminalRow($userId, $workspaceId);
        return $this->buildStatusPayload($userId, $workspaceId, $row, $eligibleCount);
    }

    public function countOpenAutoCompletableTasks(int $userId, ?int $workspaceId = null): int
    {
        $this->ensureTable();
        $workspaceId = $this->resolveWorkspaceId($workspaceId, $userId);
        if ($userId <= 0 || $workspaceId <= 0) {
            return 0;
        }
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM (
                     SELECT id
                     FROM tasks
                     WHERE workspace_id = ?
                       AND assigned_to = ?
                       AND status NOT IN ('completed', 'cancelled')
                       AND (completion_mode = 'auto'
                            OR (JSON_VALID(COALESCE(metadata_json, '{}'))
                                AND JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.auto_complete_allowed') = true))
                     UNION
                     SELECT id
                     FROM tasks
                     WHERE workspace_id = ?
                       AND created_by = ?
                       AND status NOT IN ('completed', 'cancelled')
                       AND (completion_mode = 'auto'
                            OR (JSON_VALID(COALESCE(metadata_json, '{}'))
                                AND JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.auto_complete_allowed') = true))
                 ) eligible_tasks",
                [$workspaceId, $userId, $workspaceId, $userId]
            );
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            $rows = Database::query(
                "SELECT completion_mode, metadata_json
                 FROM tasks
                 WHERE workspace_id = ?
                   AND (assigned_to = ? OR created_by = ?)
                   AND status NOT IN ('completed', 'cancelled')",
                [$workspaceId, $userId, $userId]
            );
            $count = 0;
            foreach ($rows as $row) {
                $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
                if (($row['completion_mode'] ?? null) === 'auto' || !empty($metadata['auto_complete_allowed'])) {
                    $count++;
                }
            }
            return $count;
        }
    }

    public function pruneOldTerminalRows(int $retentionDays = 14): int
    {
        $this->ensureTable();
        $retentionDays = max(1, min(365, $retentionDays));

        return Database::execute(
            "DELETE FROM task_completion_scan_queue
             WHERE status IN ('completed', 'failed')
               AND finished_at IS NOT NULL
               AND finished_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)"
        );
    }

    private function claimQueuedBatch(int $limit, ?int $userId = null, ?int $workspaceId = null): array
    {
        $sql = "SELECT *
                FROM task_completion_scan_queue
                WHERE status = 'queued'";
        $params = [];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        if ($userId !== null && $userId > 0) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        $sql .= " ORDER BY queued_at ASC, id ASC LIMIT {$limit}";

        $rows = Database::query($sql, $params);

        $claimed = [];
        foreach ($rows as $row) {
            $queueId = (int) ($row['id'] ?? 0);
            if ($queueId <= 0) {
                continue;
            }

            $updated = Database::execute(
                "UPDATE task_completion_scan_queue
                 SET status = 'running',
                     started_at = NOW(),
                     last_error = NULL,
                     updated_at = NOW()
                 WHERE id = ?
                   AND status = 'queued'",
                [$queueId]
            );
            if ($updated > 0) {
                $row['status'] = 'running';
                $row['started_at'] = date('Y-m-d H:i:s');
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    private function getActiveRow(int $userId, int $workspaceId): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM task_completion_scan_queue
             WHERE workspace_id = ?
               AND user_id = ?
               AND status IN ('queued', 'running')
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    private function getLatestCompletedRow(int $userId, int $workspaceId): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM task_completion_scan_queue
             WHERE workspace_id = ?
               AND user_id = ?
               AND status = 'completed'
             ORDER BY finished_at DESC, id DESC
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    private function getLatestTerminalRow(int $userId, int $workspaceId): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM task_completion_scan_queue
             WHERE workspace_id = ?
               AND user_id = ?
               AND status IN ('completed', 'failed')
             ORDER BY COALESCE(finished_at, queued_at) DESC, id DESC
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    private function markCompleted(int $queueId, array $payload): void
    {
        Database::execute(
            "UPDATE task_completion_scan_queue
             SET status = 'completed',
                 finished_at = NOW(),
                 results_json = ?,
                 last_error = NULL,
                 active_dedupe_key = NULL,
                 updated_at = NOW()
             WHERE id = ?",
            [json_encode($payload), $queueId]
        );
    }

    private function markContinuation(int $queueId, int $cursorTaskId, array $payload, int $remainingCount): void
    {
        Database::execute(
            "UPDATE task_completion_scan_queue
             SET status = 'queued',
                 cursor_task_id = ?,
                 continuation_count = continuation_count + 1,
                 remaining_count = ?,
                 started_at = NULL,
                 results_json = ?,
                 last_error = NULL,
                 updated_at = NOW()
             WHERE id = ? AND status = 'running'",
            [$cursorTaskId, $remainingCount, json_encode($payload), $queueId]
        );
    }

    private function markFailed(int $queueId, string $error): void
    {
        Database::execute(
            "UPDATE task_completion_scan_queue
             SET status = 'failed',
                 finished_at = NOW(),
                 last_error = ?,
                 active_dedupe_key = NULL,
                 updated_at = NOW()
             WHERE id = ?",
            [substr($error, 0, 1000), $queueId]
        );
    }

    private function requeueStaleRunningJobs(): void
    {
        Database::execute(
            "UPDATE task_completion_scan_queue
             SET status = 'queued',
                 started_at = NULL,
                 last_error = 'Re-queued after scan worker timeout.',
                 active_dedupe_key = CONCAT(workspace_id, ':', user_id),
                 updated_at = NOW()
             WHERE status = 'running'
               AND started_at IS NOT NULL
               AND started_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [self::RUN_LOCK_TIMEOUT_SECONDS]
        );
    }

    private function buildStatusPayload(int $userId, int $workspaceId, ?array $row, int $eligibleCount): array
    {
        $status = (string) ($row['status'] ?? 'idle');
        $results = $this->decodeJsonField($row['results_json'] ?? null);
        $finishedAt = (string) ($row['finished_at'] ?? '');
        $lastScanAgeSeconds = null;
        if ($finishedAt !== '') {
            $timestamp = strtotime($finishedAt);
            if ($timestamp !== false) {
                $lastScanAgeSeconds = max(0, time() - $timestamp);
            }
        }

        return [
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'eligible_task_count' => $eligibleCount,
            'show_status_bar' => $eligibleCount > 0,
            'status' => $status,
            'queued_at' => $row['queued_at'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'completed_tasks' => (int) ($results['completed_tasks'] ?? 0),
            'matched_tasks' => (int) ($results['matched_tasks'] ?? 0),
            'last_scan_age_seconds' => $lastScanAgeSeconds,
            'refresh_recommended' => !empty($results['refresh_recommended']),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'request_source' => (string) ($row['request_source'] ?? ''),
            'cursor_task_id' => !empty($row['cursor_task_id']) ? (int) $row['cursor_task_id'] : null,
            'continuation_count' => (int) ($row['continuation_count'] ?? 0),
            'remaining_count' => (int) ($row['remaining_count'] ?? 0),
            'is_fresh' => $row ? $this->rowIsFresh($row) : false,
        ];
    }

    private function rowIsFresh(array $row): bool
    {
        if (($row['status'] ?? '') !== 'completed') {
            return false;
        }
        $finishedAt = strtotime((string) ($row['finished_at'] ?? ''));
        if ($finishedAt === false) {
            return false;
        }
        return (time() - $finishedAt) <= self::FRESHNESS_WINDOW_SECONDS;
    }

    private function decodeJsonField($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, ['cron', 'tasks_page', 'manual'], true) ? $source : 'manual';
    }

    private function resolveWorkspaceId(?int $workspaceId, int $userId = 0): int
    {
        $workspaceId = (int) ($workspaceId ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        if ($userId > 0) {
            $row = Database::queryOne(
                "SELECT workspace_id
                 FROM workspace_memberships
                 WHERE user_id = ?
                   AND membership_status = 'active'
                 ORDER BY is_owner DESC, id ASC
                 LIMIT 1",
                [$userId]
            );
            return (int) ($row['workspace_id'] ?? 0);
        }

        return 0;
    }

    private function activeDedupeKey(int $workspaceId, int $userId): string
    {
        return $workspaceId . ':' . $userId;
    }

    private function ensureTable(): void
    {
        if (self::$tableReady === true) {
            return;
        }

        $exists = Database::queryOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'task_completion_scan_queue'"
        );

        if ($exists) {
            self::$tableReady = true;
            return;
        }

        Database::execute(
            "CREATE TABLE IF NOT EXISTS task_completion_scan_queue (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                workspace_id INT NOT NULL,
                user_id INT NOT NULL,
                status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
                requested_by INT NULL,
                request_source ENUM('cron', 'tasks_page', 'manual') NOT NULL DEFAULT 'manual',
                active_dedupe_key VARCHAR(64) NULL,
                cursor_task_id INT NULL,
                continuation_count INT UNSIGNED NOT NULL DEFAULT 0,
                remaining_count INT UNSIGNED NOT NULL DEFAULT 0,
                queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                last_error TEXT NULL,
                results_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_task_scan_active_workspace_user (active_dedupe_key),
                INDEX idx_task_scan_user_status (user_id, status, queued_at),
                INDEX idx_task_scan_workspace_user_status (workspace_id, user_id, status, queued_at),
                INDEX idx_task_scan_workspace_status_queued (workspace_id, status, queued_at),
                INDEX idx_task_scan_status_queued (status, queued_at),
                INDEX idx_task_scan_finished (user_id, finished_at),
                INDEX idx_task_scan_workspace_finished (workspace_id, user_id, finished_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$tableReady = true;
    }
}
