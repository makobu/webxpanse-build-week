<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceAutomationReadinessSettingsService
{
    public const DEFAULT_CADENCE = 'six_hours';
    public const MANUAL_REFRESH_THROTTLE_SECONDS = 60;

    private const SETTINGS_PATH = 'automation_readiness';

    private const CADENCES = [
        'manual_only' => [
            'label' => 'Manual only',
            'seconds' => null,
        ],
        'hourly' => [
            'label' => 'Every hour',
            'seconds' => 3600,
        ],
        'six_hours' => [
            'label' => 'Every 6 hours',
            'seconds' => 21600,
        ],
        'daily' => [
            'label' => 'Daily',
            'seconds' => 86400,
        ],
    ];

    /**
     * @return array<string,array{label:string,seconds:?int}>
     */
    public static function cadenceOptions(): array
    {
        return self::CADENCES;
    }

    /**
     * @return array{workspace_id:int,cadence:string,key:string,label:string,seconds:?int,automatic_enabled:bool,manual_throttle_seconds:int}
     */
    public function getPolicy(int $workspaceId): array
    {
        $settings = $this->readSettings($workspaceId);
        $readinessSettings = is_array($settings[self::SETTINGS_PATH] ?? null)
            ? $settings[self::SETTINGS_PATH]
            : [];
        $cadence = $this->normalizeCadence((string) ($readinessSettings['refresh_cadence'] ?? self::DEFAULT_CADENCE));

        return $this->buildPolicy($workspaceId, $cadence);
    }

    /**
     * @return array{workspace_id:int,cadence:string,key:string,label:string,seconds:?int,automatic_enabled:bool,manual_throttle_seconds:int}
     */
    public function savePolicy(int $workspaceId, string $cadence, int $actorUserId): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for automation readiness refresh settings.');
        }

        if (!Database::tableExists('workspaces') || !Database::columnExists('workspaces', 'settings_json')) {
            throw new \RuntimeException('Workspace settings are unavailable. Run the latest migrations.');
        }

        $cadence = $this->normalizeCadence($cadence);
        $settings = $this->readSettings($workspaceId);
        $readinessSettings = is_array($settings[self::SETTINGS_PATH] ?? null)
            ? $settings[self::SETTINGS_PATH]
            : [];
        $readinessSettings['refresh_cadence'] = $cadence;
        $readinessSettings['refresh_cadence_updated_at'] = gmdate('c');
        $readinessSettings['refresh_cadence_updated_by_user_id'] = $actorUserId > 0 ? $actorUserId : null;
        $settings[self::SETTINGS_PATH] = $readinessSettings;

        $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Automation readiness refresh settings could not be encoded.');
        }

        Database::execute(
            "UPDATE workspaces SET settings_json = ? WHERE id = ?",
            [$encoded, $workspaceId]
        );

        return $this->buildPolicy($workspaceId, $cadence);
    }

    public function normalizeCadence(string $cadence): string
    {
        $normalized = strtolower(trim(str_replace(['-', ' '], '_', $cadence)));
        return array_key_exists($normalized, self::CADENCES) ? $normalized : self::DEFAULT_CADENCE;
    }

    /**
     * @param array<string,mixed> $policy
     */
    public function isAutomaticEnabled(array $policy): bool
    {
        return array_key_exists('seconds', $policy) && $policy['seconds'] !== null;
    }

    /**
     * @return array<string,mixed>
     */
    private function readSettings(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspaces') || !Database::columnExists('workspaces', 'settings_json')) {
            return [];
        }

        $row = Database::queryOne(
            "SELECT settings_json FROM workspaces WHERE id = ? LIMIT 1",
            [$workspaceId]
        );
        if (!$row) {
            return [];
        }

        $settingsJson = trim((string) ($row['settings_json'] ?? ''));
        if ($settingsJson === '') {
            return [];
        }

        $decoded = json_decode($settingsJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{workspace_id:int,cadence:string,key:string,label:string,seconds:?int,automatic_enabled:bool,manual_throttle_seconds:int}
     */
    private function buildPolicy(int $workspaceId, string $cadence): array
    {
        $option = self::CADENCES[$this->normalizeCadence($cadence)];
        $seconds = $option['seconds'];

        return [
            'workspace_id' => max(0, $workspaceId),
            'cadence' => $this->normalizeCadence($cadence),
            'key' => $this->normalizeCadence($cadence),
            'label' => (string) $option['label'],
            'seconds' => $seconds === null ? null : (int) $seconds,
            'automatic_enabled' => $seconds !== null,
            'manual_throttle_seconds' => self::MANUAL_REFRESH_THROTTLE_SECONDS,
        ];
    }
}
