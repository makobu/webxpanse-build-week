<?php

namespace CRM\Services;

use CRM\Database;

class AIAutonomyDriftMonitoringService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function summarize(string $tenantKey, string $domainKey, ?string $actionKey = null, int $limit = 100): array
    {
        if (!$this->tableExists('ai_operator_demonstrations')) {
            return $this->emptySummary();
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $where = ['workspace_id = ?', 'domain_key = ?'];
        $params = [$workspaceId, $domainKey];
        if ($actionKey !== null && $actionKey !== '') {
            $where[] = 'action_key = ?';
            $params[] = $actionKey;
        }

        $rows = Database::query(
            "SELECT * FROM ai_operator_demonstrations WHERE " . implode(' AND ', $where) . " ORDER BY observed_at DESC, id DESC LIMIT " . max(1, min(500, $limit)),
            $params
        );

        if ($rows === []) {
            return $this->emptySummary();
        }

        $reversed = 0;
        $edited = 0;
        $duplicates = 0;
        $completed = 0;
        $calibrationBuckets = [
            '0.00-0.49' => ['count' => 0, 'actual_success' => 0],
            '0.50-0.74' => ['count' => 0, 'actual_success' => 0],
            '0.75-0.89' => ['count' => 0, 'actual_success' => 0],
            '0.90-1.00' => ['count' => 0, 'actual_success' => 0],
        ];
        $expectedSum = 0.0;
        $actualSum = 0.0;
        $recentWindow = array_slice($rows, 0, min(20, count($rows)));
        $olderWindow = array_slice($rows, min(20, count($rows)));
        $recentSuccess = 0;
        $olderSuccess = 0;

        foreach ($rows as $index => $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $confidence = (float) ($metadata['assistant_confidence'] ?? $metadata['confidence_score'] ?? 0.0);
            $wasSuccessful = !empty($row['was_successful']) && empty($row['was_reversed']) && empty($row['was_edited']);
            $expectedSum += $confidence;
            $actualSum += $wasSuccessful ? 1.0 : 0.0;
            if (!empty($row['was_reversed'])) {
                $reversed++;
            }
            if (!empty($row['was_edited'])) {
                $edited++;
            }
            if (!empty($metadata['duplicate_action'])) {
                $duplicates++;
            }
            if (in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved'], true)) {
                $completed++;
            }

            $bucket = $this->bucketForConfidence($confidence);
            $calibrationBuckets[$bucket]['count']++;
            if ($wasSuccessful) {
                $calibrationBuckets[$bucket]['actual_success']++;
            }

            if ($index < count($recentWindow) && $wasSuccessful) {
                $recentSuccess++;
            } elseif ($index >= count($recentWindow) && $olderWindow !== [] && $wasSuccessful) {
                $olderSuccess++;
            }
        }

        $sampleSize = count($rows);
        $recentRate = $recentWindow !== [] ? $recentSuccess / count($recentWindow) : 0.0;
        $olderRate = $olderWindow !== [] ? $olderSuccess / count($olderWindow) : $recentRate;
        $confidenceCalibrationError = abs(($expectedSum / $sampleSize) - ($actualSum / $sampleSize));
        $reversalRate = $reversed / $sampleSize;
        $editRate = $edited / $sampleSize;
        $duplicateRate = $duplicates / $sampleSize;
        $completionRate = $completed / $sampleSize;
        $trendDelta = $recentRate - $olderRate;
        $unstableReasons = [];
        if ($confidenceCalibrationError >= 0.18) {
            $unstableReasons[] = 'confidence_calibration_mismatch';
        }
        if ($reversalRate >= 0.12) {
            $unstableReasons[] = 'reversal_rate_spike';
        }
        if ($editRate >= 0.15) {
            $unstableReasons[] = 'edit_rate_spike';
        }
        if ($duplicateRate >= 0.08) {
            $unstableReasons[] = 'duplicate_action_spike';
        }
        if ($trendDelta <= -0.15) {
            $unstableReasons[] = 'completion_trend_down';
        }

        return [
            'sample_size' => $sampleSize,
            'confidence_calibration_error' => round($confidenceCalibrationError, 4),
            'reversal_rate' => round($reversalRate, 4),
            'edit_rate' => round($editRate, 4),
            'duplicate_rate' => round($duplicateRate, 4),
            'business_completion_rate' => round($completionRate, 4),
            'recent_completion_rate' => round($recentRate, 4),
            'baseline_completion_rate' => round($olderRate, 4),
            'completion_rate_delta' => round($trendDelta, 4),
            'unstable' => $unstableReasons !== [],
            'unstable_reasons' => $unstableReasons,
            'calibration_buckets' => $this->finalizeBuckets($calibrationBuckets),
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

    private function emptySummary(): array
    {
        return [
            'sample_size' => 0,
            'confidence_calibration_error' => 0.0,
            'reversal_rate' => 0.0,
            'edit_rate' => 0.0,
            'duplicate_rate' => 0.0,
            'business_completion_rate' => 0.0,
            'recent_completion_rate' => 0.0,
            'baseline_completion_rate' => 0.0,
            'completion_rate_delta' => 0.0,
            'unstable' => false,
            'unstable_reasons' => [],
            'calibration_buckets' => [],
        ];
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

    private function decodeJson($value): array
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
