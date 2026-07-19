<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceOnboardingContextGapService;
use PHPUnit\Framework\TestCase;

class WorkspaceOnboardingContextGapServiceTest extends TestCase
{
    public function testStrongContextReturnsNoRequiredQuestions(): void
    {
        $service = new WorkspaceOnboardingContextGapService();

        $review = $service->review(
            ['company_name' => 'Acme', 'company_description' => 'We help teams follow up faster.', 'owner_company_context' => 'Customers should feel clear and supported.'],
            [['name' => 'CRM setup', 'target_audience' => 'Service founders', 'pricing_info' => 'From 500']],
            [
                'ideal_customer_profile' => 'Service founders with active inbound leads.',
                'offer_angle' => 'Fast setup with practical automation.',
                'draft_tone_preset' => 'consultative',
                'positioning_notes' => 'Practical and outcome-led.',
            ],
            ['bank_instructions' => 'Pay by bank transfer.'],
            ['tone_json' => json_encode(['relationship_style' => 'trusted_advisor', 'words_to_avoid' => 'Guaranteed revenue'])],
            ['channel_ready' => true, 'autopilot_ready' => true, 'connected_whatsapp' => true]
        );

        $this->assertFalse((bool) $review['requires_answers']);
        $this->assertSame([], $review['questions']);
    }

    public function testMissingContextReturnsPrioritizedQuestionsAndCapsAtThree(): void
    {
        $service = new WorkspaceOnboardingContextGapService();

        $review = $service->review(
            ['company_name' => 'Acme', 'company_description' => 'CRM help', 'owner_company_context' => ''],
            [['name' => 'Setup', 'target_audience' => '', 'pricing_info' => '']],
            ['draft_tone_preset' => 'warm'],
            [],
            ['tone_json' => json_encode(['relationship_style' => 'trusted_advisor'])],
            ['channel_ready' => true, 'autopilot_ready' => true]
        );

        $keys = array_map(static fn(array $question): string => (string) $question['key'], $review['questions']);

        $this->assertTrue((bool) $review['requires_answers']);
        $this->assertLessThanOrEqual(3, count($review['questions']));
        $this->assertContains('ideal_customer', $keys);
        $this->assertContains('why_choose_you', $keys);
        $this->assertContains('customer_outcome', $keys);
    }
}
