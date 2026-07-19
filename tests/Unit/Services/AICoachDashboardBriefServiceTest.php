<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AICoachDashboardBriefService;
use PHPUnit\Framework\TestCase;

class AICoachDashboardBriefServiceTest extends TestCase
{
    public function testReadyWorkspaceGetsDailyOperatingBrief(): void
    {
        $brief = (new AICoachDashboardBriefService())->build([
            'company_context_ready' => true,
            'clarity_journey_ready' => true,
            'inherited_context_ready' => true,
            'strategy_ready' => true,
            'idea_validation_ready' => true,
            'recommendations_ready' => true,
            'missing_requirements' => [],
            'onboarding_payload' => [
                'products' => [['name' => 'Clarity Coach']],
            ],
        ]);

        $this->assertSame('ready', $brief['state']);
        $this->assertSame('Your daily operating brief', $brief['title']);
        $this->assertSame(100, $brief['context_strength']);
        $this->assertSame(0, $brief['missing_count']);
        $this->assertNull($brief['next_action']);
    }

    public function testIncompleteWorkspaceGetsHonestNextUnlock(): void
    {
        $brief = (new AICoachDashboardBriefService())->build([
            'company_context_ready' => true,
            'clarity_journey_ready' => false,
            'inherited_context_ready' => false,
            'strategy_ready' => false,
            'idea_validation_ready' => false,
            'recommendations_ready' => false,
            'missing_requirements' => [[
                'field' => 'clarity_journey_ready',
                'action' => 'clarity_journey',
                'message' => 'Complete the customer discovery stage in Clarity Journey.',
            ]],
            'onboarding_payload' => ['products' => []],
        ]);

        $this->assertSame('building', $brief['state']);
        $this->assertSame('Build your daily operating brief', $brief['title']);
        $this->assertSame('Complete the customer discovery stage in Clarity Journey.', $brief['summary']);
        $this->assertSame(17, $brief['context_strength']);
        $this->assertSame(1, $brief['missing_count']);
        $this->assertSame('clarity_journey', $brief['next_action']['action']);
    }
}
