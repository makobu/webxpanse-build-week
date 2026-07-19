<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceGovernanceEventLogService
{
    private const FILTERS = ['all', 'invites', 'members', 'ownership', 'slug'];

    public function log(
        int $workspaceId,
        string $eventType,
        ?int $actorUserId = null,
        ?int $targetUserId = null,
        ?int $inviteId = null,
        array $metadata = []
    ): int {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for governance history.');
        }

        $eventType = trim($eventType);
        if ($eventType === '') {
            throw new \RuntimeException('Governance event type is required.');
        }

        Database::execute(
            "INSERT INTO workspace_governance_events
             (workspace_id, actor_user_id, target_user_id, invite_id, event_type, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $actorUserId ?: null,
                $targetUserId ?: null,
                $inviteId ?: null,
                substr($eventType, 0, 100),
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function listForWorkspace(int $workspaceId, int $limit = 20, ?string $filter = null): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        return array_map(function (array $row): array {
            return $this->toReadModelRow($row);
        }, Database::query(
            "SELECT wge.*,
                    actor.email AS actor_email,
                    actor.first_name AS actor_first_name,
                    actor.last_name AS actor_last_name,
                    target.email AS target_user_email,
                    target.first_name AS target_first_name,
                    target.last_name AS target_last_name,
                    wi.email AS invite_email
             FROM workspace_governance_events wge
             LEFT JOIN users actor ON actor.id = wge.actor_user_id
             LEFT JOIN users target ON target.id = wge.target_user_id
             LEFT JOIN workspace_invites wi ON wi.id = wge.invite_id
             WHERE wge.workspace_id = ?" . $this->filterSql($filter) . "
             ORDER BY wge.id DESC
             LIMIT " . max(1, min(200, $limit)),
            array_merge([$workspaceId], $this->filterParams($filter))
        ));
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toReadModelRow(array $row): array
    {
        $metadata = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
        $eventType = (string) ($row['event_type'] ?? '');
        $category = $this->categoryForEventType($eventType);
        [$targetType, $targetId] = $this->targetIdentity($row, $category);

        $row['metadata'] = $metadata;
        $row['category'] = $category;
        $row['actor_display_name'] = $this->displayName(
            (string) ($row['actor_first_name'] ?? ''),
            (string) ($row['actor_last_name'] ?? ''),
            (string) ($row['actor_email'] ?? ''),
            'system'
        );
        $row['target_type'] = $targetType;
        $row['target_id'] = $targetId;
        $row['target_label'] = $this->targetLabel($row, $metadata, $targetType);

        return $row;
    }

    private function filterSql(?string $filter): string
    {
        $normalized = $this->normalizeFilter($filter);
        if ($normalized === 'all') {
            return '';
        }

        return " AND " . match ($normalized) {
            'invites' => "wge.event_type IN ('invite_created', 'invite_resent', 'invite_revoked', 'invite_accepted', 'invite_delivery_sent', 'invite_delivery_failed')",
            'members' => "wge.event_type IN ('member_role_changed', 'member_restored', 'member_removed', 'member_suspended')",
            'ownership' => "wge.event_type IN ('ownership_transferred', 'member_work_ownership_assigned')",
            'slug' => "wge.event_type = 'primary_slug_changed'",
            default => '1=1',
        };
    }

    /**
     * @return array<int,mixed>
     */
    private function filterParams(?string $filter): array
    {
        return [];
    }

    private function normalizeFilter(?string $filter): string
    {
        $normalized = strtolower(trim((string) $filter));
        return in_array($normalized, self::FILTERS, true) ? $normalized : 'all';
    }

    private function categoryForEventType(string $eventType): string
    {
        return match ($eventType) {
            'invite_created', 'invite_resent', 'invite_revoked', 'invite_accepted', 'invite_delivery_sent', 'invite_delivery_failed' => 'invites',
            'member_role_changed', 'member_restored', 'member_removed', 'member_suspended' => 'members',
            'ownership_transferred', 'member_work_ownership_assigned' => 'ownership',
            'primary_slug_changed' => 'slug',
            default => 'workspace',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:string,1:int|null}
     */
    private function targetIdentity(array $row, string $category): array
    {
        if (!empty($row['invite_id'])) {
            return ['invite', (int) $row['invite_id']];
        }

        if (!empty($row['target_user_id'])) {
            return [$category === 'ownership' ? 'owner' : 'member', (int) $row['target_user_id']];
        }

        return [$category === 'slug' ? 'slug' : 'workspace', null];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $metadata
     */
    private function targetLabel(array $row, array $metadata, string $targetType): string
    {
        if ($targetType === 'invite') {
            return trim((string) ($row['invite_email'] ?? ($metadata['email'] ?? '')));
        }

        if ($targetType === 'member' || $targetType === 'owner') {
            return $this->displayName(
                (string) ($row['target_first_name'] ?? ''),
                (string) ($row['target_last_name'] ?? ''),
                (string) ($row['target_user_email'] ?? ''),
                'workspace member'
            );
        }

        if ($targetType === 'slug') {
            return trim((string) ($metadata['new_slug'] ?? ''));
        }

        return '';
    }

    private function displayName(string $firstName, string $lastName, string $fallbackEmail, string $fallback): string
    {
        $name = trim(trim($firstName . ' ' . $lastName));
        if ($name !== '') {
            return $name;
        }

        $email = trim($fallbackEmail);
        if ($email !== '') {
            return $email;
        }

        return $fallback;
    }
}
