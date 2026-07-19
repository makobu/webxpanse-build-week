<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyEvaluationService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIAutonomyDriftMonitoringService $drift = null
    ) {
        $this->drift = $this->drift ?? new AIAutonomyDriftMonitoringService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function startRun(array $data): int
    {
        if (!$this->tableExists('ai_autonomy_eval_runs')) {
            return 0;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId((string) ($data['tenant_key'] ?? ''));
        $tenantKey = $this->workspaceScope->workspaceTenantKey($workspaceId);

        Database::execute(
            "INSERT INTO ai_autonomy_eval_runs
                (workspace_id, tenant_key, domain_key, autonomy_mode, run_status, scenario_count, metrics_json, summary_json,
                 started_at, completed_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $tenantKey,
                (string) ($data['domain_key'] ?? 'commercial_mvp'),
                (string) ($data['autonomy_mode'] ?? 'suggest_only'),
                (string) ($data['run_status'] ?? 'running'),
                max(0, (int) ($data['scenario_count'] ?? 0)),
                json_encode((array) ($data['metrics'] ?? [])),
                json_encode((array) ($data['summary'] ?? [])),
                (string) ($data['started_at'] ?? date('Y-m-d H:i:s')),
                !empty($data['completed_at']) ? (string) $data['completed_at'] : null,
                !empty($data['created_by']) ? (int) $data['created_by'] : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function completeRun(int $runId, array $metrics, array $summary = [], string $status = 'completed'): bool
    {
        if ($runId <= 0 || !$this->tableExists('ai_autonomy_eval_runs')) {
            return false;
        }

        Database::execute(
            "UPDATE ai_autonomy_eval_runs
             SET run_status = ?, metrics_json = ?, summary_json = ?, completed_at = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [
                $status,
                json_encode($metrics),
                json_encode($summary),
                date('Y-m-d H:i:s'),
                $runId,
            ]
        );

        return true;
    }

    public function computeMetrics(array $events): array
    {
        $sampleSize = count($events);
        if ($sampleSize === 0) {
            return [
                'sample_size' => 0,
                'precision_at_threshold' => 0.0,
                'reversal_rate' => 0.0,
                'edit_after_autonomy_rate' => 0.0,
                'approval_override_rate' => 0.0,
                'duplicate_action_rate' => 0.0,
                'business_completion_rate' => 0.0,
                'avg_time_to_outcome_minutes' => 0.0,
                'time_to_outcome_delta_minutes' => 0.0,
                'confidence_calibration_error' => 0.0,
                'calibration_buckets' => [],
            ];
        }

        $autonomousCount = 0;
        $autonomousSuccess = 0;
        $reversed = 0;
        $edited = 0;
        $overridden = 0;
        $duplicates = 0;
        $completed = 0;
        $durations = [];
        $baselineDurations = [];
        $calibrationBuckets = [
            '0.00-0.49' => ['count' => 0, 'actual_success' => 0],
            '0.50-0.74' => ['count' => 0, 'actual_success' => 0],
            '0.75-0.89' => ['count' => 0, 'actual_success' => 0],
            '0.90-1.00' => ['count' => 0, 'actual_success' => 0],
        ];
        $expectedSum = 0.0;
        $actualSum = 0.0;

        foreach ($events as $event) {
            $isAutonomous = !empty($event['autonomous']);
            $confidence = (float) ($event['confidence_score'] ?? 0.0);
            if ($isAutonomous) {
                $autonomousCount++;
                if (empty($event['was_reversed']) && empty($event['was_edited']) && !empty($event['was_successful'])) {
                    $autonomousSuccess++;
                }
                $expectedSum += $confidence;
                $actualSuccess = (empty($event['was_reversed']) && empty($event['was_edited']) && !empty($event['was_successful'])) ? 1.0 : 0.0;
                $actualSum += $actualSuccess;
                $bucket = $this->bucketForConfidence($confidence);
                $calibrationBuckets[$bucket]['count']++;
                $calibrationBuckets[$bucket]['actual_success'] += (int) $actualSuccess;
            }
            if (!empty($event['was_reversed'])) {
                $reversed++;
            }
            if (!empty($event['was_edited'])) {
                $edited++;
            }
            if (!empty($event['approval_override'])) {
                $overridden++;
            }
            if (!empty($event['duplicate_action'])) {
                $duplicates++;
            }
            if (!empty($event['completed'])) {
                $completed++;
            }
            if (isset($event['time_to_outcome_minutes'])) {
                $durations[] = (float) $event['time_to_outcome_minutes'];
            }
            if (isset($event['human_baseline_minutes'])) {
                $baselineDurations[] = (float) $event['human_baseline_minutes'];
            }
        }

        $avgDuration = $durations ? array_sum($durations) / count($durations) : 0.0;
        $avgBaseline = $baselineDurations ? array_sum($baselineDurations) / count($baselineDurations) : 0.0;

        return [
            'sample_size' => $sampleSize,
            'precision_at_threshold' => $autonomousCount > 0 ? round($autonomousSuccess / $autonomousCount, 4) : 0.0,
            'reversal_rate' => round($reversed / $sampleSize, 4),
            'edit_after_autonomy_rate' => $autonomousCount > 0 ? round($edited / $autonomousCount, 4) : 0.0,
            'approval_override_rate' => $autonomousCount > 0 ? round($overridden / $autonomousCount, 4) : 0.0,
            'duplicate_action_rate' => round($duplicates / $sampleSize, 4),
            'business_completion_rate' => round($completed / $sampleSize, 4),
            'avg_time_to_outcome_minutes' => round($avgDuration, 2),
            'time_to_outcome_delta_minutes' => round($avgBaseline - $avgDuration, 2),
            'confidence_calibration_error' => $autonomousCount > 0 ? round(abs(($expectedSum / $autonomousCount) - ($actualSum / $autonomousCount)), 4) : 0.0,
            'calibration_buckets' => $this->finalizeBuckets($calibrationBuckets),
        ];
    }

    public function buildSummary(string $tenantKey, string $domainKey, array $metrics): array
    {
        $drift = $this->drift->summarize($tenantKey, $domainKey);
        $unstableReasons = array_values(array_unique(array_merge(
            (array) ($drift['unstable_reasons'] ?? []),
            (float) ($metrics['confidence_calibration_error'] ?? 0) >= 0.18 ? ['confidence_calibration_mismatch'] : []
        )));

        return [
            'promotion_recommended' => (float) ($metrics['precision_at_threshold'] ?? 0) >= 0.9
                && (float) ($metrics['reversal_rate'] ?? 1) <= 0.08
                && (float) ($metrics['edit_after_autonomy_rate'] ?? 1) <= 0.12
                && (float) ($metrics['duplicate_action_rate'] ?? 1) <= 0.05
                && $unstableReasons === [],
            'drift_status' => $drift,
            'unstable_reasons' => $unstableReasons,
            'confidence_calibration_summary' => [
                'confidence_calibration_error' => (float) ($metrics['confidence_calibration_error'] ?? 0.0),
                'calibration_buckets' => (array) ($metrics['calibration_buckets'] ?? []),
            ],
        ];
    }

    private function bucketForConfidence(float $confidence): string
    {
        return match (true) {
            $confidence < 0.5 => '0.00-0.49',
            $confidence < 0.75 => '0.50-0.74',
            $confidence < 0.9 => '0.75-0.89',
            default => '0.90-1.00',
        };
    }

    private function finalizeBuckets(array $buckets): array
    {
        foreach ($buckets as $label => $bucket) {
            $count = max(1, (int) ($bucket['count'] ?? 0));
            $buckets[$label]['actual_success_rate'] = (int) ($bucket['count'] ?? 0) > 0
                ? round((int) ($bucket['actual_success'] ?? 0) / $count, 4)
                : 0.0;
        }
        return $buckets;
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
