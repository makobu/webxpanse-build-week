<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowScheduledTriggerService;
use CRM\Tests\DatabaseTestCase;

class WorkflowScheduledTriggerServiceTest extends DatabaseTestCase
{
    private WorkflowScheduledTriggerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowScheduledTriggerService();

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Trigger Workspace', 'trigger-workspace', 'active', 'trialing', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );
    }

    public function testDailyTriggersQueueContactsOnlyInsideWorkflowWorkspace(): void
    {
        $engine = new AutomationEngine();
        $workflowId = $engine->createWorkflow(
            'Daily Trigger Workflow',
            ['type' => 'daily_at_time', 'time' => '09:30'],
            [],
            [['type' => 'change_stage', 'stage' => 'contacted']]
        );
        Database::execute(
            "UPDATE workflows
             SET workspace_id = 2,
                 trigger_config = ?
             WHERE id = ?",
            [json_encode(['type' => 'daily_at_time', 'time' => '09:30']), $workflowId]
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, updated_at)
             VALUES (2, ?, 'Trigger', 'Scoped', 'trigger-scoped@example.com', 'new', NOW())",
            [bin2hex(random_bytes(16))]
        );
        $workspaceTwoContactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, updated_at)
             VALUES (1, ?, 'Trigger', 'Foreign', 'trigger-foreign@example.com', 'new', NOW())",
            [bin2hex(random_bytes(16))]
        );

        $fired = $this->service->processScheduledTriggers(new \DateTimeImmutable('2026-04-20 09:30:00'));

        $queued = Database::query(
            "SELECT workflow_id, workspace_id, contact_id
             FROM workflow_queue
             WHERE workflow_id = ?",
            [$workflowId]
        );

        $this->assertSame(1, $fired);
        $this->assertCount(1, $queued);
        $this->assertSame(2, (int) ($queued[0]['workspace_id'] ?? 0));
        $this->assertSame($workspaceTwoContactId, (int) ($queued[0]['contact_id'] ?? 0));
    }

    public function testBirthdayTriggersQueueContactsOnlyInsideWorkflowWorkspace(): void
    {
        $engine = new AutomationEngine();
        $workflowId = $engine->createWorkflow(
            'Birthday Trigger Workflow',
            ['type' => 'contact_birthday'],
            [],
            [['type' => 'change_stage', 'stage' => 'contacted']]
        );
        Database::execute(
            "UPDATE workflows
             SET workspace_id = 2,
                 trigger_config = ?
             WHERE id = ?",
            [json_encode(['type' => 'contact_birthday']), $workflowId]
        );

        Database::execute(
            "INSERT INTO custom_fields (field_name, field_type, module)
             VALUES ('birthday', 'date', 'contacts')"
        );
        $birthdayFieldId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, updated_at)
             VALUES (2, ?, 'Birthday', 'Scoped', 'birthday-scoped@example.com', 'new', NOW())",
            [bin2hex(random_bytes(16))]
        );
        $workspaceTwoContactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contact_custom_data (contact_id, field_id, field_value)
             VALUES (?, ?, ?)",
            [$workspaceTwoContactId, $birthdayFieldId, '2026-04-18']
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, updated_at)
             VALUES (1, ?, 'Birthday', 'Foreign', 'birthday-foreign@example.com', 'new', NOW())",
            [bin2hex(random_bytes(16))]
        );
        $foreignContactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contact_custom_data (contact_id, field_id, field_value)
             VALUES (?, ?, ?)",
            [$foreignContactId, $birthdayFieldId, '2026-04-18']
        );

        $fired = $this->service->processScheduledTriggers(new \DateTimeImmutable('2026-04-18 09:30:00'));

        $queued = Database::query(
            "SELECT workflow_id, workspace_id, contact_id
             FROM workflow_queue
             WHERE workflow_id = ?",
            [$workflowId]
        );

        $this->assertSame(1, $fired);
        $this->assertCount(1, $queued);
        $this->assertSame(2, (int) ($queued[0]['workspace_id'] ?? 0));
        $this->assertSame($workspaceTwoContactId, (int) ($queued[0]['contact_id'] ?? 0));
    }
}
