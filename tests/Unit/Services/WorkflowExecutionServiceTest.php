<?php
/**
 * Workflow Execution Service Unit Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\WorkflowExecutionService;
use CRM\Modules\AutomationEngine;
use CRM\Database;

class WorkflowExecutionServiceTest extends DatabaseTestCase
{
    private WorkflowExecutionService $service;
    private int $workflowId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowExecutionService();
        $engine = new AutomationEngine();
        $this->workflowId = $engine->createWorkflow(
            'Test Workflow',
            ['type' => 'contact_created'],
            [],
            [['type' => 'change_stage', 'stage' => 'contacted']]
        );
        Database::execute(
            "UPDATE workflows SET workspace_id = 1 WHERE id = ?",
            [$this->workflowId]
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage) VALUES (1, ?, ?, ?, ?, ?)",
            [bin2hex(random_bytes(18)), 'Test', 'Contact', 'exec_test@example.com', 'new']
        );
        $this->contactId = (int) Database::lastInsertId();
    }

    protected function tearDown(): void
    {
        Database::execute('DELETE FROM workflow_executions WHERE workflow_id = ?', [$this->workflowId]);
        Database::execute('DELETE FROM contacts WHERE id = ?', [$this->contactId]);
        Database::execute('DELETE FROM workflows WHERE id = ?', [$this->workflowId]);
        parent::tearDown();
    }

    public function testExecuteWorkflowCreatesExecutionRecord(): void
    {
        $this->service->executeWorkflow($this->workflowId, [
            'contact_id' => $this->contactId
        ]);
        $executions = Database::query(
            "SELECT * FROM workflow_executions WHERE workflow_id = ? AND contact_id = ?",
            [$this->workflowId, $this->contactId]
        );
        $this->assertCount(1, $executions);
        $this->assertContains($executions[0]['status'], ['completed', 'failed', 'running']);
        $this->assertSame(1, (int) ($executions[0]['workspace_id'] ?? 0));
        $workflow = Database::queryOne("SELECT graph_json FROM workflows WHERE id = ?", [$this->workflowId]);
        $this->assertNotEmpty($workflow['graph_json']);
        $nodeRuns = Database::query("SELECT * FROM workflow_node_runs WHERE workflow_execution_id = ?", [$executions[0]['id']]);
        $this->assertNotEmpty($nodeRuns);
        $output = json_decode((string) ($nodeRuns[0]['output_snapshot_json'] ?? '{}'), true) ?: [];
        $this->assertSame('workflow_execution', $output['autonomy']['domain_key'] ?? $output['domain_key'] ?? null);
    }

    public function testExecuteWorkflowSkipsWhenConditionsNotMet(): void
    {
        $engine = new AutomationEngine();
        $wfId = $engine->createWorkflow(
            'Conditional',
            ['type' => 'contact_created'],
            [['field' => 'stage', 'operator' => 'equals', 'value' => 'qualified']],
            [['type' => 'change_stage', 'stage' => 'contacted']]
        );
        Database::execute(
            "UPDATE workflows SET workspace_id = 1 WHERE id = ?",
            [$wfId]
        );
        $this->service->executeWorkflow($wfId, ['contact_id' => $this->contactId]);
        $exec = Database::queryOne("SELECT * FROM workflow_executions WHERE workflow_id = ?", [$wfId]);
        $this->assertNotNull($exec);
        $this->assertEquals('completed', $exec['status']);
        $this->assertSame(1, (int) ($exec['workspace_id'] ?? 0));
        Database::execute('DELETE FROM workflow_executions WHERE workflow_id = ?', [$wfId]);
        Database::execute('DELETE FROM workflows WHERE id = ?', [$wfId]);
    }

    public function testInvalidateWorkflowCache(): void
    {
        WorkflowExecutionService::invalidateWorkflowCache($this->workflowId);
        $this->assertTrue(true);
    }
}
