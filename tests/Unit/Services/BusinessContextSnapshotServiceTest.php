<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\BusinessContextSnapshotService;
use PHPUnit\Framework\TestCase;

final class BusinessContextSnapshotServiceTest extends TestCase
{
    public function testSnapshotUsesObservedSourceVersionsAndHasStableIdentity(): void
    {
        $operating = [
            'company_context' => ['name' => 'Calm Co'],
            'user_strategy_context' => [
                'has_explicit_profile' => true,
                'ideal_customer_profile' => 'Owner-led service businesses',
                'offer_angle' => 'A calmer operating rhythm',
            ],
            'startup_journey_context' => [
                'stages' => [['responses' => ['problem' => 'Work competes for attention']]],
            ],
            'pipeline_state' => ['open_deals' => 2],
            'operating_maturity' => ['stage' => 'traction', 'label' => 'Early traction'],
        ];
        $sources = [
            'strategy_snapshot' => [
                'source' => 'user_strategy_snapshots',
                'record_id' => 18,
                'observed_at' => '2026-07-17T08:00:00+00:00',
                'age_seconds' => 3600,
                'freshness_status' => 'fresh',
                'confidence' => 1.0,
            ],
        ];

        $service = new BusinessContextSnapshotService();
        $first = $service->buildFromOperatingContext(7, 11, $operating, $sources);
        $sourcesWithOlderAgeCounter = $sources;
        $sourcesWithOlderAgeCounter['strategy_snapshot']['age_seconds'] = 3617;
        $second = $service->buildFromOperatingContext(7, 11, $operating, $sourcesWithOlderAgeCounter);

        $this->assertSame($first['snapshot_id'], $second['snapshot_id']);
        $this->assertSame('ready', $first['health']['status']);
        $this->assertSame(100, $first['health']['strength']);
        $this->assertSame('2026-07-17T08:00:00+00:00', $first['source_versions']['strategy_snapshot']['observed_at']);
        $this->assertSame('Owner-led service businesses', $first['summary']['customer_focus']);

        $changedSources = $sources;
        $changedSources['strategy_snapshot']['observed_at'] = '2026-07-18T08:00:00+00:00';
        $changed = $service->buildFromOperatingContext(7, 11, $operating, $changedSources);
        $this->assertNotSame($first['snapshot_id'], $changed['snapshot_id']);
    }

    public function testCriticalStaleSourceAndConflictRequireConfirmation(): void
    {
        $snapshot = (new BusinessContextSnapshotService())->buildFromOperatingContext(7, 11, [
            'company_context' => ['name' => 'Calm Co'],
            'user_strategy_context' => ['has_explicit_profile' => true],
            'startup_journey_context' => ['readiness' => ['ready' => true]],
            'founder_operating_loop_context' => ['active_week' => ['start' => '2026-07-13']],
            'assumption_conflicts' => [[
                'type' => 'segment_mismatch',
                'severity' => 'high',
                'journey_assumption' => 'Enterprise buyers',
                'operating_evidence' => 'Current opportunities are small businesses',
            ]],
        ], [
            'strategy_snapshot' => [
                'freshness_status' => 'stale',
                'observed_at' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $this->assertSame('needs_confirmation', $snapshot['health']['status']);
        $this->assertSame(1, $snapshot['health']['conflict_count']);
        $this->assertSame(1, $snapshot['health']['stale_source_count']);
    }

    public function testJourneyAnswersAloneDoNotPretendToBeOperatingEvidence(): void
    {
        $snapshot = (new BusinessContextSnapshotService())->buildFromOperatingContext(7, 11, [
            'company_context' => ['name' => 'Calm Co'],
            'user_strategy_context' => ['has_explicit_profile' => true],
            'startup_journey_context' => ['readiness' => ['ready' => true]],
            'missing_context_flags' => ['optional_internal_table_missing'],
        ]);

        $this->assertSame('missing', $snapshot['dimensions']['operations']['status']);
        $this->assertSame(75, $snapshot['health']['strength']);
        $this->assertSame(1, $snapshot['health']['missing_count']);
        $this->assertSame('operations', $snapshot['missing_context'][0]['key']);
    }
}
