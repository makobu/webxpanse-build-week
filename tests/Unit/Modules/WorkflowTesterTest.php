<?php
/**
 * Workflow Tester Unit Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\WorkflowTester;
use CRM\Modules\AutomationEngine;
use CRM\Database;

class WorkflowTesterTest extends DatabaseTestCase
{
    private WorkflowTester $tester;
    private int $workflowId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new WorkflowTester();
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (1, ?, 'Workflow', 'Tester', ?, NOW())",
            [uniqid('workflow-contact-', true), 'workflow.tester.' . bin2hex(random_bytes(3)) . '@example.test']
        );
        $this->contactId = (int) Database::lastInsertId();
        $engine = new AutomationEngine();
        $this->workflowId = $engine->createWorkflow(
            'Test Workflow',
            ['type' => 'contact_created'],
            [],
            [
                ['type' => 'add_note', 'note' => 'Test note', 'title' => 'Note'],
                ['type' => 'create_task', 'title' => 'Follow up', 'priority' => 'high']
            ]
        );
    }

    protected function tearDown(): void
    {
        Database::execute('DELETE FROM workflows WHERE id = ?', [$this->workflowId]);
        parent::tearDown();
    }

    public function testTestWorkflowReturnsResult(): void
    {
        $result = $this->tester->testWorkflow($this->workflowId, [
            'contact_id' => $this->contactId,
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com'
        ]);
        $this->assertArrayHasKey('workflow_id', $result);
        $this->assertArrayHasKey('workflow_name', $result);
        $this->assertArrayHasKey('conditions_met', $result);
        $this->assertArrayHasKey('actions', $result);
        $this->assertArrayHasKey('would_execute', $result);
        $this->assertEquals($this->workflowId, $result['workflow_id']);
        $this->assertTrue($result['conditions_met']);
        $this->assertCount(2, $result['actions']);
    }

    public function testTestWorkflowWithConditions(): void
    {
        $engine = new AutomationEngine();
        $wfId = $engine->createWorkflow(
            'Conditional Workflow',
            ['type' => 'contact_created'],
            ['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified'],
            [['type' => 'add_note', 'note' => 'Qualified', 'title' => 'Note']]
        );
        $result = $this->tester->testWorkflow($wfId, ['contact_id' => $this->contactId, 'stage' => 'qualified']);
        $this->assertTrue($result['conditions_met']);
        $result2 = $this->tester->testWorkflow($wfId, ['contact_id' => $this->contactId, 'stage' => 'new']);
        $this->assertFalse($result2['conditions_met']);
        Database::execute('DELETE FROM workflows WHERE id = ?', [$wfId]);
    }

    public function testTestWorkflowThrowsForInvalidId(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Workflow not found');
        $this->tester->testWorkflow(99999, ['contact_id' => 1]);
    }
}
