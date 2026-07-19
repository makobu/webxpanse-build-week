<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAdviceFeedbackService;
use CRM\Tests\DatabaseTestCase;

class AIAdviceFeedbackServiceTest extends DatabaseTestCase
{
    private int $workspaceId = 1;
    private int $userId;
    private int $guidanceRunId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['feedback@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO ai_guidance_runs
                (workspace_id, user_id, surface, mode, decision, confidence_score, context_quality_score, goal_relevance_score, policy_snapshot_json, input_snapshot_json, output_snapshot_json, created_at)
             VALUES (?, ?, 'coach', 'operations', 'allow', 0.91, 0.78, 0.71, '{}', '{}', '{}', NOW())",
            [$this->workspaceId, $this->userId]
        );
        $this->guidanceRunId = (int) Database::lastInsertId();
    }

    public function testRecordsCoachFeedbackAndMapsUsefulToAcceptedOutcome(): void
    {
        $service = new AIAdviceFeedbackService();
        $feedbackId = $service->recordFeedback([
            'user_id' => $this->userId,
            'surface' => 'coach',
            'guidance_run_id' => $this->guidanceRunId,
            'feedback_type' => 'useful',
            'recommendation_key' => 'coach_' . $this->guidanceRunId . '_abc123',
        ]);

        $feedback = Database::queryOne("SELECT * FROM ai_advice_feedback WHERE id = ?", [$feedbackId]);
        $this->assertSame('useful', $feedback['feedback_type']);
        $this->assertSame($this->workspaceId, (int) $feedback['workspace_id']);
        $this->assertSame('coach_' . $this->guidanceRunId . '_abc123', $feedback['recommendation_key']);

        $outcome = Database::queryOne("SELECT * FROM ai_decision_outcomes WHERE guidance_run_id = ? ORDER BY id DESC LIMIT 1", [$this->guidanceRunId]);
        $this->assertSame('accepted', $outcome['outcome_label']);
        $this->assertSame('coach', $outcome['surface']);
        $this->assertSame($this->workspaceId, (int) $outcome['workspace_id']);
    }

    public function testDeduplicatesIdenticalFeedbackAndStoresClarityHash(): void
    {
        Database::execute("UPDATE ai_guidance_runs SET surface = 'clarity_chat' WHERE id = ?", [$this->guidanceRunId]);

        $service = new AIAdviceFeedbackService();
        $payload = [
            'user_id' => $this->userId,
            'surface' => 'clarity_chat',
            'guidance_run_id' => $this->guidanceRunId,
            'feedback_type' => 'not_useful',
            'message_hash' => hash('sha256', 'message'),
        ];

        $firstId = $service->recordFeedback($payload);
        $secondId = $service->recordFeedback($payload);

        $this->assertSame($firstId, $secondId);

        $rows = Database::query("SELECT * FROM ai_advice_feedback WHERE guidance_run_id = ?", [$this->guidanceRunId]);
        $this->assertCount(1, $rows);

        $outcome = Database::queryOne("SELECT * FROM ai_decision_outcomes WHERE guidance_run_id = ? ORDER BY id DESC LIMIT 1", [$this->guidanceRunId]);
        $this->assertSame('rejected', $outcome['outcome_label']);
    }

    public function testRecentCoachFeedbackBySignatureIgnoresRowsBeforeResetCutoff(): void
    {
        Database::execute(
            "INSERT INTO ai_advice_feedback
                (workspace_id, guidance_run_id, user_id, surface, feedback_type, recommendation_key, metadata_json, created_at)
             VALUES (?, ?, ?, 'coach', 'not_useful', 'coach_old', ?, '2024-01-01 00:00:00')",
            [
                $this->workspaceId,
                $this->guidanceRunId,
                $this->userId,
                json_encode(['feedback_signature' => 'coach_sig_reset']),
            ]
        );
        Database::execute(
            "INSERT INTO ai_advice_feedback
                (workspace_id, guidance_run_id, user_id, surface, feedback_type, recommendation_key, metadata_json, created_at)
             VALUES (?, ?, ?, 'coach', 'useful', 'coach_new', ?, NOW())",
            [
                $this->workspaceId,
                $this->guidanceRunId,
                $this->userId,
                json_encode(['feedback_signature' => 'coach_sig_keep']),
            ]
        );

        $service = new AIAdviceFeedbackService();
        $feedback = $service->getRecentCoachFeedbackBySignature(
            $this->userId,
            ['coach_sig_reset', 'coach_sig_keep'],
            900,
            ['coach_sig_reset' => '2025-01-01 00:00:00']
        );

        $this->assertArrayNotHasKey('coach_sig_reset', $feedback);
        $this->assertSame(1, $feedback['coach_sig_keep']['accepted']);
    }
}
