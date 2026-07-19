<?php

namespace CRM\Services;

use CRM\Database;

final class SmsRateLimitService
{
    public function reserve(int $workspaceId, int $count = 1): void
    {
        if ($workspaceId <= 0 || $count <= 0) {
            throw new \InvalidArgumentException('A workspace and positive SMS count are required.');
        }
        if (!Database::tableExists('workspace_sms_rate_buckets')) {
            throw new \RuntimeException('SMS rate-limit storage is unavailable. Run migrations first.');
        }

        $buckets = [
            ['minute', date('Y-m-d H:i:00'), $this->limit('SMS_RATE_PER_MINUTE', 120, 1, 10000)],
            ['hour', date('Y-m-d H:00:00'), $this->limit('SMS_RATE_PER_HOUR', 1000, 1, 100000)],
            ['day', date('Y-m-d 00:00:00'), $this->limit('SMS_RATE_PER_DAY', 5000, 1, 1000000)],
        ];

        Database::beginTransaction();
        try {
            foreach ($buckets as [$type, $start, $limit]) {
                Database::execute(
                    "INSERT IGNORE INTO workspace_sms_rate_buckets (workspace_id, bucket_type, bucket_start, used_count)
                     VALUES (?, ?, ?, 0)",
                    [$workspaceId, $type, $start]
                );
                $bucket = Database::queryOne(
                    "SELECT used_count
                     FROM workspace_sms_rate_buckets
                     WHERE workspace_id = ? AND bucket_type = ? AND bucket_start = ?
                     FOR UPDATE",
                    [$workspaceId, $type, $start]
                );
                if ((int) ($bucket['used_count'] ?? 0) + $count > $limit) {
                    throw new \RuntimeException("Workspace SMS {$type} rate limit exceeded.");
                }
                Database::execute(
                    "UPDATE workspace_sms_rate_buckets
                     SET used_count = used_count + ?
                     WHERE workspace_id = ? AND bucket_type = ? AND bucket_start = ?",
                    [$count, $workspaceId, $type, $start]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function limit(string $key, int $default, int $minimum, int $maximum): int
    {
        $raw = getenv($key);
        $value = (int) ($raw !== false ? $raw : ($_ENV[$key] ?? $default));
        return min(max($value, $minimum), $maximum);
    }
}
