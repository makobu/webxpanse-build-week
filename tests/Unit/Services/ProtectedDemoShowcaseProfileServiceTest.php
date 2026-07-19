<?php

declare(strict_types=1);

namespace CRM\Tests\Unit\Services;

use CRM\Services\ProtectedDemoShowcaseProfileService;
use PHPUnit\Framework\TestCase;

final class ProtectedDemoShowcaseProfileServiceTest extends TestCase
{
    public function testAutomationBatteryStatusIsFullAndNonStale(): void
    {
        $status = (new ProtectedDemoShowcaseProfileService())->automationBatteryStatus(123);

        $this->assertSame(100, $status['score']);
        $this->assertSame('full', $status['bucket']);
        $this->assertSame('Full automation', $status['status_label']);
        $this->assertFalse($status['is_stale']);
        $this->assertTrue($status['protected_demo_showcase']);
        $this->assertSame([], $status['top_blockers']);
        $this->assertArrayHasKey('autoresponder', $status['layers']);
        $this->assertSame(123, $status['subject_user_id']);
    }

    public function testStoryMomentsFollowRiversideArc(): void
    {
        $moments = (new ProtectedDemoShowcaseProfileService())->storyMoments();

        $this->assertCount(8, $moments);
        $this->assertSame('dashboard_scene_started', $moments[0]['key']);
        $this->assertSame('Ready', $moments[0]['state']);
        $this->assertSame('Workspace is fully configured', $moments[0]['caption']);
        $this->assertSame('inbox.php', $moments[0]['href']);
        $this->assertSame(0, $moments[0]['time']);
        $this->assertSame('inbox_message_sequence_started', $moments[1]['key']);
        $this->assertSame('Open Inbox', $moments[1]['state']);
        $this->assertSame(0, $moments[1]['time']);
        $this->assertSame('assistant_draft_typing_started', $moments[2]['key']);
        $this->assertSame('Lead arrived', $moments[2]['state']);
        $this->assertSame('quiet_recap', $moments[7]['key']);
        $this->assertSame('Recap', $moments[7]['state']);
        $this->assertSame(270, $moments[7]['time']);
    }
}
