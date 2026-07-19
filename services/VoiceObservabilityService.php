<?php

namespace CRM\Services;

use CRM\Database;

class VoiceObservabilityService
{
    private const ACTIVE_STATES = "'requested','queued','received','consent_pending','dialing_agent','agent_answered','dialing_customer','ringing','in_progress'";

    public function snapshot(int $workspaceId): array
    {
        $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
        $active = (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM voice_calls WHERE workspace_id = ? AND state IN (' . self::ACTIVE_STATES . ')',
            [$workspaceId]
        )['c'] ?? 0);
        $calls24 = Database::queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(state IN ('provider_failed','policy_blocked')) AS failed
             FROM voice_calls WHERE workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$workspaceId]
        ) ?: [];
        $transcription = Database::queryOne(
            "SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed, SUM(status = 'dead_letter') AS dead_letter,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, completed_at) END) AS average_delay_seconds
             FROM voice_transcription_jobs WHERE workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$workspaceId]
        ) ?: [];
        $backlog = Database::queryOne(
            "SELECT COUNT(*) AS jobs, COALESCE(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 0) AS oldest_seconds
             FROM voice_transcription_jobs WHERE workspace_id = ? AND status IN ('pending','processing','failed')",
            [$workspaceId]
        ) ?: [];
        $heartbeat = Database::queryOne(
            "SELECT worker_key, status, pending_count, failed_count, last_error, heartbeat_at,
                    TIMESTAMPDIFF(SECOND, heartbeat_at, NOW()) AS age_seconds
             FROM voice_worker_heartbeats WHERE workspace_id = ? ORDER BY heartbeat_at DESC LIMIT 1",
            [$workspaceId]
        ) ?: [];
        $callback = Database::queryOne(
            "SELECT SUM(accepted = 0) AS rejected,
                    SUM(accepted = 0 AND (rejection_reason LIKE '%replay%' OR rejection_reason LIKE '%duplicate%')) AS replayed
             FROM voice_call_events WHERE workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$workspaceId]
        ) ?: [];
        $retention = Database::queryOne(
            "SELECT
                (SELECT COUNT(*) FROM voice_recordings WHERE workspace_id = ? AND deleted_at IS NULL AND retained_until < NOW()) AS expired_recordings,
                (SELECT COUNT(*) FROM voice_call_transcripts WHERE workspace_id = ? AND deleted_at IS NULL AND retained_until < NOW()) AS expired_transcripts",
            [$workspaceId, $workspaceId]
        ) ?: [];
        $usage = Database::queryOne(
            "SELECT COUNT(*) AS calls, COALESCE(SUM(estimated_billable_minutes),0) AS minutes,
                    COALESCE(SUM(estimated_provider_cost),0) AS provider_cost, COALESCE(SUM(estimated_ai_cost),0) AS ai_cost
             FROM voice_usage_ledger WHERE workspace_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
            [$workspaceId]
        ) ?: [];
        $failureCategories = Database::query(
            "SELECT COALESCE(NULLIF(failure_category,''), state) AS category, COUNT(*) AS calls
             FROM voice_calls WHERE workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND state IN ('busy','no_answer','rejected','expired','provider_failed','policy_blocked')
             GROUP BY COALESCE(NULLIF(failure_category,''), state) ORDER BY calls DESC",
            [$workspaceId]
        );

        $limit = max(0, (int) ($entitlements['concurrent_calls'] ?? 0));
        $total24 = (int) ($calls24['total'] ?? 0);
        $failed24 = (int) ($calls24['failed'] ?? 0);
        $transcriptionTotal = (int) ($transcription['total'] ?? 0);
        $transcriptionCompleted = (int) ($transcription['completed'] ?? 0);
        $metrics = [
            'active_calls' => $active,
            'concurrency_limit' => $limit,
            'concurrency_percent' => $limit > 0 ? round(($active / $limit) * 100, 1) : 0.0,
            'peak_concurrency_today' => $this->peakConcurrencyToday($workspaceId),
            'calls_24h' => $total24,
            'failed_calls_24h' => $failed24,
            'failed_ratio_percent' => $total24 > 0 ? round(($failed24 / $total24) * 100, 1) : 0.0,
            'transcript_success_percent' => $transcriptionTotal > 0 ? round(($transcriptionCompleted / $transcriptionTotal) * 100, 1) : 0.0,
            'transcript_average_delay_seconds' => (int) round((float) ($transcription['average_delay_seconds'] ?? 0)),
            'dead_letter_jobs' => (int) ($transcription['dead_letter'] ?? 0),
            'backlog_jobs' => (int) ($backlog['jobs'] ?? 0),
            'oldest_backlog_seconds' => (int) ($backlog['oldest_seconds'] ?? 0),
            'callback_rejections_1h' => (int) ($callback['rejected'] ?? 0),
            'callback_replays_1h' => (int) ($callback['replayed'] ?? 0),
            'expired_recordings' => (int) ($retention['expired_recordings'] ?? 0),
            'expired_transcripts' => (int) ($retention['expired_transcripts'] ?? 0),
        ];
        return [
            'metrics' => $metrics,
            'usage' => $usage,
            'worker' => $heartbeat,
            'failure_categories' => $failureCategories,
            'alerts' => $this->alerts($metrics, $heartbeat),
        ];
    }

    private function peakConcurrencyToday(int $workspaceId): int
    {
        $rows = Database::query(
            "SELECT COALESCE(requested_at, created_at) AS started_at,
                    COALESCE(completed_at, IF(state IN (" . self::ACTIVE_STATES . "), NOW(), updated_at)) AS ended_at
             FROM voice_calls WHERE workspace_id = ? AND COALESCE(requested_at, created_at) >= CURDATE()",
            [$workspaceId]
        );
        $points = [];
        foreach ($rows as $row) {
            $start = strtotime((string) ($row['started_at'] ?? ''));
            $end = strtotime((string) ($row['ended_at'] ?? ''));
            if ($start === false || $end === false || $end < $start) continue;
            $points[] = [$start, 1];
            $points[] = [$end, -1];
        }
        usort($points, static fn(array $a, array $b): int => $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]);
        $current = 0;
        $peak = 0;
        foreach ($points as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }
        return $peak;
    }

    private function alerts(array $metrics, array $heartbeat): array
    {
        $alerts = [];
        $push = static function (array &$items, string $key, string $severity, string $message): void {
            $items[] = compact('key', 'severity', 'message');
        };
        if ($heartbeat === [] || (int) ($heartbeat['age_seconds'] ?? 999999) > 300) {
            $push($alerts, 'worker_heartbeat', 'critical', 'No healthy voice worker heartbeat has been seen in the last five minutes.');
        }
        if ((int) $metrics['oldest_backlog_seconds'] > 600) {
            $push($alerts, 'transcription_backlog', 'critical', 'The oldest transcription job has waited more than ten minutes.');
        }
        if ((float) $metrics['concurrency_percent'] >= 80) {
            $push($alerts, 'concurrency', 'warning', 'Active calls are at or above 80% of the workspace concurrency limit.');
        }
        if ((int) $metrics['calls_24h'] >= 5 && (float) $metrics['failed_ratio_percent'] >= 20) {
            $push($alerts, 'failed_calls', 'warning', 'The provider or policy failure ratio is at or above 20% for the last 24 hours.');
        }
        if ((int) $metrics['callback_rejections_1h'] >= 5 || (int) $metrics['callback_replays_1h'] >= 3) {
            $push($alerts, 'callback_security', 'critical', 'Rejected or replayed callback volume is above the security threshold.');
        }
        if ((int) $metrics['dead_letter_jobs'] > 0) {
            $push($alerts, 'dead_letters', 'warning', 'One or more transcription jobs require manual review or retry.');
        }
        if ((int) $metrics['expired_recordings'] > 0 || (int) $metrics['expired_transcripts'] > 0) {
            $push($alerts, 'retention', 'warning', 'Expired voice evidence is waiting for the retention worker.');
        }
        return $alerts;
    }
}
