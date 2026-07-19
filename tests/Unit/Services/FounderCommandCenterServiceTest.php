<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FounderCommandCenterService;
use PHPUnit\Framework\TestCase;

final class FounderCommandCenterServiceTest extends TestCase
{
    public function testCompositionKeepsOneConstraintAndAtMostThreeFounderItems(): void
    {
        $snapshot = [
            'snapshot_id' => 'snapshot-1',
            'generated_at' => '2026-07-18T08:00:00+00:00',
            'health' => ['status' => 'ready', 'strength' => 100],
            'summary' => ['customer_focus' => 'Owner-led firms'],
            'operating_context' => [
                'founder_operating_loop_context' => [
                    'current_commitment' => [
                        'title' => 'Call five qualified leads',
                        'status' => 'pending',
                        'task_id' => 44,
                    ],
                ],
            ],
        ];
        $attention = [];
        foreach (range(1, 4) as $index) {
            $attention[] = [
                'id' => 'attention:' . $index,
                'kind' => $index === 2 ? 'constraint' : 'decision',
                'constraint_key' => 'constraint:' . $index,
                'title' => 'Attention ' . $index,
                'summary' => 'Summary ' . $index,
                'why_now' => 'Why ' . $index,
                'confidence' => 0.9,
                'action_label' => 'Open',
                'action_url' => 'tasks.php',
            ];
        }
        $activity = [
            'summary' => 'One verified action was handled quietly.',
            'counts' => ['needs_you' => 0, 'handled' => 1, 'watching' => 0],
            'lanes' => ['needs_you' => [], 'handled' => [], 'watching' => []],
        ];
        $growth = [
            'title' => 'Call five qualified leads',
            'evidence_state' => 'gathering',
            'last_learning' => ['has_conclusion' => false],
        ];

        $service = new FounderCommandCenterService();
        $commandCenter = $service->compose(7, 11, $snapshot, $attention, $activity, $growth);

        $this->assertSame('Attention 1', $commandCenter['primary_constraint']['title']);
        $this->assertSame('4 items need your judgment or founder work. 1 verified action was handled quietly.', $commandCenter['summary']);
        $this->assertCount(3, $commandCenter['needs_you']);
        $this->assertArrayNotHasKey('operating_context', $commandCenter['context']);
        $this->assertSame('task_view.php?id=44', $commandCenter['current_commitment']['action_url']);
        $this->assertTrue($commandCenter['ui']['quiet_mode']);
        $this->assertSame('verified', $commandCenter['rhythm']['automate']);

        $mobile = $service->compactForMobile($commandCenter);
        $this->assertArrayNotHasKey('dimensions', $mobile['context']);
        $this->assertSame('snapshot-1', $mobile['context']['snapshot_id']);
        $this->assertCount(3, $mobile['needs_you']);
    }
}
