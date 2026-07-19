<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\MarketingStageContextService;
use PHPUnit\Framework\TestCase;

class MarketingStageContextServiceTest extends TestCase
{
    public function testStageContextReturnsSevenManualLaunchStages(): void
    {
        $payload = (new MarketingStageContextService())->build([
            'counts' => [
                'active_campaigns' => 1,
                'audience_segments' => 1,
                'briefs_in_progress' => 1,
                'drafts' => 1,
                'landing_pages' => 1,
                'distribution_queue' => 1,
                'conversion_events' => 1,
            ],
        ], ['score' => 90], [
            'actions' => [
                ['label' => 'First', 'href' => 'marketing_onboarding.php', 'reason' => 'Start here.'],
                ['label' => 'Second', 'href' => 'marketing_content.php', 'reason' => 'Then continue.'],
            ],
        ]);

        $this->assertCount(7, $payload['stages']);
        $this->assertSame([
            'setup',
            'audiences',
            'campaigns',
            'content',
            'landing',
            'send',
            'results',
        ], array_column($payload['stages'], 'key'));
        $this->assertSame(['Campaign Manager', 'Social Media', 'Design'], array_column($payload['advanced_tool_groups'], 'label'));
        $this->assertSame('First', $payload['summary']['next_action']);
        foreach ($payload['stages'] as $index => $stage) {
            $this->assertNotSame('', (string) ($stage['tooltip'] ?? ''), (string) ($stage['key'] ?? 'stage'));
            $this->assertNotSame('', (string) ($stage['visual_state'] ?? ''), (string) ($stage['key'] ?? 'stage'));
            $this->assertSame($index + 1, $stage['graph_position']);
        }
        $this->assertArrayHasKey('tooltips', $payload['summary']);
    }

    public function testLockedStagesIncludeReasonAndPrerequisiteAction(): void
    {
        $payload = (new MarketingStageContextService())->build(['counts' => []], ['score' => 0], ['actions' => []]);
        $lockedStages = array_values(array_filter(
            $payload['stages'],
            static fn(array $stage): bool => ($stage['status'] ?? '') === 'Locked'
        ));

        $this->assertNotEmpty($lockedStages);
        foreach ($lockedStages as $stage) {
            $this->assertNotSame('', (string) ($stage['locked_reason'] ?? ''));
            $this->assertNotSame('', (string) ($stage['primary_action_url'] ?? ''));
            $this->assertSame('Fix prerequisite', $stage['primary_action_label']);
        }
    }

    public function testReadyStagesRouteToExpectedPagesAndTodayActionsAreLimited(): void
    {
        $actions = [];
        for ($i = 1; $i <= 8; $i++) {
            $actions[] = ['label' => 'Action ' . $i, 'href' => 'marketing.php', 'reason' => 'Reason ' . $i];
        }

        $payload = (new MarketingStageContextService())->build([
            'counts' => [
                'active_campaigns' => 1,
                'audience_segments' => 1,
                'briefs_in_progress' => 1,
                'drafts' => 2,
                'scheduled' => 1,
                'landing_pages' => 1,
                'channel_exports_ready' => 1,
                'utm_links' => 1,
                'conversion_events' => 1,
            ],
        ], ['score' => 80], ['actions' => $actions]);

        $byKey = [];
        foreach ($payload['stages'] as $stage) {
            $byKey[$stage['key']] = $stage;
        }

        $this->assertSame('marketing_segments.php', $byKey['audiences']['primary_action_url']);
        $this->assertSame('marketing_briefs.php', $byKey['campaigns']['primary_action_url']);
        $this->assertSame('marketing_content.php', $byKey['content']['primary_action_url']);
        $this->assertSame('marketing_landing_pages.php', $byKey['landing']['primary_action_url']);
        $this->assertSame('marketing_distribution.php', $byKey['send']['primary_action_url']);
        $this->assertSame('marketing_performance.php', $byKey['results']['primary_action_url']);
        $this->assertLessThanOrEqual(5, count($payload['today_actions']));
    }

    public function testTodayActionsExcludeRecommendationSources(): void
    {
        $payload = (new MarketingStageContextService())->build(['counts' => []], ['score' => 80], [
            'actions' => [
                ['label' => 'Guided Rec', 'href' => 'marketing.php', 'reason' => 'AI recommendation.', 'source' => 'Guided Recommendation'],
                ['label' => 'Task Step', 'href' => 'marketing_task_hub.php', 'reason' => 'Procedural action.', 'source' => 'Task Hub'],
                ['label' => 'Primary Step', 'href' => 'marketing_segments.php', 'reason' => 'Primary action.', 'source' => 'Workflow'],
            ],
        ]);

        $this->assertSame(['Primary Step'], array_column($payload['today_actions'], 'label'));
        $this->assertSame('Primary Step', $payload['summary']['next_action']);
    }
}
