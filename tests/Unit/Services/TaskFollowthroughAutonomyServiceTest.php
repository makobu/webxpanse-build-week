<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Tasks;
use CRM\Services\AIAutonomyDomainControlService;
use CRM\Services\TaskFollowthroughAutonomyService;
use CRM\Tests\DatabaseTestCase;

class TaskFollowthroughAutonomyServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $viewerUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('task-auto-', true), 'task-auto@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('task-auto-viewer-', true), 'task-auto-viewer@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->viewerUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (1, ?, 'sales', 'active', 0, NOW(), NULL)",
            [$this->userId]
        );
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
             VALUES (1, ?, 'viewer', 'active', 0, NOW(), NULL)",
            [$this->viewerUserId]
        );

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Task', 'Auto', 'task-auto@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        $viewerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'viewer' LIMIT 1");
        Authorization::assignUserRole($this->userId, (int) ($salesRole['id'] ?? 0), $this->userId);
        Authorization::assignUserRole($this->viewerUserId, (int) ($viewerRole['id'] ?? 0), $this->userId);
    }

    public function testRunForTaskEscalatesStaleTask(): void
    {
        $taskId = (new Tasks())->create([
            'title' => 'Stale follow-up',
            'contact_id' => $this->contactId,
            'assigned_to' => $this->userId,
            'created_by' => $this->userId,
            'status' => 'pending',
            'priority' => 'medium',
            'due_date' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'metadata_json' => ['task_intent' => 'follow_up'],
        ]);

        (new AIAutonomyDomainControlService())->save('contact:' . $this->contactId, 'task_followthrough', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['escalate_task'],
            ],
        ]);

        $result = (new TaskFollowthroughAutonomyService())->runForTask($taskId, 'manual', $this->userId);
        $task = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$taskId]);

        $this->assertSame('auto_apply', $result['decision']);
        $this->assertSame('urgent', $task['priority']);
    }

    public function testFollowupTaskFallsBackToAiEligibleCreator(): void
    {
        Database::execute(
            "INSERT INTO tasks (workspace_id, title, contact_id, assigned_to, created_by, status, priority, metadata_json, created_at)
             VALUES (1, ?, ?, ?, ?, 'completed', 'medium', ?, NOW())",
            [
                'Completed setup task',
                $this->contactId,
                $this->viewerUserId,
                $this->userId,
                json_encode([
                    'task_intent' => 'follow_up',
                    'next_action_title' => 'Next follow-up task',
                ]),
            ]
        );
        $taskId = (int) Database::lastInsertId();

        (new AIAutonomyDomainControlService())->save('contact:' . $this->contactId, 'task_followthrough', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['create_followup_task'],
            ],
        ]);

        $result = (new TaskFollowthroughAutonomyService())->runForTask($taskId, 'manual', $this->userId);
        $createdTaskId = (int) (($result['actions'][0]['task_id'] ?? 0));
        $createdTask = Database::queryOne("SELECT * FROM tasks WHERE id = ?", [$createdTaskId]);

        $this->assertSame('auto_apply', $result['decision']);
        $this->assertSame($this->userId, (int) ($createdTask['assigned_to'] ?? 0));
    }
}
