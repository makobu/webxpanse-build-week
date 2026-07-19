<?php

namespace CRM\Services;

use CRM\Database;

final class FounderGrowthExperimentService
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function build(int $workspaceId, int $userId, array $snapshot, bool $allowMarketing = false): array
    {
        $experiment = $allowMarketing ? $this->activeMarketingExperiment($workspaceId, $userId) : null;
        $results = $experiment !== null ? $this->experimentResults($workspaceId, (int) $experiment['id']) : [];

        return $this->compose($snapshot, $experiment, $results);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed>|null $experiment
     * @param list<array<string,mixed>> $results
     * @return array<string,mixed>
     */
    public function compose(array $snapshot, ?array $experiment, array $results = []): array
    {
        $operating = (array) ($snapshot['operating_context'] ?? []);
        $loop = (array) ($operating['founder_operating_loop_context'] ?? []);
        $lastReview = (array) ($loop['last_review_outcome'] ?? []);

        if ($experiment !== null) {
            $metadata = $this->decodeJson($experiment['metadata_json'] ?? null);
            $metricName = trim((string) ($experiment['success_metric'] ?? 'conversion_rate')) ?: 'conversion_rate';
            $metricRows = array_values(array_filter($results, static fn(array $row): bool => (string) ($row['metric_name'] ?? '') === $metricName));
            $sampleSize = array_sum(array_map(static fn(array $row): int => max(0, (int) ($row['sample_size'] ?? 0)), $metricRows));
            $weightedTotal = 0.0;
            $weightedSamples = 0;
            foreach ($metricRows as $row) {
                $weight = max(0, (int) ($row['sample_size'] ?? 0));
                if ($weight === 0) {
                    continue;
                }
                $weightedTotal += ((float) ($row['metric_value'] ?? 0)) * $weight;
                $weightedSamples += $weight;
            }
            $current = $weightedSamples > 0 ? round($weightedTotal / $weightedSamples, 4) : null;
            $baseline = isset($metadata['baseline']) && is_numeric($metadata['baseline']) ? (float) $metadata['baseline'] : null;
            $target = isset($metadata['target']) && is_numeric($metadata['target']) ? (float) $metadata['target'] : null;
            $minimumSample = max(0, (int) ($metadata['minimum_sample'] ?? $metadata['min_sample_size'] ?? 0));
            $requestedDirection = (string) ($metadata['direction'] ?? 'increase');
            $direction = in_array($requestedDirection, ['increase', 'decrease'], true)
                ? $requestedDirection
                : 'increase';
            $evidenceState = 'awaiting_evidence';
            if ($current !== null) {
                $evidenceState = $minimumSample > 0 && $sampleSize < $minimumSample ? 'gathering' : 'ready_for_review';
            }

            return [
                'kind' => 'experiment',
                'id' => (int) ($experiment['id'] ?? 0),
                'title' => (string) ($experiment['title'] ?? 'Growth experiment'),
                'hypothesis' => (string) (($experiment['hypothesis'] ?? '') ?: 'The hypothesis has not been written yet.'),
                'status' => (string) ($experiment['status'] ?? 'draft'),
                'metric' => [
                    'key' => $metricName,
                    'label' => str_replace('_', ' ', $metricName),
                    'baseline' => $baseline,
                    'current' => $current,
                    'target' => $target,
                    'direction' => $direction,
                    'sample_size' => $sampleSize,
                    'minimum_sample' => $minimumSample,
                ],
                'evidence_state' => $evidenceState,
                'evidence_label' => $this->experimentEvidenceLabel($evidenceState, $sampleSize, $minimumSample),
                'guardrails' => array_slice(array_values(array_filter((array) ($metadata['guardrails'] ?? []), 'is_string')), 0, 3),
                'timebox' => [
                    'start' => $experiment['start_date'] ?? null,
                    'end' => $experiment['end_date'] ?? null,
                ],
                'action_label' => 'Review experiment',
                'action_url' => 'marketing_performance.php#performance-measurement',
                'last_learning' => $this->learningFromMetadata($metadata, $lastReview),
            ];
        }

        $plan = (array) ($loop['weekly_plan'] ?? []);
        $signal = (array) ($loop['first_customer_signal'] ?? []);
        $commitment = (array) ($loop['current_commitment'] ?? []);
        $focus = trim((string) ($plan['focus'] ?? ''));
        if ($focus === '' && $commitment === []) {
            return [
                'kind' => 'not_started',
                'title' => 'Choose the next measurable growth move',
                'hypothesis' => 'Once the primary constraint is confirmed, the system can frame one small test around it.',
                'status' => 'not_started',
                'metric' => [
                    'key' => '',
                    'label' => 'No metric selected',
                    'baseline' => null,
                    'current' => null,
                    'target' => null,
                    'direction' => 'increase',
                    'sample_size' => 0,
                    'minimum_sample' => 0,
                ],
                'evidence_state' => 'not_started',
                'evidence_label' => 'No growth experiment is active.',
                'guardrails' => [],
                'timebox' => ['start' => null, 'end' => null],
                'action_label' => 'Open Founder Loop',
                'action_url' => 'founder_operating_loop.php',
                'last_learning' => $this->learningFromMetadata([], $lastReview),
            ];
        }

        return [
            'kind' => 'operating_focus',
            'title' => $focus !== '' ? $focus : (string) ($commitment['title'] ?? 'Current founder commitment'),
            'hypothesis' => (string) (($plan['objective'] ?? '') ?: ($commitment['description'] ?? 'Complete the current commitment and record what changes.')),
            'status' => 'active',
            'metric' => [
                'key' => 'first_customer_movement',
                'label' => 'first-customer movement',
                'baseline' => null,
                'current' => (string) ($signal['metric'] ?? ''),
                'target' => (int) ($plan['paid_customer_target'] ?? 1),
                'direction' => 'increase',
                'sample_size' => (int) ($signal['leads_created'] ?? 0) + (int) ($signal['open_deals'] ?? 0),
                'minimum_sample' => 0,
            ],
            'evidence_state' => 'gathering',
            'evidence_label' => (string) (($signal['headline'] ?? '') ?: 'Founder Loop is gathering CRM evidence.'),
            'guardrails' => [],
            'timebox' => [
                'start' => $loop['active_week']['start'] ?? null,
                'end' => $loop['active_week']['end'] ?? null,
            ],
            'action_label' => 'Open Founder Loop',
            'action_url' => 'founder_operating_loop.php',
            'last_learning' => $this->learningFromMetadata([], $lastReview),
        ];
    }

    /** @return array<string,mixed>|null */
    private function activeMarketingExperiment(int $workspaceId, int $userId): ?array
    {
        try {
            if (!Database::tableExists('marketing_experiments')) {
                return null;
            }
            $row = Database::queryOne(
                "SELECT * FROM marketing_experiments
                 WHERE workspace_id = ?
                   AND status IN ('running','draft','paused')
                   AND (owner_user_id IS NULL OR owner_user_id = ?)
                 ORDER BY FIELD(status, 'running', 'draft', 'paused'), updated_at DESC, id DESC
                 LIMIT 1",
                [$workspaceId, $userId]
            );
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    private function experimentResults(int $workspaceId, int $experimentId): array
    {
        try {
            if ($experimentId <= 0 || !Database::tableExists('marketing_experiment_results')) {
                return [];
            }
            return Database::query(
                'SELECT metric_name, metric_value, sample_size, observed_at, metadata_json
                 FROM marketing_experiment_results
                 WHERE workspace_id = ? AND experiment_id = ?
                 ORDER BY observed_at ASC, id ASC',
                [$workspaceId, $experimentId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function experimentEvidenceLabel(string $state, int $sampleSize, int $minimumSample): string
    {
        if ($state === 'ready_for_review') {
            return $sampleSize . ' observation' . ($sampleSize === 1 ? '' : 's') . ' available for a human conclusion.';
        }
        if ($state === 'gathering') {
            return $sampleSize . ' of ' . $minimumSample . ' minimum observations collected.';
        }
        return 'Waiting for the first measured result.';
    }

    /** @return array<string,mixed> */
    private function learningFromMetadata(array $metadata, array $lastReview): array
    {
        $decision = strtolower(trim((string) ($metadata['learning_decision'] ?? $metadata['decision'] ?? '')));
        if (!in_array($decision, ['keep', 'change', 'stop'], true)) {
            $decision = '';
        }
        $lesson = trim((string) ($metadata['learning'] ?? $metadata['lesson'] ?? ''));
        if ($lesson === '') {
            $lesson = trim((string) (($lastReview['next_week_focus'] ?? '') ?: ($lastReview['wins'] ?? '') ?: ($lastReview['blockers'] ?? '')));
        }
        return [
            'decision' => $decision,
            'lesson' => $lesson,
            'has_conclusion' => $decision !== '' && $lesson !== '',
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
