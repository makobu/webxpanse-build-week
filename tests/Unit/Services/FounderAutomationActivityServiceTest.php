<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FounderAutomationActivityService;
use PHPUnit\Framework\TestCase;

final class FounderAutomationActivityServiceTest extends TestCase
{
    public function testOnlySucceededExecutionAppearsAsHandled(): void
    {
        $summary = (new FounderAutomationActivityService())->summarizeEvents([
            [
                'id' => 'approval:1',
                'lane' => 'needs_you',
                'lifecycle' => 'approval_required',
                'title' => 'Customer message needs approval',
                'occurred_at' => '2026-07-18T10:00:00+00:00',
            ],
            [
                'id' => 'policy:1',
                'lane' => 'watching',
                'lifecycle' => 'observed',
                'title' => 'Policy allowed an action',
                'occurred_at' => '2026-07-18T09:00:00+00:00',
            ],
            [
                'id' => 'workflow:1',
                'lane' => 'handled',
                'lifecycle' => 'succeeded',
                'title' => 'Workflow completed',
                'occurred_at' => '2026-07-18T08:00:00+00:00',
            ],
        ]);

        $this->assertSame(1, $summary['counts']['needs_you']);
        $this->assertSame(1, $summary['counts']['handled']);
        $this->assertSame(1, $summary['counts']['watching']);
        $this->assertSame('workflow:1', $summary['lanes']['handled'][0]['id']);
        $this->assertStringContainsString('1 exception needs attention', $summary['summary']);
    }

    public function testVisibleAutomationLanesStayQuietlyCapped(): void
    {
        $events = [];
        foreach (range(1, 5) as $index) {
            $events[] = [
                'id' => 'handled:' . $index,
                'lane' => 'handled',
                'lifecycle' => 'succeeded',
                'title' => 'Completed ' . $index,
                'occurred_at' => '2026-07-18T0' . $index . ':00:00+00:00',
            ];
        }

        $summary = (new FounderAutomationActivityService())->summarizeEvents($events);

        $this->assertSame(5, $summary['counts']['handled']);
        $this->assertCount(3, $summary['lanes']['handled']);
    }
}
