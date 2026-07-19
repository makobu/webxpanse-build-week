<?php

namespace CRM\Services;

use CRM\Database;

class AIPromptQualityService
{
    private AIPromptRegistryService $registry;
    private AIAutomationDiagnosticsService $diagnostics;
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->registry = new AIPromptRegistryService();
        $this->diagnostics = new AIAutomationDiagnosticsService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function getActivePromptSummary(): array
    {
        $prompts = $this->registry->getAllActivePrompts();
        $summary = [];
        foreach ($prompts as $prompt) {
            $summary[] = array_merge($prompt, $this->getPromptMetrics((string) $prompt['surface'], (string) $prompt['prompt_key'], (int) $prompt['version']));
        }
        return $summary;
    }

    public function getPromptMetrics(string $surface, string $promptKey, int $promptVersion = 0, array $filters = []): array
    {
        $matched = $this->getPromptEvents($surface, $promptKey, $promptVersion, $filters);

        $qualityScores = array_values(array_filter(array_map(function (array $event): ?float {
            $payload = (array) ($event['payload'] ?? []);
            $result = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : [];
            $draft = isset($result['draft']) && is_array($result['draft']) ? $result['draft'] : [];
            $quality = $payload['context_bundle_quality'] ?? ($draft['context_bundle_quality'] ?? null);
            if (!is_array($quality) || !isset($quality['context_quality_score'])) {
                return null;
            }
            return (float) $quality['context_quality_score'];
        }, $matched), static fn($value): bool => $value !== null));

        $avgQuality = $qualityScores ? array_sum($qualityScores) / count($qualityScores) : 0.0;
        $staleWarnings = 0;
        $overloadWarnings = 0;
        $blockedCount = 0;
        $suggestOnlyCount = 0;
        foreach ($matched as $event) {
            $decision = (string) ($event['decision'] ?? '');
            if ($decision === 'blocked') {
                $blockedCount++;
            }
            if ($decision === 'suggest_only') {
                $suggestOnlyCount++;
            }
            foreach ((array) ($event['reason_codes'] ?? []) as $reasonCode) {
                if ($reasonCode === 'stale_context_present') {
                    $staleWarnings++;
                }
                if (in_array($reasonCode, ['prompt_overload', 'bundle_trimmed'], true)) {
                    $overloadWarnings++;
                }
            }
        }

        $outcomeMetrics = $this->getOutcomeMetrics($matched);
        $runCount = count($matched);

        return [
            'recent_quality_score' => round($avgQuality, 4),
            'stale_context_rate' => $runCount > 0 ? round($staleWarnings / $runCount, 4) : 0.0,
            'overload_rate' => $runCount > 0 ? round($overloadWarnings / $runCount, 4) : 0.0,
            'recent_run_count' => $runCount,
            'blocked_rate' => $runCount > 0 ? round($blockedCount / $runCount, 4) : 0.0,
            'suggest_only_rate' => $runCount > 0 ? round($suggestOnlyCount / $runCount, 4) : 0.0,
            'outcome_sample_size' => (int) $outcomeMetrics['sample_size'],
            'accepted_rate' => (float) $outcomeMetrics['accepted_rate'],
            'edit_rate' => (float) $outcomeMetrics['edit_rate'],
            'rejected_rate' => (float) $outcomeMetrics['rejected_rate'],
            'ignored_rate' => (float) $outcomeMetrics['ignored_rate'],
            'mean_outcome_score' => (float) $outcomeMetrics['mean_outcome_score'],
            'fallback_rate' => (float) $this->getFallbackRate($matched),
        ];
    }

    public function getPromptVersionComparison(string $surface, string $promptKey, int $primaryVersion, int $compareVersion, array $filters = []): array
    {
        $primary = $this->getPromptMetrics($surface, $promptKey, $primaryVersion, $filters);
        $compare = $this->getPromptMetrics($surface, $promptKey, $compareVersion, $filters);
        $deltas = [
            'quality_delta' => round(((float) $primary['recent_quality_score']) - ((float) $compare['recent_quality_score']), 4),
            'blocked_rate_delta' => round(((float) $primary['blocked_rate']) - ((float) $compare['blocked_rate']), 4),
            'suggest_only_rate_delta' => round(((float) $primary['suggest_only_rate']) - ((float) $compare['suggest_only_rate']), 4),
            'accepted_rate_delta' => round(((float) $primary['accepted_rate']) - ((float) $compare['accepted_rate']), 4),
            'rejected_rate_delta' => round(((float) $primary['rejected_rate']) - ((float) $compare['rejected_rate']), 4),
            'edit_rate_delta' => round(((float) $primary['edit_rate']) - ((float) $compare['edit_rate']), 4),
            'fallback_rate_delta' => round(((float) $primary['fallback_rate']) - ((float) $compare['fallback_rate']), 4),
        ];

        $regressionSignals = [];
        if ($deltas['quality_delta'] < -0.05) {
            $regressionSignals[] = 'lower_quality_score';
        }
        if ($deltas['blocked_rate_delta'] > 0.05) {
            $regressionSignals[] = 'higher_blocked_rate';
        }
        if ($deltas['rejected_rate_delta'] > 0.05) {
            $regressionSignals[] = 'higher_rejection_rate';
        }
        if ($deltas['edit_rate_delta'] > 0.08) {
            $regressionSignals[] = 'higher_edit_rate';
        }
        if ($deltas['fallback_rate_delta'] > 0.05) {
            $regressionSignals[] = 'higher_fallback_rate';
        }

        return [
            'surface' => $surface,
            'prompt_key' => $promptKey,
            'primary_version' => $primaryVersion,
            'compare_version' => $compareVersion,
            'primary' => $primary,
            'compare' => $compare,
            'deltas' => $deltas,
            'regression_risk' => $regressionSignals === [] ? 'low' : (count($regressionSignals) >= 2 ? 'high' : 'medium'),
            'regression_signals' => $regressionSignals,
        ];
    }

    public function getRecentPromptEvents(): array
    {
        if (!$this->tableExists('ai_prompt_registry')) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        $rows = Database::query(
            "SELECT * FROM ai_prompt_registry
             WHERE workspace_id = ? OR workspace_id IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT 50",
            [$workspaceId]
        );

        return array_map(function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'surface' => (string) ($row['surface'] ?? ''),
                'prompt_key' => (string) ($row['prompt_key'] ?? ''),
                'version' => (int) ($row['version'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    public function getPromptDiagnostics(array $filters = []): array
    {
        $summary = [];
        foreach ($this->getActivePromptSummary() as $prompt) {
            $summary[(string) $prompt['surface']][(string) $prompt['prompt_key']] = [
                'version' => (int) $prompt['version'],
                'recent_quality_score' => (float) ($prompt['recent_quality_score'] ?? 0),
                'stale_context_rate' => (float) ($prompt['stale_context_rate'] ?? 0),
                'overload_rate' => (float) ($prompt['overload_rate'] ?? 0),
                'recent_run_count' => (int) ($prompt['recent_run_count'] ?? 0),
                'blocked_rate' => (float) ($prompt['blocked_rate'] ?? 0),
                'suggest_only_rate' => (float) ($prompt['suggest_only_rate'] ?? 0),
                'accepted_rate' => (float) ($prompt['accepted_rate'] ?? 0),
                'edit_rate' => (float) ($prompt['edit_rate'] ?? 0),
                'rejected_rate' => (float) ($prompt['rejected_rate'] ?? 0),
                'fallback_rate' => (float) ($prompt['fallback_rate'] ?? 0),
                'outcome_sample_size' => (int) ($prompt['outcome_sample_size'] ?? 0),
            ];
        }

        $comparison = null;
        if (!empty($filters['surface']) && !empty($filters['prompt_key']) && !empty($filters['primary_version']) && !empty($filters['compare_version'])) {
            $comparison = $this->getPromptVersionComparison(
                (string) $filters['surface'],
                (string) $filters['prompt_key'],
                (int) $filters['primary_version'],
                (int) $filters['compare_version'],
                $filters
            );
        }

        return [
            'summary' => $summary,
            'recent_changes' => $this->getRecentPromptEvents(),
            'comparison' => $comparison,
        ];
    }

    private function getPromptEvents(string $surface, string $promptKey, int $promptVersion, array $filters = []): array
    {
        $source = $this->resolveDiagnosticsSource($surface);
        $events = $this->diagnostics->getRecentEvents([
            'source' => $source,
            'date_from' => (string) ($filters['date_from'] ?? date('Y-m-d', strtotime('-14 days'))),
            'date_to' => (string) ($filters['date_to'] ?? date('Y-m-d')),
            'limit' => (int) ($filters['limit'] ?? 300),
        ]);

        return array_values(array_filter($events, static function (array $event) use ($promptKey, $promptVersion): bool {
            $payload = (array) ($event['payload'] ?? []);
            $eventPromptKey = (string) ($payload['prompt_key'] ?? '');
            $eventPromptVersion = (int) ($payload['prompt_version'] ?? 0);
            if ($eventPromptKey === '') {
                $result = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : [];
                $draft = isset($result['draft']) && is_array($result['draft']) ? $result['draft'] : [];
                $eventPromptKey = (string) ($draft['prompt_key'] ?? '');
                $eventPromptVersion = (int) ($draft['prompt_version'] ?? 0);
            }
            if ($eventPromptKey === '') {
                $output = isset($payload['output']) && is_array($payload['output']) ? $payload['output'] : [];
                $eventPromptKey = (string) ($output['prompt_key'] ?? '');
                $eventPromptVersion = (int) ($output['prompt_version'] ?? 0);
            }

            return $eventPromptKey === $promptKey && ($promptVersion === 0 || $eventPromptVersion === $promptVersion);
        }));
    }

    private function getOutcomeMetrics(array $events): array
    {
        if (!$this->tableExists('ai_decision_outcomes')) {
            return [
                'sample_size' => 0,
                'accepted_rate' => 0.0,
                'edit_rate' => 0.0,
                'rejected_rate' => 0.0,
                'ignored_rate' => 0.0,
                'mean_outcome_score' => 0.0,
            ];
        }

        $assistantRunIds = [];
        $guidanceRunIds = [];
        foreach ($events as $event) {
            $payload = (array) ($event['payload'] ?? []);
            if (isset($payload['run']['id'])) {
                $assistantRunIds[] = (int) $payload['run']['id'];
            }
            if (isset($payload['guidance']['id'])) {
                $guidanceRunIds[] = (int) $payload['guidance']['id'];
            }
        }

        $outcomes = [];
        if ($assistantRunIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($assistantRunIds), '?'));
            $params = array_merge([$this->workspaceScope->requireWorkspaceId()], $assistantRunIds);
            $outcomes = array_merge($outcomes, Database::query(
                "SELECT outcome_label, outcome_score
                 FROM ai_decision_outcomes
                 WHERE workspace_id = ?
                   AND assistant_run_id IN ({$placeholders})",
                $params
            ));
        }
        if ($guidanceRunIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($guidanceRunIds), '?'));
            $params = array_merge([$this->workspaceScope->requireWorkspaceId()], $guidanceRunIds);
            $outcomes = array_merge($outcomes, Database::query(
                "SELECT outcome_label, outcome_score
                 FROM ai_decision_outcomes
                 WHERE workspace_id = ?
                   AND guidance_run_id IN ({$placeholders})",
                $params
            ));
        }

        $sampleSize = count($outcomes);
        if ($sampleSize === 0) {
            return [
                'sample_size' => 0,
                'accepted_rate' => 0.0,
                'edit_rate' => 0.0,
                'rejected_rate' => 0.0,
                'ignored_rate' => 0.0,
                'mean_outcome_score' => 0.0,
            ];
        }

        $counts = [
            'accepted' => 0,
            'edited' => 0,
            'rejected' => 0,
            'ignored' => 0,
        ];
        $scoreTotal = 0.0;
        foreach ($outcomes as $outcome) {
            $label = (string) ($outcome['outcome_label'] ?? '');
            if (isset($counts[$label])) {
                $counts[$label]++;
            }
            $scoreTotal += (float) ($outcome['outcome_score'] ?? 0);
        }

        return [
            'sample_size' => $sampleSize,
            'accepted_rate' => round($counts['accepted'] / $sampleSize, 4),
            'edit_rate' => round($counts['edited'] / $sampleSize, 4),
            'rejected_rate' => round($counts['rejected'] / $sampleSize, 4),
            'ignored_rate' => round($counts['ignored'] / $sampleSize, 4),
            'mean_outcome_score' => round($scoreTotal / $sampleSize, 4),
        ];
    }

    private function getFallbackRate(array $events): float
    {
        if ($events === []) {
            return 0.0;
        }

        $fallbackCount = 0;
        foreach ($events as $event) {
            $payload = (array) ($event['payload'] ?? []);
            $result = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : [];
            if (!empty($result['fallback'])) {
                $fallbackCount++;
            }
        }

        return round($fallbackCount / count($events), 4);
    }

    private function resolveDiagnosticsSource(string $surface): string
    {
        return in_array($surface, ['coach', 'clarity_chat'], true) ? $surface : 'assistant';
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
