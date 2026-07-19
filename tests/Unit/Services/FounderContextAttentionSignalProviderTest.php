<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FounderContextAttentionSignalProvider;
use PHPUnit\Framework\TestCase;

final class FounderContextAttentionSignalProviderTest extends TestCase
{
    public function testStaleStrategicContextBecomesAnExplicitConfirmationItem(): void
    {
        $signals = (new FounderContextAttentionSignalProvider())->provide(7, 11, [
            'snapshot_id' => 'snapshot-1',
            'generated_at' => '2026-07-18T08:00:00+00:00',
            'health' => ['status' => 'needs_confirmation'],
            'source_versions' => [
                'strategy_snapshot' => [
                    'source' => 'user_strategy_snapshots',
                    'freshness_status' => 'stale',
                    'observed_at' => '2026-01-01T00:00:00+00:00',
                ],
            ],
        ]);

        $this->assertCount(1, $signals);
        $this->assertSame('business_context_stale', $signals[0]['constraint_key']);
        $this->assertTrue($signals[0]['decision_required']);
        $this->assertSame('Review business context', $signals[0]['action_label']);
    }
}
