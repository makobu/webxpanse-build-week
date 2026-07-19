<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceLifecycleObserverService
{
    private DefaultWorkspaceService $defaultWorkspace;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
    }

    /**
     * @return array<string,mixed>
     */
    public function workspaceChanged(int $workspaceId, ?int $actorUserId = null, string $reason = 'workspace_lifecycle_changed'): array
    {
        $result = ['synced' => false, 'events_refreshed' => false, 'warnings' => []];
        if ($workspaceId <= 0 || $this->defaultWorkspace->isDefaultWorkspace($workspaceId)) {
            return $result;
        }

        foreach ($this->ownerUserIds($workspaceId) as $ownerUserId) {
            try {
                (new DefaultWorkspaceOwnerContactService($this->defaultWorkspace))->syncOwnerForWorkspace($workspaceId, $ownerUserId, $actorUserId);
                $result['synced'] = true;
            } catch (\Throwable $e) {
                $result['warnings'][] = $e->getMessage();
                $this->recordSyncError($workspaceId, $ownerUserId, $e->getMessage());
            }
        }

        try {
            (new DefaultWorkspaceOpsEventService($this->defaultWorkspace))->refreshAll($actorUserId);
            $result['events_refreshed'] = true;
        } catch (\Throwable $e) {
            $result['warnings'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @return array<int>
     */
    private function ownerUserIds(int $workspaceId): array
    {
        if (!Database::tableExists('workspace_memberships')) {
            return [];
        }

        $rows = Database::query(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')
             ORDER BY is_owner DESC, id ASC",
            [$workspaceId]
        );

        return array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['user_id'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0)));
    }

    private function recordSyncError(int $workspaceId, int $ownerUserId, string $message): void
    {
        try {
            if (!Database::tableExists('default_workspace_owner_contact_sync_errors')) {
                return;
            }
            Database::execute(
                "INSERT INTO default_workspace_owner_contact_sync_errors
                    (default_workspace_id, owner_workspace_id, owner_user_id, error_type, error_message, metadata_json)
                 VALUES (?, ?, ?, 'lifecycle_observer_failed', ?, ?)",
                [
                    $this->defaultWorkspace->id(),
                    $workspaceId,
                    $ownerUserId,
                    substr($message, 0, 500),
                    json_encode(['source' => 'DefaultWorkspaceLifecycleObserverService', 'recorded_at' => gmdate('c')], JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (\Throwable $ignored) {
        }
    }
}
