<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowQueueService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class WorkflowQueueServiceTest extends DatabaseTestCase
{
    private WorkflowQueueService $service;
    private int $workflowId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowQueueService();

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Queue Workspace', 'queue-workspace', 'active', 'trialing', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        $engine = new AutomationEngine();
        $this->workflowId = $engine->createWorkflow(
            'Queue Isolation Workflow',
            ['type' => 'contact_created'],
            [],
            [['type' => 'change_stage', 'stage' => 'contacted']]
        );
        Database::execute("UPDATE workflows SET workspace_id = 2 WHERE id = ?", [$this->workflowId]);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage)
             VALUES (2, ?, 'Queue', 'Contact', 'queue-contact@example.com', 'new')",
            [bin2hex(random_bytes(16))]
        );
        $this->contactId = (int) Database::lastInsertId();
    }

    public function testProcessQueueBackfillsWorkspaceIdFromOwnedRows(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);

        Database::execute(
            "INSERT INTO workflow_queue (workflow_id, contact_id, workspace_id, event_data, status, created_at)
             VALUES (?, ?, NULL, ?, 'pending', NOW())",
            [$this->workflowId, $this->contactId, json_encode(['contact_id' => $this->contactId])]
        );
        $queueId = (int) Database::lastInsertId();

        $processed = $this->service->processQueue(10);

        $queueRow = Database::queryOne(
            "SELECT workspace_id, status
             FROM workflow_queue
             WHERE id = ?",
            [$queueId]
        );
        $contact = Database::queryOne("SELECT stage FROM contacts WHERE id = ?", [$this->contactId]);
        $execution = Database::queryOne(
            "SELECT workspace_id, status
             FROM workflow_executions
             WHERE workflow_id = ? AND contact_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$this->workflowId, $this->contactId]
        );

        $this->assertSame(1, $processed);
        $this->assertNotNull($queueRow);
        $this->assertSame(2, (int) ($queueRow['workspace_id'] ?? 0));
        $this->assertSame('completed', (string) ($queueRow['status'] ?? ''));
        $this->assertSame('contacted', (string) ($contact['stage'] ?? ''));
        $this->assertNotNull($execution);
        $this->assertSame(2, (int) ($execution['workspace_id'] ?? 0));
    }
}
