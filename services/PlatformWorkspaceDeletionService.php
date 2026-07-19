<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class PlatformWorkspaceDeletionService
{
    private OperatorAuditService $operatorAudit;
    private DefaultWorkspaceService $defaultWorkspace;

    public function __construct(?OperatorAuditService $operatorAudit = null, ?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->operatorAudit = $operatorAudit ?? new OperatorAuditService();
        $this->defaultWorkspace = $defaultWorkspace ?? new DefaultWorkspaceService();
    }

    /**
     * @param array<int|string> $workspaceIds
     * @return array{deleted_workspace_ids:array<int>,deleted_workspaces:array<int,array<string,mixed>>,row_counts:array<string,int>,total_rows_deleted:int}
     */
    public function deleteWorkspaces(array $workspaceIds, int $actorUserId, ?int $activeWorkspaceId = null, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $ids = $this->normalizeWorkspaceIds($workspaceIds);
        if ($ids === []) {
            throw new \RuntimeException('Choose at least one workspace to delete.');
        }

        $workspaces = $this->loadWorkspaces($ids);
        $this->validateGuardrails($workspaces, $ids, $activeWorkspaceId);
        $deletableUserIds = $this->workspaceOnlyOwnerUserIds($ids, $actorUserId);

        $reason = trim((string) $reason);
        if ($reason === '') {
            $reason = 'Super Admin workspace deletion from workspace directory.';
        }

        $rowCounts = [];
        $fkChecksDisabled = false;

        Database::beginTransaction();
        try {
            foreach ($workspaces as $workspace) {
                $this->operatorAudit->log(
                    'workspace_delete_requested',
                    $actorUserId,
                    (int) $workspace['id'],
                    $reason,
                    [
                        'workspace_id' => (int) $workspace['id'],
                        'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
                        'workspace_slug' => (string) ($workspace['slug'] ?? ''),
                        'workspace_name' => (string) ($workspace['name'] ?? ''),
                        'workspace_status' => (string) ($workspace['status'] ?? ''),
                        'plan_status' => (string) ($workspace['plan_status'] ?? ''),
                    ]
                );
            }

            Database::execute('SET FOREIGN_KEY_CHECKS=0');
            $fkChecksDisabled = true;

            foreach ($this->workspaceScopedTables() as $tableName) {
                $deleted = $this->deleteWorkspaceRows($tableName, $ids);
                if ($deleted > 0) {
                    $rowCounts[$tableName] = $deleted;
                }
            }

            Database::execute('SET FOREIGN_KEY_CHECKS=1');
            $fkChecksDisabled = false;

            $workspaceRowsDeleted = $this->deleteWorkspaceRows('workspaces', $ids, 'id');
            $rowCounts['workspaces'] = $workspaceRowsDeleted;

            if ($deletableUserIds !== []) {
                $deletedUsers = $this->deleteUsers($deletableUserIds);
                if ($deletedUsers > 0) {
                    $rowCounts['users'] = $deletedUsers;
                }
            }

            $totalDeleted = array_sum($rowCounts);
            $deletedWorkspaceIds = array_map(static fn(array $row): int => (int) $row['id'], $workspaces);
            $deletedSnapshots = array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'uuid' => (string) ($row['uuid'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'plan_status' => (string) ($row['plan_status'] ?? ''),
                ];
            }, $workspaces);

            $this->operatorAudit->log(
                'workspace_delete_completed',
                $actorUserId,
                null,
                $reason,
                [
                    'deleted_workspace_ids' => $deletedWorkspaceIds,
                    'deleted_workspaces' => $deletedSnapshots,
                    'deleted_user_ids' => $deletableUserIds,
                    'row_counts' => $rowCounts,
                    'total_rows_deleted' => $totalDeleted,
                ]
            );

            Database::commit();
            try {
                (new DefaultWorkspaceOwnerContactReconciliationService($this->defaultWorkspace))->run($actorUserId, 'Workspace deletion completed.');
                (new DefaultWorkspaceOpsEventService($this->defaultWorkspace))->refreshAll($actorUserId);
            } catch (\Throwable $e) {
                error_log('Default workspace deletion reconciliation failed: ' . $e->getMessage());
            }

            return [
                'deleted_workspace_ids' => $deletedWorkspaceIds,
                'deleted_workspaces' => $deletedSnapshots,
                'row_counts' => $rowCounts,
                'total_rows_deleted' => $totalDeleted,
            ];
        } catch (\Throwable $e) {
            if ($fkChecksDisabled) {
                try {
                    Database::execute('SET FOREIGN_KEY_CHECKS=1');
                } catch (\Throwable $ignored) {
                }
            }
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function requireSuperAdmin(int $actorUserId): void
    {
        $actor = $actorUserId > 0
            ? Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId])
            : null;

        if (!Authorization::isSuperAdmin($actor ?: null)) {
            throw new \RuntimeException('Only Super Admin can delete workspaces.');
        }
    }

    /**
     * @param array<int|string> $workspaceIds
     * @return array<int>
     */
    private function normalizeWorkspaceIds(array $workspaceIds): array
    {
        $ids = [];
        foreach ($workspaceIds as $workspaceId) {
            $id = (int) $workspaceId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param array<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function loadWorkspaces(array $ids): array
    {
        $rows = Database::query(
            "SELECT id, uuid, name, slug, status, plan_status, created_at
             FROM workspaces
             WHERE id IN (" . $this->placeholders($ids) . ")
             ORDER BY id ASC",
            $ids
        );

        if (count($rows) !== count($ids)) {
            throw new \RuntimeException('One or more selected workspaces could not be found.');
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $workspaces
     * @param array<int> $ids
     */
    private function validateGuardrails(array $workspaces, array $ids, ?int $activeWorkspaceId): void
    {
        if ($activeWorkspaceId !== null && $activeWorkspaceId > 0 && in_array($activeWorkspaceId, $ids, true)) {
            throw new \RuntimeException('You cannot delete your current active workspace.');
        }

        foreach ($workspaces as $workspace) {
            if ($this->defaultWorkspace->isDefaultWorkspace(null, $workspace)) {
                throw new \RuntimeException('The default workspace cannot be deleted.');
            }

            if ((new DemoWorkspaceService())->isDemoWorkspace((int) ($workspace['id'] ?? 0))) {
                throw new \RuntimeException('The protected demo workspace cannot be deleted.');
            }

            $workspaceId = (int) ($workspace['id'] ?? 0);
            $presentationGuard = new PresentationWorkspaceGuardService();
            if ($presentationGuard->isBlocked('tenant_delete', $workspaceId)) {
                throw new \RuntimeException($presentationGuard->message('tenant_delete'));
            }
        }

        $workspaceCount = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspaces")['c'] ?? 0);
        if (($workspaceCount - count($ids)) < 1) {
            throw new \RuntimeException('At least one workspace must remain on the platform.');
        }
    }

    /**
     * @return array<int,string>
     */
    private function workspaceScopedTables(): array
    {
        $rows = Database::query(
            "SELECT TABLE_NAME AS table_name
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND COLUMN_NAME = 'workspace_id'
             ORDER BY TABLE_NAME ASC"
        );

        $tables = [];
        foreach ($rows as $row) {
            $tableName = strtolower((string) ($row['table_name'] ?? ''));
            if ($tableName === '' || in_array($tableName, ['workspaces', 'operator_audit_log'], true)) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $tableName)) {
                continue;
            }
            $tables[] = $tableName;
        }

        return array_values(array_unique($tables));
    }

    /**
     * @param array<int> $workspaceIds
     * @return array<int>
     */
    private function workspaceOnlyOwnerUserIds(array $workspaceIds, int $actorUserId): array
    {
        $candidateRows = Database::query(
            "SELECT DISTINCT wm.user_id
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id IN (" . $this->placeholders($workspaceIds) . ")
               AND wm.user_id <> ?
               AND u.role = 'owner'",
            array_merge($workspaceIds, [$actorUserId])
        );

        $candidateIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['user_id'] ?? 0),
            $candidateRows
        ), static fn(int $id): bool => $id > 0));

        if ($candidateIds === []) {
            return [];
        }

        $outsideRows = Database::query(
            "SELECT DISTINCT user_id
             FROM workspace_memberships
             WHERE user_id IN (" . $this->placeholders($candidateIds) . ")
               AND workspace_id NOT IN (" . $this->placeholders($workspaceIds) . ")",
            array_merge($candidateIds, $workspaceIds)
        );
        $outsideIds = array_fill_keys(array_map(
            static fn(array $row): int => (int) ($row['user_id'] ?? 0),
            $outsideRows
        ), true);

        return array_values(array_filter($candidateIds, static fn(int $userId): bool => empty($outsideIds[$userId])));
    }

    /**
     * @param array<int> $ids
     */
    private function deleteWorkspaceRows(string $tableName, array $ids, string $columnName = 'workspace_id'): int
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !preg_match('/^[A-Za-z0-9_]+$/', $columnName)) {
            throw new \RuntimeException('Unsafe workspace deletion target.');
        }

        return Database::execute(
            "DELETE FROM `{$tableName}` WHERE `{$columnName}` IN (" . $this->placeholders($ids) . ")",
            $ids
        );
    }

    /**
     * @param array<int> $userIds
     */
    private function deleteUsers(array $userIds): int
    {
        (new OwnerHelpExpertService())->deleteProfilesForUsers($userIds);

        return Database::execute(
            "DELETE FROM users WHERE id IN (" . $this->placeholders($userIds) . ")",
            $userIds
        );
    }

    /**
     * @param array<int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
