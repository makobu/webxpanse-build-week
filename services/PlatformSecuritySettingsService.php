<?php

namespace CRM\Services;

use CRM\Database;

class PlatformSecuritySettingsService
{
    private const SETTINGS_ID = 1;

    /**
     * @return array{require_superadmin_workspace_2fa:bool,require_platform_workspace_2fa:bool,updated_by_user_id:?int,updated_at:?string}
     */
    public function getSettings(): array
    {
        if (!$this->tableExists()) {
            return $this->defaults();
        }

        $this->ensureRow();
        $row = Database::queryOne(
            "SELECT require_superadmin_workspace_2fa, updated_by_user_id, updated_at
             FROM platform_security_settings
             WHERE id = ?
             LIMIT 1",
            [self::SETTINGS_ID]
        );

        if (!$row) {
            return $this->defaults();
        }

        return [
            'require_superadmin_workspace_2fa' => !empty($row['require_superadmin_workspace_2fa']),
            'require_platform_workspace_2fa' => !empty($row['require_superadmin_workspace_2fa']),
            'updated_by_user_id' => !empty($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            'updated_at' => !empty($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    public function requiresPlatformWorkspace2FA(): bool
    {
        return (bool) $this->getSettings()['require_platform_workspace_2fa'];
    }

    public function requiresSuperadminWorkspace2FA(): bool
    {
        return $this->requiresPlatformWorkspace2FA();
    }

    public function setRequirePlatformWorkspace2FA(bool $required, int $actorUserId): void
    {
        $this->setRequireSuperadminWorkspace2FA($required, $actorUserId);
    }

    public function setRequireSuperadminWorkspace2FA(bool $required, int $actorUserId): void
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Platform security settings are unavailable. Run the latest migrations.');
        }

        Database::execute(
            "INSERT INTO platform_security_settings (id, require_superadmin_workspace_2fa, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                require_superadmin_workspace_2fa = VALUES(require_superadmin_workspace_2fa),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [self::SETTINGS_ID, $required ? 1 : 0, $actorUserId > 0 ? $actorUserId : null]
        );
    }

    /**
     * @return array{require_superadmin_workspace_2fa:bool,require_platform_workspace_2fa:bool,updated_by_user_id:?int,updated_at:?string}
     */
    private function defaults(): array
    {
        return [
            'require_superadmin_workspace_2fa' => false,
            'require_platform_workspace_2fa' => false,
            'updated_by_user_id' => null,
            'updated_at' => null,
        ];
    }

    private function ensureRow(): void
    {
        Database::execute(
            "INSERT INTO platform_security_settings (id, require_superadmin_workspace_2fa)
             VALUES (?, 0)
             ON DUPLICATE KEY UPDATE id = VALUES(id)",
            [self::SETTINGS_ID]
        );
    }

    private function tableExists(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_security_settings'"
            );
        } catch (\Throwable $e) {
            try {
                Database::query("SELECT 1 FROM platform_security_settings LIMIT 1");
                return true;
            } catch (\Throwable $ignored) {
                return false;
            }
        }
    }
}
