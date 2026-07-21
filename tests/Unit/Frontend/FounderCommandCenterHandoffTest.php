<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class FounderCommandCenterHandoffTest extends TestCase
{
    public function testFounderConstraintIsHandedToClarityWithVisibleEvidence(): void
    {
        $dashboard = (string) file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertStringContainsString('data-founder-command-coach', $dashboard);
        $this->assertStringContainsString('Ask Clarity why', $dashboard);
        $this->assertStringContainsString('coachButton.dataset.constraintTitle', $dashboard);
        $this->assertStringContainsString('coachButton.dataset.constraintSummary', $dashboard);
        $this->assertStringContainsString('coachButton.dataset.constraintWhy', $dashboard);
        $this->assertStringContainsString('coachButton.dataset.constraintEvidence', $dashboard);
        $this->assertStringContainsString("aiCoachButton.hasAttribute('data-founder-command-coach')", $dashboard);
        $this->assertStringContainsString("window.ClarityChatBubble.ask(question, 'founder_command_center')", $dashboard);
        $this->assertStringContainsString('what still needs founder judgment', $dashboard);
        $this->assertStringContainsString('the next measurable action', $dashboard);
    }
}
