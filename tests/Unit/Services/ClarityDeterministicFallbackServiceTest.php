<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\ClarityDeterministicFallbackService;
use CRM\Services\ClarityResponsePresentationService;
use PHPUnit\Framework\TestCase;

class ClarityDeterministicFallbackServiceTest extends TestCase
{
    public function testBuildsEvidenceBackedFounderConstraintExplanation(): void
    {
        $answer = (new ClarityDeterministicFallbackService())->build(
            'Why is "Convert active pipeline into paid proof" the highest-priority constraint right now? '
                . 'Current signal: Active deals need follow-up, demo, pricing, or close actions. '
                . 'Why now: 3 active pipeline signals. Evidence: 0 paid / 0 won / 3 open deals. '
                . 'Explain the evidence, what still needs founder judgment, and the next measurable action.',
            ['intent' => 'explanation']
        );

        $this->assertStringContainsString('Convert active pipeline into paid proof', $answer);
        $this->assertStringContainsString('0 paid / 0 won / 3 open deals', $answer);
        $this->assertStringContainsString('3 active pipeline signals', $answer);
        $this->assertStringContainsString('Founder judgment and next measurable action', $answer);
        $this->assertStringNotContainsString("couldn't generate", $answer);

        $presented = (new ClarityResponsePresentationService())->present(
            $answer,
            ['level' => 'Level 2'],
            ['intent' => 'explanation']
        );
        $this->assertStringContainsString('0 paid / 0 won / 3 open deals', $presented['answer']);
        $this->assertStringContainsString('Founder judgment and next measurable action', $presented['answer']);
        $this->assertStringContainsString('booked demo, proposal decision, or payment', $presented['answer']);
    }

    public function testDoesNotInventFallbackForUnstructuredGeneralQuestion(): void
    {
        $answer = (new ClarityDeterministicFallbackService())->build(
            'Where are my tasks?',
            ['intent' => 'general']
        );

        $this->assertSame('', $answer);
    }
}
