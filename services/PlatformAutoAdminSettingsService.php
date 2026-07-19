<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\UserPreferences;

class PlatformAutoAdminSettingsService
{
    private const SETTINGS_ID = 1;
    private const LEGACY_GLOBAL_USER_ID = 1;

    private UserPreferences $userPreferences;

    public function __construct(?UserPreferences $userPreferences = null)
    {
        $this->userPreferences = $userPreferences ?? new UserPreferences();
    }

    /**
     * @return array{enabled:bool,updated_by_user_id:?int,updated_at:?string}
     */
    public function getSettings(): array
    {
        if (!$this->tableExists()) {
            return [
                'enabled' => $this->userPreferences->isAutoAdminEnabled(self::LEGACY_GLOBAL_USER_ID),
                'updated_by_user_id' => null,
                'updated_at' => null,
            ];
        }

        $this->ensureRow();
        $row = Database::queryOne(
            "SELECT enabled, updated_by_user_id, updated_at
             FROM platform_auto_admin_settings
             WHERE id = ?
             LIMIT 1",
            [self::SETTINGS_ID]
        );

        if (!$row) {
            return [
                'enabled' => false,
                'updated_by_user_id' => null,
                'updated_at' => null,
            ];
        }

        return [
            'enabled' => !empty($row['enabled']),
            'updated_by_user_id' => !empty($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            'updated_at' => !empty($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getSettings()['enabled'];
    }

    public function setEnabled(bool $enabled, int $actorUserId): void
    {
        if (!$this->tableExists()) {
            $this->userPreferences->setAutoAdminEnabled(self::LEGACY_GLOBAL_USER_ID, $enabled);
            return;
        }

        Database::execute(
            "INSERT INTO platform_auto_admin_settings (id, enabled, updated_by_user_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [self::SETTINGS_ID, $enabled ? 1 : 0, $actorUserId > 0 ? $actorUserId : null]
        );
    }

    private function ensureRow(): void
    {
        $legacyEnabled = $this->userPreferences->isAutoAdminEnabled(self::LEGACY_GLOBAL_USER_ID);

        Database::execute(
            "INSERT INTO platform_auto_admin_settings (id, enabled)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = VALUES(id)",
            [self::SETTINGS_ID, $legacyEnabled ? 1 : 0]
        );
    }

    private function tableExists(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_auto_admin_settings'"
            );
        } catch (\Throwable $e) {
            try {
                Database::query("SELECT 1 FROM platform_auto_admin_settings LIMIT 1");
                return true;
            } catch (\Throwable $ignored) {
                return false;
            }
        }
    }
}
