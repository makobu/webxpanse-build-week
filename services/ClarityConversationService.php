<?php

namespace CRM\Services;

use CRM\Database;

class ClarityConversationService
{
    /** @return array<string,mixed> */
    public function currentOrCreate(int $workspaceId, int $userId, ?int $conversationId = null): array
    {
        $row = null;
        if ($conversationId !== null && $conversationId > 0) {
            $row = Database::queryOne(
                "SELECT * FROM organization_intelligence_conversations
                 WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'clarity_chat' AND status = 'active' LIMIT 1",
                [$conversationId, $workspaceId, $userId]
            );
        }
        $row = $row ?: Database::queryOne(
            "SELECT * FROM organization_intelligence_conversations
             WHERE workspace_id = ? AND user_id = ? AND surface = 'clarity_chat' AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1",
            [$workspaceId, $userId]
        );
        if (!$row) {
            Database::execute(
                "INSERT INTO organization_intelligence_conversations
                    (workspace_id, user_id, surface, status, expires_at)
                 VALUES (?, ?, 'clarity_chat', 'active', DATE_ADD(NOW(), INTERVAL 30 DAY))",
                [$workspaceId, $userId]
            );
            $row = Database::queryOne('SELECT * FROM organization_intelligence_conversations WHERE id = LAST_INSERT_ID()') ?: [];
        }
        return $row;
    }

    public function append(
        int $conversationId,
        int $workspaceId,
        int $userId,
        string $role,
        string $text,
        string $page,
        array $scope = [],
        ?int $guidanceRunId = null,
        ?string $messageHash = null
    ): int {
        $role = in_array($role, ['user', 'assistant', 'system'], true) ? $role : 'assistant';
        $page = $this->normalizePage($page);
        Database::execute(
            "INSERT INTO organization_intelligence_messages
                (conversation_id, workspace_id, user_id, message_role, message_text, room, scope_json, guidance_run_id, message_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $conversationId,
                $workspaceId,
                $userId,
                $role,
                $text,
                $page,
                json_encode($scope, JSON_UNESCAPED_SLASHES),
                $guidanceRunId,
                $messageHash,
            ]
        );
        $id = (int) Database::lastInsertId();
        Database::execute(
            "UPDATE organization_intelligence_conversations
             SET last_room = ?, last_message_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
             WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'clarity_chat'",
            [$page, $conversationId, $workspaceId, $userId]
        );
        $this->refreshRollingSummary($conversationId, $workspaceId, $userId);
        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $conversationId, int $workspaceId, int $userId, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $rows = Database::query(
            "SELECT id, message_role, message_text, room, scope_json, created_at
             FROM organization_intelligence_messages
             WHERE conversation_id = ? AND workspace_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT {$limit}",
            [$conversationId, $workspaceId, $userId]
        );
        $rows = array_reverse($rows);
        $normalizer = new AITextResponseNormalizerService();
        $history = [];
        foreach ($rows as $row) {
            $role = (string) ($row['message_role'] ?? 'assistant');
            $text = (string) ($row['message_text'] ?? '');
            if ($role === 'assistant') {
                $text = $normalizer->normalize($text);
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $scope = json_decode((string) ($row['scope_json'] ?? '{}'), true);
            $history[] = [
                'role' => $role,
                'text' => substr($text, 0, 1200),
                'page' => (string) ($row['room'] ?? ''),
                'scope' => is_array($scope) ? $scope : [],
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
        return $history;
    }

    /** @return array<string,mixed> */
    public function promptContext(array $conversation, int $workspaceId, int $userId): array
    {
        $conversationId = (int) ($conversation['id'] ?? 0);
        if ($conversationId <= 0) {
            return [];
        }
        return [
            'conversation_id' => $conversationId,
            'rolling_summary' => (string) ($conversation['rolling_summary'] ?? ''),
            'recent_messages' => $this->history($conversationId, $workspaceId, $userId, 8),
            'continuity_rule' => 'Use recent messages only to resolve follow-ups. Current server-owned page and entity evidence wins when context has changed.',
        ];
    }

    private function refreshRollingSummary(int $conversationId, int $workspaceId, int $userId): void
    {
        $rows = Database::query(
            "SELECT message_role, message_text, room FROM organization_intelligence_messages
             WHERE conversation_id = ? AND workspace_id = ? AND user_id = ?
             ORDER BY id DESC LIMIT 8",
            [$conversationId, $workspaceId, $userId]
        );
        $rows = array_reverse($rows);
        $parts = [];
        foreach ($rows as $row) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($row['message_text'] ?? ''))) ?? '');
            if ($text === '') {
                continue;
            }
            $parts[] = strtoupper(substr((string) ($row['message_role'] ?? 'message'), 0, 1))
                . '[' . (string) ($row['room'] ?? 'dashboard.php') . ']: '
                . substr($text, 0, 220);
        }
        Database::execute(
            "UPDATE organization_intelligence_conversations SET rolling_summary = ?
             WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'clarity_chat'",
            [substr(implode("\n", $parts), 0, 2000), $conversationId, $workspaceId, $userId]
        );
    }

    private function normalizePage(string $page): string
    {
        $page = basename(parse_url(strtolower(trim($page)), PHP_URL_PATH) ?: $page);
        return $page !== '' ? substr($page, 0, 40) : 'dashboard.php';
    }
}
