<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\WorkflowAutonomyExecutionService;
use CRM\Tests\DatabaseTestCase;

class WorkflowAutonomyExecutionServiceTest extends DatabaseTestCase
{
    private int $contactId;
    private int $workflowId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO contacts (uuid, first_name, last_name, email, stage, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
            [bin2hex(random_bytes(18)), 'Workflow', 'Auto', 'workflow-auto@example.com', 'new']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workflows (name, trigger_config, actions, is_active, created_at, updated_at)
             VALUES (?, ?, ?, 1, NOW(), NOW())",
            [
                'Workflow Autonomy',
                json_encode(['type' => 'contact_created']),
                json_encode([['type' => 'change_stage', 'stage' => 'contacted']]),
            ]
        );
        $this->workflowId = (int) Database::lastInsertId();

        (new AIAutonomyDomainControlService())->save('contact:' . $this->contactId, 'workflow_execution', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['change_stage', 'send_email'],
            ],
        ]);
    }

    public function testExecutesInternalWorkflowActionAndCapturesDemonstration(): void
    {
        $workflow = Database::queryOne("SELECT * FROM workflows WHERE id = ?", [$this->workflowId]);
        $service = new WorkflowAutonomyExecutionService();

        $executed = false;
        $result = $service->executeAction(
            $workflow ?: [],
            ['type' => 'change_stage', 'stage' => 'contacted'],
            [
                'contact_id' => $this->contactId,
                'workflow_id' => $this->workflowId,
                'execution_id' => 101,
                'node_id' => 'action_1',
                'workflow_queue_state' => 'queued',
            ],
            function () use (&$executed): array {
                $executed = true;
                return ['mutated' => true];
            }
        );

        $demo = Database::queryOne(
            "SELECT * FROM ai_operator_demonstrations WHERE domain_key = 'workflow_execution' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [$this->contactId]
        );

        $this->assertTrue($executed);
        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($demo);
    }

    public function testBlocksCustomerFacingWorkflowActionWithoutRecipient(): void
    {
        Database::execute("UPDATE contacts SET email = NULL WHERE id = ?", [$this->contactId]);
        $workflow = Database::queryOne("SELECT * FROM workflows WHERE id = ?", [$this->workflowId]);
        $service = new WorkflowAutonomyExecutionService();

        $result = $service->executeAction(
            $workflow ?: [],
            ['type' => 'send_email', 'subject' => 'Hello', 'body' => 'Test'],
            [
                'contact_id' => $this->contactId,
                'workflow_id' => $this->workflowId,
                'execution_id' => 202,
                'node_id' => 'action_2',
                'workflow_queue_state' => 'queued',
            ],
            static function (): void {
            }
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('missing_recipient', (array) ($result['decision']['reasons'] ?? []));
    }
}
