<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;

class AIWorkspaceScopeService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function requireWorkspaceId(?string $tenantKey = null, ?int $workspaceId = null): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        }

        $resolved = $this->resolveWorkspaceIdFromTenantKey($tenantKey);
        if ($resolved !== null && $resolved > 0) {
            $this->workspaceScope->assertCurrentUserCanAccessWorkspace($resolved);
            return $resolved;
        }

        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    public function currentTenantKey(?int $workspaceId = null): string
    {
        return $this->workspaceTenantKey($this->requireWorkspaceId(null, $workspaceId));
    }

    public function workspaceTenantKey(int $workspaceId): string
    {
        return 'workspace:' . max(1, $workspaceId);
    }

    public function resolveWorkspaceIdFromTenantKey(?string $tenantKey): ?int
    {
        $tenantKey = trim((string) $tenantKey);
        if ($tenantKey === '') {
            return $this->workspaceScope->currentWorkspaceId();
        }

        if (preg_match('/^workspace:(\d+)$/', $tenantKey, $matches)) {
            $workspaceId = (int) $matches[1];
            $this->workspaceScope->assertCurrentUserCanAccessWorkspace($workspaceId);
            return $workspaceId;
        }

        if ($tenantKey === 'global:default') {
            return $this->workspaceScope->currentWorkspaceId() ?? $this->firstWorkspaceId();
        }

        [$prefix, $identifier] = array_pad(explode(':', $tenantKey, 2), 2, '');
        $entityId = (int) $identifier;
        if ($entityId <= 0) {
            return $this->workspaceScope->currentWorkspaceId();
        }

        $resolved = match ($prefix) {
            'contact' => $this->workspaceIdForEntity('contacts', $entityId),
            'company' => $this->workspaceIdForEntity('companies', $entityId),
            'deal' => $this->workspaceIdForEntity('deals', $entityId),
            'invoice' => $this->workspaceIdForEntity('invoices', $entityId),
            'task' => $this->workspaceIdForEntity('tasks', $entityId),
            'workflow' => $this->workspaceIdForEntity('workflows', $entityId),
            'user' => $this->workspaceIdForUser($entityId),
            default => $this->workspaceScope->currentWorkspaceId(),
        };

        if ($resolved !== null && $resolved > 0) {
            $this->workspaceScope->assertCurrentUserCanAccessWorkspace($resolved);
        }

        return $resolved;
    }

    public function resolvePreferenceUserId(?int $workspaceId = null, ?int $preferredUserId = null): int
    {
        $workspaceId = $this->requireWorkspaceId(null, $workspaceId);

        if ($preferredUserId !== null && $preferredUserId > 0) {
            $membership = Database::queryOne(
                "SELECT 1
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND membership_status = 'active'
                 LIMIT 1",
                [$workspaceId, $preferredUserId]
            );
            if ($membership) {
                return $preferredUserId;
            }
        }

        $authUserId = (int) (Auth::userId() ?? 0);
        if ($authUserId > 0) {
            $membership = Database::queryOne(
                "SELECT 1
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND membership_status = 'active'
                 LIMIT 1",
                [$workspaceId, $authUserId]
            );
            if ($membership) {
                return $authUserId;
            }
        }

        $row = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY CASE role_slug
                        WHEN 'owner' THEN 0
                        WHEN 'admin' THEN 1
                        ELSE 2
                      END,
                      id ASC
             LIMIT 1",
            [$workspaceId]
        );

        return max(1, (int) ($row['user_id'] ?? $preferredUserId ?? $authUserId ?? 1));
    }

    private function workspaceIdForEntity(string $table, int $entityId): ?int
    {
        if ($entityId <= 0) {
            return null;
        }

        try {
            $row = Database::queryOne(
                "SELECT workspace_id
                 FROM {$table}
                 WHERE id = ?
                 LIMIT 1",
                [$entityId]
            );
        } catch (\Throwable $e) {
            return $this->workspaceScope->currentWorkspaceId();
        }

        $workspaceId = (int) ($row['workspace_id'] ?? 0);
        return $workspaceId > 0 ? $workspaceId : $this->workspaceScope->currentWorkspaceId();
    }

    private function workspaceIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT workspace_id
             FROM workspace_memberships
             WHERE user_id = ?
               AND membership_status = 'active'
             ORDER BY CASE role_slug
                        WHEN 'owner' THEN 0
                        WHEN 'admin' THEN 1
                        ELSE 2
                      END,
                      id ASC
             LIMIT 1",
            [$userId]
        );

        $workspaceId = (int) ($row['workspace_id'] ?? 0);
        return $workspaceId > 0 ? $workspaceId : $this->workspaceScope->currentWorkspaceId();
    }

    private function firstWorkspaceId(): int
    {
        $row = Database::queryOne("SELECT MIN(id) AS workspace_id FROM workspaces");
        return max(1, (int) ($row['workspace_id'] ?? 1));
    }
}
