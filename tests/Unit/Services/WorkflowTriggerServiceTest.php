<?php
/**
 * Workflow Trigger Service Unit Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\WorkflowTriggerService;

class WorkflowTriggerServiceTest extends DatabaseTestCase
{
    private WorkflowTriggerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowTriggerService();
    }

    public function testMapTriggerToEventName(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mapTriggerToEventName');
        $method->setAccessible(true);

        $this->assertEquals('contact.created', $method->invoke($this->service, 'contact_created'));
        $this->assertEquals('deal.won', $method->invoke($this->service, 'deal_won'));
        $this->assertEquals('stage.changed', $method->invoke($this->service, 'stage_changed'));
        $this->assertEquals('task.completed', $method->invoke($this->service, 'task_completed'));
        $this->assertEquals('email.opened', $method->invoke($this->service, 'email_opened'));
    }

    public function testInitializeWorkflows(): void
    {
        $this->service->initializeWorkflows();
        $subscribed = $this->service->getSubscribedWorkflows();
        $this->assertIsArray($subscribed);
    }
}
