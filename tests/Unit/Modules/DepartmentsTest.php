<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\Departments;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class DepartmentsTest extends DatabaseTestCase
{
    private Departments $departments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->departments = new Departments();
    }

    public function testStarterDepartmentsAndAdminPermissionExist(): void
    {
        $departmentsBySlug = [];
        foreach ($this->departments->getAll(true) as $department) {
            $departmentsBySlug[(string) ($department['slug'] ?? '')] = $department;
        }

        foreach (Departments::STARTER_DEPARTMENTS as $starter) {
            $slug = (string) $starter['slug'];
            $this->assertArrayHasKey($slug, $departmentsBySlug);
            $this->assertSame(1, (int) ($departmentsBySlug[$slug]['is_active'] ?? 0));
            $this->assertSame(0, (int) ($departmentsBySlug[$slug]['is_system'] ?? 1));
        }
        $this->assertSame('Leadership / Admin', (string) ($departmentsBySlug['admin']['name'] ?? ''));

        $permission = Database::queryOne("SELECT permission_key FROM permissions WHERE permission_key = 'org.departments.manage'");
        $this->assertNotNull($permission);

        $adminGrant = Database::queryOne(
            "SELECT rp.permission_id
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = 'admin' AND p.permission_key = 'org.departments.manage' AND rp.can_access = 1"
        );
        $this->assertNotNull($adminGrant);
    }

    public function testCreateAndSyncUserDepartmentKeepsLegacyRoleSafe(): void
    {
        $departmentId = $this->departments->create([
            'name' => 'Client Experience',
            'slug' => 'client_experience',
            'description' => 'Post-sale customer team',
            'is_active' => true,
        ]);

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('dept-user-', true), 'dept-user@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $this->departments->syncUserDepartment($userId, $departmentId);

        $user = Database::queryOne("SELECT department_id, role FROM users WHERE id = ?", [$userId]);
        $this->assertSame($departmentId, (int) $user['department_id']);
        $this->assertSame('viewer', $user['role']);
    }

    public function testDepartmentsAreScopedByWorkspace(): void
    {
        $this->ensureWorkspace(2, 'department-scope-two', 'Department Scope Two');

        WorkspaceContext::activateRuntimeWorkspace(1);
        $workspaceOneDepartmentId = $this->departments->create([
            'name' => 'Client Experience',
            'slug' => 'client_experience',
            'description' => 'Workspace one success team',
            'is_active' => true,
        ]);

        WorkspaceContext::activateRuntimeWorkspace(2);
        $workspaceTwoDepartmentId = $this->departments->create([
            'name' => 'Client Experience',
            'slug' => 'client_experience',
            'description' => 'Workspace two success team',
            'is_active' => true,
        ]);

        $this->assertNotSame($workspaceOneDepartmentId, $workspaceTwoDepartmentId);
        $this->assertNull($this->departments->getById($workspaceOneDepartmentId));
        $this->assertSame($workspaceTwoDepartmentId, (int) ($this->departments->getById($workspaceTwoDepartmentId)['id'] ?? 0));

        $userId = $this->createUser('workspace-two-dept@example.test');
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status)
             VALUES (2, ?, 'viewer', 'active')",
            [$userId]
        );

        $this->departments->syncUserDepartment($userId, $workspaceTwoDepartmentId);
        $workspaceTwoDepartment = $this->departments->getById($workspaceTwoDepartmentId);
        $this->assertSame(1, (int) ($workspaceTwoDepartment['users_count'] ?? 0));

        WorkspaceContext::activateRuntimeWorkspace(1);
        $workspaceOneDepartment = $this->departments->getById($workspaceOneDepartmentId);
        $this->assertSame(0, (int) ($workspaceOneDepartment['users_count'] ?? -1));
    }

    public function testEnsureStarterDefaultsSeedsEditableDepartmentsForNewWorkspace(): void
    {
        $this->ensureWorkspace(3, 'department-starter-three', 'Department Starter Three');

        $this->departments->ensureStarterDefaults(3);

        $starterSlugs = array_map(static fn(array $starter): string => (string) $starter['slug'], Departments::STARTER_DEPARTMENTS);
        $rows = Database::query(
            "SELECT id, slug, name, is_active, is_system
             FROM departments
             WHERE workspace_id = 3
               AND slug IN (" . implode(',', array_fill(0, count($starterSlugs), '?')) . ")
             ORDER BY slug ASC",
            $starterSlugs
        );
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[(string) $row['slug']] = $row;
        }

        $this->assertCount(count($starterSlugs), $bySlug);
        foreach ($starterSlugs as $slug) {
            $this->assertSame(1, (int) ($bySlug[$slug]['is_active'] ?? 0));
            $this->assertSame(0, (int) ($bySlug[$slug]['is_system'] ?? 1));
        }

        $this->assertTrue($this->departments->delete((int) $bySlug['finance']['id'], 3));
        $this->assertNull(Database::queryOne("SELECT id FROM departments WHERE workspace_id = 3 AND slug = 'finance' LIMIT 1"));
    }

    public function testDepartmentWorkOwnershipSummaryAndMembersStayOptional(): void
    {
        $this->ensureWorkspace(4, 'department-ownership-four', 'Department Ownership Four');
        WorkspaceContext::activateRuntimeWorkspace(4);
        $departmentId = $this->departments->create([
            'name' => 'Sales Team',
            'slug' => 'sales',
            'description' => 'Revenue group',
            'is_active' => true,
        ]);
        $assignedUserId = $this->createUser('department-assigned@example.test');
        $missingUserId = $this->createUser('department-missing@example.test');
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, department_id, membership_status, joined_at)
             VALUES (4, ?, 'viewer', ?, 'active', NOW()), (4, ?, 'viewer', ?, 'active', NOW())",
            [$assignedUserId, $departmentId, $missingUserId, $departmentId]
        );

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults(4);
        $salesId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = 4 AND slug = 'sales' LIMIT 1"
        )['id'] ?? 0);
        $functionService->saveUserAssignments(4, $assignedUserId, [$salesId], $salesId, [$salesId => 'owner']);

        $summary = $this->departments->workOwnershipSummaryByDepartment(4);
        $members = $this->departments->membersWithWorkOwnership($departmentId, 4);
        $suggestions = $this->departments->suggestedWorkOwnershipForDepartment(['slug' => 'sales', 'name' => 'Sales Team'], 4);

        $this->assertSame(2, (int) ($summary[$departmentId]['active_members'] ?? 0));
        $this->assertSame(1, (int) ($summary[$departmentId]['assigned_members'] ?? 0));
        $this->assertSame(1, (int) ($summary[$departmentId]['missing_members'] ?? 0));
        $this->assertContains('Sales', (array) ($summary[$departmentId]['primary_areas'] ?? []));
        $this->assertCount(2, $members);
        $ownershipByEmail = [];
        foreach ($members as $member) {
            $ownershipByEmail[(string) ($member['email'] ?? '')] = (array) ($member['work_ownership'] ?? []);
        }
        $this->assertNotEmpty($ownershipByEmail['department-assigned@example.test']);
        $this->assertSame([], $ownershipByEmail['department-missing@example.test']);
        $this->assertSame(['sales'], array_map(static fn(array $function): string => (string) ($function['slug'] ?? ''), $suggestions));
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug)",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('dept-scope-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }
}
