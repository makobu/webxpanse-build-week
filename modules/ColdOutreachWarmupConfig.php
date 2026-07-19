<?php

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class ColdOutreachWarmupConfig
{
    private const CHANNELS = ['email', 'whatsapp'];

    private const DEFAULTS = [
        'enabled' => false,
        'initial_daily_cold_limit' => 10,
        'current_daily_cold_limit' => 10,
        'auto_admin_warmup_enabled' => false,
        'weekly_increment' => 5,
        'max_limit' => 50,
        'last_auto_adjusted_at' => null,
        'updated_by' => null,
        'updated_at' => null,
    ];

    public function get(string $channel, ?int $workspaceId = null): array
    {
        $channel = $this->normalizeChannel($channel);
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = null;
        if ($workspaceId > 0 && Database::tableExists('workspace_cold_outreach_warmup_config')) {
            $row = Database::queryOne(
                "SELECT * FROM workspace_cold_outreach_warmup_config WHERE workspace_id = ? AND channel = ? LIMIT 1",
                [$workspaceId, $channel]
            );
        }
        if (!$row) {
            $row = Database::queryOne(
                "SELECT * FROM cold_outreach_warmup_config WHERE channel = ? LIMIT 1",
                [$channel]
            );
        }

        if (!$row) {
            return array_merge(['workspace_id' => $workspaceId, 'channel' => $channel], self::DEFAULTS);
        }

        return $this->normalizeRow($row);
    }

    public function getAll(?int $workspaceId = null): array
    {
        $configs = [];
        foreach (self::CHANNELS as $channel) {
            $configs[$channel] = $this->get($channel, $workspaceId);
        }

        return $configs;
    }

    public function save(string $channel, array $data, ?int $userId = null, ?int $workspaceId = null): array
    {
        $channel = $this->normalizeChannel($channel);
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $current = $this->get($channel, $workspaceId > 0 ? $workspaceId : null);
        $merged = array_merge($current, $data);

        $enabled = !empty($merged['enabled']);
        $autoAdminWarmupEnabled = !empty($merged['auto_admin_warmup_enabled']);
        $initialLimit = max(0, (int) ($merged['initial_daily_cold_limit'] ?? self::DEFAULTS['initial_daily_cold_limit']));
        $currentLimit = max(0, (int) ($merged['current_daily_cold_limit'] ?? $initialLimit));
        $weeklyIncrement = max(0, (int) ($merged['weekly_increment'] ?? self::DEFAULTS['weekly_increment']));
        $maxLimit = max($currentLimit, max(0, (int) ($merged['max_limit'] ?? self::DEFAULTS['max_limit'])));
        $currentLimit = min($currentLimit, $maxLimit);
        $initialLimit = min($initialLimit, $maxLimit);
        $lastAutoAdjustedAt = $merged['last_auto_adjusted_at'] ?? null;

        if ($workspaceId > 0 && Database::tableExists('workspace_cold_outreach_warmup_config')) {
            Database::execute(
                "INSERT INTO workspace_cold_outreach_warmup_config
                    (workspace_id, channel, enabled, initial_daily_cold_limit, current_daily_cold_limit,
                     auto_admin_warmup_enabled, weekly_increment, max_limit, last_auto_adjusted_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    initial_daily_cold_limit = VALUES(initial_daily_cold_limit),
                    current_daily_cold_limit = VALUES(current_daily_cold_limit),
                    auto_admin_warmup_enabled = VALUES(auto_admin_warmup_enabled),
                    weekly_increment = VALUES(weekly_increment),
                    max_limit = VALUES(max_limit),
                    last_auto_adjusted_at = VALUES(last_auto_adjusted_at),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $workspaceId,
                    $channel,
                    $enabled ? 1 : 0,
                    $initialLimit,
                    $currentLimit,
                    $autoAdminWarmupEnabled ? 1 : 0,
                    $weeklyIncrement,
                    $maxLimit,
                    $lastAutoAdjustedAt,
                    $userId,
                ]
            );

            return $this->get($channel, $workspaceId);
        }

        Database::execute(
            "INSERT INTO cold_outreach_warmup_config
                (channel, enabled, initial_daily_cold_limit, current_daily_cold_limit, auto_admin_warmup_enabled, weekly_increment, max_limit, last_auto_adjusted_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                initial_daily_cold_limit = VALUES(initial_daily_cold_limit),
                current_daily_cold_limit = VALUES(current_daily_cold_limit),
                auto_admin_warmup_enabled = VALUES(auto_admin_warmup_enabled),
                weekly_increment = VALUES(weekly_increment),
                max_limit = VALUES(max_limit),
                last_auto_adjusted_at = VALUES(last_auto_adjusted_at),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP",
            [
                $channel,
                $enabled ? 1 : 0,
                $initialLimit,
                $currentLimit,
                $autoAdminWarmupEnabled ? 1 : 0,
                $weeklyIncrement,
                $maxLimit,
                $lastAutoAdjustedAt,
                $userId,
            ]
        );

        return $this->get($channel);
    }

    public function getUsageSummary(string $channel, ?string $date = null): array
    {
        $channel = $this->normalizeChannel($channel);
        $date = $date ?: date('Y-m-d');
        $row = Database::queryOne(
            "SELECT
                SUM(CASE WHEN reservation_status IN ('reserved', 'sent') THEN 1 ELSE 0 END) AS reserved_or_sent,
                SUM(CASE WHEN reservation_status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN reservation_status = 'failed' THEN 1 ELSE 0 END) AS failed_count
             FROM cold_outreach_reservations
             WHERE channel = ?
               AND reserved_for_date = ?",
            [$channel, $date]
        );

        return [
            'date' => $date,
            'reserved_or_sent' => (int) ($row['reserved_or_sent'] ?? 0),
            'sent_count' => (int) ($row['sent_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
        ];
    }

    public function getRecentAdjustments(string $channel, int $limit = 10): array
    {
        $channel = $this->normalizeChannel($channel);
        return Database::query(
            "SELECT *
             FROM cold_outreach_warmup_adjustments
             WHERE channel = ?
             ORDER BY created_at DESC, id DESC
             LIMIT " . max(1, min(50, $limit)),
            [$channel]
        );
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Unsupported cold outreach channel.');
        }

        return $channel;
    }

    private function normalizeRow(array $row): array
    {
        return [
            'workspace_id' => isset($row['workspace_id']) ? (int) $row['workspace_id'] : null,
            'channel' => (string) ($row['channel'] ?? ''),
            'enabled' => !empty($row['enabled']),
            'initial_daily_cold_limit' => (int) ($row['initial_daily_cold_limit'] ?? self::DEFAULTS['initial_daily_cold_limit']),
            'current_daily_cold_limit' => (int) ($row['current_daily_cold_limit'] ?? self::DEFAULTS['current_daily_cold_limit']),
            'auto_admin_warmup_enabled' => !empty($row['auto_admin_warmup_enabled']),
            'weekly_increment' => (int) ($row['weekly_increment'] ?? self::DEFAULTS['weekly_increment']),
            'max_limit' => (int) ($row['max_limit'] ?? self::DEFAULTS['max_limit']),
            'last_auto_adjusted_at' => $row['last_auto_adjusted_at'] ?? null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        try {
            return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
