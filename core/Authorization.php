<?php
/**
 * Authorization helpers (RBAC with legacy-role compatibility).
 */

namespace CRM;

class Authorization
{
    private static ?bool $rbacAvailable = null;
    private static array $userPermissionCache = [];
    private static array $globalUserRoleCache = [];
    private static array $workspaceUserRoleCache = [];
    private static array $tableExistsCache = [];
    private const PLATFORM_ONLY_PERMISSION_KEYS = [
        'admin.audit_logs.view',
        'billing.trials.manage',
        'platform.settings.manage',
        'platform.system.reset',
        'platform.users.view',
    ];

    private const PLATFORM_ONLY_PERMISSION_PREFIXES = [
        'operator.',
        'platform.',
    ];
    private const LEGACY_ADMIN_COMPATIBILITY_PERMISSION_KEYS = [
        'analytics.view_all',
        'contacts.view_all',
        'conversations.view_all',
        'events.view_all',
        'notifications.view_all',
        'tasks.view_all',
    ];

    public static function can(string $permission, ?array $user = null): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        if ($user === null) {
            if (!Auth::check()) {
                return false;
            }
            $user = Auth::user();
        }

        if (!$user || empty($user['id'])) {
            return false;
        }

        if (!self::isRbacAvailable()) {
            return ($user['role'] ?? '') === 'admin';
        }

        $userId = (int) $user['id'];

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (self::isDemoViewerViewAllBlocked($permission)) {
            return false;
        }

        // Legacy admins without an assigned access profile keep only the app-level
        // view-all permissions needed by existing clients. Admin-management and
        // platform permissions still require explicit RBAC assignment.
        if (self::getUserRole($userId) === null) {
            return ($user['role'] ?? '') === 'admin' && self::isLegacyAdminCompatibilityPermission($permission);
        }

        $permissions = self::getUserPermissions($userId);
        return isset($permissions[$permission]);
    }

    public static function canAny(array $permissions, ?array $user = null): bool
    {
        foreach ($permissions as $permission) {
            if (self::can((string) $permission, $user)) {
                return true;
            }
        }
        return false;
    }

    public static function canAccessUsersPage(?array $user = null): bool
    {
        if ($user === null) {
            if (!Auth::check()) {
                return false;
            }
            $user = Auth::user();
        }

        if (!$user || empty($user['id'])) {
            return false;
        }

        if (!self::isRbacAvailable()) {
            return in_array((string) ($user['role'] ?? ''), ['admin', 'owner'], true);
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        $role = self::getUserRole((int) $user['id']);
        if ((string) ($role['slug'] ?? '') !== 'owner') {
            return false;
        }

        return self::hasAccessProfilePermission('admin.users.access', $user);
    }

    public static function hasAccessProfilePermission(string $permission, ?array $user = null): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        if ($user === null) {
            if (!Auth::check()) {
                return false;
            }
            $user = Auth::user();
        }

        if (!$user || empty($user['id'])) {
            return false;
        }

        if (!self::isRbacAvailable()) {
            return self::can($permission, $user);
        }

        if (self::getUserRole((int) $user['id']) === null) {
            return false;
        }

        $permissions = self::getUserPermissions((int) $user['id']);
        return isset($permissions[$permission]);
    }

    public static function requirePermission(string $permission, bool $asJson = false): void
    {
        if (self::can($permission)) {
            return;
        }

        if ($asJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }

        http_response_code(403);
        die('Access denied: Insufficient permissions');
    }

    public static function isRbacAvailable(): bool
    {
        if (self::$rbacAvailable !== null) {
            return self::$rbacAvailable;
        }

        try {
            $requiredTables = ['roles', 'permissions', 'role_permissions', 'user_roles'];

            $dbRow = Database::queryOne("SELECT DATABASE() AS db_name");
            $dbName = (string) ($dbRow['db_name'] ?? '');

            if ($dbName !== '') {
                $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
                $params = array_merge([$dbName], $requiredTables);
                $countRow = Database::queryOne(
                    "SELECT COUNT(*) AS table_count
                     FROM information_schema.tables
                     WHERE table_schema = ?
                       AND table_name IN ($placeholders)",
                    $params
                );
                $foundCount = (int) ($countRow['table_count'] ?? 0);
                self::$rbacAvailable = ($foundCount === count($requiredTables));
            } else {
                self::$rbacAvailable = false;
            }

            // Fallback when information_schema visibility is restricted.
            if (!self::$rbacAvailable) {
                Database::query("SELECT 1 FROM roles LIMIT 1");
                Database::query("SELECT 1 FROM permissions LIMIT 1");
                Database::query("SELECT 1 FROM role_permissions LIMIT 1");
                Database::query("SELECT 1 FROM user_roles LIMIT 1");
                self::$rbacAvailable = true;
            }
        } catch (\Throwable $e) {
            self::$rbacAvailable = false;
        }

        return self::$rbacAvailable;
    }

    public static function getUserRole(int $userId): ?array
    {
        if (!self::isRbacAvailable() || $userId <= 0) {
            return null;
        }

        $globalRole = self::getGlobalUserRole($userId);
        if (($globalRole['slug'] ?? null) === 'superadmin') {
            return $globalRole;
        }

        $workspaceRole = self::getWorkspaceUserRole($userId);
        if ($workspaceRole !== null) {
            return $workspaceRole;
        }

        return $globalRole;
    }

    public static function getGlobalUserRole(int $userId): ?array
    {
        if (!self::isRbacAvailable() || $userId <= 0) {
            return null;
        }

        if (array_key_exists($userId, self::$globalUserRoleCache)) {
            return self::$globalUserRoleCache[$userId];
        }

        self::$globalUserRoleCache[$userId] = Database::queryOne(
            "SELECT r.id, r.name, r.slug, r.description, r.is_system
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
             LIMIT 1",
            [$userId]
        );
        return self::$globalUserRoleCache[$userId];
    }

    public static function getWorkspaceUserRole(int $userId, ?int $workspaceId = null): ?array
    {
        if (!self::isRbacAvailable() || $userId <= 0) {
            return null;
        }

        $workspaceId = $workspaceId ?: (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return null;
        }

        $cacheKey = $workspaceId . ':' . $userId;
        if (array_key_exists($cacheKey, self::$workspaceUserRoleCache)) {
            return self::$workspaceUserRoleCache[$cacheKey];
        }

        if (!self::tableExists('workspace_user_roles')) {
            self::$workspaceUserRoleCache[$cacheKey] = null;
            return null;
        }

        self::$workspaceUserRoleCache[$cacheKey] = Database::queryOne(
            "SELECT r.id, r.name, r.slug, r.description, r.is_system
             FROM workspace_user_roles wur
             JOIN roles r ON r.id = wur.role_id
             WHERE wur.workspace_id = ?
               AND wur.user_id = ?
            LIMIT 1",
            [$workspaceId, $userId]
        );
        return self::$workspaceUserRoleCache[$cacheKey];
    }

    public static function isSuperAdmin(?array $user = null): bool
    {
        $user = $user ?? Auth::user();
        if (!$user || empty($user['id']) || !self::isRbacAvailable()) {
            return false;
        }

        $role = self::getGlobalUserRole((int) $user['id']);
        return (string) ($role['slug'] ?? '') === 'superadmin';
    }

    public static function getRoleById(int $roleId): ?array
    {
        if (!self::isRbacAvailable() || $roleId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT id, name, slug, description, is_system, is_active
             FROM roles
             WHERE id = ?",
            [$roleId]
        );
    }

    public static function hasAssignedRole(int $userId): bool
    {
        return self::getUserRole($userId) !== null;
    }

    public static function isLegacyFallbackMode(?array $user = null): bool
    {
        if (!Auth::check()) {
            return false;
        }

        $user = $user ?? Auth::user();
        if (!$user || empty($user['id'])) {
            return false;
        }

        return self::isRbacAvailable() && !self::hasAssignedRole((int) $user['id']);
    }

    public static function assignUserRole(int $userId, int $roleId, ?int $assignedBy = null): void
    {
        if (!self::isRbacAvailable() || $userId <= 0 || $roleId <= 0) {
            return;
        }

        $existing = Database::queryOne("SELECT user_id FROM user_roles WHERE user_id = ?", [$userId]);
        if ($existing) {
            Database::execute(
                "UPDATE user_roles SET role_id = ?, assigned_by = ?, updated_at = NOW() WHERE user_id = ?",
                [$roleId, $assignedBy, $userId]
            );
        } else {
            Database::execute(
                "INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)",
                [$userId, $roleId, $assignedBy]
            );
        }

        self::clearUserPermissionCache($userId);
    }

    public static function resetCaches(): void
    {
        self::$rbacAvailable = null;
        self::$userPermissionCache = [];
        self::$globalUserRoleCache = [];
        self::$workspaceUserRoleCache = [];
        self::$tableExistsCache = [];
    }

    public static function assignUserRoleBySlug(int $userId, string $roleSlug, ?int $assignedBy = null): bool
    {
        if (!self::isRbacAvailable() || $userId <= 0) {
            return false;
        }

        $roleSlug = trim($roleSlug);
        if ($roleSlug === '') {
            return false;
        }

        $role = Database::queryOne(
            "SELECT id
             FROM roles
             WHERE slug = ?
               AND is_active = 1
             LIMIT 1",
            [$roleSlug]
        );

        if (!$role) {
            return false;
        }

        self::assignUserRole($userId, (int) $role['id'], $assignedBy);
        return true;
    }

    public static function assignWorkspaceUserRole(int $workspaceId, int $userId, int $roleId, ?int $assignedBy = null): void
    {
        if (!self::isRbacAvailable() || !self::tableExists('workspace_user_roles') || $workspaceId <= 0 || $userId <= 0 || $roleId <= 0) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by), updated_at = NOW()",
            [$workspaceId, $userId, $roleId, $assignedBy]
        );

        self::clearUserPermissionCache($userId);
    }

    public static function assignWorkspaceUserRoleBySlug(int $workspaceId, int $userId, string $roleSlug, ?int $assignedBy = null): bool
    {
        if (!self::isRbacAvailable() || $workspaceId <= 0 || $userId <= 0) {
            return false;
        }

        $role = Database::queryOne(
            "SELECT id
             FROM roles
             WHERE slug = ?
               AND is_active = 1
             LIMIT 1",
            [trim($roleSlug)]
        );

        if (!$role) {
            return false;
        }

        self::assignWorkspaceUserRole($workspaceId, $userId, (int) $role['id'], $assignedBy);
        return true;
    }

    public static function clearUserRole(int $userId): void
    {
        if (!self::isRbacAvailable() || $userId <= 0) {
            return;
        }
        Database::execute("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
        self::clearUserPermissionCache($userId);
    }

    public static function getPermissionKeysForRole(int $roleId): array
    {
        if (!self::isRbacAvailable() || $roleId <= 0) {
            return [];
        }

        $result = [];
        $rows = Database::query(
            "SELECT p.permission_key
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             JOIN roles r ON r.id = rp.role_id AND r.is_active = 1
             WHERE rp.role_id = ? AND rp.can_access = 1",
            [$roleId]
        );

        foreach ($rows as $row) {
            $key = (string) ($row['permission_key'] ?? '');
            if ($key !== '') {
                $result[$key] = true;
            }
        }

        return $result;
    }

    public static function canAssignRole(int $roleId, ?array $user = null): bool
    {
        if (!self::isRbacAvailable() || $roleId <= 0) {
            return true;
        }

        if (!Auth::check()) {
            return false;
        }

        $user = $user ?? Auth::user();
        if (!$user || empty($user['id'])) {
            return false;
        }

        $targetRole = self::getRoleById($roleId);
        if ($targetRole === null || (int) ($targetRole['is_active'] ?? 0) !== 1) {
            return false;
        }

        $targetRoleSlug = (string) ($targetRole['slug'] ?? '');
        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (in_array($targetRoleSlug, ['superadmin', 'admin_ops'], true)) {
            return false;
        }

        $actorRole = self::getUserRole((int) $user['id']);
        if ((string) ($actorRole['slug'] ?? '') === 'admin' && $targetRoleSlug === 'owner') {
            return true;
        }

        $actorPermissions = self::getUserPermissions((int) $user['id']);
        $targetPermissions = self::getPermissionKeysForRole($roleId);

        foreach ($targetPermissions as $permissionKey => $_allowed) {
            if (self::isPlatformOnlyPermission($permissionKey)) {
                return false;
            }
            if (!isset($actorPermissions[$permissionKey])) {
                return false;
            }
        }

        return true;
    }

    public static function getAssignableRoles(?array $user = null): array
    {
        if (!self::isRbacAvailable()) {
            return [];
        }

        $user = $user ?? Auth::user();
        if (!$user || empty($user['id'])) {
            return [];
        }

        $roles = Database::query(
            "SELECT id, name, slug
             FROM roles
             WHERE is_active = 1
             ORDER BY is_system DESC,
                FIELD(slug, 'superadmin', 'admin_ops', 'admin', 'owner', 'accountant', 'expert', 'sales', 'marketing', 'viewer'),
                name ASC"
        );

        $assignable = [];
        foreach ($roles as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            if ($roleId > 0 && self::canAssignRole($roleId, $user)) {
                $assignable[] = $role;
            }
        }

        return $assignable;
    }

    public static function getAssignablePermissions(?array $user = null): array
    {
        if (!self::isRbacAvailable()) {
            return [];
        }

        if (!Auth::check()) {
            return [];
        }

        $user = $user ?? Auth::user();
        if (!$user || empty($user['id'])) {
            return [];
        }

        $isSuperAdmin = self::isSuperAdmin($user);

        $assignable = [];
        $rows = Database::query(
            "SELECT id, permission_key, label, is_sensitive
             FROM permissions
             ORDER BY permission_key ASC"
        );

        foreach ($rows as $row) {
            $permissionKey = (string) ($row['permission_key'] ?? '');
            if ($isSuperAdmin) {
                $assignable[] = $row;
                continue;
            }

            if (self::isPlatformOnlyPermission($permissionKey)) {
                continue;
            }

            $actorPermissions = $actorPermissions ?? self::getUserPermissions((int) $user['id']);
            if ($permissionKey !== '' && isset($actorPermissions[$permissionKey])) {
                $assignable[] = $row;
            }
        }

        return $assignable;
    }

    public static function canAssignPermissionIds(array $permissionIds, ?array $user = null): bool
    {
        $permissionIds = array_values(array_filter(array_map('intval', $permissionIds), static fn(int $id): bool => $id > 0));
        if ($permissionIds === []) {
            return true;
        }

        if (!self::isRbacAvailable()) {
            return true;
        }

        if (!Auth::check()) {
            return false;
        }

        $user = $user ?? Auth::user();
        if (!$user || empty($user['id'])) {
            return false;
        }

        $isSuperAdmin = self::isSuperAdmin($user);

        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $rows = Database::query(
            "SELECT permission_key
             FROM permissions
             WHERE id IN ($placeholders)",
            $permissionIds
        );

        if (count($rows) !== count($permissionIds)) {
            return false;
        }

        if ($isSuperAdmin) {
            return true;
        }

        $actorPermissions = self::getUserPermissions((int) $user['id']);
        if ($actorPermissions === []) {
            return false;
        }

        foreach ($rows as $row) {
            $permissionKey = (string) ($row['permission_key'] ?? '');
            if (self::isPlatformOnlyPermission($permissionKey)) {
                return false;
            }
            if ($permissionKey === '' || !isset($actorPermissions[$permissionKey])) {
                return false;
            }
        }

        return true;
    }

    public static function isPlatformOnlyPermission(string $permissionKey): bool
    {
        $permissionKey = trim($permissionKey);
        if ($permissionKey === '') {
            return false;
        }

        if (in_array($permissionKey, self::PLATFORM_ONLY_PERMISSION_KEYS, true)) {
            return true;
        }

        foreach (self::PLATFORM_ONLY_PERMISSION_PREFIXES as $prefix) {
            if (str_starts_with($permissionKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isLegacyAdminCompatibilityPermission(string $permissionKey): bool
    {
        return in_array(trim($permissionKey), self::LEGACY_ADMIN_COMPATIBILITY_PERMISSION_KEYS, true);
    }

    private static function isDemoViewerViewAllBlocked(string $permissionKey): bool
    {
        if (!str_ends_with(trim($permissionKey), '.view_all')) {
            return false;
        }

        try {
            $workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
            if ($workspaceId <= 0 || !(new \CRM\Services\DemoWorkspaceService())->isDemoWorkspace($workspaceId)) {
                return false;
            }

            return (string) (\CRM\Services\WorkspaceContext::currentRoleSlug() ?? '') === 'demo_viewer';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function canManageUserTarget(int $targetUserId, ?array $user = null): bool
    {
        if ($targetUserId <= 0 || !Auth::check()) {
            return false;
        }

        if (!self::isRbacAvailable()) {
            return true;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        $workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return false;
        }

        if ($workspaceId > 0 && self::tableExists('workspace_memberships')) {
            $targetMembership = Database::queryOne(
                "SELECT id
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND membership_status = 'active'
                 LIMIT 1",
                [$workspaceId, $targetUserId]
            );
            if (!$targetMembership) {
                return false;
            }
        }

        $targetRole = self::getUserRole($targetUserId);
        if (!$targetRole || empty($targetRole['id'])) {
            return false;
        }

        return self::canAssignRole((int) $targetRole['id'], $user);
    }

    public static function getRoleDeletionBlocker(int $roleId): ?string
    {
        if (!self::isRbacAvailable()) {
            return 'RBAC tables are not available.';
        }

        $role = self::getRoleById($roleId);
        if (!$role) {
            return 'Access profile not found.';
        }

        if ((int) ($role['is_system'] ?? 0) === 1) {
            return 'System access profiles cannot be deleted.';
        }

        $assignedCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM user_roles
             WHERE role_id = ?",
            [$roleId]
        )['c'] ?? 0);

        if ($assignedCount > 0) {
            return 'This access profile is still assigned to one or more users.';
        }

        return null;
    }

    public static function canDeleteRole(int $roleId): bool
    {
        return self::getRoleDeletionBlocker($roleId) === null;
    }

    public static function deleteRole(int $roleId): void
    {
        $blocker = self::getRoleDeletionBlocker($roleId);
        if ($blocker !== null) {
            throw new \RuntimeException($blocker);
        }

        Database::beginTransaction();
        try {
            Database::execute("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);
            Database::execute("DELETE FROM roles WHERE id = ?", [$roleId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private static function getUserPermissions(int $userId): array
    {
        $workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
        $cacheKey = $userId . ':' . $workspaceId;
        if (isset(self::$userPermissionCache[$cacheKey])) {
            return self::$userPermissionCache[$cacheKey];
        }

        $result = [];
        $role = self::getUserRole($userId);
        if (!$role || empty($role['id'])) {
            self::$userPermissionCache[$cacheKey] = [];
            return [];
        }

        $rows = Database::query(
            "SELECT p.permission_key
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id AND r.is_active = 1
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?
               AND rp.can_access = 1",
            [(int) $role['id']]
        );

        foreach ($rows as $row) {
            $key = (string) ($row['permission_key'] ?? '');
            if ($key !== '') {
                $result[$key] = true;
            }
        }

        self::$userPermissionCache[$cacheKey] = $result;
        return $result;
    }

    private static function clearUserPermissionCache(int $userId): void
    {
        unset(self::$globalUserRoleCache[$userId]);
        unset(self::$userPermissionCache[$userId]);
        $prefix = $userId . ':';
        foreach (array_keys(self::$userPermissionCache) as $cacheKey) {
            if (str_starts_with((string) $cacheKey, $prefix)) {
                unset(self::$userPermissionCache[$cacheKey]);
            }
        }
        foreach (array_keys(self::$workspaceUserRoleCache) as $cacheKey) {
            if (str_ends_with((string) $cacheKey, ':' . $userId)) {
                unset(self::$workspaceUserRoleCache[$cacheKey]);
            }
        }
    }

    private static function tableExists(string $table): bool
    {
        if (array_key_exists($table, self::$tableExistsCache)) {
            return self::$tableExistsCache[$table];
        }

        try {
            $exists = (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
            if ($exists) {
                self::$tableExistsCache[$table] = true;
                return true;
            }
        } catch (\Throwable $e) {
            // Fall through to direct table probe when information_schema access is restricted.
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            self::$tableExistsCache[$table] = false;
            return false;
        }

        try {
            Database::query("SELECT 1 FROM `{$table}` LIMIT 1");
            self::$tableExistsCache[$table] = true;
            return true;
        } catch (\Throwable $e) {
            self::$tableExistsCache[$table] = false;
            return false;
        }
    }
}
