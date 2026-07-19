<?php

namespace CRM\Services;

class AICoachRelevanceScorer
{
    private AIGoalRelevanceService $goalRelevance;

    public function __construct()
    {
        $this->goalRelevance = new AIGoalRelevanceService();
    }

    public function scoreRecommendation(array $recommendation, array $context): array
    {
        $goalScore = $this->goalRelevance->scoreAdviceAgainstGoals(
            $recommendation,
            (array) ($context['goal_state']['active_goals'] ?? []),
            $context
        );

        $capabilityPenalty = 0.0;
        $reason = strtolower((string) ($recommendation['reason'] ?? ''));
        foreach ((array) ($context['missing_context_flags'] ?? []) as $flag) {
            if ($flag !== '' && strpos($reason, strtolower((string) $flag)) !== false) {
                $capabilityPenalty = max($capabilityPenalty, 0.25);
            }
        }

        $score = max(0.0, min(1.0, $goalScore - $capabilityPenalty));
        return [
            'score' => $score,
            'suppressed' => $score < (float) (($context['ai_settings']['goal_relevance_min_score'] ?? 0.70)),
        ];
    }

    public function filterIrrelevantRecommendations(array $recommendations, array $context): array
    {
        $suppressed = [];
        foreach (['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'] as $bucket) {
            $kept = [];
            foreach ((array) ($recommendations[$bucket] ?? []) as $item) {
                $scored = $this->scoreRecommendation((array) $item, $context);
                $item['goal_relevance_score'] = $scored['score'];
                if ($scored['suppressed']) {
                    $suppressed[] = $item;
                    continue;
                }
                $kept[] = $item;
            }
            $recommendations[$bucket] = $kept;
        }
        $recommendations['suppressed_recommendations'] = $suppressed;
        return $recommendations;
    }
}
