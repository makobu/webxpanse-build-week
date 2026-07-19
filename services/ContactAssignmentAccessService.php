<?php

namespace CRM\Services;

use CRM\Database;

class ContactAssignmentAccessService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function getAssignableUsers(?int $workspaceId = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('workspace_memberships')) {
            return [];
        }

        $users = Database::query(
            "SELECT u.id, u.email
             FROM users u
             JOIN workspace_memberships wm ON wm.user_id = u.id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             GROUP BY u.id, u.email
             ORDER BY u.email ASC",
            [$workspaceId]
        );

        return array_values(array_map(
            static fn (array $user): array => [
                'id' => (int) ($user['id'] ?? 0),
                'email' => (string) ($user['email'] ?? ''),
            ],
            $users
        ));
    }

    public function assertAssignableUser(?int $userId, ?int $workspaceId = null): void
    {
        if ($userId === null || $userId <= 0) {
            return;
        }

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('workspace_memberships')) {
            throw new \RuntimeException('The selected user is not a member of the active workspace.');
        }

        $membership = Database::queryOne(
            "SELECT wm.id
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.user_id = ?
               AND wm.membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        );

        if (!$membership) {
            throw new \RuntimeException('The selected user is not a member of the active workspace.');
        }
    }
}
