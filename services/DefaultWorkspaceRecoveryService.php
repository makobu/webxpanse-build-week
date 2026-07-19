<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class DefaultWorkspaceRecoveryService
{
    private DefaultWorkspaceService $defaultWorkspace;
    private DefaultWorkspaceProtectedActionService $protectedActions;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null, ?DefaultWorkspaceProtectedActionService $protectedActions = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->protectedActions = $protectedActions ?: new DefaultWorkspaceProtectedActionService($this->defaultWorkspace);
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>
     */
    public function recover(?array $actor, string $reason): array
    {
        $actorUserId = (int) ($actor['id'] ?? 0);
        $this->protectedActions->authorize('default_workspace_recovery', $actor, null, $reason, [], true);
        $before = $this->safeHealth($actorUserId);
        $workspaceId = $this->ensureIdentity($actorUserId);

        $this->protectedActions->auditSuccess(
            'default_workspace_recovery_started',
            $actorUserId,
            $workspaceId,
            $reason,
            ['status' => (string) ($before['status'] ?? 'unknown')]
        );

        $operationalization = (new DefaultWorkspaceOperationalizationService())->operationalize($actorUserId);
        $reconciliation = (new DefaultWorkspaceOwnerContactReconciliationService($this->defaultWorkspace))->run($actorUserId, $reason);
        $opsEvents = (new DefaultWorkspaceOpsEventService($this->defaultWorkspace))->refreshAll($actorUserId);
        $after = $this->safeHealth($actorUserId);

        $report = [
            'workspace_id' => $workspaceId,
            'before_health_status' => (string) ($before['status'] ?? 'unknown'),
            'after_health_status' => (string) ($after['status'] ?? 'unknown'),
            'operationalization' => $operationalization,
            'reconciliation' => $reconciliation,
            'ops_events' => $opsEvents,
            'health' => $after,
        ];

        $this->protectedActions->auditSuccess(
            'default_workspace_recovery_completed',
            $actorUserId,
            $workspaceId,
            $reason,
            ['status' => (string) ($before['status'] ?? 'unknown')],
            ['status' => (string) ($after['status'] ?? 'unknown')],
            [
                'reconciliation' => array_intersect_key($reconciliation, array_flip(['scanned', 'created', 'updated', 'marked_inactive', 'failed'])),
                'ops_events' => $opsEvents,
            ]
        );

        return $report;
    }

    private function ensureIdentity(int $actorUserId): int
    {
        try {
            return $this->defaultWorkspace->id();
        } catch (\Throwable $e) {
            if (!Database::tableExists('workspaces')) {
                throw $e;
            }

            Database::execute(
                "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_by)
                 VALUES (1, '00000000-0000-4000-8000-000000000001', 'Default Workspace', 'default', 'active', 'inactive', ?)
                 ON DUPLICATE KEY UPDATE slug = 'default', status = 'active', updated_at = NOW()",
                [$actorUserId > 0 ? $actorUserId : null]
            );
        }

        $workspaceId = $this->defaultWorkspace->id();

        if (Database::tableExists('workspace_slugs')) {
            Database::execute(
                "INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
                 VALUES (?, 'default', 1)
                 ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id), is_primary = VALUES(is_primary)",
                [$workspaceId]
            );
        }

        if (Database::tableExists('workspace_wallets')) {
            Database::execute(
                "INSERT INTO workspace_wallets (workspace_id, currency, token_balance, reserved_tokens, lifetime_credited_tokens, lifetime_debited_tokens, last_activity_at)
                 VALUES (?, 'KES', 0, 0, 0, 0, NOW())
                 ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id)",
                [$workspaceId]
            );
        }

        if ($actorUserId > 0 && Authorization::isSuperAdmin(Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId]) ?: null)) {
            Database::execute(
                "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at, invited_by)
                 VALUES (?, ?, 'superadmin', 'active', 1, NOW(), ?)
                 ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = VALUES(membership_status), is_owner = VALUES(is_owner)",
                [$workspaceId, $actorUserId, $actorUserId]
            );
        }

        return $workspaceId;
    }

    /**
     * @return array<string,mixed>
     */
    private function safeHealth(int $actorUserId): array
    {
        try {
            return (new DefaultWorkspaceHealthService($this->defaultWorkspace))->health($actorUserId);
        } catch (\Throwable $e) {
            return ['status' => 'critical', 'issues' => [$e->getMessage()], 'warnings' => []];
        }
    }
}
