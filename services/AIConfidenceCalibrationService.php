<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\UserPreferences;

class AIConfidenceCalibrationService
{
    private const GLOBAL_BOUNDS = [
        'ai_advice_min_confidence' => [0.80, 0.97],
        'ai_action_min_confidence' => [0.86, 0.99],
        'assistant_customer_send_min_confidence' => [0.86, 0.99],
        'ai_goal_relevance_min_score' => [0.55, 0.90],
        'ai_auto_task_completion_min_confidence' => [0.92, 0.995],
    ];

    private UserPreferences $preferences;
    private AIThresholdUpdateService $thresholds;
    private AIRuntimeControlService $runtimeControls;
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->preferences = new UserPreferences();
        $this->thresholds = new AIThresholdUpdateService();
        $this->runtimeControls = new AIRuntimeControlService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function getCalibrationSummary(array $filters = []): array
    {
        $rows = $this->fetchOutcomes($filters);
        $grouped = [];
        foreach ($rows as $row) {
            $surface = (string) ($row['surface'] ?? 'assistant');
            $actionType = (string) ($row['action_type'] ?? 'unknown');
            $grouped[$surface][$actionType][] = $row;
        }

        $summary = [];
        foreach ($grouped as $surface => $actions) {
            foreach ($actions as $actionType => $items) {
                $summary[$surface][$actionType] = $this->computeMetrics($items, $surface, $actionType);
            }
        }

        return [
            'summary' => $summary,
            'recent_changes' => $this->getRecentChanges(),
        ];
    }

    public function getSurfaceMetrics(string $surface, array $filters = []): array
    {
        return $this->getCalibrationSummary($filters + ['surface' => $surface])['summary'][$surface] ?? [];
    }

    public function getOutcomeRows(array $filters = []): array
    {
        return $this->fetchOutcomes($filters);
    }

    public function getRecentTuningChanges(): array
    {
        return $this->getRecentChanges();
    }

    public function getThresholdRecommendations(array $filters = []): array
    {
        $summary = $this->getCalibrationSummary($filters)['summary'];
        $recommendations = [];
        foreach ($summary as $surface => $actions) {
            foreach ($actions as $actionType => $metrics) {
                $recommendation = $this->buildRecommendation($surface, $actionType, $metrics);
                if ($recommendation !== null) {
                    $recommendations[] = $recommendation;
                }
            }
        }
        return $recommendations;
    }

    public function applyAutonomousTuning(array $filters = []): array
    {
        $control = $this->runtimeControls->getEffectiveControl('autonomous_tuning');
        if (in_array((string) ($control['control_mode'] ?? 'normal'), ['paused', 'diagnostics_only'], true)) {
            return ['applied' => [], 'skipped' => ['runtime_control_' . (string) ($control['control_mode'] ?? 'paused')]];
        }
        if (!$this->isAutonomousTuningEnabled()) {
            return ['applied' => [], 'skipped' => ['autonomous_tuning_disabled']];
        }
        if ($this->hasCalibrationFreeze()) {
            return ['applied' => [], 'skipped' => ['calibration_freeze_condition']];
        }

        $applied = [];
        $skipped = [];
        foreach ($this->getThresholdRecommendations($filters) as $recommendation) {
            $current = (float) $recommendation['current_threshold'];
            $target = (float) $recommendation['recommended_threshold'];
            $change = round($target - $current, 4);
            if (abs($change) < 0.0001) {
                $skipped[] = $recommendation['surface'] . ':' . $recommendation['action_type'] . ':no_change';
                continue;
            }

            $change = max(-$this->getDailyChangeCap(), min($this->getDailyChangeCap(), $change));
            $bounded = $this->boundThreshold((string) $recommendation['threshold_key'], $current + $change);
            $rollingAdjusted = $this->applyRollingCap((string) $recommendation['threshold_key'], $bounded, $current);
            if ($rollingAdjusted === $current) {
                $skipped[] = $recommendation['surface'] . ':' . $recommendation['action_type'] . ':rolling_cap';
                continue;
            }

            $this->thresholds->applyThreshold((string) $recommendation['threshold_key'], $rollingAdjusted, [
                'surface' => $recommendation['surface'],
                'action_type' => $recommendation['action_type'],
                'scope_type' => $recommendation['scope_type'],
                'previous_value' => $current,
                'recommended_value' => $target,
                'sample_size' => $recommendation['sample_size'],
                'observed_precision' => $recommendation['observed_precision'],
                'observed_edit_rate' => $recommendation['observed_edit_rate'],
                'observed_rejection_rate' => $recommendation['observed_rejection_rate'],
                'observed_reversal_rate' => $recommendation['observed_reversal_rate'],
                'observed_failure_rate' => $recommendation['observed_failure_rate'],
                'change_reason_summary' => $recommendation['change_reason_summary'],
                'calibration_snapshot_json' => $recommendation['calibration_snapshot_json'],
                'applied_automatically' => true,
            ]);
            $applied[] = $recommendation + ['applied_value' => $rollingAdjusted];
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    public function computePrecisionAtThreshold(string $surface, string $actionType, float $threshold): array
    {
        $rows = $this->fetchOutcomes(['surface' => $surface, 'action_type' => $actionType]);
        $eligible = array_values(array_filter($rows, static fn(array $row): bool => (float) ($row['predicted_confidence'] ?? 0) >= $threshold));
        $success = count(array_filter($eligible, static fn(array $row): bool => in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved', 'completed'], true)));
        return [
            'sample_size' => count($eligible),
            'precision' => count($eligible) > 0 ? round($success / count($eligible), 4) : 0.0,
        ];
    }

    public function recordTuningChange(array $change): int
    {
        $this->thresholds->applyThreshold((string) $change['threshold_key'], (float) $change['applied_value'], $change);
        $row = Database::queryOne("SELECT id FROM ai_threshold_tuning_log ORDER BY id DESC LIMIT 1");
        return (int) ($row['id'] ?? 0);
    }

    private function computeMetrics(array $rows, string $surface, string $actionType): array
    {
        $sampleSize = count($rows);
        $counts = array_fill_keys(['accepted', 'approved', 'completed', 'edited', 'ignored', 'rejected', 'reversed', 'failed'], 0);
        $outcomeScore = 0.0;
        $target = $this->thresholds->resolveThresholdTarget($surface, $actionType);
        $currentThreshold = (float) ($this->thresholds->getCurrentThreshold($target['threshold_key'], $surface, $actionType) ?? 0.92);
        $highConfidenceRows = [];
        $falseBlocks = 0;

        foreach ($rows as $row) {
            $label = (string) ($row['outcome_label'] ?? 'ignored');
            if (isset($counts[$label])) {
                $counts[$label]++;
            }
            $outcomeScore += (float) ($row['outcome_score'] ?? 0);
            if ((float) ($row['predicted_confidence'] ?? 0) >= $currentThreshold) {
                $highConfidenceRows[] = $row;
            }
            if (in_array((string) ($row['policy_decision'] ?? ''), ['blocked', 'suggest_only'], true)
                && in_array($label, ['accepted', 'approved', 'completed'], true)) {
                $falseBlocks++;
            }
        }

        $successCount = $counts['accepted'] + $counts['approved'] + $counts['completed'];
        $highSuccess = count(array_filter($highConfidenceRows, static fn(array $row): bool => in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved', 'completed'], true)));
        $highFailures = count(array_filter($highConfidenceRows, static fn(array $row): bool => in_array((string) ($row['outcome_label'] ?? ''), ['rejected', 'reversed', 'failed'], true)));

        return [
            'sample_size' => $sampleSize,
            'acceptance_rate' => $sampleSize > 0 ? round($successCount / $sampleSize, 4) : 0.0,
            'edit_rate' => $sampleSize > 0 ? round($counts['edited'] / $sampleSize, 4) : 0.0,
            'rejection_rate' => $sampleSize > 0 ? round($counts['rejected'] / $sampleSize, 4) : 0.0,
            'reversal_rate' => $sampleSize > 0 ? round($counts['reversed'] / $sampleSize, 4) : 0.0,
            'failure_rate' => $sampleSize > 0 ? round($counts['failed'] / $sampleSize, 4) : 0.0,
            'ignored_rate' => $sampleSize > 0 ? round($counts['ignored'] / $sampleSize, 4) : 0.0,
            'mean_outcome_score' => $sampleSize > 0 ? round($outcomeScore / $sampleSize, 4) : 0.0,
            'current_threshold' => $currentThreshold,
            'precision_at_current_threshold' => count($highConfidenceRows) > 0 ? round($highSuccess / count($highConfidenceRows), 4) : 0.0,
            'high_confidence_success_rate' => count($highConfidenceRows) > 0 ? round($highSuccess / count($highConfidenceRows), 4) : 0.0,
            'high_confidence_failure_rate' => count($highConfidenceRows) > 0 ? round($highFailures / count($highConfidenceRows), 4) : 0.0,
            'false_block_indicator' => $sampleSize > 0 ? round($falseBlocks / $sampleSize, 4) : 0.0,
            'band_metrics' => $this->computeBandMetrics($rows),
            'threshold_target' => $target,
        ];
    }

    private function computeBandMetrics(array $rows): array
    {
        $bands = [
            '0.00-0.69' => [0.00, 0.69],
            '0.70-0.84' => [0.70, 0.84],
            '0.85-0.91' => [0.85, 0.91],
            '0.92-0.96' => [0.92, 0.96],
            '0.97-1.00' => [0.97, 1.00],
        ];
        $output = [];
        foreach ($bands as $label => [$min, $max]) {
            $bucket = array_values(array_filter($rows, static fn(array $row): bool => (float) ($row['predicted_confidence'] ?? 0) >= $min && (float) ($row['predicted_confidence'] ?? 0) <= $max));
            $success = count(array_filter($bucket, static fn(array $row): bool => in_array((string) ($row['outcome_label'] ?? ''), ['accepted', 'approved', 'completed'], true)));
            $output[$label] = [
                'sample_size' => count($bucket),
                'success_rate' => count($bucket) > 0 ? round($success / count($bucket), 4) : 0.0,
            ];
        }
        return $output;
    }

    private function buildRecommendation(string $surface, string $actionType, array $metrics): ?array
    {
        if ((int) ($metrics['sample_size'] ?? 0) < $this->getMinSampleSize()) {
            return null;
        }
        $currentThreshold = (float) ($metrics['current_threshold'] ?? 0.92);
        $currentBand = $this->findCurrentBand((array) ($metrics['band_metrics'] ?? []), $currentThreshold);
        if (($currentBand['sample_size'] ?? 0) < 10 || $this->hasInsufficientMaturity($surface, $actionType)) {
            return null;
        }
        if ((float) ($metrics['reversal_rate'] ?? 0) > 0.05 || (float) ($metrics['failure_rate'] ?? 0) > 0.08) {
            return null;
        }

        $recommended = $currentThreshold;
        $reason = null;
        if (
            (float) ($metrics['high_confidence_success_rate'] ?? 0) >= 0.92
            && (float) ($metrics['rejection_rate'] ?? 0) <= 0.03
            && (float) ($metrics['reversal_rate'] ?? 0) <= 0.02
            && ((float) ($metrics['edit_rate'] ?? 0) <= 0.15 || !str_contains($actionType, 'draft'))
            && (float) ($metrics['false_block_indicator'] ?? 0) >= 0.10
        ) {
            $recommended = $currentThreshold - 0.02;
            $reason = 'Lowered threshold after sustained high precision and elevated false-block indicator.';
        } elseif (
            (float) ($metrics['high_confidence_failure_rate'] ?? 0) >= 0.05
            || ((float) ($metrics['rejection_rate'] ?? 0) + (float) ($metrics['reversal_rate'] ?? 0)) >= 0.08
        ) {
            $recommended = $currentThreshold + 0.02;
            $reason = 'Raised threshold after repeated high-confidence failures, rejections, or reversals.';
        }

        if ($reason === null) {
            return null;
        }

        $target = (array) ($metrics['threshold_target'] ?? []);
        return [
            'surface' => $surface,
            'action_type' => $actionType,
            'threshold_key' => (string) ($target['threshold_key'] ?? 'ai_action_min_confidence'),
            'scope_type' => (string) ($target['scope_type'] ?? 'global'),
            'current_threshold' => $currentThreshold,
            'recommended_threshold' => $this->boundThreshold((string) ($target['threshold_key'] ?? 'ai_action_min_confidence'), $recommended),
            'sample_size' => (int) $metrics['sample_size'],
            'observed_precision' => (float) ($metrics['precision_at_current_threshold'] ?? 0),
            'observed_edit_rate' => (float) ($metrics['edit_rate'] ?? 0),
            'observed_rejection_rate' => (float) ($metrics['rejection_rate'] ?? 0),
            'observed_reversal_rate' => (float) ($metrics['reversal_rate'] ?? 0),
            'observed_failure_rate' => (float) ($metrics['failure_rate'] ?? 0),
            'change_reason_summary' => $reason,
            'calibration_snapshot_json' => $metrics,
        ];
    }

    private function fetchOutcomes(array $filters = []): array
    {
        if (!$this->tableExists('ai_decision_outcomes')) {
            return [];
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $sql = "SELECT * FROM ai_decision_outcomes";
        $where = ['workspace_id = ?'];
        $params = [$workspaceId];
        foreach (['surface', 'decision_type', 'action_type', 'outcome_label'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = $field . ' = ?';
                $params[] = (string) $filters[$field];
            }
        }
        if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null) {
            $where[] = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(measured_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(measured_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY measured_at DESC, id DESC';
        $rows = Database::query($sql, $params);

        if (empty($filters['deal_id']) && empty($filters['invoice_id']) && empty($filters['contact_id']) && empty($filters['task_id'])) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($filters): bool {
            if (!empty($filters['task_id']) && (int) ($row['task_id'] ?? 0) !== (int) $filters['task_id']) {
                return false;
            }

            $metadata = json_decode((string) ($row['outcome_metadata_json'] ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];

            foreach (['deal_id' => 'linked_deal_id', 'invoice_id' => 'linked_invoice_id', 'contact_id' => 'linked_contact_id'] as $filterKey => $metadataKey) {
                if (!empty($filters[$filterKey]) && (int) ($metadata[$metadataKey] ?? 0) !== (int) $filters[$filterKey]) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function getRecentChanges(): array
    {
        if (!$this->tableExists('ai_threshold_tuning_log')) {
            return [];
        }
        return Database::query(
            "SELECT *
             FROM ai_threshold_tuning_log
             WHERE workspace_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 25",
            [$this->workspaceScope->requireWorkspaceId()]
        );
    }

    private function boundThreshold(string $thresholdKey, float $value): float
    {
        [$min, $max] = self::GLOBAL_BOUNDS[$thresholdKey] ?? [0.0, 1.0];
        return round(max($min, min($max, $value)), 4);
    }

    private function applyRollingCap(string $thresholdKey, float $candidate, float $current): float
    {
        if (!$this->tableExists('ai_threshold_tuning_log')) {
            return $candidate;
        }
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $rows = Database::query(
            "SELECT previous_value, applied_value
             FROM ai_threshold_tuning_log
             WHERE workspace_id = ?
               AND threshold_key = ?
               AND created_at >= ?
             ORDER BY created_at ASC",
            [$workspaceId, $thresholdKey, date('Y-m-d H:i:s', strtotime('-14 days'))]
        );
        $net = 0.0;
        foreach ($rows as $row) {
            $net += abs((float) ($row['applied_value'] ?? 0) - (float) ($row['previous_value'] ?? 0));
        }
        $remaining = max(0.0, $this->getRollingChangeCap() - $net);
        if ($remaining <= 0.0) {
            return $current;
        }
        $delta = $candidate - $current;
        $delta = max(-$remaining, min($remaining, $delta));
        return round($current + $delta, 4);
    }

    private function getDailyChangeCap(): float
    {
        return $this->preferences->getAICalibrationDailyChangeCap($this->preferenceUserId());
    }

    private function getRollingChangeCap(): float
    {
        return $this->preferences->getAICalibrationRollingChangeCap($this->preferenceUserId());
    }

    private function getMinSampleSize(): int
    {
        return $this->preferences->getAICalibrationMinSampleSize($this->preferenceUserId());
    }

    private function isAutonomousTuningEnabled(): bool
    {
        return $this->preferences->isAIAutonomousThresholdTuningEnabled($this->preferenceUserId());
    }

    private function hasCalibrationFreeze(): bool
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $preferenceUserId = $this->preferenceUserId();
        if ($this->preferences->getAIModeLock($preferenceUserId) === 'guardian') {
            return true;
        }
        if ($this->tableExists('ai_capability_state_log')) {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM ai_capability_state_log
                 WHERE workspace_id = ?
                   AND status IN ('degraded', 'missing')
                   AND created_at >= ?",
                [$workspaceId, date('Y-m-d H:i:s', strtotime('-1 day'))]
            );
            if ((int) ($row['c'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private function hasInsufficientMaturity(string $surface, string $actionType): bool
    {
        if (!$this->tableExists('ai_decision_outcomes')) {
            return true;
        }
        $row = Database::queryOne(
            "SELECT MIN(measured_at) AS first_measured
             FROM ai_decision_outcomes
             WHERE workspace_id = ?
               AND surface = ?
               AND action_type = ?",
            [$this->workspaceScope->requireWorkspaceId(), $surface, $actionType]
        );
        $first = (string) ($row['first_measured'] ?? '');
        return $first === '' || strtotime($first) > strtotime('-7 days');
    }

    private function findCurrentBand(array $bands, float $threshold): array
    {
        foreach ($bands as $label => $metrics) {
            [$min, $max] = array_map('floatval', explode('-', $label));
            if ($threshold >= $min && $threshold <= $max) {
                return $metrics;
            }
        }
        return ['sample_size' => 0];
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

    private function preferenceUserId(): int
    {
        return $this->workspaceScope->resolvePreferenceUserId();
    }
}
