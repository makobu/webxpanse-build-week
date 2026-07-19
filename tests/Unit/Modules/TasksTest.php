<?php
/**
 * Tasks Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Authorization;
use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Tasks;
use CRM\Database;

class TasksTest extends DatabaseTestCase
{
    private Tasks $tasks;
    private int $testUserId;
    private int $testContactId;
    private int $viewerUserId;
    private int $assigneeUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tasks = new Tasks();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) 
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('tasks-test-', true), 'test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) 
             VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('tasks-viewer-', true), 'viewer@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->viewerUserId = (int) Database::lastInsertId();

        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        $viewerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'viewer' LIMIT 1");
        Authorization::assignUserRole($this->testUserId, (int) ($salesRole['id'] ?? 0), $this->testUserId);
        Authorization::assignUserRole($this->viewerUserId, (int) ($viewerRole['id'] ?? 0), $this->testUserId);

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) 
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('tasks-assignee-', true), 'assignee@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->assigneeUserId = (int) Database::lastInsertId();
        Authorization::assignUserRole($this->assigneeUserId, (int) ($salesRole['id'] ?? 0), $this->testUserId);
        
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at) 
             VALUES (1, ?, ?, ?, ?, NOW())",
            [uniqid('contact_', true), 'John', 'Doe', 'john@example.com']
        );
        $this->testContactId = (int) Database::lastInsertId();
    }
    
    public function testCreateTask()
    {
        $id = $this->tasks->create([
            'title' => 'Test Task',
            'description' => 'Test description',
            'assigned_to' => $this->testUserId,
            'contact_id' => $this->testContactId,
            'status' => 'pending',
            'priority' => 'high',
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$id]);
        $this->assertEquals('Test Task', $task['title']);
        $this->assertEquals('pending', $task['status']);
        $this->assertEquals('high', $task['priority']);
    }
    
    public function testCreateTaskRequiresTitle()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Task title is required");
        
        $this->tasks->create([]);
    }

    public function testCreateTaskRejectsBlankTitleAfterSanitizing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Task title is required");

        $this->tasks->create([
            'title' => '   ',
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateTaskRejectsInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task status');

        $this->tasks->create([
            'title' => 'Bad Status Task',
            'status' => 'not_a_status',
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateTaskRejectsInvalidPriority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task priority');

        $this->tasks->create([
            'title' => 'Bad Priority Task',
            'priority' => 'impossible',
            'created_by' => $this->testUserId,
        ]);
    }

    public function testCreateTaskRejectsInvalidDueDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task due date must be a valid date or time');

        $this->tasks->create([
            'title' => 'Bad Date Task',
            'due_date' => '2026-02-31T09:00',
            'created_by' => $this->testUserId,
        ]);
    }
    
    public function testGetTaskById()
    {
        $id = $this->tasks->create([
            'title' => 'Test Task',
            'created_by' => $this->testUserId
        ]);
        
        $task = $this->tasks->getById($id);
        
        $this->assertIsArray($task);
        $this->assertEquals('Test Task', $task['title']);
        $this->assertEquals($id, $task['id']);
    }
    
    public function testGetTaskByIdNotFound()
    {
        $task = $this->tasks->getById(99999);
        
        $this->assertNull($task);
    }
    
    public function testUpdateTask()
    {
        $id = $this->tasks->create([
            'title' => 'Original Title',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->tasks->update($id, [
            'title' => 'Updated Title',
            'status' => 'completed'
        ]);
        
        $this->assertTrue($result);
        
        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$id]);
        $this->assertEquals('Updated Title', $task['title']);
        $this->assertEquals('completed', $task['status']);
        $demo = Database::queryOne("SELECT * FROM ai_operator_demonstrations WHERE entity_type = 'task' AND action_key = 'complete_task' ORDER BY id DESC LIMIT 1");
        $this->assertNotNull($demo);
    }

    public function testUpdateTaskRejectsInvalidStatus(): void
    {
        $id = $this->tasks->create([
            'title' => 'Original Title',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task status');

        $this->tasks->update($id, ['status' => 'not_a_status']);
    }

    public function testUpdateTaskRejectsInvalidPriority(): void
    {
        $id = $this->tasks->create([
            'title' => 'Original Title',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task priority');

        $this->tasks->update($id, ['priority' => 'impossible']);
    }
    
    public function testDeleteTask()
    {
        $id = $this->tasks->create([
            'title' => 'Task to Delete',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->tasks->delete($id);
        
        $this->assertTrue($result);
        
        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$id]);
        $this->assertNull($task);
    }
    
    public function testGetUserTasks()
    {
        // Create tasks for user
        $this->tasks->create([
            'title' => 'Task 1',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId
        ]);
        
        $this->tasks->create([
            'title' => 'Task 2',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId
        ]);
        
        $tasks = $this->tasks->getUserTasks($this->testUserId);
        
        $this->assertIsArray($tasks);
        $this->assertGreaterThanOrEqual(2, count($tasks));
    }

    public function testGetUserTasksHonorsRequestedLimit(): void
    {
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, priority, created_at)
             VALUES
                (1, 'Bounded task 1', ?, ?, 'pending', 'medium', NOW()),
                (1, 'Bounded task 2', ?, ?, 'pending', 'medium', NOW())",
            [$this->testUserId, $this->testUserId, $this->testUserId, $this->testUserId]
        );

        $tasks = $this->tasks->getUserTasks($this->testUserId, [], 1);

        $this->assertCount(1, $tasks);
        $this->assertSame($this->testUserId, (int) ($tasks[0]['assigned_to'] ?? 0));
    }

    public function testGetNewUnresolvedSinceCountUsesTasksPageOpenedTimestamp(): void
    {
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, priority, created_at)
             VALUES
                (1, 'Older open task', ?, ?, 'pending', 'medium', '2026-05-19 08:00:00'),
                (1, 'New pending task', ?, ?, 'pending', 'medium', '2026-05-19 09:30:00'),
                (1, 'New in-progress task', ?, ?, 'in_progress', 'medium', '2026-05-19 10:00:00'),
                (1, 'New completed task', ?, ?, 'completed', 'medium', '2026-05-19 10:30:00'),
                (1, 'Other user new task', ?, ?, 'pending', 'medium', '2026-05-19 11:00:00')",
            [
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->assigneeUserId,
                $this->testUserId,
            ]
        );

        $this->assertSame(
            2,
            $this->tasks->getNewUnresolvedSinceCount($this->testUserId, '2026-05-19 09:00:00')
        );
    }

    public function testGetNewUnresolvedSinceCountFallsBackToAssignedUnresolvedWhenNeverOpened(): void
    {
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, assigned_to, created_by, status, priority, created_at)
             VALUES
                (1, 'Pending task', ?, ?, 'pending', 'medium', '2026-05-19 08:00:00'),
                (1, 'In-progress task', ?, ?, 'in_progress', 'medium', '2026-05-19 09:00:00'),
                (1, 'Cancelled task', ?, ?, 'cancelled', 'medium', '2026-05-19 10:00:00')",
            [
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
                $this->testUserId,
            ]
        );

        $this->assertSame(2, $this->tasks->getNewUnresolvedSinceCount($this->testUserId, null));
    }

    public function testGetAiStarterTasksReturnsOnlyActiveCoachTasksInRankOrder(): void
    {
        $lowStarterId = $this->tasks->create([
            'title' => 'Low starter',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
            'priority' => 'low',
            'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'metadata_json' => ['source_surface' => 'ai_coach'],
        ]);
        $urgentStarterId = $this->tasks->create([
            'title' => 'Urgent starter',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
            'priority' => 'urgent',
            'due_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'metadata_json' => ['source_surface' => 'ai_coach'],
        ]);
        $completedStarterId = $this->tasks->create([
            'title' => 'Completed starter',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
            'priority' => 'urgent',
            'metadata_json' => ['source_surface' => 'ai_coach'],
        ]);
        $this->tasks->update($completedStarterId, ['status' => 'completed']);
        $this->tasks->create([
            'title' => 'Manual task',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId,
            'priority' => 'urgent',
        ]);

        $starterTasks = $this->tasks->getAiStarterTasks($this->testUserId, 5);
        $starterIds = array_map(static fn(array $task): int => (int) $task['id'], $starterTasks);

        $this->assertSame([$urgentStarterId, $lowStarterId], array_values(array_intersect($starterIds, [$urgentStarterId, $lowStarterId])));
        $this->assertNotContains($completedStarterId, $starterIds);
    }
    
    public function testGetCount()
    {
        $this->tasks->create([
            'title' => 'Task 1',
            'assigned_to' => $this->testUserId,
            'created_by' => $this->testUserId
        ]);
        
        $count = $this->tasks->getCount(['assigned_to' => $this->testUserId]);

        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testCreateRejectsAiAssignmentToIneligibleUser(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('eligible to receive AI-assigned tasks');

        $this->tasks->create([
            'title' => 'AI Task',
            'assigned_to' => $this->viewerUserId,
            'assignment_mode' => 'ai',
            'created_by' => $this->testUserId,
            'actor_user_id' => $this->testUserId,
            'metadata_json' => [
                'task_intent' => 'follow_up',
                'source_surface' => 'ai_coach',
            ],
        ]);
    }

    public function testCreateRejectsAiAssignmentWhenAssigneeLacksDomainPermission(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('eligible to receive AI-assigned tasks');

        $this->tasks->create([
            'title' => 'Update company profile details',
            'assigned_to' => $this->testUserId,
            'assignment_mode' => 'ai',
            'created_by' => $this->testUserId,
            'actor_user_id' => $this->testUserId,
            'metadata_json' => [
                'task_intent' => 'company_profile',
                'source_surface' => 'ai_coach',
            ],
        ]);
    }

    public function testUpdateRequiresReassignPermissionToChangeAssignee(): void
    {
        $marketingRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'marketing' LIMIT 1");
        Authorization::assignUserRole($this->testUserId, (int) ($marketingRole['id'] ?? 0), $this->testUserId);

        $taskId = $this->tasks->create([
            'title' => 'Task needing reassignment',
            'assigned_to' => $this->assigneeUserId,
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reassign tasks');

        $this->tasks->update($taskId, [
            'assigned_to' => $this->testUserId,
            'actor_user_id' => $this->testUserId,
        ]);
    }

    public function testReassignmentCreatesDemonstrationRecord(): void
    {
        $taskId = $this->tasks->create([
            'title' => 'Reassign me',
            'assigned_to' => $this->assigneeUserId,
            'created_by' => $this->testUserId,
        ]);

        $result = $this->tasks->update($taskId, [
            'assigned_to' => $this->testUserId,
            'actor_user_id' => $this->testUserId,
        ]);

        $this->assertTrue($result);
        $demo = Database::queryOne(
            "SELECT * FROM ai_operator_demonstrations WHERE entity_type = 'task' AND action_key = 'reassign_task' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotNull($demo);
    }

    public function testGetByIdReturnsNullForAnotherWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        Database::execute(
            "INSERT INTO tasks (workspace_id, title, status, priority, created_by, created_at)
             VALUES (2, 'Foreign Task', 'pending', 'medium', ?, NOW())",
            [$this->testUserId]
        );
        $foreignTaskId = (int) Database::lastInsertId();

        $this->assertNull($this->tasks->getById($foreignTaskId));
    }

    public function testAutomatedCreationNormalizesTypedSourceAndDedupes(): void
    {
        $payload = [
            'title' => 'Meeting action item',
            'created_by' => $this->testUserId,
            'source_surface' => 'meeting_note_taker',
            'source_run_id' => 'meeting-run-77',
            'origin_type' => 'automation',
            'completion_mode' => 'review',
            'automation_dedupe_key' => 'meeting_note:run-77:item-1',
            'metadata_json' => ['completion_evidence_types' => ['completed_event']],
        ];

        $firstId = $this->tasks->create($payload);
        $secondId = $this->tasks->create($payload);

        $this->assertSame($firstId, $secondId);
        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$firstId]);
        $metadata = json_decode((string) $task['metadata_json'], true) ?: [];
        $this->assertSame('automation', $task['origin_type']);
        $this->assertSame('review', $task['completion_mode']);
        $this->assertSame('meeting_note_taker', $task['source_surface']);
        $this->assertSame('meeting-run-77', $metadata['source_run_id']);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM tasks WHERE workspace_id = 1 AND automation_dedupe_key = ?",
            ['meeting_note:run-77:item-1']
        )['c'] ?? 0));
    }

    public function testFailedSubtaskBuildRollsBackParentTask(): void
    {
        try {
            $this->tasks->createWithSubtasks([
                'title' => 'Parent must roll back',
                'created_by' => $this->testUserId,
            ], [
                ['title' => new \stdClass()],
            ]);
            $this->fail('Expected invalid subtask payload to fail.');
        } catch (\Throwable $e) {
            $count = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM tasks WHERE title = 'Parent must roll back'"
            )['c'] ?? 0);
            $this->assertSame(0, $count);
        }
    }
}
