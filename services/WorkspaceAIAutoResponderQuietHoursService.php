<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceAIAutoResponderQuietHoursService
{
    private const DEFAULTS = [
        'enabled' => false,
        'start' => '20:00',
        'end' => '08:00',
        'timezone' => 'UTC',
    ];

    private const COMMON_TIMEZONE_LABELS = [
        'UTC' => 'UTC',
        'Africa/Nairobi' => 'Africa/Nairobi',
        'Africa/Kampala' => 'Africa/Kampala',
        'Africa/Dar_es_Salaam' => 'Africa/Dar es Salaam',
        'Africa/Lagos' => 'Africa/Lagos',
        'Africa/Accra' => 'Africa/Accra',
        'Africa/Johannesburg' => 'Africa/Johannesburg',
        'Africa/Cairo' => 'Africa/Cairo',
        'Europe/London' => 'Europe/London',
        'Europe/Paris' => 'Europe/Paris',
        'Europe/Berlin' => 'Europe/Berlin',
        'Europe/Madrid' => 'Europe/Madrid',
        'Europe/Rome' => 'Europe/Rome',
        'Asia/Dubai' => 'Asia/Dubai',
        'Asia/Kolkata' => 'Asia/Kolkata',
        'Asia/Singapore' => 'Asia/Singapore',
        'Asia/Tokyo' => 'Asia/Tokyo',
        'Australia/Sydney' => 'Australia/Sydney',
        'America/New_York' => 'America/New York',
        'America/Chicago' => 'America/Chicago',
        'America/Denver' => 'America/Denver',
        'America/Los_Angeles' => 'America/Los Angeles',
        'America/Toronto' => 'America/Toronto',
        'America/Mexico_City' => 'America/Mexico City',
        'America/Sao_Paulo' => 'America/Sao Paulo',
    ];

    private const LOCATION_TIMEZONE_ALIASES = [
        'nairobi' => 'Africa/Nairobi',
        'kenya' => 'Africa/Nairobi',
        'kampala' => 'Africa/Kampala',
        'uganda' => 'Africa/Kampala',
        'dar es salaam' => 'Africa/Dar_es_Salaam',
        'tanzania' => 'Africa/Dar_es_Salaam',
        'rwanda' => 'Africa/Kigali',
        'kigali' => 'Africa/Kigali',
        'ethiopia' => 'Africa/Addis_Ababa',
        'addis ababa' => 'Africa/Addis_Ababa',
        'nigeria' => 'Africa/Lagos',
        'lagos' => 'Africa/Lagos',
        'ghana' => 'Africa/Accra',
        'accra' => 'Africa/Accra',
        'south africa' => 'Africa/Johannesburg',
        'johannesburg' => 'Africa/Johannesburg',
        'egypt' => 'Africa/Cairo',
        'cairo' => 'Africa/Cairo',
        'morocco' => 'Africa/Casablanca',
        'united kingdom' => 'Europe/London',
        'uk' => 'Europe/London',
        'england' => 'Europe/London',
        'london' => 'Europe/London',
        'ireland' => 'Europe/Dublin',
        'france' => 'Europe/Paris',
        'paris' => 'Europe/Paris',
        'germany' => 'Europe/Berlin',
        'berlin' => 'Europe/Berlin',
        'spain' => 'Europe/Madrid',
        'madrid' => 'Europe/Madrid',
        'italy' => 'Europe/Rome',
        'rome' => 'Europe/Rome',
        'netherlands' => 'Europe/Amsterdam',
        'sweden' => 'Europe/Stockholm',
        'norway' => 'Europe/Oslo',
        'denmark' => 'Europe/Copenhagen',
        'finland' => 'Europe/Helsinki',
        'poland' => 'Europe/Warsaw',
        'turkey' => 'Europe/Istanbul',
        'uae' => 'Asia/Dubai',
        'united arab emirates' => 'Asia/Dubai',
        'dubai' => 'Asia/Dubai',
        'saudi arabia' => 'Asia/Riyadh',
        'qatar' => 'Asia/Qatar',
        'israel' => 'Asia/Jerusalem',
        'india' => 'Asia/Kolkata',
        'kolkata' => 'Asia/Kolkata',
        'pakistan' => 'Asia/Karachi',
        'bangladesh' => 'Asia/Dhaka',
        'sri lanka' => 'Asia/Colombo',
        'nepal' => 'Asia/Kathmandu',
        'china' => 'Asia/Shanghai',
        'hong kong' => 'Asia/Hong_Kong',
        'singapore' => 'Asia/Singapore',
        'malaysia' => 'Asia/Kuala_Lumpur',
        'philippines' => 'Asia/Manila',
        'indonesia' => 'Asia/Jakarta',
        'thailand' => 'Asia/Bangkok',
        'vietnam' => 'Asia/Ho_Chi_Minh',
        'japan' => 'Asia/Tokyo',
        'south korea' => 'Asia/Seoul',
        'australia' => 'Australia/Sydney',
        'sydney' => 'Australia/Sydney',
        'new zealand' => 'Pacific/Auckland',
        'united states' => 'America/New_York',
        'usa' => 'America/New_York',
        'new york' => 'America/New_York',
        'los angeles' => 'America/Los_Angeles',
        'chicago' => 'America/Chicago',
        'denver' => 'America/Denver',
        'canada' => 'America/Toronto',
        'toronto' => 'America/Toronto',
        'mexico' => 'America/Mexico_City',
        'brazil' => 'America/Sao_Paulo',
        'argentina' => 'America/Argentina/Buenos_Aires',
        'colombia' => 'America/Bogota',
        'peru' => 'America/Lima',
        'chile' => 'America/Santiago',
    ];

    /**
     * @return array{enabled:bool,start:string,end:string,timezone:string}
     */
    public function get(int $workspaceId): array
    {
        $defaults = $this->defaultsForWorkspace($workspaceId);
        if ($workspaceId <= 0 || !$this->tableExists()) {
            return $defaults;
        }

        $row = Database::queryOne(
            "SELECT enabled, start_time, end_time, timezone, updated_by_user_id
             FROM workspace_ai_autoresponder_quiet_hours
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );

        if (!$row) {
            return $defaults;
        }

        return [
            'enabled' => !empty($row['enabled']),
            'start' => $this->normalizeStoredTime((string) ($row['start_time'] ?? $defaults['start']), $defaults['start']),
            'end' => $this->normalizeStoredTime((string) ($row['end_time'] ?? $defaults['end']), $defaults['end']),
            'timezone' => $this->resolveStoredTimezone(
                $workspaceId,
                (string) ($row['timezone'] ?? $defaults['timezone']),
                empty($row['updated_by_user_id'])
            ),
        ];
    }

    /**
     * @return array<string,string>
     */
    public function timezoneOptions(int $workspaceId = 0): array
    {
        $preferred = $this->defaultTimezoneForWorkspace($workspaceId);
        $ordered = [$preferred, ...array_keys(self::COMMON_TIMEZONE_LABELS), ...\DateTimeZone::listIdentifiers()];
        $options = [];

        foreach ($ordered as $timezone) {
            $timezone = $this->normalizeTimezoneIdentifier((string) $timezone);
            if ($timezone === '' || isset($options[$timezone])) {
                continue;
            }
            $options[$timezone] = self::COMMON_TIMEZONE_LABELS[$timezone] ?? $timezone;
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{enabled:bool,start:string,end:string,timezone:string}
     */
    public function save(int $workspaceId, array $input, int $updatedByUserId): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for AI auto-responder quiet hours.');
        }

        $quietHours = [
            'enabled' => !empty($input['enabled']),
            'start' => $this->normalizeInputTime((string) ($input['start'] ?? self::DEFAULTS['start']), self::DEFAULTS['start']),
            'end' => $this->normalizeInputTime((string) ($input['end'] ?? self::DEFAULTS['end']), self::DEFAULTS['end']),
            'timezone' => $this->normalizeTimezone(
                (string) ($input['timezone'] ?? ''),
                $this->defaultTimezoneForWorkspace($workspaceId)
            ),
        ];

        Database::execute(
            "INSERT INTO workspace_ai_autoresponder_quiet_hours
                (workspace_id, enabled, start_time, end_time, timezone, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                timezone = VALUES(timezone),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $workspaceId,
                $quietHours['enabled'] ? 1 : 0,
                $quietHours['start'] . ':00',
                $quietHours['end'] . ':00',
                $quietHours['timezone'],
                $updatedByUserId > 0 ? $updatedByUserId : null,
            ]
        );

        return $quietHours;
    }

    private function normalizeInputTime(string $value, string $default): string
    {
        $value = trim($value);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return $value;
        }

        return $default;
    }

    private function normalizeStoredTime(string $value, string $default): string
    {
        $value = trim($value);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            return substr($value, 0, 5);
        }

        return $default;
    }

    private function normalizeTimezone(string $value, string $fallback = 'UTC'): string
    {
        $timezone = $this->normalizeTimezoneIdentifier($value);
        if ($timezone !== '') {
            return $timezone;
        }

        $fallbackTimezone = $this->normalizeTimezoneIdentifier($fallback);
        return $fallbackTimezone !== '' ? $fallbackTimezone : self::DEFAULTS['timezone'];
    }

    private function normalizeTimezoneIdentifier(string $value): string
    {
        $timezone = trim($value);
        if ((str_starts_with($timezone, '"') && str_ends_with($timezone, '"'))
            || (str_starts_with($timezone, "'") && str_ends_with($timezone, "'"))
        ) {
            $timezone = trim($timezone, "\"'");
        }
        if ($timezone === '') {
            return '';
        }

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable $e) {
            // Try location/country aliases below.
        }

        $normalized = strtolower($timezone);
        $normalized = str_replace(['.', ',', '_', '-'], [' ', ' ', ' ', ' '], $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: '';
        $normalized = trim($normalized);

        foreach (self::LOCATION_TIMEZONE_ALIASES as $alias => $iana) {
            if (preg_match('/(^|\s)' . preg_quote($alias, '/') . '($|\s)/', $normalized) === 1) {
                return $iana;
            }
        }

        if (preg_match('/^utc\s*([+-])\s*(\d{1,2})(?::?(\d{2}))?$/i', $normalized, $m)) {
            $sign = $m[1] === '-' ? -1 : 1;
            $hours = (int) $m[2];
            $mins = isset($m[3]) ? (int) $m[3] : 0;
            $offset = $sign * (($hours * 60) + $mins);
            $name = timezone_name_from_abbr('', $offset * 60, 0);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * @return array{enabled:bool,start:string,end:string,timezone:string}
     */
    private function defaultsForWorkspace(int $workspaceId): array
    {
        $defaults = self::DEFAULTS;
        $defaults['timezone'] = $this->defaultTimezoneForWorkspace($workspaceId);

        return $defaults;
    }

    private function defaultTimezoneForWorkspace(int $workspaceId): string
    {
        foreach ($this->workspaceTimezoneCandidates($workspaceId) as $candidate) {
            $timezone = $this->normalizeTimezoneIdentifier($candidate);
            if ($timezone !== '') {
                return $timezone;
            }
        }

        return self::DEFAULTS['timezone'];
    }

    private function resolveStoredTimezone(int $workspaceId, string $storedTimezone, bool $isSystemSeeded): string
    {
        $workspaceDefault = $this->defaultTimezoneForWorkspace($workspaceId);
        $stored = $this->normalizeTimezoneIdentifier($storedTimezone);

        if ($stored !== '' && (!$isSystemSeeded || $stored !== self::DEFAULTS['timezone'] || $workspaceDefault === self::DEFAULTS['timezone'])) {
            return $stored;
        }

        return $workspaceDefault;
    }

    /**
     * @return list<string>
     */
    private function workspaceTimezoneCandidates(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('company_profile')) {
            return [];
        }

        $columns = [];
        foreach (['company_timezone', 'company_location', 'company_address'] as $column) {
            if (Database::columnExists('company_profile', $column)) {
                $columns[] = $column;
            }
        }
        if ($columns === []) {
            return [];
        }

        $where = 'is_active = TRUE';
        $params = [];
        if (Database::columnExists('company_profile', 'workspace_id')) {
            $where = 'workspace_id = ? AND ' . $where;
            $params[] = $workspaceId;
        }

        try {
            $row = Database::queryOne(
                'SELECT ' . implode(', ', $columns) . "
                 FROM company_profile
                 WHERE {$where}
                 ORDER BY id DESC
                 LIMIT 1",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (!$row) {
            return [];
        }

        $candidates = [];
        foreach ($columns as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        return $candidates;
    }

    private function tableExists(): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'workspace_ai_autoresponder_quiet_hours'"
            );
            return ((int) ($row['cnt'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
