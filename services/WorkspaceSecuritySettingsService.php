<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Session;

class WorkspaceSecuritySettingsService
{
    private const TABLE = 'workspace_security_settings';
    private const PENDING_SETUP_KEY = 'pending_workspace_2fa_setup';

    /**
     * @return array{workspace_id:int,require_member_2fa:bool,updated_by_user_id:?int,updated_at:?string}
     */
    public function getSettings(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !$this->tableExists()) {
            return $this->defaults($workspaceId);
        }

        $row = Database::queryOne(
            "SELECT workspace_id, require_member_2fa, updated_by_user_id, updated_at
             FROM workspace_security_settings
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );

        if (!$row) {
            return $this->defaults($workspaceId);
        }

        return [
            'workspace_id' => (int) ($row['workspace_id'] ?? $workspaceId),
            'require_member_2fa' => !empty($row['require_member_2fa']),
            'updated_by_user_id' => !empty($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            'updated_at' => !empty($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    public function requiresMember2FA(int $workspaceId): bool
    {
        return !empty($this->getSettings($workspaceId)['require_member_2fa']);
    }

    public function setRequireMember2FA(int $workspaceId, bool $required, int $actorUserId): void
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for security settings.');
        }

        if (!$this->tableExists()) {
            throw new \RuntimeException('Workspace security settings are unavailable. Run the latest migrations.');
        }

        Database::execute(
            "INSERT INTO workspace_security_settings (workspace_id, require_member_2fa, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                require_member_2fa = VALUES(require_member_2fa),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [$workspaceId, $required ? 1 : 0, $actorUserId > 0 ? $actorUserId : null]
        );
    }

    public function canUserAccessWorkspace(int $workspaceId, int $userId): bool
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return false;
        }

        return !$this->requiresMember2FA($workspaceId) || self::userHasTwoFactor($userId);
    }

    public function assertUserCanAccessWorkspace(int $workspaceId, int $userId): void
    {
        if (!$this->canUserAccessWorkspace($workspaceId, $userId)) {
            throw new \RuntimeException('This workspace requires two-factor authentication. Enable 2FA before accessing it.');
        }
    }

    /**
     * @return array{total:int,protected:int,missing:int,missing_members:array<int,array<string,mixed>>}
     */
    public function memberTwoFactorSummary(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return [
                'total' => 0,
                'protected' => 0,
                'missing' => 0,
                'missing_members' => [],
            ];
        }

        $rows = Database::query(
            "SELECT u.id, u.email, u.first_name, u.last_name, wm.role_slug, wm.is_owner,
                    CASE WHEN u.two_factor_enabled = 1 AND u.two_factor_secret IS NOT NULL AND u.two_factor_secret <> '' THEN 1 ELSE 0 END AS has_2fa
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), u.email ASC",
            [$workspaceId]
        );

        $protected = 0;
        $missingMembers = [];
        foreach ($rows as $row) {
            if (!empty($row['has_2fa'])) {
                $protected++;
                continue;
            }

            $missingMembers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => (string) ($row['email'] ?? ''),
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'role_slug' => (string) ($row['role_slug'] ?? 'viewer'),
                'is_owner' => !empty($row['is_owner']),
            ];
        }

        return [
            'total' => count($rows),
            'protected' => $protected,
            'missing' => count($missingMembers),
            'missing_members' => $missingMembers,
        ];
    }

    public static function userHasTwoFactor(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $row = Database::queryOne(
                "SELECT two_factor_enabled, two_factor_secret
                 FROM users
                 WHERE id = ?
                 LIMIT 1",
                [$userId]
            );
        } catch (\Throwable $e) {
            return false;
        }

        return !empty($row['two_factor_enabled']) && !empty($row['two_factor_secret']);
    }

    /**
     * @param array<string,mixed> $workspace
     */
    public static function setPendingSetup(int $userId, int $workspaceId, array $workspace = [], ?string $returnTo = null): void
    {
        if ($userId <= 0 || $workspaceId <= 0) {
            return;
        }

        Session::set(self::PENDING_SETUP_KEY, [
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'workspace_name' => (string) ($workspace['workspace_name'] ?? $workspace['name'] ?? 'Workspace'),
            'workspace_slug' => (string) ($workspace['workspace_slug'] ?? $workspace['slug'] ?? ''),
            'return_to' => self::sanitizeReturnTo($returnTo),
            'created_at' => time(),
        ]);
    }

    public static function pendingSetup(): ?array
    {
        $pending = Session::get(self::PENDING_SETUP_KEY);
        return is_array($pending) ? $pending : null;
    }

    public static function hasPendingSetupForUser(int $userId): bool
    {
        $pending = self::pendingSetup();
        return $pending !== null
            && (int) ($pending['user_id'] ?? 0) === $userId
            && (int) ($pending['workspace_id'] ?? 0) > 0;
    }

    public static function clearPendingSetup(): void
    {
        Session::remove(self::PENDING_SETUP_KEY);
    }

    /**
     * @return array{workspace_id:int,require_member_2fa:bool,updated_by_user_id:?int,updated_at:?string}
     */
    private function defaults(int $workspaceId): array
    {
        return [
            'workspace_id' => max(0, $workspaceId),
            'require_member_2fa' => false,
            'updated_by_user_id' => null,
            'updated_at' => null,
        ];
    }

    private function tableExists(): bool
    {
        return Database::tableExists(self::TABLE);
    }

    private static function sanitizeReturnTo(?string $returnTo): string
    {
        $returnTo = trim((string) $returnTo);
        if ($returnTo === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $returnTo)) {
            return 'dashboard.php';
        }

        if (str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) {
            return 'dashboard.php';
        }

        return $returnTo;
    }
}
