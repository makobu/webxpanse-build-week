<?php
/**
 * Workflow Execution Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\AutomationEngine;
use CRM\Modules\Contacts;
use CRM\Modules\Tasks;
use CRM\Modules\Events;
use CRM\Database;

class WorkflowExecutionTest extends DatabaseTestCase
{
    private AutomationEngine $engine;
    private Contacts $contacts;
    private Tasks $tasks;
    private Events $events;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new AutomationEngine();
        $this->contacts = new Contacts();
        $this->tasks = new Tasks();
        $this->events = new Events();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['workflow@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM workflows WHERE created_by = ?", [$this->testUserId]);
        Database::execute("DELETE FROM contacts WHERE email LIKE 'workflow%@example.com'");
        Database::execute("DELETE FROM tasks WHERE assigned_to = ?", [$this->testUserId]);
        Database::execute("DELETE FROM events WHERE assigned_to = ?", [$this->testUserId]);
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        parent::tearDown();
    }
    
    public function testWorkflowExecutionOnContactCreation(): void
    {
        // 1. Create workflow that triggers on contact creation
        $workflowId = Database::execute(
            "INSERT INTO workflows (name, trigger_event, trigger_conditions, actions, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                'Test Workflow',
                'contact_created',
                json_encode([]),
                json_encode([
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => 'Follow up with new contact',
                            'priority' => 'high',
                            'assigned_to' => $this->testUserId
                        ]
                    ]
                ]),
                1,
                $this->testUserId
            ]
        );
        $workflowId = (int) Database::lastInsertId();
        
        // 2. Create a contact (should trigger workflow)
        $contact = $this->contacts->create([
            'first_name' => 'Workflow',
            'last_name' => 'Test',
            'email' => 'workflowtest@example.com'
        ]);
        $contactId = $contact['id'];
        
        // 3. Verify workflow was triggered
        // Check if task was created
        $tasks = $this->tasks->getByContact($contactId);
        $this->assertGreaterThan(0, count($tasks));
        
        // Verify task details
        $task = $tasks[0];
        $this->assertEquals('Follow up with new contact', $task['title']);
        $this->assertEquals('high', $task['priority']);
        $this->assertEquals($this->testUserId, $task['assigned_to']);
    }
    
    public function testWorkflowExecutionOnContactStageChange(): void
    {
        // 1. Create contact
        $contact = $this->contacts->create([
            'first_name' => 'Stage',
            'last_name' => 'Test',
            'email' => 'stagetest@example.com',
            'stage' => 'new'
        ]);
        $contactId = $contact['id'];
        
        // 2. Create workflow for stage change
        $workflowId = Database::execute(
            "INSERT INTO workflows (name, trigger_event, trigger_conditions, actions, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                'Stage Change Workflow',
                'contact_updated',
                json_encode(['field' => 'stage', 'value' => 'qualified']),
                json_encode([
                    [
                        'type' => 'create_event',
                        'config' => [
                            'title' => 'Schedule meeting with qualified lead',
                            'event_type' => 'meeting',
                            'assigned_to' => $this->testUserId
                        ]
                    ]
                ]),
                1,
                $this->testUserId
            ]
        );
        $workflowId = (int) Database::lastInsertId();
        
        // 3. Update contact stage to qualified
        $this->contacts->update($contactId, ['stage' => 'qualified']);
        
        // 4. Verify workflow was triggered
        $events = $this->events->getByContact($contactId);
        $this->assertGreaterThan(0, count($events));
        
        $event = $events[0];
        $this->assertEquals('Schedule meeting with qualified lead', $event['title']);
        $this->assertEquals('meeting', $event['event_type']);
    }
    
    public function testWorkflowWithMultipleActions(): void
    {
        // 1. Create workflow with multiple actions
        $workflowId = Database::execute(
            "INSERT INTO workflows (name, trigger_event, trigger_conditions, actions, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                'Multi-Action Workflow',
                'contact_created',
                json_encode([]),
                json_encode([
                    [
                        'type' => 'update_contact',
                        'config' => ['stage' => 'contacted']
                    ],
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => 'Send welcome email',
                            'assigned_to' => $this->testUserId
                        ]
                    ]
                ]),
                1,
                $this->testUserId
            ]
        );
        $workflowId = (int) Database::lastInsertId();
        
        // 2. Create contact
        $contact = $this->contacts->create([
            'first_name' => 'Multi',
            'last_name' => 'Action',
            'email' => 'multiaction@example.com'
        ]);
        $contactId = $contact['id'];
        
        // 3. Verify all actions executed
        $updatedContact = $this->contacts->getById($contactId);
        $this->assertEquals('contacted', $updatedContact['stage']);
        
        $tasks = $this->tasks->getByContact($contactId);
        $this->assertGreaterThan(0, count($tasks));
        $this->assertEquals('Send welcome email', $tasks[0]['title']);
    }
    
    public function testWorkflowWithConditions(): void
    {
        // 1. Create workflow with conditions
        $workflowId = Database::execute(
            "INSERT INTO workflows (name, trigger_event, trigger_conditions, actions, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                'Conditional Workflow',
                'contact_created',
                json_encode([
                    'conditions' => [
                        ['field' => 'lead_source', 'operator' => 'equals', 'value' => 'website']
                    ]
                ]),
                json_encode([
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => 'Follow up website lead',
                            'assigned_to' => $this->testUserId
                        ]
                    ]
                ]),
                1,
                $this->testUserId
            ]
        );
        $workflowId = (int) Database::lastInsertId();
        
        // 2. Create contact that matches condition
        $contact1 = $this->contacts->create([
            'first_name' => 'Match',
            'email' => 'match@example.com',
            'lead_source' => 'website'
        ]);
        
        // 3. Create contact that doesn't match condition
        $contact2 = $this->contacts->create([
            'first_name' => 'NoMatch',
            'email' => 'nomatch@example.com',
            'lead_source' => 'referral'
        ]);
        
        // 4. Verify workflow only triggered for matching contact
        $tasks1 = $this->tasks->getByContact($contact1['id']);
        $tasks2 = $this->tasks->getByContact($contact2['id']);
        
        $this->assertGreaterThan(0, count($tasks1));
        $this->assertEquals(0, count($tasks2));
    }
}
