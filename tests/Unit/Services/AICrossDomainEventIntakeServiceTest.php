<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AICrossDomainEventIntakeService;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Tests\DatabaseTestCase;

class AICrossDomainEventIntakeServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $dealId;
    private int $taskId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['event-intake@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (uuid, first_name, last_name, email, created_at) VALUES (?, ?, ?, ?, NOW())",
            [uniqid('event_', true), 'Event', 'Intake', 'event-intake@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (?, ?, ?, ?, 'proposal', 1200, 'USD', NOW())",
            ['Event Intake Deal', $this->contactId, $this->userId, $this->userId]
        );
        $this->dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO tasks (title, status, contact_id, created_by, assigned_to, created_at, metadata_json)
             VALUES (?, 'open', ?, ?, ?, NOW(), '{}')",
            ['Event Intake Task', $this->contactId, $this->userId, $this->userId]
        );
        $this->taskId = (int) Database::lastInsertId();
    }

    public function testProcessEventCreatesEventOriginRun(): void
    {
        $service = new AICrossDomainEventIntakeService();
        $result = $service->processEvent([
            'tenant_key' => 'contact:' . $this->contactId,
            'source_domain' => 'deal_followthrough',
            'trigger_key' => 'deal_milestone',
            'trigger_entity_type' => 'deal',
            'trigger_entity_id' => $this->dealId,
            'related_entity_ids' => [
                'deal_id' => $this->dealId,
                'contact_id' => $this->contactId,
            ],
        ], $this->userId);

        $this->assertSame('run_created', $result['intake_decision']);
        $this->assertNotEmpty($result['linked_run_id']);

        $run = Database::queryOne("SELECT * FROM ai_cross_domain_runs WHERE id = ?", [(int) $result['linked_run_id']]);
        $this->assertSame(1, (int) ($run['workspace_id'] ?? 0));
        $this->assertSame('workspace:1', (string) ($run['tenant_key'] ?? ''));
        $this->assertSame('event', $run['origin_type']);
        $this->assertSame('deal_followthrough', $run['trigger_source_domain']);
        $this->assertSame('deal_milestone', $run['trigger_key']);
        $this->assertSame('planned', $run['run_status']);
    }

    public function testDuplicateTriggerIsRejectedWhenActiveRunExists(): void
    {
        $service = new AICrossDomainEventIntakeService();
        $event = [
            'tenant_key' => 'contact:' . $this->contactId,
            'source_domain' => 'deal_followthrough',
            'trigger_key' => 'deal_milestone',
            'trigger_entity_type' => 'deal',
            'trigger_entity_id' => $this->dealId,
            'related_entity_ids' => [
                'deal_id' => $this->dealId,
                'contact_id' => $this->contactId,
            ],
        ];

        $first = $service->processEvent($event, $this->userId);
        $second = $service->processEvent($event, $this->userId);

        $this->assertSame('run_created', $first['intake_decision']);
        $this->assertSame('duplicate_rejected', $second['intake_decision']);
        $this->assertSame('active_run_exists', $second['reason']);
    }

    public function testCustomerFacingContainmentSuppressesEventRunCreation(): void
    {
        $controls = new AIAutonomyDomainControlService();
        $controls->save('contact:' . $this->contactId, 'cross_domain_orchestrator', [
            'autonomy_mode' => 'full_auto',
            'metadata' => [
                'pause_customer_facing_only' => true,
            ],
        ], $this->userId);

        $service = new AICrossDomainEventIntakeService();
        $result = $service->processEvent([
            'tenant_key' => 'contact:' . $this->contactId,
            'source_domain' => 'customer_thread',
            'trigger_key' => 'reply_needed',
            'trigger_entity_type' => 'contact',
            'trigger_entity_id' => $this->contactId,
            'related_entity_ids' => [
                'contact_id' => $this->contactId,
                'communication_id' => 44,
            ],
            'metadata' => [
                'thread_status' => 'waiting_on_us',
            ],
        ], $this->userId);

        $this->assertSame('suppressed', $result['intake_decision']);
        $this->assertSame('customer_facing_contained', $result['reason']);
    }

    public function testTaskMilestoneCanCreateWaitingRun(): void
    {
        $service = new AICrossDomainEventIntakeService();
        $result = $service->processEvent([
            'tenant_key' => 'contact:' . $this->contactId,
            'source_domain' => 'task_followthrough',
            'trigger_key' => 'task_completed',
            'trigger_entity_type' => 'task',
            'trigger_entity_id' => $this->taskId,
            'related_entity_ids' => [
                'task_id' => $this->taskId,
                'contact_id' => $this->contactId,
            ],
        ], $this->userId);

        $this->assertSame('run_waiting', $result['intake_decision']);

        $run = Database::queryOne("SELECT * FROM ai_cross_domain_runs WHERE id = ?", [(int) $result['linked_run_id']]);
        $this->assertSame('workspace:1', (string) ($run['tenant_key'] ?? ''));
        $this->assertSame('waiting', $run['run_status']);
        $this->assertStringContainsString('task_followthrough', (string) $run['wait_state_json']);
    }
}
