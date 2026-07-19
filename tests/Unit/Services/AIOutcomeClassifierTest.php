<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIOutcomeClassifier;
use CRM\Tests\TestCase;

class AIOutcomeClassifierTest extends TestCase
{
    public function testAssistantDraftAcceptedWhenSentBodyIsNearlyUnchanged(): void
    {
        $classifier = new AIOutcomeClassifier();

        $result = $classifier->classifyAssistantDraftOutcome(
            ['id' => 12],
            [
                'draft' => ['plain_body' => 'Hello Jane, thanks for your message. We can proceed tomorrow.'],
                'sent_body' => 'Hello Jane, thanks for your message. We can proceed tomorrow.',
            ]
        );

        $this->assertSame('accepted', $result['outcome_label']);
        $this->assertSame(1.0, $result['outcome_score']);
    }

    public function testAssistantDraftEditedWhenSentBodyDiffersMaterially(): void
    {
        $classifier = new AIOutcomeClassifier();

        $result = $classifier->classifyAssistantDraftOutcome(
            ['id' => 13],
            [
                'draft' => ['plain_body' => 'Thanks for reaching out. Here is the quote and next step.'],
                'sent_body' => 'Thanks for reaching out. I changed the pricing and removed the setup fee.',
            ]
        );

        $this->assertSame('edited', $result['outcome_label']);
        $this->assertSame(0.55, $result['outcome_score']);
    }

    public function testApprovalRejectedAndFailedOutcomesAreClassifiedCorrectly(): void
    {
        $classifier = new AIOutcomeClassifier();

        $rejected = $classifier->classifyApprovalOutcome(['id' => 2, 'status' => 'rejected']);
        $failed = $classifier->classifyApprovalOutcome(
            ['id' => 3, 'status' => 'approved'],
            ['execution' => ['status' => 'failed']]
        );

        $this->assertSame('rejected', $rejected['outcome_label']);
        $this->assertSame('failed', $failed['outcome_label']);
    }

    public function testTaskCompletedAndReopenedStatesAreClassifiedCorrectly(): void
    {
        $classifier = new AIOutcomeClassifier();

        $completed = $classifier->classifyTaskOutcome([
            'id' => 21,
            'status' => 'completed',
            'metadata_json' => json_encode(['completion_source' => 'evidence']),
        ]);
        $reopened = $classifier->classifyTaskOutcome([
            'id' => 21,
            'status' => 'open',
            'metadata_json' => json_encode(['completed_by_evidence' => true]),
        ]);

        $this->assertSame('completed', $completed['outcome_label']);
        $this->assertSame('reversed', $reopened['outcome_label']);
    }
}
