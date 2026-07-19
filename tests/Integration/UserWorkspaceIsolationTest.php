<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class UserWorkspaceIsolationTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testWorkspaceOwnerListsOnlyWorkspaceMembersAndCannotSeeSuperAdmin(): void
    {
        $this->ensureWorkspace(2, 'user-scope-two', 'User Scope Two');

        $ownerUserId = $this->createUser('workspace-owner@example.test');
        $workspaceMemberId = $this->createUser('workspace-member@example.test');
        $foreignUserId = $this->createUser('foreign-member@example.test');
        $superAdminId = $this->createUser('super-admin@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $workspaceMemberId, 'viewer', false, $ownerUserId);
        $memberships->addOrUpdateMembership(2, $foreignUserId, 'admin', false, $foreignUserId);
        $memberships->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($workspaceMemberId, 1, 'viewer');
        $this->assignWorkspaceRole($foreignUserId, 2, 'admin');
        $this->assignGlobalRole($superAdminId, 'superadmin');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('workspace-member@example.test', $body);
        $this->assertStringNotContainsString('foreign-member@example.test', $body);
        $this->assertStringNotContainsString('super-admin@example.test', $body);

        $foreignView = $this->runWebEndpoint('public/user_view.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
            'query' => ['id' => $foreignUserId],
        ]);
        $superAdminView = $this->runWebEndpoint('public/user_view.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
            'query' => ['id' => $superAdminId],
        ]);

        $this->assertSame(302, (int) ($foreignView['status'] ?? 0));
        $this->assertSame(302, (int) ($superAdminView['status'] ?? 0));
    }

    public function testWorkspaceAdminWithUserPermissionsCannotAccessUsersArea(): void
    {
        $adminUserId = $this->createUser('blocked-workspace-admin@example.test');
        $targetUserId = $this->createUser('blocked-user-target@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $adminUserId, 'admin', false, $adminUserId);
        $memberships->addOrUpdateMembership(1, $targetUserId, 'viewer', false, $adminUserId);

        $this->assignWorkspaceRole($adminUserId, 1, 'admin');
        $this->assignWorkspaceRole($targetUserId, 1, 'viewer');

        foreach ([
            ['public/users.php', []],
            ['public/user_view.php', ['id' => $targetUserId]],
            ['public/user_create.php', []],
            ['public/user_edit.php', ['id' => $targetUserId]],
            ['public/user_delete.php', ['id' => $targetUserId]],
        ] as [$endpoint, $query]) {
            $response = $this->runWebEndpoint($endpoint, $this->workspaceSession($adminUserId, 1, 'admin'), [
                'method' => 'GET',
                'query' => $query,
            ]);

            $this->assertSame(302, (int) ($response['status'] ?? 0), $endpoint . ' should redirect non-owner admins away.');
        }
    }

    public function testCustomRoleWithUsersAccessPermissionCannotAccessUsersArea(): void
    {
        $customRoleId = $this->createRoleWithPermissions('users_access_custom', 'Users Access Custom', [
            'admin.users.access',
            'admin.users.manage',
            'admin.users.edit',
            'admin.users.delete',
        ]);
        $this->assertGreaterThan(0, $customRoleId);

        $actorUserId = $this->createUser('custom-users-access@example.test');
        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $actorUserId, 'member', false, $actorUserId);

        $this->assignGlobalRole($actorUserId, 'users_access_custom');
        $this->assignWorkspaceRole($actorUserId, 1, 'users_access_custom');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($actorUserId, 1, 'users_access_custom'), [
            'method' => 'GET',
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    public function testWorkspaceOwnerBulkDeleteRemovesSelectedUsersOnlyFromActiveWorkspace(): void
    {
        $this->ensureWorkspace(2, 'bulk-user-scope-two', 'Bulk User Scope Two');

        $ownerUserId = $this->createUser('bulk-workspace-owner@example.test');
        $firstMemberId = $this->createUser('bulk-member-one@example.test');
        $secondMemberId = $this->createUser('bulk-member-two@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $firstMemberId, 'viewer', false, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $secondMemberId, 'viewer', false, $ownerUserId);
        $memberships->addOrUpdateMembership(2, $firstMemberId, 'viewer', false, $ownerUserId);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($firstMemberId, 1, 'viewer');
        $this->assignWorkspaceRole($secondMemberId, 1, 'viewer');
        $this->assignWorkspaceRole($firstMemberId, 2, 'viewer');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'bulk_action' => 'delete_users',
                'selected_user_ids' => [$ownerUserId, $firstMemberId, $secondMemberId],
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('active', $this->membershipStatus(1, $ownerUserId));
        $this->assertSame('removed', $this->membershipStatus(1, $firstMemberId));
        $this->assertSame('removed', $this->membershipStatus(1, $secondMemberId));
        $this->assertSame('active', $this->membershipStatus(2, $firstMemberId));
        $this->assertNotNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$firstMemberId]));
        $this->assertNull(Database::queryOne('SELECT user_id FROM workspace_user_roles WHERE workspace_id = 1 AND user_id = ? LIMIT 1', [$firstMemberId]));
    }

    public function testOwnerUsersActionRoutesStillRequireActionPermissions(): void
    {
        $ownerUserId = $this->createUser('owner-page-only@example.test');
        $targetUserId = $this->createUser('owner-page-only-target@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $targetUserId, 'viewer', false, $ownerUserId);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($targetUserId, 1, 'viewer');
        $this->removeRolePermissions('owner', ['admin.users.manage', 'admin.users.edit', 'admin.users.delete']);

        $listResponse = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
        ]);
        $viewResponse = $this->runWebEndpoint('public/user_view.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
            'query' => ['id' => $targetUserId],
        ]);

        $this->assertSame(200, (int) ($listResponse['status'] ?? 0), (string) ($listResponse['stderr'] ?? ''));
        $this->assertSame(200, (int) ($viewResponse['status'] ?? 0), (string) ($viewResponse['stderr'] ?? ''));

        foreach ([
            ['public/user_create.php', []],
            ['public/user_edit.php', ['id' => $targetUserId]],
            ['public/user_delete.php', ['id' => $targetUserId]],
        ] as [$endpoint, $query]) {
            $response = $this->runWebEndpoint($endpoint, $this->workspaceSession($ownerUserId, 1, 'owner'), [
                'method' => 'GET',
                'query' => $query,
            ]);

            $this->assertSame(302, (int) ($response['status'] ?? 0), $endpoint . ' should still require its action permission.');
        }
    }

    public function testSuperAdminBulkDeleteDeletesSelectedUserAccounts(): void
    {
        $superAdminId = $this->createUser('bulk-super-admin@example.test');
        $targetUserId = $this->createUser('bulk-delete-target@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);
        $memberships->addOrUpdateMembership(1, $targetUserId, 'viewer', false, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignGlobalRole($targetUserId, 'admin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($targetUserId, 1, 'viewer');
        $this->deleteElevatedGlobalRolesExcept([$superAdminId, $targetUserId]);

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'bulk_action' => 'delete_users',
                'selected_user_ids' => [$superAdminId, $targetUserId],
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNotNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$superAdminId]));
        $this->assertNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$targetUserId]));
    }

    public function testSuperAdminBulkDeleteWithWorkspaceFilterRemovesOnlyThatMembership(): void
    {
        $this->ensureWorkspace(13, 'bulk-filter-workspace-a', 'Bulk Filter Workspace A');
        $this->ensureWorkspace(14, 'bulk-filter-workspace-b', 'Bulk Filter Workspace B');

        $superAdminId = $this->createUser('bulk-filter-superadmin@example.test');
        $targetUserId = $this->createUser('bulk-filter-target@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(13, $targetUserId, 'viewer', false, $superAdminId);
        $memberships->addOrUpdateMembership(14, $targetUserId, 'viewer', false, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($targetUserId, 13, 'viewer');
        $this->assignWorkspaceRole($targetUserId, 14, 'viewer');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'POST',
            'query' => ['workspace_id' => 13],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'bulk_action' => 'delete_users',
                'selected_user_ids' => [$targetUserId],
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNotNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$targetUserId]));
        $this->assertSame('removed', $this->membershipStatus(13, $targetUserId));
        $this->assertSame('active', $this->membershipStatus(14, $targetUserId));
        $this->assertNull(Database::queryOne('SELECT user_id FROM workspace_user_roles WHERE workspace_id = 13 AND user_id = ? LIMIT 1', [$targetUserId]));
        $this->assertNotNull(Database::queryOne('SELECT user_id FROM workspace_user_roles WHERE workspace_id = 14 AND user_id = ? LIMIT 1', [$targetUserId]));
    }

    public function testSuperAdminCanDeleteLastExactAdminAccountWhenSuperAdminRemains(): void
    {
        $superAdminId = $this->createUser('delete-super-admin@example.test');
        $adminUserId = $this->createUser('delete-last-exact-admin@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignGlobalRole($adminUserId, 'admin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->deleteElevatedGlobalRolesExcept([$superAdminId, $adminUserId]);

        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'admin'"
        )['c'] ?? 0));

        $response = $this->runWebEndpoint('public/user_delete.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'POST',
            'query' => ['id' => $adminUserId],
            'post' => [
                'csrf_token' => 'test-csrf-token',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNotNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$superAdminId]));
        $this->assertNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$adminUserId]));
    }

    public function testDeletePageBlocksFinalElevatedAdminAccount(): void
    {
        $superAdminId = $this->createUser('delete-final-superadmin@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->deleteElevatedGlobalRolesExcept([$superAdminId]);

        $response = $this->runWebEndpoint('public/user_delete.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'GET',
            'query' => ['id' => $superAdminId],
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Cannot delete the last admin or super admin account.', $body);
        $this->assertStringContainsString('disabled', $body);
    }

    public function testSuperAdminDeleteWithWorkspaceContextRemovesOnlyThatMembership(): void
    {
        $this->ensureWorkspace(13, 'delete-context-workspace-a', 'Delete Context Workspace A');
        $this->ensureWorkspace(14, 'delete-context-workspace-b', 'Delete Context Workspace B');

        $superAdminId = $this->createUser('delete-context-superadmin@example.test');
        $targetUserId = $this->createUser('delete-context-target@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(13, $targetUserId, 'viewer', false, $superAdminId);
        $memberships->addOrUpdateMembership(14, $targetUserId, 'viewer', false, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($targetUserId, 13, 'viewer');
        $this->assignWorkspaceRole($targetUserId, 14, 'viewer');

        $response = $this->runWebEndpoint('public/user_delete.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'POST',
            'query' => ['id' => $targetUserId, 'workspace_id' => 13],
            'post' => [
                'csrf_token' => 'test-csrf-token',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNotNull(Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$targetUserId]));
        $this->assertSame('removed', $this->membershipStatus(13, $targetUserId));
        $this->assertSame('active', $this->membershipStatus(14, $targetUserId));
        $this->assertNull(Database::queryOne('SELECT user_id FROM workspace_user_roles WHERE workspace_id = 13 AND user_id = ? LIMIT 1', [$targetUserId]));
    }

    public function testWorkspaceOwnerCannotDirectlyAddUserToWorkspace(): void
    {
        $this->ensureWorkspace(2, 'create-reuse-workspace-two', 'Create Reuse Workspace Two');

        $ownerUserId = $this->createUser('create-reuse-owner@example.test');
        $existingUserId = $this->createUser('create-reuse-existing@example.test');
        $departmentId = $this->ensureDepartment(1, 'Create Reuse Support', 'create_reuse_support');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(2, $existingUserId, 'viewer', false, $ownerUserId);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($existingUserId, 2, 'viewer');

        $response = $this->runWebEndpoint('public/user_create.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'POST',
            'post' => array_merge([
                'csrf_token' => 'test-csrf-token',
                'email' => 'create-reuse-existing@example.test',
                'password' => 'ShouldNotReplace1!',
                'first_name' => 'ShouldNotReplace',
                'last_name' => 'Identity',
                'department_id' => $departmentId,
                'access_role_id' => $this->roleId('viewer'),
            ], $this->functionPostData(1, 'viewer', false)),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM users WHERE LOWER(TRIM(email)) = ?',
            ['create-reuse-existing@example.test']
        )['c'] ?? 0));
        $this->assertSame('', $this->membershipStatus(1, $existingUserId));
        $this->assertSame('active', $this->membershipStatus(2, $existingUserId));
        $user = Database::queryOne('SELECT first_name, password_hash FROM users WHERE id = ? LIMIT 1', [$existingUserId]) ?? [];
        $this->assertSame('create-reuse-existing', (string) ($user['first_name'] ?? ''));
        $this->assertTrue(password_verify('secret', (string) ($user['password_hash'] ?? '')));
        $this->assertFalse(password_verify('ShouldNotReplace1!', (string) ($user['password_hash'] ?? '')));
    }

    public function testAddUserRejectsEmailAlreadyActiveInWorkspace(): void
    {
        $superAdminId = $this->createUser('create-existing-superadmin@example.test');
        $existingUserId = $this->createUser('create-existing-member@example.test');
        $departmentId = $this->ensureDepartment(1, 'Create Existing Support', 'create_existing_support');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(1, $existingUserId, 'viewer', false, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($existingUserId, 1, 'viewer');

        $response = $this->runWebEndpoint('public/user_create.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'POST',
            'post' => array_merge([
                'csrf_token' => 'test-csrf-token',
                'email' => 'create-existing-member@example.test',
                'department_id' => $departmentId,
                'access_role_id' => $this->roleId('viewer'),
            ], $this->functionPostData(1, 'viewer', false)),
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('That user already belongs to this workspace.', $body);
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = 1
               AND user_id = ?",
            [$existingUserId]
        )['c'] ?? 0));
    }

    public function testAddUserAllowsNoDepartmentWhenWorkOwnershipIsAssigned(): void
    {
        $superAdminId = $this->createUser('create-unassigned-superadmin@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');

        $response = $this->runWebEndpoint('public/user_create.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'POST',
            'post' => array_merge([
                'csrf_token' => 'test-csrf-token',
                'email' => 'create-unassigned-member@example.test',
                'password' => 'P@ssword123!',
                'first_name' => 'No',
                'last_name' => 'Department',
                'department_id' => '',
                'access_role_id' => $this->roleId('viewer'),
                'verify_immediately' => '1',
            ], $this->functionPostData(1, 'viewer', false)),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $createdUser = Database::queryOne(
            "SELECT id, role, department_id FROM users WHERE email = ? LIMIT 1",
            ['create-unassigned-member@example.test']
        ) ?? [];
        $createdUserId = (int) ($createdUser['id'] ?? 0);
        $membership = Database::queryOne(
            "SELECT role_slug, department_id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$createdUserId]
        ) ?? [];
        $assignmentCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM user_function_assignments WHERE workspace_id = 1 AND user_id = ?",
            [$createdUserId]
        )['c'] ?? 0);

        $this->assertGreaterThan(0, $createdUserId);
        $this->assertSame('viewer', (string) ($createdUser['role'] ?? ''));
        $this->assertNull($createdUser['department_id'] ?? null);
        $this->assertSame('viewer', (string) ($membership['role_slug'] ?? ''));
        $this->assertNull($membership['department_id'] ?? null);
        $this->assertGreaterThan(0, $assignmentCount);
    }

    public function testAddUserStillRequiresWorkOwnershipWithoutDepartment(): void
    {
        $superAdminId = $this->createUser('create-requires-ownership-superadmin@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');

        $response = $this->runWebEndpoint('public/user_create.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'email' => 'create-requires-ownership-member@example.test',
                'password' => 'P@ssword123!',
                'first_name' => 'Needs',
                'last_name' => 'Ownership',
                'department_id' => '',
                'access_role_id' => $this->roleId('viewer'),
                'verify_immediately' => '1',
            ],
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Assign at least one work ownership function.', $body);
        $this->assertNull(Database::queryOne(
            "SELECT id FROM users WHERE email = ? LIMIT 1",
            ['create-requires-ownership-member@example.test']
        ));
    }

    public function testEditUserAllowsNoDepartmentAndShowsWorkOwnership(): void
    {
        $ownerUserId = $this->createUser('edit-unassigned-owner@example.test');
        $targetUserId = $this->createUser('edit-unassigned-member@example.test');
        $departmentId = $this->ensureDepartment(1, 'Legacy Placement', 'legacy_placement');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $targetUserId, 'viewer', false, $ownerUserId);
        Database::execute("UPDATE workspace_memberships SET department_id = ? WHERE workspace_id = 1 AND user_id = ?", [$departmentId, $targetUserId]);
        Database::execute("UPDATE users SET department_id = ? WHERE id = ?", [$departmentId, $targetUserId]);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($targetUserId, 1, 'viewer');

        $response = $this->runWebEndpoint('public/user_edit.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'POST',
            'query' => ['id' => $targetUserId],
            'post' => array_merge([
                'csrf_token' => 'test-csrf-token',
                'first_name' => 'Edit',
                'last_name' => 'Unassigned',
                'email' => 'edit-unassigned-member@example.test',
                'department_id' => '',
                'access_role_id' => $this->roleId('viewer'),
                'mark_email_verified' => '1',
            ], $this->functionPostData(1, 'viewer', false)),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $membership = Database::queryOne(
            "SELECT role_slug, department_id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$targetUserId]
        ) ?? [];
        $this->assertSame('viewer', (string) ($membership['role_slug'] ?? ''));
        $this->assertNull($membership['department_id'] ?? null);

        $viewResponse = $this->runWebEndpoint('public/user_view.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
            'query' => ['id' => $targetUserId],
        ]);
        $viewBody = (string) ($viewResponse['body'] ?? '');
        $this->assertSame(200, (int) ($viewResponse['status'] ?? 0), (string) ($viewResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Unassigned', $viewBody);
        $this->assertStringContainsString('Work Ownership', $viewBody);
        $this->assertStringContainsString('Operations', $viewBody);

        $listResponse = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
            'query' => ['search' => 'edit-unassigned-member@example.test'],
        ]);
        $listBody = (string) ($listResponse['body'] ?? '');
        $this->assertSame(200, (int) ($listResponse['status'] ?? 0), (string) ($listResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Work Ownership', $listBody);
        $this->assertStringContainsString('Unassigned', $listBody);
        $this->assertStringContainsString('Operations', $listBody);
    }

    public function testSuperAdminUsersPageLinksSingleWorkspaceRowsWithWorkspaceContext(): void
    {
        $this->ensureWorkspace(13, 'single-row-workspace', 'Single Row Workspace');

        $superAdminId = $this->createUser('users-row-superadmin@example.test');
        $targetUserId = $this->createUser('users-row-single@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(13, $targetUserId, 'viewer', false, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($targetUserId, 13, 'viewer');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'GET',
            'query' => ['search' => 'users-row-single@example.test'],
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('user_view.php?id=' . $targetUserId . '&amp;workspace_id=13', $body);
        $this->assertStringContainsString('user_edit.php?id=' . $targetUserId . '&amp;workspace_id=13', $body);
    }

    public function testUsersPageRepairsOwnerWorkOwnershipForSingleWorkspaceRows(): void
    {
        $this->ensureWorkspace(15, 'owner-repair-workspace', 'Owner Repair Workspace');

        $superAdminId = $this->createUser('users-owner-repair-superadmin@example.test');
        $targetOwnerId = $this->createUser('users-owner-repair-owner@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(15, $targetOwnerId, 'owner', true, $superAdminId);
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id IN (1, 15) AND user_id = ?", [$targetOwnerId]);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($targetOwnerId, 15, 'owner');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'GET',
            'query' => ['search' => 'users-owner-repair-owner@example.test'],
        ]);

        $body = (string) ($response['body'] ?? '');
        $functionService = new OrganizationFunctionService();
        $workspaceAssignments = $functionService->assignmentsForUser(15, $targetOwnerId, true);
        $defaultWorkspaceAssignments = $functionService->assignmentsForUser(1, $targetOwnerId, true);
        $assignmentsBySlug = [];
        foreach ($workspaceAssignments as $assignment) {
            $assignmentsBySlug[(string) ($assignment['slug'] ?? '')] = $assignment;
        }

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('users-owner-repair-owner@example.test', $body);
        $this->assertStringContainsString('Leadership', $body);
        $this->assertStringNotContainsString('Missing ownership', $body);
        $this->assertCount(count($functionService->listAssignableFunctions(15)), $workspaceAssignments);
        $this->assertTrue((bool) ($assignmentsBySlug['leadership']['is_primary'] ?? false));
        foreach ($workspaceAssignments as $assignment) {
            $this->assertSame('owner', (string) ($assignment['assignment_type'] ?? ''));
        }
        $this->assertSame([], $defaultWorkspaceAssignments);
    }

    public function testSuperAdminUsersPageRequiresWorkspaceForAmbiguousOrPlatformOnlyRows(): void
    {
        $this->ensureWorkspace(13, 'multi-row-workspace-a', 'Multi Row Workspace A');
        $this->ensureWorkspace(14, 'multi-row-workspace-b', 'Multi Row Workspace B');

        $superAdminId = $this->createUser('users-row-context-superadmin@example.test');
        $multiWorkspaceUserId = $this->createUser('users-row-multi@example.test');
        $platformOnlyUserId = $this->createUser('users-row-platform-only@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(13, $multiWorkspaceUserId, 'viewer', false, $superAdminId);
        $memberships->addOrUpdateMembership(14, $multiWorkspaceUserId, 'viewer', false, $superAdminId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');
        $this->assignWorkspaceRole($multiWorkspaceUserId, 13, 'viewer');
        $this->assignWorkspaceRole($multiWorkspaceUserId, 14, 'viewer');

        $multiResponse = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'GET',
            'query' => ['search' => 'users-row-multi@example.test'],
        ]);
        $multiBody = (string) ($multiResponse['body'] ?? '');

        $this->assertSame(200, (int) ($multiResponse['status'] ?? 0), (string) ($multiResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Select workspace', $multiBody);
        $this->assertStringNotContainsString('Missing ownership', $multiBody);
        $this->assertStringNotContainsString('user_edit.php?id=' . $multiWorkspaceUserId, $multiBody);

        $platformOnlyResponse = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'GET',
            'query' => ['search' => 'users-row-platform-only@example.test'],
        ]);
        $platformOnlyBody = (string) ($platformOnlyResponse['body'] ?? '');

        $this->assertSame(200, (int) ($platformOnlyResponse['status'] ?? 0), (string) ($platformOnlyResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Platform only', $platformOnlyBody);
        $this->assertStringContainsString('Select workspace', $platformOnlyBody);
        $this->assertStringNotContainsString('user_edit.php?id=' . $platformOnlyUserId, $platformOnlyBody);
    }

    public function testUsersPageUsesOnlyActiveWorkspaceDepartments(): void
    {
        $this->ensureWorkspace(2, 'department-user-scope-two', 'Department User Scope Two');

        $ownerUserId = $this->createUser('department-owner@example.test');
        $workspaceMemberId = $this->createUser('department-member@example.test');
        $foreignUserId = $this->createUser('department-foreign@example.test');
        $localDepartmentId = $this->ensureDepartment(1, 'Local HR', 'local_hr');
        $foreignDepartmentId = $this->ensureDepartment(2, 'Foreign HR', 'foreign_hr');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $workspaceMemberId, 'viewer', false, $ownerUserId);
        $memberships->addOrUpdateMembership(2, $foreignUserId, 'viewer', false, $foreignUserId);

        Database::execute("UPDATE workspace_memberships SET department_id = ? WHERE workspace_id = 1 AND user_id = ?", [$localDepartmentId, $workspaceMemberId]);
        Database::execute("UPDATE workspace_memberships SET department_id = ? WHERE workspace_id = 2 AND user_id = ?", [$foreignDepartmentId, $foreignUserId]);
        Database::execute("UPDATE users SET department_id = ? WHERE id = ?", [$localDepartmentId, $workspaceMemberId]);
        Database::execute("UPDATE users SET department_id = ? WHERE id = ?", [$foreignDepartmentId, $foreignUserId]);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($workspaceMemberId, 1, 'viewer');
        $this->assignWorkspaceRole($foreignUserId, 2, 'viewer');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Local HR', $body);
        $this->assertStringNotContainsString('Foreign HR', $body);
        $this->assertStringNotContainsString('department-foreign@example.test', $body);
    }

    public function testMissingAccessProfileWarningUsesActiveWorkspaceOnly(): void
    {
        $this->ensureWorkspace(2, 'missing-profile-scope-two', 'Missing Profile Scope Two');

        $ownerUserId = $this->createUser('missing-profile-owner@example.test');
        $workspaceMemberId = $this->createUser('missing-profile-member@example.test');
        $foreignUserId = $this->createUser('missing-profile-foreign@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $workspaceMemberId, 'viewer', false, $ownerUserId);
        $memberships->addOrUpdateMembership(2, $foreignUserId, 'viewer', false, $foreignUserId);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($workspaceMemberId, 1, 'viewer');
        $this->assignWorkspaceRole($foreignUserId, 2, 'viewer');
        Database::execute('DELETE FROM workspace_user_roles WHERE workspace_id = 2 AND user_id = ?', [$foreignUserId]);

        $withoutLocalMissing = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
        ]);
        $this->assertSame(200, (int) ($withoutLocalMissing['status'] ?? 0), (string) ($withoutLocalMissing['stderr'] ?? ''));
        $this->assertStringNotContainsString('in this workspace still have no access profile assigned', (string) ($withoutLocalMissing['body'] ?? ''));

        Database::execute('DELETE FROM workspace_user_roles WHERE workspace_id = 1 AND user_id = ?', [$workspaceMemberId]);
        $withLocalMissing = $this->runWebEndpoint('public/users.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'GET',
        ]);

        $body = (string) ($withLocalMissing['body'] ?? '');
        $this->assertSame(200, (int) ($withLocalMissing['status'] ?? 0), (string) ($withLocalMissing['stderr'] ?? ''));
        $this->assertStringContainsString('1 user in this workspace still have no access profile assigned', $body);
        $this->assertStringNotContainsString('missing-profile-foreign@example.test', $body);
    }

    public function testUsersPageHidesProtectedDemoGuestUsersFromRealSystemMenus(): void
    {
        $superAdminId = $this->createUser('hide-demo-guests-superadmin@example.test');
        $sessionUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        Database::execute(
            "INSERT INTO users
                (uuid, first_name, last_name, email, password_hash, role, is_demo_guest, demo_expires_at, demo_session_uuid, email_verified_at, created_at)
             VALUES (UUID(), 'Hidden', 'Demo', 'demo-hidden-user@example.invalid', ?, 'viewer', 1, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?, NOW(), NOW())",
            [password_hash('hidden-demo-user', PASSWORD_DEFAULT), $sessionUuid]
        );
        $demoGuestId = (int) Database::lastInsertId();

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'superadmin', true, $superAdminId);
        $memberships->addOrUpdateMembership(1, $demoGuestId, 'demo_viewer', false, null);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'superadmin');

        $response = $this->runWebEndpoint('public/users.php', $this->workspaceSession($superAdminId, 1, 'superadmin'), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringNotContainsString('demo-hidden-user@example.invalid', $body);
        $this->assertStringNotContainsString('in this workspace still have no access profile assigned', $body);
    }

    public function testSuperAdminEditRequiresExplicitWorkspaceContext(): void
    {
        $this->ensureWorkspace(13, 'pick-and-go-limited-edit-required', 'Pick And Go Limited Edit Required');

        $superAdminId = $this->createUser('edit-required-superadmin@example.test');
        $targetUserId = $this->createUser('edit-required-target@example.test');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);
        $memberships->addOrUpdateMembership(13, $targetUserId, 'owner', true, $targetUserId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'owner');
        $this->assignWorkspaceRole($targetUserId, 13, 'owner');

        $response = $this->runWebEndpoint('public/user_edit.php', $this->workspaceSession($superAdminId, 1, 'owner'), [
            'method' => 'POST',
            'query' => ['id' => $targetUserId],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'first_name' => 'Required',
                'last_name' => 'Target',
                'email' => 'edit-required-target@example.test',
                'department_id' => $this->ensureDepartment(13, 'Edit Required Sales', 'edit_required_sales'),
                'access_role_id' => $this->roleId('owner'),
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNull(Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$targetUserId]
        ));
    }

    public function testSuperAdminEditUpdatesOnlyExplicitWorkspaceMembership(): void
    {
        $this->ensureWorkspace(13, 'pick-and-go-limited-edit', 'Pick And Go Limited Edit');

        $superAdminId = $this->createUser('edit-superadmin@example.test');
        $targetUserId = $this->createUser('edit-pick-and-go-owner@example.test');
        $departmentId = $this->ensureDepartment(13, 'Pick And Go Sales', 'pick_go_sales_edit');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);
        $memberships->addOrUpdateMembership(13, $targetUserId, 'viewer', false, $targetUserId);

        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'owner');
        $this->assignWorkspaceRole($targetUserId, 13, 'viewer');

        $response = $this->runWebEndpoint('public/user_edit.php', $this->workspaceSession($superAdminId, 1, 'owner'), [
            'method' => 'POST',
            'query' => ['id' => $targetUserId, 'workspace_id' => 13],
            'post' => array_merge([
                'csrf_token' => 'test-csrf-token',
                'workspace_id' => 13,
                'first_name' => 'Pick',
                'last_name' => 'Owner',
                'email' => 'edit-pick-and-go-owner@example.test',
                'department_id' => $departmentId,
                'access_role_id' => $this->roleId('owner'),
                'mark_email_verified' => '1',
            ], $this->functionPostData(13, 'owner', true)),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertNull(Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$targetUserId]
        ));

        $membership = Database::queryOne(
            "SELECT role_slug, is_owner, department_id FROM workspace_memberships WHERE workspace_id = 13 AND user_id = ? LIMIT 1",
            [$targetUserId]
        ) ?? [];
        $this->assertSame('owner', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame(1, (int) ($membership['is_owner'] ?? 0));
        $this->assertSame($departmentId, (int) ($membership['department_id'] ?? 0));
        $this->assertSame('owner', $this->workspaceRoleSlug($targetUserId, 13));
    }

    public function testDefaultWorkspaceSuperAdminEditPreservesOwnerMembershipWhenNoWorkspaceAccessProfilePosted(): void
    {
        $actorUserId = $this->createUser('default-edit-actor@example.test');
        $targetUserId = $this->createUser('default-edit-superadmin@example.test');
        $departmentId = $this->ensureDepartment(1, 'Default Workspace Admin', 'default_workspace_admin');
        $organizationFunctions = new OrganizationFunctionService();
        $organizationFunctions->ensureDefaults(1);
        $functionIds = $organizationFunctions->defaultFunctionIdsForRole(1, 'superadmin', true);

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $actorUserId, 'superadmin', true, $actorUserId);
        $memberships->addOrUpdateMembership(1, $targetUserId, 'viewer', false, $actorUserId);

        $this->assignGlobalRole($actorUserId, 'superadmin');
        $this->assignGlobalRole($targetUserId, 'superadmin');
        $this->assignWorkspaceRole($actorUserId, 1, 'superadmin');

        $response = $this->runWebEndpoint('public/user_edit.php', $this->workspaceSession($actorUserId, 1, 'superadmin'), [
            'method' => 'POST',
            'query' => ['id' => $targetUserId, 'workspace_id' => 1],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'workspace_id' => 1,
                'first_name' => 'Default',
                'last_name' => 'Superadmin',
                'email' => 'default-edit-superadmin@example.test',
                'department_id' => $departmentId,
                'access_role_id' => 0,
                'function_ids' => $functionIds,
                'primary_function_id' => (int) ($functionIds[0] ?? 0),
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $membership = Database::queryOne(
            "SELECT role_slug, is_owner, department_id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$targetUserId]
        ) ?? [];
        $this->assertSame('superadmin', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame(1, (int) ($membership['is_owner'] ?? 0));
        $this->assertSame($departmentId, (int) ($membership['department_id'] ?? 0));
        $this->assertSame('superadmin', $this->workspaceRoleSlug($targetUserId, 1));
    }

    public function testWorkspaceOwnerEditUsesActiveWorkspaceMembership(): void
    {
        $ownerUserId = $this->createUser('workspace-edit-owner@example.test');
        $targetUserId = $this->createUser('workspace-edit-target@example.test');
        $departmentId = $this->ensureDepartment(1, 'Workspace Edit Support', 'workspace_edit_support');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $targetUserId, 'viewer', false, $ownerUserId);

        $this->assignWorkspaceRole($ownerUserId, 1, 'owner');
        $this->assignWorkspaceRole($targetUserId, 1, 'viewer');

        $response = $this->runWebEndpoint('public/user_edit.php', $this->workspaceSession($ownerUserId, 1, 'owner'), [
            'method' => 'POST',
            'query' => ['id' => $targetUserId],
            'post' => array_merge([
                'csrf_token' => 'test-csrf-token',
                'first_name' => 'Workspace',
                'last_name' => 'Target',
                'email' => 'workspace-edit-target@example.test',
                'department_id' => $departmentId,
                'access_role_id' => $this->roleId('viewer'),
                'mark_email_verified' => '1',
            ], $this->functionPostData(1, 'viewer', false)),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $membership = Database::queryOne(
            "SELECT role_slug, is_owner, department_id FROM workspace_memberships WHERE workspace_id = 1 AND user_id = ? LIMIT 1",
            [$targetUserId]
        ) ?? [];
        $this->assertSame('viewer', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame(0, (int) ($membership['is_owner'] ?? 1));
        $this->assertSame($departmentId, (int) ($membership['department_id'] ?? 0));
        $this->assertSame('viewer', $this->workspaceRoleSlug($targetUserId, 1));
    }

    public function testHrSettingsEndpointUsesActiveWorkspace(): void
    {
        $this->ensureWorkspace(2, 'hr-settings-scope-two', 'HR Settings Scope Two');

        $superAdminId = $this->createUser('hr-settings-superadmin@example.test');
        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $superAdminId, 'owner', true, $superAdminId);
        $memberships->addOrUpdateMembership(2, $superAdminId, 'owner', true, $superAdminId);
        $this->assignGlobalRole($superAdminId, 'superadmin');
        $this->assignWorkspaceRole($superAdminId, 1, 'owner');
        $this->assignWorkspaceRole($superAdminId, 2, 'owner');

        $workspaceOnePost = $this->runWebEndpoint('api/hr/settings.php', $this->workspaceSession($superAdminId, 1, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'thresholds' => ['high_performer' => 77],
            ],
        ]);
        $workspaceTwoPost = $this->runWebEndpoint('api/hr/settings.php', $this->workspaceSession($superAdminId, 2, 'owner'), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'thresholds' => ['high_performer' => 91],
            ],
        ]);

        $this->assertSame(200, (int) ($workspaceOnePost['status'] ?? 0), (string) ($workspaceOnePost['stderr'] ?? ''));
        $this->assertSame(200, (int) ($workspaceTwoPost['status'] ?? 0), (string) ($workspaceTwoPost['stderr'] ?? ''));

        $workspaceOneGet = $this->runWebEndpoint('api/hr/settings.php', $this->workspaceSession($superAdminId, 1, 'owner'), [
            'method' => 'GET',
        ]);
        $workspaceTwoGet = $this->runWebEndpoint('api/hr/settings.php', $this->workspaceSession($superAdminId, 2, 'owner'), [
            'method' => 'GET',
        ]);

        $workspaceOnePayload = json_decode((string) ($workspaceOneGet['body'] ?? ''), true);
        $workspaceTwoPayload = json_decode((string) ($workspaceTwoGet['body'] ?? ''), true);

        $this->assertSame(77, (int) ($workspaceOnePayload['settings']['thresholds']['high_performer'] ?? 0));
        $this->assertSame(91, (int) ($workspaceTwoPayload['settings']['thresholds']['high_performer'] ?? 0));
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, first_name, email, password_hash, role, created_at)
             VALUES (?, ?, ?, ?, 'admin', NOW())",
            [uniqid('user-scope-', true), strtok($email, '@'), $email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function membershipStatus(int $workspaceId, int $userId): string
    {
        $row = Database::queryOne(
            "SELECT membership_status FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return (string) ($row['membership_status'] ?? '');
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
    }

    private function assignWorkspaceRole(int $userId, int $workspaceId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        Database::execute(
            "INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$workspaceId, $userId, (int) ($role['id'] ?? 0), $userId]
        );
    }

    /**
     * @param int[] $userIds
     */
    private function deleteElevatedGlobalRolesExcept(array $userIds): void
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), static fn(int $userId): bool => $userId > 0));
        if ($userIds === []) {
            Database::execute(
                "DELETE ur
                 FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE r.slug IN ('admin', 'superadmin')"
            );
            return;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        Database::execute(
            "DELETE ur
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug IN ('admin', 'superadmin')
               AND ur.user_id NOT IN ({$placeholders})",
            $userIds
        );
    }

    private function roleId(string $roleSlug): int
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        return (int) ($role['id'] ?? 0);
    }

    /**
     * @param string[] $permissionKeys
     */
    private function createRoleWithPermissions(string $roleSlug, string $roleName, array $permissionKeys): int
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES (?, ?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1",
            [$roleName, $roleSlug, $roleName . ' test role']
        );
        $roleId = $this->roleId($roleSlug);
        if ($roleId <= 0) {
            return 0;
        }

        Database::execute("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
        if ($permissionKeys === []) {
            return $roleId;
        }

        $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             SELECT ?, id, 1
             FROM permissions
             WHERE permission_key IN ({$placeholders})",
            array_merge([$roleId], $permissionKeys)
        );

        return $roleId;
    }

    /**
     * @param string[] $permissionKeys
     */
    private function removeRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        if ($permissionKeys === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($permissionKeys), '?'));
        Database::execute(
            "DELETE rp
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = ?
               AND p.permission_key IN ({$placeholders})",
            array_merge([$roleSlug], $permissionKeys)
        );
    }

    private function workspaceRoleSlug(int $userId, int $workspaceId): string
    {
        $row = Database::queryOne(
            "SELECT r.slug
             FROM workspace_user_roles wur
             JOIN roles r ON r.id = wur.role_id
             WHERE wur.workspace_id = ?
               AND wur.user_id = ?
             LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return (string) ($row['slug'] ?? '');
    }

    /**
     * @return array<string,mixed>
     */
    private function functionPostData(int $workspaceId, string $roleSlug = 'viewer', bool $isOwner = false): array
    {
        $organizationFunctions = new OrganizationFunctionService();
        $organizationFunctions->ensureDefaults($workspaceId);
        $functionIds = $organizationFunctions->defaultFunctionIdsForRole($workspaceId, $roleSlug, $isOwner);

        return [
            'function_ids' => $functionIds,
            'primary_function_id' => (int) ($functionIds[0] ?? 0),
        ];
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

    private function ensureDepartment(int $workspaceId, string $name, string $slug): int
    {
        Database::execute(
            "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
             VALUES (?, ?, ?, ?, 1, 0)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1",
            [$workspaceId, $name, $slug, $name . ' department']
        );

        $department = Database::queryOne(
            "SELECT id FROM departments WHERE workspace_id = ? AND slug = ? LIMIT 1",
            [$workspaceId, $slug]
        );

        return (int) ($department['id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSession(int $userId, int $workspaceId, string $roleSlug): array
    {
        $workspace = Database::queryOne("SELECT uuid, name, slug FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ?",
            [$workspaceId, $userId]
        ) ?? [];
        $user = Database::queryOne("SELECT uuid, email, role FROM users WHERE id = ?", [$userId]) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'admin'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => $roleSlug,
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'test-csrf-token',
        ];
    }
}
