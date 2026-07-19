<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Targets;

class AIGoalRelevanceService
{
    public function getPrimaryGoals(int $userId): array
    {
        try {
            $targets = new Targets();
            return $targets->getAll(['user_id' => $userId, 'status' => 'active'], 5, 0);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function scoreAdviceAgainstGoals(array $advice, array $goals, array $context): float
    {
        if (empty($goals)) {
            return 0.4;
        }

        $haystack = strtolower(implode(' ', array_filter([
            (string) ($advice['title'] ?? ''),
            (string) ($advice['reason'] ?? ''),
            (string) ($advice['category'] ?? ''),
        ])));

        $score = 0.0;
        foreach ($goals as $goal) {
            $goalText = strtolower(implode(' ', array_filter([
                (string) ($goal['title'] ?? ''),
                (string) ($goal['description'] ?? ''),
                (string) ($goal['target_type'] ?? ''),
                (string) ($goal['unit'] ?? ''),
            ])));
            if ($goalText !== '' && $this->hasOverlap($haystack, $goalText)) {
                $score = max($score, 0.9);
            }
        }

        if ($score === 0.0) {
            $readinessGaps = (array) ($context['feature_state']['readiness_gaps'] ?? []);
            foreach ($readinessGaps as $gap) {
                if ($this->hasOverlap($haystack, strtolower((string) $gap))) {
                    $score = max($score, 0.72);
                }
            }
        }

        return min(1.0, max(0.0, $score > 0 ? $score : 0.2));
    }

    public function rankTasksAgainstGoals(array $tasks, array $goals): array
    {
        foreach ($tasks as &$task) {
            $task['goal_relevance_score'] = $this->scoreAdviceAgainstGoals($task, $goals, []);
        }
        unset($task);

        usort($tasks, static function (array $a, array $b): int {
            return ($b['goal_relevance_score'] <=> $a['goal_relevance_score']);
        });

        return $tasks;
    }

    private function hasOverlap(string $a, string $b): bool
    {
        $tokensA = array_unique(array_filter(preg_split('/\W+/', $a) ?: [], static fn ($token) => strlen($token) > 3));
        $tokensB = array_unique(array_filter(preg_split('/\W+/', $b) ?: [], static fn ($token) => strlen($token) > 3));
        return count(array_intersect($tokensA, $tokensB)) > 0;
    }
}
