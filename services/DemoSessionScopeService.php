<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;

class DemoSessionScopeService
{
    public function activeSession(?int $workspaceId = null): ?array
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        $sessionId = (int) (Session::get('demo_visitor_session_id') ?? 0);
        $sessionUuid = trim((string) (Session::get('demo_visitor_session_uuid') ?? ''));

        if ($workspaceId <= 0 || $sessionId <= 0 || $sessionUuid === '') {
            return null;
        }

        $session = Database::queryOne(
            "SELECT *
             FROM demo_visitor_sessions
             WHERE id = ?
               AND session_uuid = ?
               AND workspace_id = ?
               AND status = 'active'
               AND expires_at > NOW()
             LIMIT 1",
            [$sessionId, $sessionUuid, $workspaceId]
        );

        if (!$session) {
            return null;
        }

        $currentUserId = (int) (Auth::userId() ?? 0);
        $sessionUserId = (int) ($session['user_id'] ?? 0);
        $guestUserId = (int) ($session['guest_user_id'] ?? 0);
        if ($currentUserId > 0 && $sessionUserId > 0 && $currentUserId !== $sessionUserId && $currentUserId !== $guestUserId) {
            return null;
        }

        return $session;
    }

    public function requireActiveSession(?int $workspaceId = null): array
    {
        $session = $this->activeSession($workspaceId);
        if ($session === null) {
            throw new \RuntimeException('An active demo session is required.');
        }

        return $session;
    }

    public function activeSessionId(?int $workspaceId = null): ?int
    {
        $session = $this->activeSession($workspaceId);
        return $session ? (int) ($session['id'] ?? 0) : null;
    }

    public function isActiveDemoWorkspace(?int $workspaceId = null): bool
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        return (new DemoWorkspaceService())->isDemoWorkspace($workspaceId);
    }

    public function canOperateDemo(?array $user = null): bool
    {
        $user = $user ?? Auth::user();
        if (!$user || empty($user['id'])) {
            return false;
        }

        return Authorization::isSuperAdmin($user) || Authorization::can('demo.workspace.operate', $user);
    }

    public function forceOwnerScopeAllAllowed(bool $requestedCanViewAll): bool
    {
        if (!$this->isActiveDemoWorkspace()) {
            return $requestedCanViewAll;
        }

        return $this->canOperateDemo(Auth::user()) ? $requestedCanViewAll : false;
    }

    /**
     * @return array{sql:string, params:array<int,mixed>}
     */
    public function visibilityClause(string $alias = '', ?int $workspaceId = null, bool $allowOperatorOnly = false): array
    {
        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if (!$this->isActiveDemoWorkspace($workspaceId) || !Database::columnExists($this->stripAliasTable($alias), 'demo_visibility')) {
            return ['sql' => '', 'params' => []];
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        if ($allowOperatorOnly && $this->canOperateDemo(Auth::user())) {
            return ['sql' => '1 = 1', 'params' => []];
        }

        $session = $this->activeSession($workspaceId);
        if (!$session) {
            return ['sql' => "{$prefix}demo_visibility = 'public_seed'", 'params' => []];
        }

        return [
            'sql' => "(
                {$prefix}demo_visibility = 'public_seed'
                OR ({$prefix}demo_visibility = 'session_private' AND {$prefix}demo_session_id = ?)
            )",
            'params' => [(int) $session['id']],
        ];
    }

    /**
     * @return array{sql:string, params:array<int,mixed>}
     */
    public function entityOwnershipClause(string $table, string $alias = '', ?int $workspaceId = null): array
    {
        if (!Database::columnExists($table, 'demo_visibility')) {
            return ['sql' => '', 'params' => []];
        }

        $workspaceId = (int) ($workspaceId ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        if (!$this->isActiveDemoWorkspace($workspaceId)) {
            return ['sql' => '', 'params' => []];
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $session = $this->activeSession($workspaceId);
        if (!$session) {
            return ['sql' => "{$prefix}demo_visibility = 'public_seed'", 'params' => []];
        }

        return [
            'sql' => "(
                {$prefix}demo_visibility = 'public_seed'
                OR ({$prefix}demo_visibility = 'session_private' AND {$prefix}demo_session_id = ?)
            )",
            'params' => [(int) $session['id']],
        ];
    }

    public function registerEntity(int $sessionId, int $workspaceId, string $tableName, int $recordId, string $visibility = 'session_private', array $metadata = []): void
    {
        if ($sessionId <= 0 || $workspaceId <= 0 || $tableName === '' || $recordId <= 0) {
            return;
        }

        Database::execute(
            "INSERT INTO demo_session_entities (demo_session_id, workspace_id, table_name, record_id, visibility, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE visibility = VALUES(visibility), metadata_json = VALUES(metadata_json), updated_at = NOW()",
            [
                $sessionId,
                $workspaceId,
                $tableName,
                $recordId,
                $visibility,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    private function stripAliasTable(string $alias): string
    {
        $alias = trim($alias);
        $map = [
            'c' => 'communications',
            'ct' => 'contacts',
            'e' => 'emails',
            'th' => 'conversation_threads',
            'emails' => 'emails',
            'notifications' => 'notifications',
        ];

        $alias = rtrim($alias, '.');
        return $map[$alias] ?? $alias;
    }
}
