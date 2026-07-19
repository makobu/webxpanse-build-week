<?php

namespace CRM\Services;

use CRM\Database;

class SettingsResetService
{
    private SettingsResetTableCatalog $tableCatalog;
    private DemoWorkspaceRepairService $demoWorkspaceRepairService;

    public function __construct(
        ?SettingsResetTableCatalog $tableCatalog = null,
        ?DemoWorkspaceRepairService $demoWorkspaceRepairService = null
    ) {
        $this->tableCatalog = $tableCatalog ?? new SettingsResetTableCatalog();
        $this->demoWorkspaceRepairService = $demoWorkspaceRepairService ?? new DemoWorkspaceRepairService();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        return $this->tableCatalog->definitions();
    }

    /**
     * @param array<string,mixed> $definition
     * @return array{deleted:array<string,int>,skipped:array<string,string>,summary:string}
     */
    public function execute(array $definition, ?int $workspaceId = null, int $adminUserId = 0): array
    {
        $deleted = [];
        $skipped = [];
        $isWorkspaceReset = $workspaceId !== null && $workspaceId > 0;
        $platformResetWorkspaceUserIds = [];
        $postCommitAutoIncrementTables = [];

        Database::beginTransaction();
        try {
            if (!empty($definition['platform_reset'])) {
                $platformResetWorkspaceUserIds = $this->platformResetWorkspaceMemberUserIds($adminUserId);
            }

            foreach ((array) ($definition['tables'] ?? []) as $tableName) {
                $tableName = (string) $tableName;
                $skipReason = $this->skipReason($tableName, $isWorkspaceReset);
                if ($skipReason !== null) {
                    $skipped[$tableName] = $skipReason;
                    continue;
                }

                $deleted[$tableName] = $isWorkspaceReset
                    ? $this->deleteWorkspaceRows($tableName, (int) $workspaceId)
                    : $this->deleteAllRows($tableName);
                if (!$isWorkspaceReset) {
                    $postCommitAutoIncrementTables[] = $tableName;
                }
            }

            $preferenceKeys = array_values(array_filter(array_map(
                static fn($value): string => trim((string) $value),
                (array) ($definition['preference_keys'] ?? [])
            )));

            if ($preferenceKeys !== [] && $isWorkspaceReset) {
                $deleted['user_preferences:context_markers'] = $this->deleteWorkspacePreferenceRows(
                    $preferenceKeys,
                    (int) $workspaceId
                );
            } elseif ($preferenceKeys !== [] && $this->tableExists('user_preferences')) {
                $placeholders = implode(', ', array_fill(0, count($preferenceKeys), '?'));
                $deleted['user_preferences:context_markers'] = (int) (Database::queryOne(
                    "SELECT COUNT(*) AS c
                     FROM user_preferences
                     WHERE preference_key IN ($placeholders)",
                    $preferenceKeys
                )['c'] ?? 0);
                Database::execute(
                    "DELETE FROM user_preferences
                     WHERE preference_key IN ($placeholders)",
                    $preferenceKeys
                );
            }

            if (!empty($definition['platform_reset'])) {
                $deleted['users:workspace_members'] = $this->deletePlatformResetWorkspaceUsers($platformResetWorkspaceUserIds);
                $this->ensureDefaultWorkspaceAfterPlatformReset($adminUserId);
                $demoRepair = $this->demoWorkspaceRepairService->ensureBaseline($adminUserId, true);
                $deleted['protected_demo_workspace:baseline'] = !empty($demoRepair['created']) ? 1 : 0;
                $deleted['protected_demo_workspace:public_seed'] = (int) ($demoRepair['seeded']['created'] ?? 0);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->resetAutoIncrementCounters($postCommitAutoIncrementTables);

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'summary' => $this->summarizeDeletedRows($deleted),
        ];
    }

    public function tableExists(string $tableName): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    public function tableHasColumn(string $tableName, string $columnName): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !preg_match('/^[A-Za-z0-9_]+$/', $columnName)) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$tableName, $columnName]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /**
     * @return string[]
     */
    public function protectedTables(): array
    {
        return $this->tableCatalog->protectedTables();
    }

    private function isProtectedTable(string $tableName): bool
    {
        return in_array(strtolower($tableName), $this->tableCatalog->protectedTables(), true);
    }

    private function skipReason(string $tableName, bool $isWorkspaceReset): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            return 'invalid_table_name';
        }

        if ($this->isProtectedTable($tableName)) {
            return 'protected_table';
        }

        if (!$this->tableExists($tableName)) {
            return 'missing_table';
        }

        if ($isWorkspaceReset && !$this->tableHasColumn($tableName, 'workspace_id')) {
            return 'missing_workspace_id';
        }

        return null;
    }

    private function deleteAllRows(string $tableName): int
    {
        $before = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM `{$tableName}`")['c'] ?? 0);
        Database::execute("DELETE FROM `{$tableName}`");

        return $before;
    }

    private function deleteWorkspaceRows(string $tableName, int $workspaceId): int
    {
        $before = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM `{$tableName}` WHERE workspace_id = ?",
            [$workspaceId]
        )['c'] ?? 0);
        Database::execute("DELETE FROM `{$tableName}` WHERE workspace_id = ?", [$workspaceId]);

        return $before;
    }

    /**
     * @param string[] $preferenceKeys
     */
    private function deleteWorkspacePreferenceRows(array $preferenceKeys, int $workspaceId): int
    {
        if ($workspaceId <= 0 || $preferenceKeys === [] || !$this->tableExists('user_preferences')) {
            return 0;
        }

        if (!$this->tableExists('workspace_memberships')) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($preferenceKeys), '?'));
        $params = array_merge([$workspaceId], $preferenceKeys);
        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM user_preferences up
             JOIN workspace_memberships wm ON wm.user_id = up.user_id
             WHERE wm.workspace_id = ?
               AND up.preference_key IN ($placeholders)",
            $params
        )['c'] ?? 0);
        Database::execute(
            "DELETE up
             FROM user_preferences up
             JOIN workspace_memberships wm ON wm.user_id = up.user_id
             WHERE wm.workspace_id = ?
               AND up.preference_key IN ($placeholders)",
            $params
        );

        return $count;
    }

    /**
     * @return int[]
     */
    private function platformResetWorkspaceMemberUserIds(int $adminUserId): array
    {
        if (!$this->tableExists('users') || !$this->tableExists('workspace_memberships')) {
            return [];
        }

        $params = [];
        $adminFilter = '';
        if ($adminUserId > 0) {
            $adminFilter = ' AND wm.user_id <> ?';
            $params[] = $adminUserId;
        }

        $globalRoleJoin = '';
        $globalRoleFilter = '';
        if ($this->tableExists('user_roles') && $this->tableExists('roles')) {
            $globalRoleJoin = "\n             LEFT JOIN user_roles ur ON ur.user_id = wm.user_id\n             LEFT JOIN roles gr ON gr.id = ur.role_id";
            $globalRoleFilter = " AND (gr.slug IS NULL OR gr.slug <> 'superadmin')";
        }

        $rows = Database::query(
            "SELECT DISTINCT wm.user_id
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id{$globalRoleJoin}
             WHERE wm.user_id > 0{$adminFilter}{$globalRoleFilter}
             ORDER BY wm.user_id ASC",
            $params
        );

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['user_id'] ?? 0),
            $rows
        ), static fn(int $userId): bool => $userId > 0));
    }

    /**
     * @param int[] $userIds
     */
    private function deletePlatformResetWorkspaceUsers(array $userIds): int
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn($value): int => (int) $value, $userIds),
            static fn(int $userId): bool => $userId > 0
        )));

        if ($userIds === [] || !$this->tableExists('users')) {
            return 0;
        }

        (new OwnerHelpExpertService())->deleteProfilesForUsers($userIds);

        return Database::execute(
            "DELETE FROM users WHERE id IN (" . $this->placeholders($userIds) . ")",
            $userIds
        );
    }

    private function ensureDefaultWorkspaceAfterPlatformReset(int $adminUserId): void
    {
        if (!$this->tableExists('workspaces')) {
            return;
        }

        $workspace = Database::queryOne(
            "SELECT id
             FROM workspaces
             WHERE id = 1 OR slug = 'default'
             ORDER BY CASE WHEN id = 1 THEN 0 ELSE 1 END
             LIMIT 1"
        );
        if (!$workspace) {
            $uuid = sprintf(
                '%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
            Database::execute(
                "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_by)
                 VALUES (1, ?, 'Default Workspace', 'default', 'active', 'inactive', ?)",
                [$uuid, $adminUserId > 0 ? $adminUserId : null]
            );
            $workspaceId = 1;
        } else {
            $workspaceId = (int) ($workspace['id'] ?? 0);
            Database::execute(
                "UPDATE workspaces
                 SET slug = 'default', status = 'active', updated_at = NOW()
                 WHERE id = ?
                   AND NOT EXISTS (
                       SELECT 1
                       FROM (SELECT id FROM workspaces WHERE slug = 'default' AND id <> ?) existing_default
                   )",
                [$workspaceId, $workspaceId]
            );
        }

        if ($workspaceId <= 0) {
            return;
        }

        if ($this->tableExists('workspace_slugs')) {
            Database::execute(
                "INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
                 VALUES (?, 'default', 1)
                 ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id), is_primary = VALUES(is_primary)",
                [$workspaceId]
            );
        }

        if ($adminUserId > 0 && $this->tableExists('workspace_memberships')) {
            $roleSlug = $this->isGlobalSuperAdminUser($adminUserId) ? 'superadmin' : 'owner';
            Database::execute(
                "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
                 VALUES (?, ?, ?, 'active', 1, NOW(), ?)
                 ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = VALUES(membership_status), is_owner = VALUES(is_owner), joined_at = COALESCE(joined_at, VALUES(joined_at))",
                [$workspaceId, $adminUserId, $roleSlug, $adminUserId]
            );
            $this->ensureDefaultWorkspaceUserRole($workspaceId, $adminUserId, $roleSlug);
        }
    }

    private function isGlobalSuperAdminUser(int $userId): bool
    {
        if ($userId <= 0 || !$this->tableExists('user_roles') || !$this->tableExists('roles')) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT r.slug
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?
             LIMIT 1",
            [$userId]
        );

        return (string) ($row['slug'] ?? '') === 'superadmin';
    }

    private function ensureDefaultWorkspaceUserRole(int $workspaceId, int $userId, string $roleSlug): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tableExists('workspace_user_roles') || !$this->tableExists('roles')) {
            return;
        }

        $role = Database::queryOne(
            "SELECT id FROM roles WHERE slug = ? AND is_active = 1 LIMIT 1",
            [$roleSlug]
        );
        $roleId = (int) ($role['id'] ?? 0);
        if ($roleId <= 0) {
            return;
        }

        Database::execute(
            "INSERT INTO workspace_user_roles (workspace_id, user_id, role_id, assigned_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by), updated_at = NOW()",
            [$workspaceId, $userId, $roleId, $userId]
        );
    }

    /**
     * @param int[] $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }

    /**
     * @param string[] $tableNames
     */
    private function resetAutoIncrementCounters(array $tableNames): void
    {
        foreach (array_values(array_unique($tableNames)) as $tableName) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !$this->tableExists($tableName)) {
                continue;
            }

            try {
                Database::execute("ALTER TABLE `{$tableName}` AUTO_INCREMENT = 1");
            } catch (\Throwable $e) {
                // Best-effort post-commit maintenance only; reset correctness must not depend on it.
            }
        }
    }

    /**
     * @param array<string,int> $deleted
     */
    private function summarizeDeletedRows(array $deleted): string
    {
        $summaryParts = [];
        foreach ($deleted as $tableName => $count) {
            $summaryParts[] = $tableName . ': ' . $count;
        }

        return $summaryParts === [] ? 'none' : implode(', ', $summaryParts);
    }
}
