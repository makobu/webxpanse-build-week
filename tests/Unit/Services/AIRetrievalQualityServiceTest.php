<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIRetrievalQualityService;
use PHPUnit\Framework\TestCase;

class AIRetrievalQualityServiceTest extends TestCase
{
    public function testFlagsStaleAndOverloadedBundles(): void
    {
        $service = new AIRetrievalQualityService();

        $bundle = [
            'blocks' => [
                [
                    'label' => 'Old context',
                    'relevance_score' => 0.60,
                    'block_priority' => 'preferred',
                    'freshness_seconds' => 90000,
                    'content' => str_repeat('old', 2000),
                ],
                [
                    'label' => 'New context',
                    'relevance_score' => 0.75,
                    'block_priority' => 'required',
                    'freshness_seconds' => 10,
                    'content' => str_repeat('new', 2500),
                ],
            ],
            'bundle_quality' => [
                'trimmed_block_count' => 2,
                'priority_mix' => ['required' => 1, 'preferred' => 1],
            ],
        ];

        $score = $service->scoreBundle($bundle);
        $this->assertContains('stale_context_present', $score['warnings']);
        $this->assertContains('bundle_trimmed', $score['warnings']);
        $this->assertArrayHasKey('overload_risk', $score);
        $this->assertArrayHasKey('priority_mix', $score);
        $this->assertGreaterThan(0, $score['stale_block_count']);
    }

    public function testDetectsLowSignalBundle(): void
    {
        $service = new AIRetrievalQualityService();
        $bundle = [
            'blocks' => [
                [
                    'label' => 'Weak context',
                    'relevance_score' => 0.20,
                    'block_priority' => 'discardable',
                    'freshness_seconds' => 30,
                    'content' => ['x' => 'y'],
                ],
            ],
        ];

        $lowSignal = $service->detectLowSignalBundle($bundle);
        $this->assertTrue($lowSignal['low_signal']);
        $this->assertContains('low_signal_context', $lowSignal['warnings']);
        $this->assertContains('low_signal_context_bundle', $lowSignal['warnings']);
    }
}
