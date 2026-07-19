<?php

namespace CRM\Services;

use CRM\Database;

class OrganizationIntelligenceConversationService
{
    public function currentOrCreate(int $workspaceId, int $userId, ?int $conversationId = null): array
    {
        $row = null;
        if ($conversationId !== null && $conversationId > 0) {
            $row = Database::queryOne(
                "SELECT * FROM organization_intelligence_conversations
                 WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'organization_intelligence' AND status = 'active' LIMIT 1",
                [$conversationId, $workspaceId, $userId]
            );
        }
        $row = $row ?: Database::queryOne(
            "SELECT * FROM organization_intelligence_conversations
             WHERE workspace_id = ? AND user_id = ? AND surface = 'organization_intelligence' AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1",
            [$workspaceId, $userId]
        );
        if (!$row) {
            Database::execute(
                "INSERT INTO organization_intelligence_conversations
                    (workspace_id, user_id, surface, status, expires_at)
                 VALUES (?, ?, 'organization_intelligence', 'active', DATE_ADD(NOW(), INTERVAL 90 DAY))",
                [$workspaceId, $userId]
            );
            $row = Database::queryOne('SELECT * FROM organization_intelligence_conversations WHERE id = LAST_INSERT_ID()') ?: [];
        }
        return $row;
    }

    public function append(int $conversationId, int $workspaceId, int $userId, string $role, string $text, string $room, string $subRoom, array $scope, ?int $snapshotId = null, ?int $guidanceRunId = null, ?string $messageHash = null): int
    {
        $role = in_array($role, ['user', 'assistant', 'system'], true) ? $role : 'assistant';
        Database::execute(
            "INSERT INTO organization_intelligence_messages
                (conversation_id, workspace_id, user_id, message_role, message_text, room, sub_room,
                 scope_json, context_snapshot_id, guidance_run_id, message_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $conversationId,
                $workspaceId,
                $userId,
                $role,
                $text,
                $room,
                $subRoom !== '' ? $subRoom : null,
                json_encode($scope, JSON_UNESCAPED_SLASHES),
                $snapshotId,
                $guidanceRunId,
                $messageHash,
            ]
        );
        $id = (int) Database::lastInsertId();
        Database::execute(
            "UPDATE organization_intelligence_conversations
             SET last_room = ?, last_sub_room = ?, last_message_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 90 DAY)
             WHERE id = ? AND workspace_id = ? AND user_id = ?",
            [$room, $subRoom !== '' ? $subRoom : null, $conversationId, $workspaceId, $userId]
        );
        $this->refreshRollingSummary($conversationId, $workspaceId, $userId);
        return $id;
    }

    public function appendScopeDividerIfChanged(int $conversationId, int $workspaceId, int $userId, string $room, string $subRoom, array $scope, ?int $snapshotId = null): ?int
    {
        $latest = Database::queryOne(
            "SELECT room, sub_room, scope_json FROM organization_intelligence_messages
             WHERE conversation_id = ? AND workspace_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT 1",
            [$conversationId, $workspaceId, $userId]
        );
        if (!$latest) {
            return null;
        }
        $previousScope = json_decode((string) ($latest['scope_json'] ?? '{}'), true);
        $changed = (string) ($latest['room'] ?? '') !== $room
            || (string) ($latest['sub_room'] ?? '') !== $subRoom
            || (is_array($previousScope) ? $previousScope : []) !== $scope;
        if (!$changed) {
            return null;
        }
        return $this->append(
            $conversationId,
            $workspaceId,
            $userId,
            'system',
            'Scope changed to ' . ucwords(str_replace('_', ' ', $room . ($subRoom !== '' ? ' / ' . $subRoom : ''))) . '.',
            $room,
            $subRoom,
            $scope,
            $snapshotId
        );
    }

    public function history(int $conversationId, int $workspaceId, int $userId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $queryLimit = min(150, $limit * 3);
        $rows = Database::query(
            "SELECT id, message_role, message_text, room, sub_room, scope_json, context_snapshot_id, guidance_run_id, created_at
             FROM organization_intelligence_messages
             WHERE conversation_id = ? AND workspace_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT {$queryLimit}",
            [$conversationId, $workspaceId, $userId]
        );
        $rows = array_reverse($rows);
        $normalizer = new AITextResponseNormalizerService();
        $history = [];
        foreach ($rows as $row) {
            $role = (string) $row['message_role'];
            $text = (string) $row['message_text'];
            if ($role === 'assistant') {
                $text = $normalizer->normalize($text);
                if ($text === '') {
                    continue;
                }
            }
            $scope = json_decode((string) ($row['scope_json'] ?? '{}'), true);
            $history[] = [
                'id' => (int) $row['id'],
                'role' => $role,
                'text' => $text,
                'room' => (string) $row['room'],
                'sub_room' => (string) ($row['sub_room'] ?? ''),
                'scope' => is_array($scope) ? $scope : [],
                'context_snapshot_id' => !empty($row['context_snapshot_id']) ? (int) $row['context_snapshot_id'] : null,
                'guidance_run_id' => !empty($row['guidance_run_id']) ? (int) $row['guidance_run_id'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return array_slice($history, -$limit);
    }

    public function clear(int $conversationId, int $workspaceId, int $userId): void
    {
        Database::execute(
            "UPDATE organization_intelligence_conversations SET status = 'cleared', expires_at = NOW()
             WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'organization_intelligence'",
            [$conversationId, $workspaceId, $userId]
        );
    }

    public function purgeExpired(): int
    {
        return Database::execute(
            "DELETE FROM organization_intelligence_conversations
             WHERE surface = 'organization_intelligence' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
    }

    private function refreshRollingSummary(int $conversationId, int $workspaceId, int $userId): void
    {
        $rows = Database::query(
            "SELECT message_role, message_text, room, sub_room
             FROM organization_intelligence_messages
             WHERE conversation_id = ? AND workspace_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT 8",
            [$conversationId, $workspaceId, $userId]
        );
        $rows = array_reverse($rows);
        $parts = [];
        $normalizer = new AITextResponseNormalizerService();
        foreach ($rows as $row) {
            $rawText = (string) ($row['message_text'] ?? '');
            if ((string) ($row['message_role'] ?? '') === 'assistant') {
                $rawText = $normalizer->normalize($rawText);
            }
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($rawText)) ?? '');
            if ($text === '') {
                continue;
            }
            $parts[] = strtoupper(substr((string) ($row['message_role'] ?? 'message'), 0, 1))
                . '[' . (string) ($row['room'] ?? 'brief') . ']: '
                . substr($text, 0, 220);
        }
        Database::execute(
            'UPDATE organization_intelligence_conversations SET rolling_summary = ? WHERE id = ? AND workspace_id = ? AND user_id = ?',
            [substr(implode("\n", $parts), 0, 2000), $conversationId, $workspaceId, $userId]
        );
    }
}
