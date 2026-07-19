<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\ColdOutreachWarmupConfig;

class ColdOutreachWarmupService
{
    private ColdOutreachWarmupConfig $config;

    public function __construct(?ColdOutreachWarmupConfig $config = null)
    {
        $this->config = $config ?? new ColdOutreachWarmupConfig();
    }

    public function run(?int $userId = null): array
    {
        $results = [];
        foreach (['email', 'whatsapp'] as $channel) {
            $results[$channel] = $this->processChannel($channel, $userId);
        }

        return $results;
    }

    public function processChannel(string $channel, ?int $userId = null): array
    {
        $config = $this->config->get($channel);
        if (!$config['enabled'] || !$config['auto_admin_warmup_enabled']) {
            return $this->recordSkip($channel, $config, 'Warmup disabled for this channel.', $userId, ['skip_reason' => 'disabled']);
        }

        $lastAdjustedAt = $config['last_auto_adjusted_at'] ? strtotime((string) $config['last_auto_adjusted_at']) : false;
        if ($lastAdjustedAt !== false && $lastAdjustedAt > strtotime('-7 days')) {
            return $this->recordSkip($channel, $config, 'Weekly warmup window not reached yet.', $userId, ['skip_reason' => 'cooldown']);
        }

        $health = $this->evaluateHealth($channel);
        if (empty($health['can_increase'])) {
            return $this->recordSkip($channel, $config, (string) ($health['reason'] ?? 'Health gate blocked increase.'), $userId, $health);
        }

        $currentLimit = (int) ($config['current_daily_cold_limit'] ?? 0);
        $newLimit = min(
            (int) ($config['max_limit'] ?? $currentLimit),
            $currentLimit + max(0, (int) ($config['weekly_increment'] ?? 0))
        );

        if ($newLimit <= $currentLimit) {
            return $this->recordSkip($channel, $config, 'Current limit already at max limit.', $userId, ['skip_reason' => 'at_max'] + $health);
        }

        $updated = $this->config->save($channel, [
            'current_daily_cold_limit' => $newLimit,
            'last_auto_adjusted_at' => date('Y-m-d H:i:s'),
        ], $userId);

        Database::execute(
            "INSERT INTO cold_outreach_warmup_adjustments
                (channel, action_type, previous_limit, new_limit, reason, metadata_json, acted_by)
             VALUES (?, 'increase', ?, ?, ?, ?, ?)",
            [
                $channel,
                $currentLimit,
                $newLimit,
                'Auto Admin weekly warmup increase',
                json_encode($health),
                $userId,
            ]
        );

        return [
            'channel' => $channel,
            'action' => 'increase',
            'previous_limit' => $currentLimit,
            'new_limit' => $newLimit,
            'config' => $updated,
            'health' => $health,
        ];
    }

    private function evaluateHealth(string $channel): array
    {
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $sentCount = 0;
        $problemCount = 0;
        $rateLimitCount = 0;

        if ($channel === 'email') {
            $row = Database::queryOne(
                "SELECT
                    COUNT(*) AS sent_count,
                    SUM(CASE WHEN e.status IN ('bounced', 'failed') THEN 1 ELSE 0 END) AS problem_count
                 FROM cold_outreach_reservations r
                 JOIN emails e ON r.source_id = e.id
                 WHERE r.channel = 'email'
                   AND r.created_at >= ?
                   AND r.reservation_status = 'sent'",
                [$sevenDaysAgo]
            );
            $sentCount = (int) ($row['sent_count'] ?? 0);
            $problemCount = (int) ($row['problem_count'] ?? 0);
        } else {
            $row = Database::queryOne(
                "SELECT
                    COUNT(*) AS sent_count,
                    SUM(CASE WHEN wm.status = 'failed' THEN 1 ELSE 0 END) AS problem_count,
                    SUM(CASE WHEN wm.error_message LIKE '%rate limit%' OR wm.error_message LIKE '%131056%' THEN 1 ELSE 0 END) AS rate_limit_count
                 FROM cold_outreach_reservations r
                 JOIN whatsapp_messages wm ON r.source_id = wm.id
                 WHERE r.channel = 'whatsapp'
                   AND r.created_at >= ?
                   AND r.reservation_status = 'sent'",
                [$sevenDaysAgo]
            );
            $sentCount = (int) ($row['sent_count'] ?? 0);
            $problemCount = (int) ($row['problem_count'] ?? 0);
            $rateLimitCount = (int) ($row['rate_limit_count'] ?? 0);
        }

        if ($sentCount <= 0) {
            return [
                'can_increase' => false,
                'reason' => 'No recent cold outreach sends to evaluate.',
                'sent_count' => 0,
                'problem_rate' => 0,
                'rate_limit_count' => $rateLimitCount,
            ];
        }

        $problemRate = $sentCount > 0 ? $problemCount / $sentCount : 1;
        if ($channel === 'email' && $problemRate > 0.05) {
            return [
                'can_increase' => false,
                'reason' => 'Email failure or bounce rate is too high for a warmup increase.',
                'sent_count' => $sentCount,
                'problem_count' => $problemCount,
                'problem_rate' => $problemRate,
            ];
        }

        if ($channel === 'whatsapp' && ($problemRate > 0.05 || $rateLimitCount > 0)) {
            return [
                'can_increase' => false,
                'reason' => 'WhatsApp delivery failures or rate limits block a warmup increase.',
                'sent_count' => $sentCount,
                'problem_count' => $problemCount,
                'problem_rate' => $problemRate,
                'rate_limit_count' => $rateLimitCount,
            ];
        }

        return [
            'can_increase' => true,
            'reason' => 'Channel health is acceptable for a weekly warmup increase.',
            'sent_count' => $sentCount,
            'problem_count' => $problemCount,
            'problem_rate' => $problemRate,
            'rate_limit_count' => $rateLimitCount,
        ];
    }

    private function recordSkip(string $channel, array $config, string $reason, ?int $userId, array $metadata): array
    {
        Database::execute(
            "INSERT INTO cold_outreach_warmup_adjustments
                (channel, action_type, previous_limit, new_limit, reason, metadata_json, acted_by)
             VALUES (?, 'skip', ?, ?, ?, ?, ?)",
            [
                $channel,
                (int) ($config['current_daily_cold_limit'] ?? 0),
                (int) ($config['current_daily_cold_limit'] ?? 0),
                $reason,
                json_encode($metadata),
                $userId,
            ]
        );

        return [
            'channel' => $channel,
            'action' => 'skip',
            'previous_limit' => (int) ($config['current_daily_cold_limit'] ?? 0),
            'new_limit' => (int) ($config['current_daily_cold_limit'] ?? 0),
            'reason' => $reason,
            'health' => $metadata,
            'config' => $config,
        ];
    }
}
