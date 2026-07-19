<?php
/**
 * Target Advice Module
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceScopeService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\AIService;
use CRM\Services\WorkspaceTargetAutomationSettingsService;

class TargetAdvice
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function generateAdvice(int $targetId): string
    {
        $target = (new TargetIntelligenceService())->getAdviceContext($targetId);
        if (!$target) {
            throw new \Exception("Target not found");
        }

        if (($target['status'] ?? 'active') !== 'active') {
            $advice = $this->getStatusBasedAdvice($target);
            $this->storeAdvice($targetId, $advice, $this->determineAdviceType((float) ($target['progress_percentage'] ?? 0), (string) ($target['status_category'] ?? 'general')));
            return $advice;
        }

        $progress = (float) ($target['progress_percentage'] ?? 0);
        $daysRemaining = (int) ($target['days_remaining'] ?? 0);
        $statusBand = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
        $advice = $this->buildAdvice($target, $progress, $daysRemaining, $statusBand);

        $this->storeAdvice(
            $targetId,
            $advice,
            $this->determineAdviceType($progress, $statusBand),
            [
                'advice_source' => 'deterministic',
                'status_band' => $statusBand,
                'forecast_score' => (float) ($target['forecast_score'] ?? 0),
                'projected_completion_date' => (string) ($target['projected_completion_date'] ?? ''),
                'blockers' => (array) ($target['blockers'] ?? []),
                'next_best_actions' => (array) ($target['next_best_actions'] ?? []),
                'rollup_source_label' => (string) ($target['rollup_source_label'] ?? ''),
            ]
        );

        return $advice;
    }

    public function generateAiAdvice(int $targetId): string
    {
        $target = (new TargetIntelligenceService())->getAdviceContext($targetId);
        if (!$target) {
            throw new \RuntimeException('Target not found');
        }
        $workspaceId = (int) ($target['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId());
        $settings = (new WorkspaceTargetAutomationSettingsService())->get($workspaceId);
        if (empty($settings['allow_ai_advice']) || $settings['mode'] === 'off') {
            throw new \RuntimeException('AI target advice is disabled for this workspace.');
        }
        $raw = (new AIService())->process('ai_target_advice', [
            'target' => [
                'title' => (string) ($target['title'] ?? ''), 'description' => (string) ($target['description'] ?? ''),
                'target_value' => (float) ($target['target_value'] ?? 0), 'current_value' => (float) ($target['current_value'] ?? 0),
                'target_date' => (string) ($target['target_date'] ?? ''), 'measurement' => (array) ($target['measurement'] ?? []),
                'blockers' => (array) ($target['blockers'] ?? []), 'next_best_actions' => (array) ($target['next_best_actions'] ?? []),
            ],
            'rules' => ['advice_only' => true, 'must_not_change_target' => true],
        ], ['surface' => 'target_advice', 'workspace_id' => $workspaceId, 'user_id' => (int) ($target['user_id'] ?? 0)]);
        $decoded = json_decode($raw, true);
        $text = is_array($decoded) ? trim((string) ($decoded['advice'] ?? '')) : trim($raw);
        if ($text === '') {
            throw new \RuntimeException('The AI advice provider returned an invalid response.');
        }
        $this->storeAdvice($targetId, $text, 'tactical', [
            'advice_source' => 'ai', 'provider_run' => is_array($decoded) ? ($decoded['run_id'] ?? null) : null,
            'confidence' => is_array($decoded) ? ($decoded['confidence'] ?? null) : null,
            'evidence_fingerprints' => array_values(array_filter(array_map(static fn(array $item): string => (string) ($item['fingerprint'] ?? ''), (array) ($target['measurement']['evidence'] ?? [])))),
        ]);
        return $text;
    }

    private function buildAdvice(array $target, float $progress, int $daysRemaining, string $statusBand): string
    {
        $title = (string) ($target['title'] ?? 'Target');
        $currentValue = number_format((float) ($target['current_value'] ?? 0), 2);
        $targetValue = number_format((float) ($target['target_value'] ?? 0), 2);
        $unit = (string) ($target['unit'] ?? '');
        $projectedDate = (string) ($target['projected_completion_date'] ?? '');
        $forecastScore = (float) ($target['forecast_score'] ?? 0);
        $paceSummary = trim((string) ($target['pace_summary'] ?? ''));
        $rollupSourceLabel = trim((string) ($target['rollup_source_label'] ?? ''));
        $blockers = array_values(array_filter((array) ($target['blockers'] ?? []), 'is_string'));
        $nextActions = array_values(array_filter((array) ($target['next_best_actions'] ?? []), 'is_string'));
        $milestoneSummary = (array) ($target['milestone_summary'] ?? []);
        $milestoneCompleted = (int) ($milestoneSummary['completed'] ?? 0);
        $milestoneTotal = (int) ($milestoneSummary['total'] ?? 0);

        $parts = [];
        $parts[] = match ($statusBand) {
            'completed' => "'{$title}' is complete. Capture what worked and decide whether to set a higher follow-on target.",
            'blocked' => "'{$title}' is blocked because there is not enough measurable movement yet.",
            'behind' => "'{$title}' is behind pace. Current progress is {$progress}% with {$daysRemaining} days remaining.",
            'at_risk' => "'{$title}' is at risk. It needs focused action now to stay achievable.",
            default => "'{$title}' is moving. Current progress is {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}%).",
        };

        if ($rollupSourceLabel !== '') {
            $parts[] = "Progress source: {$rollupSourceLabel}.";
        }
        if ($paceSummary !== '') {
            $parts[] = $paceSummary;
        }
        if ($projectedDate !== '') {
            $parts[] = "Projected completion date: " . date('M d, Y', strtotime($projectedDate)) . ". Forecast confidence: " . number_format($forecastScore * 100, 0) . "%.";
        }
        if ($milestoneTotal > 0) {
            $parts[] = "Milestones completed: {$milestoneCompleted} of {$milestoneTotal}.";
        }
        if ($blockers !== []) {
            $parts[] = "Main blockers:\n- " . implode("\n- ", $blockers);
        }
        if ($nextActions !== []) {
            $parts[] = "Recommended next actions:\n- " . implode("\n- ", array_slice($nextActions, 0, 3));
        }
        if ($progress < 100 && $daysRemaining > 0) {
            $remaining = max(0, (float) ($target['target_value'] ?? 0) - (float) ($target['current_value'] ?? 0));
            $parts[] = "Required pace from here: approximately " . number_format($remaining / max(1, $daysRemaining), 2) . "{$unit} per day.";
        }

        return implode("\n\n", $parts);
    }

    private function getStatusBasedAdvice(array $target): string
    {
        $title = (string) ($target['title'] ?? 'Target');
        return match ((string) ($target['status'] ?? 'active')) {
            'completed' => "Target '{$title}' is complete. Preserve the winning actions and set the next measurable goal.",
            'missed' => "Target '{$title}' was missed. Review the blockers and reset it with a more realistic pace or breakdown.",
            'cancelled' => "Target '{$title}' is cancelled. Reactivate it only if it still matches current priorities.",
            default => "Target '{$title}' is not currently active.",
        };
    }

    private function determineAdviceType(float $progress, string $statusCategory): string
    {
        if ($progress >= 100 || $statusCategory === 'completed') {
            return 'success';
        }
        if (in_array($statusCategory, ['at_risk', 'behind', 'blocked', 'missed'], true)) {
            return 'warning';
        }
        if ($statusCategory === 'on_track') {
            return 'motivational';
        }
        return 'general';
    }

    public function storeAdvice(int $targetId, string $adviceText, string $adviceType = 'general', array $metadata = []): int
    {
        if ($this->hasMetadataJsonColumn()) {
            Database::execute(
                "INSERT INTO target_advice (workspace_id, target_id, advice_text, advice_type, metadata_json)
                 VALUES (?, ?, ?, ?, ?)",
                [$this->workspaceScope->requireActiveWorkspaceId(), $targetId, $adviceText, $adviceType, json_encode($metadata, JSON_UNESCAPED_SLASHES)]
            );
        } else {
            Database::execute(
                "INSERT INTO target_advice (workspace_id, target_id, advice_text, advice_type)
                 VALUES (?, ?, ?, ?)",
                [$this->workspaceScope->requireActiveWorkspaceId(), $targetId, $adviceText, $adviceType]
            );
        }

        return (int) Database::lastInsertId();
    }

    public function getLatestAdvice(int $targetId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM target_advice
             WHERE workspace_id = ? AND target_id = ?
             ORDER BY generated_at DESC
             LIMIT 1",
            [$this->workspaceScope->requireActiveWorkspaceId(), $targetId]
        );
    }

    public function getAllAdvice(int $targetId, int $limit = 10): array
    {
        return Database::query(
            "SELECT * FROM target_advice
             WHERE workspace_id = ? AND target_id = ?
             ORDER BY generated_at DESC
             LIMIT ?",
            [$this->workspaceScope->requireActiveWorkspaceId(), $targetId, $limit]
        );
    }

    public function cleanupOldAdvice(int $targetId, int $keepCount = 5): void
    {
        Database::execute(
            "DELETE FROM target_advice
             WHERE workspace_id = ? AND target_id = ?
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM target_advice
                     WHERE workspace_id = ? AND target_id = ?
                     ORDER BY generated_at DESC
                     LIMIT ?
                 ) AS keep_rows
             )",
            [$this->workspaceScope->requireActiveWorkspaceId(), $targetId, $this->workspaceScope->requireActiveWorkspaceId(), $targetId, $keepCount]
        );
    }

    private function hasMetadataJsonColumn(): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'target_advice' AND COLUMN_NAME = 'metadata_json'"
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
