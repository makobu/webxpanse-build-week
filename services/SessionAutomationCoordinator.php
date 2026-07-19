<?php

namespace CRM\Services;

use CRM\Modules\AITaskAutomationService;

class SessionAutomationCoordinator
{
    private const FEATURE_TASK_AUTO_COMPLETE = 'task_auto_complete';
    private const FEATURE_AI_TASK_AUTO_SEED = 'ai_task_auto_seed';

    private const FEATURE_POLICIES = [
        self::FEATURE_TASK_AUTO_COMPLETE => [
            'cooldown_seconds' => 120,
            'priority' => 10,
        ],
        self::FEATURE_AI_TASK_AUTO_SEED => [
            'cooldown_seconds' => 300,
            'priority' => 20,
        ],
    ];

    private const MAX_EXECUTED_FEATURES = 2;

    private SessionAutomationStateService $state;
    private TaskCompletionScanQueueService $taskScanQueue;
    private AITaskAutomationService $taskAutomation;

    public function __construct(
        ?SessionAutomationStateService $state = null,
        ?TaskCompletionScanQueueService $taskScanQueue = null,
        ?AITaskAutomationService $taskAutomation = null
    ) {
        $this->state = $state ?? new SessionAutomationStateService();
        $this->taskScanQueue = $taskScanQueue ?? new TaskCompletionScanQueueService();
        $this->taskAutomation = $taskAutomation ?? new AITaskAutomationService();
    }

    public function runForUser(int $userId, array $context = []): array
    {
        $results = [];
        $executed = 0;

        foreach ($this->getFeatureOrder() as $featureKey) {
            if ($executed >= self::MAX_EXECUTED_FEATURES) {
                $results[] = $this->buildSkippedResult($featureKey, 'budget_exhausted');
                continue;
            }

            if (
                $featureKey === self::FEATURE_AI_TASK_AUTO_SEED
                && ($context['trigger'] ?? '') === 'session_postload'
                && empty($context['allow_inline_ai_task_seed'])
            ) {
                $results[] = [
                    'feature' => self::FEATURE_AI_TASK_AUTO_SEED,
                    'status' => 'skipped',
                    'ran' => false,
                    'skipped_reason' => 'deferred_to_background_worker',
                    'duration_ms' => 0,
                    'created_count' => 0,
                    'skipped_count' => 0,
                    'retired_count' => 0,
                    'changed' => false,
                    'retired' => false,
                    'mode' => '',
                    'background_worker_required' => true,
                    'user_visible_changes' => [],
                ];
                continue;
            }

            $cooldown = (int) (self::FEATURE_POLICIES[$featureKey]['cooldown_seconds'] ?? 0);
            if (!$this->state->shouldAttempt($userId, $featureKey, $cooldown)) {
                $results[] = $this->buildSkippedResult($featureKey, 'cooldown');
                continue;
            }

            $this->state->markAttempt($userId, $featureKey, ['context' => $context]);
            $startedAt = microtime(true);

            try {
                $result = match ($featureKey) {
                    self::FEATURE_TASK_AUTO_COMPLETE => $this->runTaskAutoComplete($userId),
                    self::FEATURE_AI_TASK_AUTO_SEED => $this->runAiTaskAutoSeed($userId, $context),
                    default => $this->buildSkippedResult($featureKey, 'unsupported_feature'),
                };
                $result['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

                $status = (string) ($result['status'] ?? 'ok');
                $this->state->markResult(
                    $userId,
                    $featureKey,
                    $status,
                    $result,
                    $status === 'ok'
                );
                $results[] = $result;
            } catch (\Throwable $e) {
                $result = [
                    'feature' => $featureKey,
                    'status' => 'failed',
                    'ran' => true,
                    'skipped_reason' => '',
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'user_visible_changes' => [],
                    'error' => 'Automation run failed.',
                ];
                $this->state->markResult($userId, $featureKey, 'failed', $result, false);
                $results[] = $result;
            }

            $executed++;
        }

        return [
            'user_id' => $userId,
            'context' => $context,
            'executed_features' => $executed,
            'features' => $results,
        ];
    }

    private function runTaskAutoComplete(int $userId): array
    {
        $eligibleCount = $this->taskScanQueue->countOpenAutoCompletableTasks($userId);
        if ($eligibleCount <= 0) {
            return [
                'feature' => self::FEATURE_TASK_AUTO_COMPLETE,
                'status' => 'skipped',
                'ran' => false,
                'skipped_reason' => 'no_eligible_tasks',
                'user_visible_changes' => [],
                'eligible_task_count' => 0,
                'scan' => $this->taskScanQueue->getUserScanStatus($userId),
            ];
        }

        $queueStatus = $this->taskScanQueue->enqueueForUser($userId, $userId, 'manual', false);
        $scan = $this->taskScanQueue->getUserScanStatus($userId);

        return [
            'feature' => self::FEATURE_TASK_AUTO_COMPLETE,
            'status' => 'ok',
            'ran' => true,
            'skipped_reason' => '',
            'eligible_task_count' => $eligibleCount,
            'queue_status' => (string) ($queueStatus['status'] ?? 'idle'),
            'processed_jobs' => 0,
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'matched_tasks' => (int) ($scan['matched_tasks'] ?? 0),
            'completed_tasks' => (int) ($scan['completed_tasks'] ?? 0),
            'refresh_recommended' => !empty($scan['refresh_recommended']),
            'scan' => $scan,
            'background_worker_required' => true,
            'user_visible_changes' => !empty($scan['refresh_recommended']) ? ['tasks_refresh_recommended'] : [],
        ];
    }

    private function runAiTaskAutoSeed(int $userId, array $context = []): array
    {
        if (!$this->taskAutomation->shouldAutoSeedToday($userId)) {
            return [
                'feature' => self::FEATURE_AI_TASK_AUTO_SEED,
                'status' => 'skipped',
                'ran' => false,
                'skipped_reason' => 'not_due',
                'created_count' => 0,
                'skipped_count' => 0,
                'retired_count' => 0,
                'changed' => false,
                'retired' => false,
                'mode' => '',
                'user_visible_changes' => [],
            ];
        }

        $result = $this->taskAutomation->autoSeedDailyTasks($userId);
        $createdCount = (int) ($result['created_count'] ?? 0);
        $retiredCount = (int) ($result['retired_count'] ?? 0);
        $userVisibleChanges = [];
        if ($createdCount > 0) {
            $userVisibleChanges[] = 'new_ai_tasks_created';
        }
        if ($retiredCount > 0) {
            $userVisibleChanges[] = 'stale_ai_tasks_retired';
        }

        return [
            'feature' => self::FEATURE_AI_TASK_AUTO_SEED,
            'status' => 'ok',
            'ran' => true,
            'skipped_reason' => '',
            'created_count' => $createdCount,
            'skipped_count' => (int) ($result['skipped'] ?? 0),
            'retired_count' => $retiredCount,
            'changed' => $createdCount > 0 || $retiredCount > 0,
            'retired' => $retiredCount > 0,
            'blocked_by_gate' => (int) ($result['blocked_by_gate'] ?? 0),
            'blocked_by_plan' => (int) ($result['blocked_by_plan'] ?? 0),
            'gate_redirected' => (int) ($result['gate_redirected'] ?? 0),
            'created_titles' => (array) ($result['created_titles'] ?? []),
            'mode' => (string) ($result['mode'] ?? ''),
            'user_visible_changes' => $userVisibleChanges,
        ];
    }

    private function getFeatureOrder(): array
    {
        $features = array_keys(self::FEATURE_POLICIES);
        usort($features, static function (string $left, string $right): int {
            return (self::FEATURE_POLICIES[$left]['priority'] ?? 999) <=> (self::FEATURE_POLICIES[$right]['priority'] ?? 999);
        });
        return $features;
    }

    private function buildSkippedResult(string $featureKey, string $reason): array
    {
        return [
            'feature' => $featureKey,
            'status' => 'skipped',
            'ran' => false,
            'skipped_reason' => $reason,
            'duration_ms' => 0,
            'user_visible_changes' => [],
        ];
    }
}
