<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FounderAttentionRankingService;
use PHPUnit\Framework\TestCase;

final class FounderAttentionRankingServiceTest extends TestCase
{
    public function testRankingIsGlobalDeduplicatedAndCappedAtThree(): void
    {
        $ranked = (new FounderAttentionRankingService())->rank([
            $this->signal('low', 'Low value task', 'low', 'low'),
            $this->signal('blocked', 'Customer approval blocked', 'high', 'high', [
                'decision_required' => true,
                'blocked' => true,
                'kind' => 'constraint',
            ]),
            $this->signal('duplicate-weaker', 'Duplicate weak copy', 'medium', 'low', [
                'dedupe_key' => 'same-constraint',
            ]),
            $this->signal('duplicate-stronger', 'Duplicate strong copy', 'high', 'high', [
                'dedupe_key' => 'same-constraint',
                'decision_required' => true,
            ]),
            $this->signal('fourth', 'Useful but fourth', 'medium', 'medium'),
        ], 3);

        $this->assertCount(3, $ranked);
        $this->assertSame('blocked', $ranked[0]['id']);
        $this->assertSame('duplicate-stronger', $ranked[1]['id']);
        $this->assertCount(1, array_filter($ranked, static fn(array $item): bool => $item['dedupe_key'] === 'same-constraint'));
        $this->assertArrayHasKey('score_breakdown', $ranked[0]);
    }

    public function testStaleEvidenceIsPenalizedAgainstFreshEquivalent(): void
    {
        $ranked = (new FounderAttentionRankingService())->rank([
            $this->signal('stale', 'Stale signal', 'high', 'medium', ['freshness_status' => 'stale']),
            $this->signal('fresh', 'Fresh signal', 'high', 'medium', ['freshness_status' => 'fresh']),
        ]);

        $this->assertSame('fresh', $ranked[0]['id']);
        $this->assertGreaterThan($ranked[1]['attention_score'], $ranked[0]['attention_score']);
    }

    /** @param array<string,mixed> $extra */
    private function signal(string $id, string $title, string $impact, string $urgency, array $extra = []): array
    {
        return $extra + [
            'id' => $id,
            'dedupe_key' => $id,
            'title' => $title,
            'impact' => $impact,
            'urgency' => $urgency,
            'confidence' => 0.9,
            'freshness_status' => 'fresh',
            'effort' => 'low',
            'reversibility' => 'easy',
            'observed_at' => '2026-07-18T08:00:00+00:00',
        ];
    }
}
