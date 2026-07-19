<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIControlDecisionBridge;
use CRM\Services\AIRuntimeControlService;
use CRM\Tests\DatabaseTestCase;

class AIControlDecisionBridgeTest extends DatabaseTestCase
{
    public function testPausedControlBlocksActions(): void
    {
        $controls = new AIRuntimeControlService();
        $controls->setControl('assistant', 'paused', 1, 'Incident response');

        $bridge = new AIControlDecisionBridge($controls);
        $decision = $bridge->applyControlToActionDecision('assistant', [
            'decision' => 'allow',
            'reasons' => [],
            'warnings' => [],
        ]);

        $this->assertSame('blocked', $decision['decision']);
        $this->assertContains('runtime_control_paused', $decision['reasons']);
        $this->assertFalse($decision['can_execute']);
    }

    public function testDiagnosticsOnlyDowngradesAdviceToSuggestOnly(): void
    {
        $controls = new AIRuntimeControlService();
        $controls->setControl('coach', 'diagnostics_only', 1, 'Read-only mode');

        $bridge = new AIControlDecisionBridge($controls);
        $decision = $bridge->applyControlToAdviceDecision('coach', [
            'decision' => 'allow',
            'reasons' => [],
            'warnings' => [],
        ]);

        $this->assertSame('suggest_only', $decision['decision']);
        $this->assertContains('runtime_control_diagnostics_only', $decision['reasons']);
    }
}
