<?php

namespace CRM\Services;

class AICoachRecommendationRankingService
{
    private const RECOMMENDATION_SECTIONS = ['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'];

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public function rank(array $recommendations, array $feedbackBySignature = [], array $controlsBySignature = []): array
    {
        $summary = $this->emptySummary();
        if ($feedbackBySignature === [] && $controlsBySignature === []) {
            return [$recommendations, $summary];
        }

        $ranked = $recommendations;
        $suppressed = array_values((array) ($recommendations['suppressed_recommendations'] ?? []));
        foreach (self::RECOMMENDATION_SECTIONS as $section) {
            $items = [];
            foreach (array_values((array) ($ranked[$section] ?? [])) as $index => $item) {
                $signature = (string) ($item['feedback_signature'] ?? '');
                $history = (array) ($feedbackBySignature[$signature] ?? []);
                $adminControls = (array) ($controlsBySignature[$signature] ?? []);
                [$item, $score, $suppressForAdmin, $adminSummary] = $this->scoreItem($item, $history, $adminControls, $index, $summary);

                $rejected = (int) (($item['feedback_summary']['rejected'] ?? 0));
                $dismissed = (int) (($item['feedback_summary']['dismissed'] ?? 0));
                $accepted = (int) (($item['feedback_summary']['accepted'] ?? 0));
                if ($score !== 0 || ($rejected + $dismissed) > 0 || $adminControls !== []) {
                    $summary['adjusted_count']++;
                }

                if ($adminSummary !== null) {
                    $item['admin_tuning_summary'] = $adminSummary;
                }

                if ($suppressForAdmin || (($rejected + $dismissed) >= 2 && $accepted === 0)) {
                    $item['feedback_suppressed'] = true;
                    $suppressed[] = $item;
                    continue;
                }

                $items[] = $item;
            }

            usort($items, static function (array $left, array $right): int {
                $leftScore = (int) ($left['feedback_rank_score'] ?? 0);
                $rightScore = (int) ($right['feedback_rank_score'] ?? 0);
                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }
                return (int) ($left['_feedback_original_index'] ?? 0) <=> (int) ($right['_feedback_original_index'] ?? 0);
            });

            $ranked[$section] = array_map(static function (array $item): array {
                unset($item['_feedback_original_index']);
                return $item;
            }, $items);
        }
        $ranked['suppressed_recommendations'] = $suppressed;

        if ($this->hasRecommendationItems($recommendations) && !$this->hasRecommendationItems($ranked)) {
            return [$recommendations, $this->emptySummary()];
        }

        return [$ranked, $summary];
    }

    /**
     * @param array<string,mixed> $summary
     * @return array{0:array<string,mixed>,1:int,2:bool,3:?array<string,mixed>}
     */
    private function scoreItem(array $item, array $history, array $adminControls, int $index, array &$summary): array
    {
        $accepted = (int) ($history['accepted'] ?? 0);
        $rejected = (int) ($history['rejected'] ?? 0);
        $dismissed = (int) ($history['dismissed'] ?? 0);
        $score = ($accepted * 2) - $rejected - $dismissed;
        $adminSummary = [
            'boosted' => false,
            'muted' => false,
            'reset_learning' => false,
            'control_scopes' => [],
        ];
        $suppressForAdmin = false;

        foreach ($adminControls as $control) {
            $controlType = (string) ($control['control_type'] ?? '');
            $controlScope = (string) ($control['control_scope'] ?? '');
            if (!isset($summary['control_counts'][$controlType])) {
                continue;
            }
            $summary['control_counts'][$controlType]++;
            $adminSummary['control_scopes'][] = $controlScope;
            if ($controlType === 'boosted') {
                $score += 3;
                $adminSummary['boosted'] = true;
            } elseif ($controlType === 'muted') {
                $score -= 4;
                $adminSummary['muted'] = true;
                if ($controlScope === 'feedback_signature') {
                    $suppressForAdmin = true;
                }
            } elseif ($controlType === 'reset_learning') {
                $adminSummary['reset_learning'] = true;
            }
        }

        $item['feedback_summary'] = [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'dismissed' => $dismissed,
            'latest_feedback_type' => (string) ($history['latest_feedback_type'] ?? ''),
        ];
        $item['feedback_rank_score'] = $score;
        $item['_feedback_original_index'] = $index;
        $summary['accepted_count'] += $accepted;
        $summary['rejected_count'] += $rejected;
        $summary['dismissed_count'] += $dismissed;

        if ($adminSummary['boosted'] || $adminSummary['muted'] || $adminSummary['reset_learning']) {
            $adminSummary['control_scopes'] = array_values(array_unique(array_filter($adminSummary['control_scopes'])));
            return [$item, $score, $suppressForAdmin, $adminSummary];
        }

        return [$item, $score, $suppressForAdmin, null];
    }

    private function hasRecommendationItems(array $recommendations): bool
    {
        foreach (self::RECOMMENDATION_SECTIONS as $section) {
            if (!empty($recommendations[$section])) {
                return true;
            }
        }
        return false;
    }

    private function emptySummary(): array
    {
        return [
            'accepted_count' => 0,
            'rejected_count' => 0,
            'dismissed_count' => 0,
            'adjusted_count' => 0,
            'control_counts' => [
                'boosted' => 0,
                'muted' => 0,
                'reset_learning' => 0,
            ],
        ];
    }
}
