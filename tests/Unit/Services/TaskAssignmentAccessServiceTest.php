<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Tests\DatabaseTestCase;

class TaskAssignmentAccessServiceTest extends DatabaseTestCase
{
    private int $salesUserId;
    private int $viewerUserId;
    private int $ownerUserId;
    private int $foreignSalesUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'sales', NOW())",
            [uniqid('task-sales-', true), 'task-sales@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->salesUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('task-viewer-', true), 'task-viewer@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->viewerUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'owner', NOW())",
            [uniqid('task-owner-', true), 'task-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->ownerUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'sales', NOW())",
            [uniqid('task-foreign-sales-', true), 'task-foreign-sales@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->foreignSalesUserId = (int) Database::lastInsertId();

        $salesRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'sales' LIMIT 1");
        $viewerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'viewer' LIMIT 1");
        $ownerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        Authorization::assignUserRole($this->salesUserId, (int) ($salesRole['id'] ?? 0), $this->salesUserId);
        Authorization::assignUserRole($this->viewerUserId, (int) ($viewerRole['id'] ?? 0), $this->viewerUserId);
        Authorization::assignUserRole($this->ownerUserId, (int) ($ownerRole['id'] ?? 0), $this->ownerUserId);
        Authorization::assignUserRole($this->foreignSalesUserId, (int) ($salesRole['id'] ?? 0), $this->foreignSalesUserId);
        $this->addWorkspaceMembership(1, $this->salesUserId, 'sales');
        $this->addWorkspaceMembership(1, $this->viewerUserId, 'viewer');
        $this->addWorkspaceMembership(1, $this->ownerUserId, 'owner', true);
        $this->ensureWorkspace(2, 'task-assignment-foreign', 'Task Assignment Foreign');
        $this->addWorkspaceMembership(2, $this->foreignSalesUserId, 'sales');
    }

    public function testResolveAiAssigneeRequiresAiAssignablePermission(): void
    {
        $service = new TaskAssignmentAccessService();

        $assignment = $service->resolveAiAssignee([$this->viewerUserId, $this->salesUserId], [
            'metadata_json' => ['task_intent' => 'follow_up'],
        ]);

        $this->assertSame($this->salesUserId, (int) ($assignment['assigned_to'] ?? 0));
        $this->assertSame('matched_candidate', $assignment['resolution']);
    }

    public function testManualAssigneeValidationRejectsViewer(): void
    {
        $service = new TaskAssignmentAccessService();

        $this->assertFalse($service->isManualAssigneeValid($this->viewerUserId));
        $this->assertFalse($service->isAiAssigneeValid($this->viewerUserId));
    }

    public function testResolveAiAssigneeSkipsCandidateWithoutDomainPermission(): void
    {
        $service = new TaskAssignmentAccessService();

        $assignment = $service->resolveAiAssignee([$this->salesUserId, $this->ownerUserId], [
            'metadata_json' => ['task_intent' => 'segmentation'],
        ]);

        $this->assertSame($this->ownerUserId, (int) ($assignment['assigned_to'] ?? 0));
        $this->assertSame('segmentation', $assignment['permission_domain']);
    }

    public function testResolveAiAssigneeLeavesTaskUnassignedForUnknownIntent(): void
    {
        $service = new TaskAssignmentAccessService();

        $assignment = $service->resolveAiAssignee([$this->salesUserId], [
            'metadata_json' => ['task_intent' => 'unknown_domain'],
        ]);

        $this->assertNull($assignment['assigned_to']);
        $this->assertSame('ai_assignee_unknown_intent', $assignment['reason_code']);
    }

    public function testManualAssigneesAreScopedToActiveWorkspace(): void
    {
        $service = new TaskAssignmentAccessService();

        $users = $service->getManualAssignableUsers();
        $ids = array_map('intval', array_column($users, 'id'));

        $this->assertContains($this->salesUserId, $ids);
        $this->assertNotContains($this->foreignSalesUserId, $ids);
        $this->assertTrue($service->isManualAssigneeValid($this->salesUserId));
        $this->assertFalse($service->isManualAssigneeValid($this->foreignSalesUserId));
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function addWorkspaceMembership(int $workspaceId, int $userId, string $roleSlug, bool $isOwner = false): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, 'active', ?, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = 'active', is_owner = VALUES(is_owner)",
            [$workspaceId, $userId, $roleSlug, $isOwner ? 1 : 0]
        );
    }
}
