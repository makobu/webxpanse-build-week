<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceOwnerContactReconciliationService
{
    private DefaultWorkspaceService $defaultWorkspace;
    private DefaultWorkspaceOwnerContactService $ownerContacts;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null, ?DefaultWorkspaceOwnerContactService $ownerContacts = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->ownerContacts = $ownerContacts ?: new DefaultWorkspaceOwnerContactService($this->defaultWorkspace);
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?int $actorUserId = null, string $reason = 'Default workspace owner-contact reconciliation'): array
    {
        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $result = [
            'scanned' => 0,
            'created' => 0,
            'updated' => 0,
            'marked_inactive' => 0,
            'skipped_default_workspace' => 0,
            'failed' => 0,
            'warnings' => [],
            'sync_run_id' => null,
        ];

        if (!Database::tableExists('default_workspace_owner_contacts')) {
            $result['warnings'][] = 'Owner-contact mapping table is missing.';
            return $result;
        }

        $runId = $this->startRun($defaultWorkspaceId, $actorUserId, $reason);
        $result['sync_run_id'] = $runId;

        try {
            $seen = [];
            foreach ($this->activeOwnerRows($defaultWorkspaceId) as $row) {
                $workspaceId = (int) ($row['workspace_id'] ?? 0);
                $ownerUserId = (int) ($row['owner_user_id'] ?? 0);
                if ($workspaceId <= 0 || $ownerUserId <= 0) {
                    continue;
                }

                $result['scanned']++;
                $seen[$workspaceId . ':' . $ownerUserId] = true;

                if ($this->defaultWorkspace->isDefaultWorkspace($workspaceId, $row)) {
                    $result['skipped_default_workspace']++;
                    continue;
                }

                try {
                    $sync = $this->ownerContacts->syncOwnerForWorkspace($workspaceId, $ownerUserId, $actorUserId);
                    $status = (string) ($sync['status'] ?? '');
                    if ($status === 'created') {
                        $result['created']++;
                    } elseif ($status === 'updated') {
                        $result['updated']++;
                    } elseif (($sync['relationship_status'] ?? '') === 'inactive') {
                        $result['marked_inactive']++;
                    }
                    $this->markMappingSynced($defaultWorkspaceId, $workspaceId, $ownerUserId);
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $this->recordError($runId, $defaultWorkspaceId, $workspaceId, $ownerUserId, 'owner_sync_failed', $e->getMessage());
                    $this->markMappingFailed($defaultWorkspaceId, $workspaceId, $ownerUserId, $e->getMessage());
                }
            }

            foreach ($this->inactiveMappingRows($defaultWorkspaceId) as $row) {
                $workspaceId = (int) ($row['owner_workspace_id'] ?? 0);
                $ownerUserId = (int) ($row['owner_user_id'] ?? 0);
                if ($workspaceId <= 0 || $ownerUserId <= 0 || isset($seen[$workspaceId . ':' . $ownerUserId])) {
                    continue;
                }

                $reasonCode = $this->inactiveReason($row);
                if ($this->markMappingInactive((int) $row['id'], (int) ($row['contact_id'] ?? 0), $defaultWorkspaceId, $reasonCode)) {
                    $result['marked_inactive']++;
                }
            }

            $this->finishRun($runId, $result['failed'] > 0 ? 'completed_with_errors' : 'completed', $result);
        } catch (\Throwable $e) {
            $result['failed']++;
            $result['warnings'][] = $e->getMessage();
            $this->finishRun($runId, 'failed', $result);
            throw $e;
        }

        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function activeOwnerRows(int $defaultWorkspaceId): array
    {
        if (!Database::tableExists('workspace_memberships')) {
            return [];
        }

        return Database::query(
            "SELECT w.id AS workspace_id, w.slug AS workspace_slug, w.status, w.plan_status, wm.user_id AS owner_user_id
             FROM workspaces w
             JOIN workspace_memberships wm ON wm.workspace_id = w.id
             JOIN users u ON u.id = wm.user_id
             WHERE w.id <> ?
               AND wm.membership_status = 'active'
               AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
             ORDER BY w.id ASC, wm.is_owner DESC, wm.id ASC",
            [$defaultWorkspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inactiveMappingRows(int $defaultWorkspaceId): array
    {
        return Database::query(
            "SELECT map.*, w.status AS workspace_status, w.plan_status AS workspace_plan_status, wm.id AS active_owner_membership_id, u.id AS existing_owner_user_id
             FROM default_workspace_owner_contacts map
             LEFT JOIN workspaces w ON w.id = map.owner_workspace_id
             LEFT JOIN users u ON u.id = map.owner_user_id
             LEFT JOIN workspace_memberships wm
               ON wm.workspace_id = map.owner_workspace_id
              AND wm.user_id = map.owner_user_id
              AND wm.membership_status = 'active'
              AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
             WHERE map.default_workspace_id = ?
               AND (
                   w.id IS NULL
                   OR u.id IS NULL
                   OR wm.id IS NULL
                   OR LOWER(COALESCE(w.status, '')) IN ('suspended','archived','locked','inactive','deleted')
               )
             ORDER BY map.id ASC",
            [$defaultWorkspaceId]
        );
    }

    private function startRun(int $defaultWorkspaceId, ?int $actorUserId, string $reason): ?int
    {
        if (!Database::tableExists('default_workspace_owner_contact_sync_runs')) {
            return null;
        }

        Database::execute(
            "INSERT INTO default_workspace_owner_contact_sync_runs (default_workspace_id, actor_user_id, run_status, reason, started_at)
             VALUES (?, ?, 'running', ?, NOW())",
            [$defaultWorkspaceId, $actorUserId, substr(trim($reason), 0, 255)]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $result
     */
    private function finishRun(?int $runId, string $status, array $result): void
    {
        if ($runId === null || !Database::tableExists('default_workspace_owner_contact_sync_runs')) {
            return;
        }

        Database::execute(
            "UPDATE default_workspace_owner_contact_sync_runs
             SET run_status = ?,
                 scanned = ?,
                 created_count = ?,
                 updated_count = ?,
                 marked_inactive_count = ?,
                 skipped_default_workspace_count = ?,
                 failed_count = ?,
                 warnings_json = ?,
                 completed_at = NOW()
             WHERE id = ?",
            [
                $status,
                (int) ($result['scanned'] ?? 0),
                (int) ($result['created'] ?? 0),
                (int) ($result['updated'] ?? 0),
                (int) ($result['marked_inactive'] ?? 0),
                (int) ($result['skipped_default_workspace'] ?? 0),
                (int) ($result['failed'] ?? 0),
                json_encode((array) ($result['warnings'] ?? []), JSON_UNESCAPED_SLASHES),
                $runId,
            ]
        );
    }

    private function recordError(?int $runId, int $defaultWorkspaceId, ?int $workspaceId, ?int $ownerUserId, string $type, string $message): void
    {
        if (!Database::tableExists('default_workspace_owner_contact_sync_errors')) {
            return;
        }

        Database::execute(
            "INSERT INTO default_workspace_owner_contact_sync_errors
                (sync_run_id, default_workspace_id, owner_workspace_id, owner_user_id, error_type, error_message, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $runId,
                $defaultWorkspaceId,
                $workspaceId ?: null,
                $ownerUserId ?: null,
                substr($type, 0, 80),
                substr($message, 0, 500),
                json_encode(['recorded_at' => gmdate('c')], JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function markMappingSynced(int $defaultWorkspaceId, int $workspaceId, int $ownerUserId): void
    {
        if (!Database::columnExists('default_workspace_owner_contacts', 'sync_status')) {
            return;
        }

        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET sync_status = CASE WHEN relationship_status = 'inactive' THEN 'inactive' ELSE 'synced' END,
                 inactive_reason = CASE WHEN relationship_status = 'inactive' THEN inactive_reason ELSE NULL END,
                 last_sync_error = NULL,
                 last_reconciled_at = NOW()
             WHERE default_workspace_id = ? AND owner_workspace_id = ? AND owner_user_id = ?",
            [$defaultWorkspaceId, $workspaceId, $ownerUserId]
        );
    }

    private function markMappingFailed(int $defaultWorkspaceId, int $workspaceId, int $ownerUserId, string $message): void
    {
        if (!Database::columnExists('default_workspace_owner_contacts', 'sync_status')) {
            return;
        }

        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET sync_status = 'failed',
                 last_sync_error = ?,
                 last_reconciled_at = NOW()
             WHERE default_workspace_id = ? AND owner_workspace_id = ? AND owner_user_id = ?",
            [substr($message, 0, 500), $defaultWorkspaceId, $workspaceId, $ownerUserId]
        );
    }

    private function markMappingInactive(int $mappingId, int $contactId, int $defaultWorkspaceId, string $reason): bool
    {
        $existing = Database::queryOne(
            "SELECT relationship_status, sync_status FROM default_workspace_owner_contacts WHERE id = ? LIMIT 1",
            [$mappingId]
        ) ?: [];

        Database::execute(
            "UPDATE default_workspace_owner_contacts
             SET relationship_status = 'inactive',
                 customer_state = 'ineligible',
                 sync_status = 'inactive',
                 inactive_reason = ?,
                 last_sync_error = NULL,
                 last_reconciled_at = NOW(),
                 last_synced_at = NOW()
             WHERE id = ?",
            [substr($reason, 0, 120), $mappingId]
        );

        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT metadata_json FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
                [$defaultWorkspaceId, $contactId]
            );
            $metadata = $this->decodeJson($contact['metadata_json'] ?? null);
            $metadata['default_workspace_nurture_qualified'] = false;
            $metadata['default_workspace_contact_scope'] = 'inactive_workspace_owner';
            $metadata['default_workspace_use'] = 'inactive_owner_reference';
            $metadata['current_paying_customer'] = false;
            $metadata['marketing_conversion_allowed'] = false;
            $metadata['inactive_reason'] = $reason;
            $metadata['last_synced_at'] = gmdate('c');
            Database::execute(
                "UPDATE contacts SET metadata_json = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?",
                [json_encode($metadata, JSON_UNESCAPED_SLASHES), $defaultWorkspaceId, $contactId]
            );
        }

        return (string) ($existing['relationship_status'] ?? '') !== 'inactive'
            || (string) ($existing['sync_status'] ?? '') !== 'inactive';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function inactiveReason(array $row): string
    {
        if (empty($row['existing_owner_user_id'])) {
            return 'owner_user_missing';
        }
        if (empty($row['active_owner_membership_id'])) {
            return 'owner_membership_inactive';
        }
        $status = strtolower(trim((string) ($row['workspace_status'] ?? '')));
        if (in_array($status, ['suspended', 'archived', 'locked', 'inactive', 'deleted'], true)) {
            return 'workspace_' . $status;
        }
        return 'workspace_owner_ineligible';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
