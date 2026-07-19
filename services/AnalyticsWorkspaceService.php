<?php

namespace CRM\Services;

use CRM\Database;

class AnalyticsWorkspaceService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function requireAnalyticsWorkspaceId(?int $workspaceId = null): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    public function workspaceClause(string $alias = '', string $column = 'workspace_id', ?int $workspaceId = null): array
    {
        return $this->workspaceScope->workspaceClause($alias, $column, $workspaceId);
    }

    /**
     * @param array<int, mixed> $params
     */
    public function appendWorkspaceCondition(string $where, array &$params, string $qualifiedColumn = 'workspace_id', ?int $workspaceId = null): string
    {
        $params[] = $this->requireAnalyticsWorkspaceId($workspaceId);
        return $where . ' AND ' . $qualifiedColumn . ' = ?';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveWorkspaceUsers(?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->requireAnalyticsWorkspaceId($workspaceId);

        return Database::query(
            "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, wm.role_slug, wm.membership_status
             FROM workspace_memberships wm
             INNER JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY COALESCE(NULLIF(u.first_name, ''), u.email), COALESCE(NULLIF(u.last_name, ''), '')",
            [$resolvedWorkspaceId]
        );
    }

    public function ensureScopedUserId(?int $userId, ?int $workspaceId = null): ?int
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }

        $resolvedWorkspaceId = $this->requireAnalyticsWorkspaceId($workspaceId);
        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$resolvedWorkspaceId, $userId]
        );

        return $membership ? $userId : null;
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    public function workspaceMembershipClause(string $userColumn, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->requireAnalyticsWorkspaceId($workspaceId);

        return [
            'sql' => "EXISTS (
                SELECT 1
                FROM workspace_memberships wm
                WHERE wm.workspace_id = ?
                  AND wm.user_id = {$userColumn}
                  AND wm.membership_status = 'active'
            )",
            'params' => [$resolvedWorkspaceId],
        ];
    }
}
