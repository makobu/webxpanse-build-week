<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\VoiceCallStateMachineService;
use PHPUnit\Framework\TestCase;

class VoiceCallStateMachineServiceTest extends TestCase
{
    public function testHappyPathTransitionsAreAllowed(): void
    {
        $states = new VoiceCallStateMachineService();
        $this->assertTrue($states->canTransition('requested', 'queued'));
        $this->assertTrue($states->canTransition('queued', 'dialing_agent'));
        $this->assertTrue($states->canTransition('dialing_agent', 'agent_answered'));
        $this->assertTrue($states->canTransition('agent_answered', 'dialing_customer'));
        $this->assertTrue($states->canTransition('dialing_customer', 'ringing'));
        $this->assertTrue($states->canTransition('ringing', 'in_progress'));
        $this->assertTrue($states->canTransition('in_progress', 'completed'));
    }

    public function testTerminalAndBackwardTransitionsAreRejected(): void
    {
        $states = new VoiceCallStateMachineService();
        $this->assertTrue($states->isTerminal('completed'));
        $this->assertFalse($states->canTransition('completed', 'ringing'));
        $this->assertFalse($states->canTransition('in_progress', 'queued'));
        $this->assertFalse($states->canTransition('requested', 'completed'));
        $this->assertTrue($states->canTransition('dialing_agent', 'completed'));
        $this->assertTrue($states->canTransition('dialing_customer', 'completed'));
    }
}
