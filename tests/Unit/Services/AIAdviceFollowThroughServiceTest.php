<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAdviceFollowThroughService;
use CRM\Tests\DatabaseTestCase;

class AIAdviceFollowThroughServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['followthrough@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testLinkManualActionRecordsCoachActedOnFeedback(): void
    {
        Database::execute(
            "INSERT INTO ai_guidance_runs (workspace_id, user_id, surface, mode, decision, confidence_score, context_quality_score, goal_relevance_score, policy_snapshot_json, input_snapshot_json, output_snapshot_json, created_at)
             VALUES (1, ?, 'coach', 'foundation', 'allow', 0.9, 0.8, 0.7, '{}', '{}', '{}', NOW())",
            [$this->userId]
        );
        $guidanceRunId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, status, created_by, assigned_to, created_at, metadata_json)
             VALUES (1, 'Coach task', 'pending', ?, ?, NOW(), '{}')",
            [$this->userId, $this->userId]
        );
        $taskId = (int) Database::lastInsertId();

        $service = new AIAdviceFollowThroughService();
        $service->linkManualAction([
            'surface' => 'coach',
            'guidance_run_id' => $guidanceRunId,
            'task_id' => $taskId,
            'user_id' => $this->userId,
            'recommendation_key' => 'coach_1_key',
            'source_recommendation_type' => 'coach_recommendation',
        ]);

        $feedback = Database::queryOne("SELECT * FROM ai_advice_feedback WHERE guidance_run_id = ? ORDER BY id DESC LIMIT 1", [$guidanceRunId]);
        $this->assertSame('acted_on', $feedback['feedback_type']);

        $task = Database::queryOne("SELECT metadata_json FROM tasks WHERE id = ?", [$taskId]);
        $metadata = json_decode((string) $task['metadata_json'], true);
        $this->assertSame($guidanceRunId, (int) $metadata['guidance_run_id']);
        $this->assertSame('coach_1_key', $metadata['recommendation_key']);
    }

    public function testLinkManualActionRecordsAssistantFollowThroughOutcome(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at)
             VALUES (1, 'Assistant', 'Followthrough', 'assistant-followthrough@example.com', NOW())"
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, 'Assistant follow-through deal', ?, ?, ?, 'proposal', 0, 'USD', NOW())",
            [$contactId, $this->userId, $this->userId]
        );
        $dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, status, created_by, assigned_to, created_at, metadata_json)
             VALUES (1, 'Assistant task', 'pending', ?, ?, NOW(), '{}')",
            [$this->userId, $this->userId]
        );
        $taskId = (int) Database::lastInsertId();

        $service = new AIAdviceFollowThroughService();
        $service->linkManualAction([
            'surface' => 'assistant',
            'assistant_run_id' => 23,
            'task_id' => $taskId,
            'user_id' => $this->userId,
            'linked_contact_id' => $contactId,
            'linked_deal_id' => $dealId,
            'source_recommendation_type' => 'draft_customer_reply',
        ]);

        $row = Database::queryOne("SELECT * FROM ai_decision_outcomes WHERE assistant_run_id = ? ORDER BY id DESC LIMIT 1", [23]);
        $metadata = json_decode((string) $row['outcome_metadata_json'], true);

        $this->assertSame('assistant', $row['surface']);
        $this->assertSame('accepted', $row['outcome_label']);
        $this->assertTrue((bool) ($metadata['manual_followthrough'] ?? false));
        $this->assertSame($dealId, (int) $metadata['linked_deal_id']);
        $this->assertSame($contactId, (int) $metadata['linked_contact_id']);

        $demo = Database::queryOne("SELECT * FROM ai_operator_demonstrations WHERE action_key = 'draft_customer_reply' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($demo);
    }
}
