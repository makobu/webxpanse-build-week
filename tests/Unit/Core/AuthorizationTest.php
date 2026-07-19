<?php

namespace CRM\Tests\Unit\Core;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class AuthorizationTest extends DatabaseTestCase
{
    private int $adminUserId;
    private int $ownerUserId;
    private int $assignedAdminUserId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-admin-', true), 'rbac-admin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->adminUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('auth-owner-', true), 'rbac-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->ownerUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-assigned-admin-', true), 'assigned-admin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->assignedAdminUserId = (int) Database::lastInsertId();

        Database::execute("DELETE FROM user_roles WHERE user_id = ?", [$this->adminUserId]);
        $ownerRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        Authorization::assignUserRole($this->ownerUserId, (int) ($ownerRole['id'] ?? 0), $this->ownerUserId);
        Authorization::assignUserRole($this->assignedAdminUserId, (int) ($adminRole['id'] ?? 0), $this->assignedAdminUserId);
    }

    protected function tearDown(): void
    {
        Session::destroy();
        $this->setAuthorizationRbacAvailable(null);
        parent::tearDown();
    }

    public function testRbacEnabledLegacyAdminWithoutAssignedRoleDoesNotBypassPermissions(): void
    {
        $this->authenticateAs($this->adminUserId);

        $this->assertFalse(Authorization::hasAssignedRole($this->adminUserId));
        $this->assertTrue(Authorization::isLegacyFallbackMode(Auth::user()));
        $this->assertFalse(Authorization::can('admin.users.manage', Auth::user()));
    }

    public function testRbacEnabledAssignedRoleGrantsPermissionRegardlessOfLegacyRole(): void
    {
        $this->authenticateAs($this->ownerUserId);

        $this->assertTrue(Authorization::hasAssignedRole($this->ownerUserId));
        $this->assertTrue(Authorization::can('admin.users.manage', Auth::user()));
    }

    public function testOwnerRoleReceivesMobileTaskAndNotificationViewAllPermissions(): void
    {
        $this->authenticateAs($this->ownerUserId);

        $this->assertTrue(Authorization::can('tasks.view_all', Auth::user()));
        $this->assertTrue(Authorization::can('notifications.view_all', Auth::user()));
        $this->assertTrue(Authorization::can('events.view_all', Auth::user()));
    }

    public function testUsersPageAccessRequiresOwnerOrSuperadmin(): void
    {
        $this->authenticateAs($this->ownerUserId);
        $this->assertTrue(Authorization::canAccessUsersPage(Auth::user()));

        $this->authenticateAs($this->assignedAdminUserId);
        $this->assertTrue(Authorization::can('admin.users.manage', Auth::user()));
        $this->assertFalse(Authorization::canAccessUsersPage(Auth::user()));

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-users-superadmin-', true), 'users-page-superadmin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $superadminUserId = (int) Database::lastInsertId();
        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Authorization::assignUserRole($superadminUserId, (int) ($superadminRole['id'] ?? 0), $superadminUserId);

        $this->authenticateAs($superadminUserId);
        $this->assertTrue(Authorization::canAccessUsersPage(Auth::user()));
    }

    public function testCustomRoleWithUsersAccessPermissionStillCannotOpenUsersPage(): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES ('Custom Users Access', 'custom_users_access', 'Custom users access test role', 0, 1)"
        );
        $roleId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             SELECT ?, id, 1
             FROM permissions
             WHERE permission_key IN ('admin.users.access', 'admin.users.manage', 'admin.users.edit', 'admin.users.delete')",
            [$roleId]
        );
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-custom-users-access-', true), 'custom-users-access@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $customUserId = (int) Database::lastInsertId();
        Authorization::assignUserRole($customUserId, $roleId, $customUserId);

        $this->authenticateAs($customUserId);

        $this->assertTrue(Authorization::can('admin.users.access', Auth::user()));
        $this->assertFalse(Authorization::canAccessUsersPage(Auth::user()));
    }

    public function testRbacUnavailableFallsBackToLegacyAdminCompatibility(): void
    {
        $this->authenticateAs($this->adminUserId);
        $this->setAuthorizationRbacAvailable(false);

        $this->assertFalse(Authorization::isRbacAvailable());
        $this->assertTrue(Authorization::can('admin.users.manage', Auth::user()));
        $this->assertTrue(Authorization::canAccessUsersPage(Auth::user()));
    }

    public function testGetAssignablePermissionsReturnsOnlyCurrentUsersAssignablePermissions(): void
    {
        $this->authenticateAs($this->ownerUserId);

        $assignablePermissions = Authorization::getAssignablePermissions(Auth::user());
        $assignableKeys = array_column($assignablePermissions, 'permission_key');
        $allKeys = array_column(Database::query("SELECT permission_key FROM permissions ORDER BY permission_key ASC"), 'permission_key');
        $disallowedKeys = array_values(array_diff($allKeys, $assignableKeys));

        $this->assertNotEmpty($assignablePermissions);
        $this->assertContains('admin.users.manage', $assignableKeys);
        $this->assertNotEmpty($disallowedKeys, 'Expected at least one permission to remain unassignable.');
        $this->assertNotContains($disallowedKeys[0], $assignableKeys);
    }

    public function testCanAssignPermissionIdsRejectsPermissionsOutsideAssignableSet(): void
    {
        $this->authenticateAs($this->ownerUserId);

        $assignablePermissions = Authorization::getAssignablePermissions(Auth::user());
        $assignableIds = array_map(static fn (array $permission): int => (int) $permission['id'], $assignablePermissions);
        $this->assertNotEmpty($assignableIds);

        $placeholders = implode(',', array_fill(0, count($assignableIds), '?'));
        $disallowedPermission = Database::queryOne(
            "SELECT id FROM permissions WHERE id NOT IN ($placeholders) LIMIT 1",
            $assignableIds
        );

        $this->assertTrue(Authorization::canAssignPermissionIds([$assignableIds[0]], Auth::user()));
        $this->assertNotEmpty($disallowedPermission['id'] ?? null, 'Expected at least one disallowed permission.');
        $this->assertFalse(Authorization::canAssignPermissionIds([$assignableIds[0], (int) $disallowedPermission['id']], Auth::user()));
    }

    public function testAssignedAdminCanAssignOwnerButNotSuperadminRole(): void
    {
        $this->authenticateAs($this->assignedAdminUserId);

        $assignableRoles = Authorization::getAssignableRoles(Auth::user());
        $assignableSlugs = array_column($assignableRoles, 'slug');

        $this->assertContains('owner', $assignableSlugs);
        $this->assertNotContains('superadmin', $assignableSlugs);
    }

    public function testLegacyAdminOpsRoleCannotBypassCanonicalSuperadminNormalization(): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active) VALUES (?, ?, ?, 1, 1)",
            ['Legacy Super Admin', 'admin_ops', 'Legacy top-level role']
        );
        $legacyRoleId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-legacy-superadmin-', true), 'legacy-superadmin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $legacyUserId = (int) Database::lastInsertId();
        Authorization::assignUserRole($legacyUserId, $legacyRoleId, $legacyUserId);

        $this->authenticateAs($legacyUserId);

        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        $this->assertNotEmpty($adminRole['id'] ?? null);
        $this->assertFalse(Authorization::canAssignRole((int) $adminRole['id'], Auth::user()));

        $assignableRoles = Authorization::getAssignableRoles(Auth::user());
        $assignableSlugs = array_column($assignableRoles, 'slug');
        $this->assertNotContains('admin', $assignableSlugs);
    }

    public function testCanonicalSuperadminRoleCanAssignAdminRole(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-superadmin-', true), 'superadmin-assigner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $superadminUserId = (int) Database::lastInsertId();

        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        Authorization::assignUserRole($superadminUserId, (int) ($superadminRole['id'] ?? 0), $superadminUserId);

        $this->authenticateAs($superadminUserId);

        $this->assertTrue(Authorization::canAssignRole((int) ($adminRole['id'] ?? 0), Auth::user()));

        $assignableRoles = Authorization::getAssignableRoles(Auth::user());
        $assignableSlugs = array_column($assignableRoles, 'slug');
        $this->assertContains('admin', $assignableSlugs);
    }

    public function testCanonicalSuperadminCanAssignPermissionEvenWhenOwnRoleIsMissingIt(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-superadmin-permission-', true), 'superadmin-permission@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $superadminUserId = (int) Database::lastInsertId();

        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        $permission = Database::queryOne("SELECT id, permission_key FROM permissions ORDER BY id DESC LIMIT 1");
        $this->assertNotEmpty($superadminRole['id'] ?? null);
        $this->assertNotEmpty($permission['id'] ?? null);

        Authorization::assignUserRole($superadminUserId, (int) $superadminRole['id'], $superadminUserId);
        Database::execute(
            "DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?",
            [(int) $superadminRole['id'], (int) $permission['id']]
        );

        $this->authenticateAs($superadminUserId);

        $this->assertTrue(Authorization::isSuperAdmin(Auth::user()));
        $this->assertTrue(Authorization::canAssignPermissionIds([(int) $permission['id']], Auth::user()));

        $assignablePermissions = Authorization::getAssignablePermissions(Auth::user());
        $assignableIds = array_map(static fn (array $row): int => (int) $row['id'], $assignablePermissions);
        $this->assertContains((int) $permission['id'], $assignableIds);
    }

    public function testAccessProfilePermissionCheckDoesNotUseSuperadminBypass(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-superadmin-exact-', true), 'superadmin-exact@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $superadminUserId = (int) Database::lastInsertId();

        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        $permission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = 'feature.automation_readiness_card' LIMIT 1");
        $this->assertNotEmpty($superadminRole['id'] ?? null);
        $this->assertNotEmpty($permission['id'] ?? null);

        Authorization::assignUserRole($superadminUserId, (int) $superadminRole['id'], $superadminUserId);
        Database::execute(
            "DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?",
            [(int) $superadminRole['id'], (int) $permission['id']]
        );

        $this->authenticateAs($superadminUserId);

        $this->assertTrue(Authorization::can('feature.automation_readiness_card', Auth::user()));
        $this->assertFalse(Authorization::hasAccessProfilePermission('feature.automation_readiness_card', Auth::user()));
    }

    public function testOwnerCannotAssignAdminRole(): void
    {
        $this->authenticateAs($this->ownerUserId);

        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        $this->assertNotEmpty($adminRole['id'] ?? null);
        $this->assertFalse(Authorization::canAssignRole((int) $adminRole['id'], Auth::user()));

        $assignableRoles = Authorization::getAssignableRoles(Auth::user());
        $assignableSlugs = array_column($assignableRoles, 'slug');
        $this->assertNotContains('admin', $assignableSlugs);
    }

    public function testSuperadminRoleExistsAndHasAllPermissions(): void
    {
        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        $this->assertNotEmpty($superadminRole['id'] ?? null);

        $permissionCount = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM permissions")['c'] ?? 0);
        $superadminPermissionCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM role_permissions
             WHERE role_id = ?
               AND can_access = 1",
            [(int) $superadminRole['id']]
        )['c'] ?? 0);

        $this->assertSame($permissionCount, $superadminPermissionCount);
    }

    public function testUsersPageAccessPermissionIsSeededOnlyToOwnerAndSuperadminSystemRoles(): void
    {
        $rows = Database::query(
            "SELECT r.slug
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.permission_key = 'admin.users.access'
               AND rp.can_access = 1
             ORDER BY r.slug"
        );
        $slugs = array_map(static fn(array $row): string => (string) $row['slug'], $rows);

        $this->assertContains('owner', $slugs);
        $this->assertContains('superadmin', $slugs);
        $this->assertNotContains('admin', $slugs);
    }

    public function testNormalizationMigrationConvertsAdminOpsIntoCanonicalSuperadminRole(): void
    {
        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        $this->assertNotEmpty($superadminRole['id'] ?? null);
        $superadminRoleId = (int) $superadminRole['id'];

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('auth-migration-superadmin-', true), 'migration-superadmin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Authorization::assignUserRole($userId, $superadminRoleId, $userId);

        Database::execute(
            "UPDATE roles
             SET slug = 'admin_ops',
                 name = 'Super Admin',
                 description = 'Legacy top-level role'
             WHERE id = ?",
            [$superadminRoleId]
        );
        Database::execute("DELETE FROM role_permissions WHERE role_id = ?", [$superadminRoleId]);
        Database::execute(
            "INSERT INTO role_permissions (role_id, permission_id, can_access)
             SELECT ?, id, 1
             FROM permissions
             WHERE permission_key = 'admin.users.manage'",
            [$superadminRoleId]
        );

        $this->runSqlMigrationFile(__DIR__ . '/../../../database/migrations/183_normalize_superadmin_role.sql');

        $canonicalRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        $this->assertNotEmpty($canonicalRole['id'] ?? null);
        $this->assertNull(Database::queryOne("SELECT id FROM roles WHERE slug = 'admin_ops' LIMIT 1"));

        $assignedRole = Authorization::getUserRole($userId);
        $this->assertSame('superadmin', $assignedRole['slug'] ?? null);

        $permissionCount = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM permissions")['c'] ?? 0);
        $superadminPermissionCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM role_permissions
             WHERE role_id = ?
               AND can_access = 1",
            [(int) $canonicalRole['id']]
        )['c'] ?? 0);

        $this->assertSame($permissionCount, $superadminPermissionCount);
    }

    public function testSystemRoleCannotBeDeleted(): void
    {
        $systemRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'owner' LIMIT 1");
        $this->assertSame('System access profiles cannot be deleted.', Authorization::getRoleDeletionBlocker((int) ($systemRole['id'] ?? 0)));
        $this->assertFalse(Authorization::canDeleteRole((int) ($systemRole['id'] ?? 0)));
    }

    public function testAssignedCustomRoleCannotBeDeleted(): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active) VALUES (?, ?, ?, 0, 1)",
            ['Custom QA', 'custom_qa', 'QA access']
        );
        $roleId = (int) Database::lastInsertId();
        Authorization::assignUserRole($this->ownerUserId, $roleId, $this->adminUserId);

        $this->assertSame(
            'This access profile is still assigned to one or more users.',
            Authorization::getRoleDeletionBlocker($roleId)
        );
        $this->assertFalse(Authorization::canDeleteRole($roleId));
    }

    public function testUnassignedCustomRoleCanBeDeleted(): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active) VALUES (?, ?, ?, 0, 1)",
            ['Temporary Role', 'temporary_role', 'Disposable access']
        );
        $roleId = (int) Database::lastInsertId();

        $this->assertNull(Authorization::getRoleDeletionBlocker($roleId));
        $this->assertTrue(Authorization::canDeleteRole($roleId));

        Authorization::deleteRole($roleId);
        $this->assertNull(Authorization::getRoleById($roleId));
    }

    private function authenticateAs(int $userId): void
    {
        Session::start();
        Session::set('user_id', $userId);
    }

    private function setAuthorizationRbacAvailable(?bool $value): void
    {
        $reflection = new \ReflectionClass(Authorization::class);
        $property = $reflection->getProperty('rbacAvailable');
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    private function runSqlMigrationFile(string $path): void
    {
        $sql = file_get_contents($path);
        $this->assertNotFalse($sql, 'Expected migration file to be readable.');

        foreach ($this->splitSqlStatements((string) $sql) as $statement) {
            $stmt = Database::getInstance()->prepare($statement);
            $stmt->execute();

            do {
                if ($stmt->columnCount() > 0) {
                    $stmt->fetchAll(\PDO::FETCH_ASSOC);
                }
            } while ($stmt->nextRowset());

            $stmt->closeCursor();
        }
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $inSingleQuote = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];

            if ($inSingleQuote) {
                $current .= $char;
                if ($char === "'") {
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $current .= "'";
                        $index++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($char === "'") {
                $current .= $char;
                $inSingleQuote = true;
                continue;
            }

            if ($char === ';') {
                $statement = $this->cleanSqlStatement($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $statement = $this->cleanSqlStatement($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function cleanSqlStatement(string $statement): string
    {
        $statement = trim($statement);
        if ($statement === '') {
            return '';
        }

        $cleaned = [];
        foreach (preg_split('/\R/', $statement) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                continue;
            }
            $cleaned[] = $line;
        }

        return trim(implode("\n", $cleaned));
    }
}
