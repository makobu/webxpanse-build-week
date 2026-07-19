<?php

namespace CRM\Services;

use CRM\Database;

class OperatorAuditService
{
    public function log(
        string $actionType,
        ?int $actorUserId,
        ?int $targetWorkspaceId = null,
        ?string $reason = null,
        array $metadata = [],
        ?int $targetUserId = null
    ): int {
        $actionType = trim($actionType);
        if ($actionType === '') {
            throw new \RuntimeException('Operator audit action is required.');
        }

        Database::execute(
            "INSERT INTO operator_audit_log
             (actor_user_id, target_workspace_id, target_user_id, action_type, reason, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $actorUserId ?: null,
                $targetWorkspaceId ?: null,
                $targetUserId ?: null,
                $actionType,
                $reason !== null && trim($reason) !== '' ? substr(trim($reason), 0, 255) : null,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function listForWorkspace(int $workspaceId, int $limit = 20): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
            return $row;
        }, Database::query(
            "SELECT oal.*, actor.email AS actor_email, actor.role AS actor_role, target.email AS target_user_email
             FROM operator_audit_log oal
             LEFT JOIN users actor ON actor.id = oal.actor_user_id
             LEFT JOIN users target ON target.id = oal.target_user_id
             WHERE oal.target_workspace_id = ?
             ORDER BY oal.id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
