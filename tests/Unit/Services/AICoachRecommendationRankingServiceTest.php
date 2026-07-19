<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AICoachRecommendationRankingService;
use CRM\Tests\TestCase;

class AICoachRecommendationRankingServiceTest extends TestCase
{
    public function testUsefulAndActedOnFeedbackBoostsRecommendationOrder(): void
    {
        $service = new AICoachRecommendationRankingService();
        [$ranked, $summary] = $service->rank($this->recommendations(), [
            'coach_sig_low' => ['rejected' => 1],
            'coach_sig_high' => ['accepted' => 2, 'latest_feedback_type' => 'acted_on'],
        ]);

        $this->assertSame('High value', $ranked['priorities'][0]['title']);
        $this->assertSame(2, $summary['accepted_count']);
        $this->assertSame(1, $summary['rejected_count']);
        $this->assertSame('acted_on', $ranked['priorities'][0]['feedback_summary']['latest_feedback_type']);
    }

    public function testNegativeFeedbackDownranksAndRepeatedNegativeSuppressesExactMatches(): void
    {
        $service = new AICoachRecommendationRankingService();
        [$ranked] = $service->rank($this->recommendations(), [
            'coach_sig_high' => ['rejected' => 1],
            'coach_sig_low' => ['rejected' => 1, 'dismissed' => 1],
        ]);

        $this->assertSame('High value', $ranked['priorities'][0]['title']);
        $this->assertCount(1, $ranked['priorities']);
        $this->assertSame('Low value', $ranked['suppressed_recommendations'][0]['title']);
        $this->assertTrue($ranked['suppressed_recommendations'][0]['feedback_suppressed']);
    }

    public function testAdminBoostCanLiftMildlyNegativeRecommendation(): void
    {
        $service = new AICoachRecommendationRankingService();
        [$ranked, $summary] = $service->rank($this->recommendations(), [
            'coach_sig_low' => ['rejected' => 1],
        ], [
            'coach_sig_low' => [$this->control('boosted', 'feedback_signature')],
        ]);

        $this->assertSame('Low value', $ranked['priorities'][0]['title']);
        $this->assertTrue($ranked['priorities'][0]['admin_tuning_summary']['boosted']);
        $this->assertSame(1, $summary['control_counts']['boosted']);
    }

    public function testAdminBoostDoesNotOverrideHardSuppressionFromRepeatedNegativeFeedback(): void
    {
        $service = new AICoachRecommendationRankingService();
        [$ranked] = $service->rank($this->recommendations(), [
            'coach_sig_low' => ['rejected' => 2],
        ], [
            'coach_sig_low' => [$this->control('boosted', 'feedback_signature')],
        ]);

        $this->assertCount(1, $ranked['priorities']);
        $this->assertSame('Low value', $ranked['suppressed_recommendations'][0]['title']);
    }

    public function testAdminMuteSuppressesExactSignatureAndSourceMuteOnlyDownranks(): void
    {
        $service = new AICoachRecommendationRankingService();
        [$signatureMuted] = $service->rank($this->recommendations(), [], [
            'coach_sig_low' => [$this->control('muted', 'feedback_signature')],
        ]);
        [$sourceMuted] = $service->rank($this->recommendations(), [], [
            'coach_sig_low' => [$this->control('muted', 'source_type')],
        ]);

        $this->assertCount(1, $signatureMuted['priorities']);
        $this->assertSame('Low value', $signatureMuted['suppressed_recommendations'][0]['title']);
        $this->assertCount(2, $sourceMuted['priorities']);
        $this->assertSame('Low value', $sourceMuted['priorities'][1]['title']);
        $this->assertTrue($sourceMuted['priorities'][1]['admin_tuning_summary']['muted']);
    }

    public function testFallbackRecommendationsRemainVisibleWhenAllItemsAreSuppressed(): void
    {
        $service = new AICoachRecommendationRankingService();
        [$ranked, $summary] = $service->rank($this->recommendations(), [
            'coach_sig_high' => ['dismissed' => 2],
            'coach_sig_low' => ['rejected' => 2],
        ]);

        $this->assertCount(2, $ranked['priorities']);
        $this->assertSame(0, $summary['adjusted_count']);
    }

    private function recommendations(): array
    {
        return [
            'foundation_gaps' => [],
            'priorities' => [
                [
                    'title' => 'Low value',
                    'feedback_signature' => 'coach_sig_low',
                    'source_recommendation_type' => 'crm_activity',
                ],
                [
                    'title' => 'High value',
                    'feedback_signature' => 'coach_sig_high',
                    'source_recommendation_type' => 'crm_activity',
                ],
            ],
            'quick_wins' => [],
            'missing_features' => [],
            'suppressed_recommendations' => [],
        ];
    }

    private function control(string $type, string $scope): array
    {
        return [
            'control_type' => $type,
            'control_scope' => $scope,
            'control_value' => 'coach_sig_low',
        ];
    }
}
