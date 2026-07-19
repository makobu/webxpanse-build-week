<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FounderGrowthExperimentService;
use PHPUnit\Framework\TestCase;

final class FounderGrowthExperimentServiceTest extends TestCase
{
    public function testExperimentDoesNotClaimAConclusionBeforeMinimumEvidence(): void
    {
        $growth = (new FounderGrowthExperimentService())->compose([], [
            'id' => 14,
            'title' => 'Shorter proposal follow-up',
            'hypothesis' => 'A same-day reply will improve conversion.',
            'status' => 'running',
            'success_metric' => 'conversion_rate',
            'metadata_json' => json_encode([
                'baseline' => 0.12,
                'target' => 0.18,
                'minimum_sample' => 20,
                'direction' => 'increase',
            ]),
        ], [[
            'metric_name' => 'conversion_rate',
            'metric_value' => 0.25,
            'sample_size' => 5,
        ]]);

        $this->assertSame('gathering', $growth['evidence_state']);
        $this->assertSame('5 of 20 minimum observations collected.', $growth['evidence_label']);
        $this->assertFalse($growth['last_learning']['has_conclusion']);
        $this->assertSame(0.25, $growth['metric']['current']);
    }

    public function testLearningRequiresBothDecisionAndLesson(): void
    {
        $growth = (new FounderGrowthExperimentService())->compose([], [
            'id' => 15,
            'title' => 'Offer test',
            'status' => 'paused',
            'success_metric' => 'replies',
            'metadata_json' => json_encode([
                'decision' => 'change',
                'lesson' => 'The narrow segment replied more often.',
            ]),
        ]);

        $this->assertSame('change', $growth['last_learning']['decision']);
        $this->assertTrue($growth['last_learning']['has_conclusion']);
    }

    public function testMetricRowsWithoutObservationsAreNotPresentedAsEvidence(): void
    {
        $growth = (new FounderGrowthExperimentService())->compose([], [
            'id' => 16,
            'title' => 'No-sample result',
            'status' => 'running',
            'success_metric' => 'conversion_rate',
            'metadata_json' => json_encode(['minimum_sample' => 10]),
        ], [[
            'metric_name' => 'conversion_rate',
            'metric_value' => 0.75,
            'sample_size' => 0,
        ]]);

        $this->assertNull($growth['metric']['current']);
        $this->assertSame('awaiting_evidence', $growth['evidence_state']);
        $this->assertSame('Waiting for the first measured result.', $growth['evidence_label']);
    }
}
