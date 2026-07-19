<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowScheduler;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class WorkflowSchedulerTest extends DatabaseTestCase
{
    private WorkflowScheduler $scheduler;
    private int $workflowId;
    private int $contactId;
    private int $executionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new WorkflowScheduler();

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Scheduler Workspace', 'scheduler-workspace', 'active', 'trialing', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        $engine = new AutomationEngine();
        $this->workflowId = $engine->createWorkflow(
            'Scheduler Isolation Workflow',
            ['type' => 'contact_created'],
            [],
            [['type' => 'change_stage', 'stage' => 'qualified']]
        );
        Database::execute("UPDATE workflows SET workspace_id = 2 WHERE id = ?", [$this->workflowId]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage)
             VALUES (2, ?, 'Scheduler', 'Contact', 'scheduler-contact@example.com', 'new')",
            [bin2hex(random_bytes(16))]
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workflow_executions (workflow_id, contact_id, workspace_id, status)
             VALUES (?, ?, 2, 'pending')",
            [$this->workflowId, $this->contactId]
        );
        $this->executionId = (int) Database::lastInsertId();
    }

    public function testProcessScheduledActionsBackfillsWorkspaceIdAndExecutesInsideOwnedWorkspace(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);

        Database::execute(
            "INSERT INTO scheduled_workflow_actions
             (workflow_id, execution_id, workspace_id, action_index, scheduled_for, timezone, status, retry_count)
             VALUES (?, ?, NULL, 0, DATE_SUB(NOW(), INTERVAL 1 MINUTE), 'UTC', 'pending', 0)",
            [$this->workflowId, $this->executionId]
        );
        $scheduledActionId = (int) Database::lastInsertId();

        $processed = $this->scheduler->processScheduledActions();

        $scheduledAction = Database::queryOne(
            "SELECT workspace_id, status
             FROM scheduled_workflow_actions
             WHERE id = ?",
            [$scheduledActionId]
        );
        $contact = Database::queryOne("SELECT stage FROM contacts WHERE id = ?", [$this->contactId]);

        $this->assertSame(1, $processed);
        $this->assertNotNull($scheduledAction);
        $this->assertSame(2, (int) ($scheduledAction['workspace_id'] ?? 0));
        $this->assertSame('executed', (string) ($scheduledAction['status'] ?? ''));
        $this->assertSame('qualified', (string) ($contact['stage'] ?? ''));
    }

    public function testProcessRetryQueueBackfillsWorkspaceIdAndKeepsReplayInsideOwnedWorkspace(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);

        Database::execute(
            "INSERT INTO workflow_retry_queue
             (workflow_queue_id, workflow_execution_id, workflow_id, workspace_id, node_id, action_index, retry_after, retry_count, last_error, payload_json, status)
             VALUES (NULL, ?, ?, NULL, 'node-1', 0, DATE_SUB(NOW(), INTERVAL 1 MINUTE), 0, 'transient', ?, 'pending')",
            [
                $this->executionId,
                $this->workflowId,
                json_encode([
                    'contact_id' => $this->contactId,
                    'node' => [
                        'id' => 'node-1',
                        'type' => 'action',
                        'subtype' => 'change_stage',
                        'config' => ['stage' => 'contacted'],
                    ],
                ]),
            ]
        );
        $retryId = (int) Database::lastInsertId();

        $processed = $this->scheduler->processRetryQueue();

        $retryRow = Database::queryOne(
            "SELECT workspace_id, status
             FROM workflow_retry_queue
             WHERE id = ?",
            [$retryId]
        );
        $contact = Database::queryOne("SELECT stage FROM contacts WHERE id = ?", [$this->contactId]);

        $this->assertSame(1, $processed);
        $this->assertNotNull($retryRow);
        $this->assertSame(2, (int) ($retryRow['workspace_id'] ?? 0));
        $this->assertSame('completed', (string) ($retryRow['status'] ?? ''));
        $this->assertSame('contacted', (string) ($contact['stage'] ?? ''));
    }
}
