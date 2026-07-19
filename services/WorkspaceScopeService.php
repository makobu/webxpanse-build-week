<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;

class WorkspaceScopeService
{
    private static int $currentWorkspaceCalls = 0;
    private static array $workspaceAccessCache = [];

    public function requireActiveWorkspaceId(?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $workspaceId ?: $this->currentWorkspaceId();
        if ($resolvedWorkspaceId === null || $resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('An active workspace is required.');
        }

        if ($workspaceId !== null && $workspaceId > 0) {
            $this->assertCurrentUserCanAccessWorkspace($resolvedWorkspaceId);
        }

        return $resolvedWorkspaceId;
    }

    public function currentWorkspaceId(): ?int
    {
        self::$currentWorkspaceCalls++;
        $limit = (int) ($_ENV['WORKSPACE_SCOPE_GUARD_LIMIT'] ?? 0);
        if ($limit > 0 && self::$currentWorkspaceCalls > $limit) {
            throw new \RuntimeException('Workspace scope guard tripped after ' . self::$currentWorkspaceCalls . ' calls.');
        }

        return WorkspaceContext::currentWorkspaceId();
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    public function workspaceClause(string $alias = '', string $column = 'workspace_id', ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->requireActiveWorkspaceId($workspaceId);
        $qualifiedColumn = $alias !== '' ? $alias . $column : $column;

        return [
            'sql' => $qualifiedColumn . ' = ?',
            'params' => [$resolvedWorkspaceId],
        ];
    }

    public function assertSameWorkspace(string $table, int $id, int $workspaceId): void
    {
        if ($id <= 0) {
            throw new \RuntimeException('Invalid entity id.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name for workspace scoping.');
        }

        $row = Database::queryOne(
            "SELECT workspace_id FROM {$table} WHERE id = ? LIMIT 1",
            [$id]
        );

        if (!$row) {
            throw new \RuntimeException('The requested record was not found in the active workspace.');
        }

        if ((int) ($row['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('The requested record does not belong to the active workspace.');
        }
    }

    public function assertCurrentUserCanAccessWorkspace(int $workspaceId): void
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('An active workspace is required.');
        }

        if (!Auth::check()) {
            return;
        }

        $user = Auth::user();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('The active user is required for workspace access.');
        }

        if (Authorization::isSuperAdmin($user)) {
            self::$workspaceAccessCache[$workspaceId . ':' . $userId] = true;
            return;
        }

        $cacheKey = $workspaceId . ':' . $userId;
        if (array_key_exists($cacheKey, self::$workspaceAccessCache)) {
            if (self::$workspaceAccessCache[$cacheKey]) {
                return;
            }
            throw new \RuntimeException('The requested workspace is not available to the active user.');
        }

        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        );

        if (!$membership) {
            self::$workspaceAccessCache[$cacheKey] = false;
            throw new \RuntimeException('The requested workspace is not available to the active user.');
        }

        self::$workspaceAccessCache[$cacheKey] = true;
    }

    public static function resetGuards(): void
    {
        self::$currentWorkspaceCalls = 0;
        self::$workspaceAccessCache = [];
    }
}
