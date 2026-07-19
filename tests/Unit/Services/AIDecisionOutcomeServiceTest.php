<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIDecisionOutcomeService;
use CRM\Tests\DatabaseTestCase;

class AIDecisionOutcomeServiceTest extends DatabaseTestCase
{
    public function testRecordAssistantOutcomeStoresLinkedRowAndAvoidsDuplicates(): void
    {
        $service = new AIDecisionOutcomeService();

        $run = [
            'id' => 55,
            'user_id' => 1,
            'intent' => 'draft_customer_reply',
            'result_json' => json_encode([
                'draft' => ['plain_body' => 'Hello there'],
                'policy' => [
                    'decision' => 'allow',
                    'confidence_score' => 0.94,
                    'context_quality_score' => 0.83,
                    'goal_relevance_score' => 0.79,
                    'threshold' => 0.92,
                    'mode' => '2',
                ],
            ]),
        ];

        $classification = [
            'outcome_label' => 'accepted',
            'outcome_score' => 1.0,
            'metadata' => ['sent' => true],
            'measured_at' => '2026-03-06 12:00:00',
        ];

        $firstId = $service->recordAssistantOutcome($run, $classification);
        $secondId = $service->recordAssistantOutcome($run, $classification);

        $this->assertGreaterThan(0, $firstId);
        $this->assertSame($firstId, $secondId);

        $row = Database::queryOne("SELECT * FROM ai_decision_outcomes WHERE id = ?", [$firstId]);
        $this->assertSame('assistant', $row['surface']);
        $this->assertSame('draft', $row['decision_type']);
        $this->assertSame('draft_customer_reply', $row['action_type']);
        $this->assertSame('accepted', $row['outcome_label']);
        $this->assertSame('allow', $row['policy_decision']);
    }

    public function testRecordApprovalOutcomeStoresThresholdSnapshot(): void
    {
        $service = new AIDecisionOutcomeService();

        $approval = [
            'id' => 9,
            'status' => 'approved',
            'action_key' => 'send_document',
            'requested_by_id' => 1,
            'reason' => 'Recipient required',
            'payload' => [
                'context' => [
                    'assistant_confidence' => 0.91,
                    'context_quality_score' => 0.82,
                    'goal_relevance_score' => 0.74,
                ],
            ],
        ];

        $id = $service->recordApprovalOutcome($approval, [
            'outcome_label' => 'approved',
            'outcome_score' => 1.0,
            'metadata' => ['status' => 'sent'],
            'measured_at' => '2026-03-06 13:00:00',
        ]);

        $row = Database::queryOne("SELECT * FROM ai_decision_outcomes WHERE id = ?", [$id]);
        $snapshot = json_decode($row['threshold_snapshot_json'], true);

        $this->assertSame('commercial', $row['surface']);
        $this->assertSame('approval', $row['decision_type']);
        $this->assertSame('send_document', $row['action_type']);
        $this->assertSame('Recipient required', $snapshot['reason']);
    }

    public function testRecordTaskOutcomeStoresSourceRunLinkageFromMetadata(): void
    {
        $service = new AIDecisionOutcomeService();

        $task = [
            'id' => 17,
            'assigned_to' => 3,
            'created_by' => 1,
            'metadata_json' => json_encode([
                'source_surface' => 'assistant',
                'assistant_run_id' => 44,
                'commercial_run_id' => 12,
                'approval_id' => 7,
                'guidance_run_id' => 5,
                'linked_deal_id' => 22,
                'linked_contact_id' => 8,
                'linked_invoice_id' => 71,
                'message_hash' => 'hash-1',
                'source_recommendation_type' => 'draft_customer_reply',
            ]),
        ];

        $id = $service->recordTaskOutcome($task, [
            'outcome_label' => 'completed',
            'outcome_score' => 1.0,
            'metadata' => ['completion_source' => 'manual'],
            'measured_at' => '2026-03-06 14:00:00',
        ]);

        $row = Database::queryOne("SELECT * FROM ai_decision_outcomes WHERE id = ?", [$id]);
        $metadata = json_decode((string) $row['outcome_metadata_json'], true);

        $this->assertSame('assistant', $row['surface']);
        $this->assertSame(5, (int) $row['guidance_run_id']);
        $this->assertSame(44, (int) $row['assistant_run_id']);
        $this->assertSame(12, (int) $row['commercial_run_id']);
        $this->assertSame(7, (int) $row['approval_id']);
        $this->assertSame(22, (int) $metadata['linked_deal_id']);
        $this->assertSame(8, (int) $metadata['linked_contact_id']);
        $this->assertSame(71, (int) $metadata['linked_invoice_id']);
    }
}
