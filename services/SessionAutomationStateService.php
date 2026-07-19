<?php

namespace CRM\Services;

use CRM\Database;

class SessionAutomationStateService
{
    private static ?bool $tableReady = null;

    public function shouldAttempt(int $userId, string $featureKey, int $cooldownSeconds): bool
    {
        $this->ensureTable();
        if ($userId <= 0 || $featureKey === '') {
            return false;
        }

        if ($cooldownSeconds <= 0) {
            return true;
        }

        $row = $this->getState($userId, $featureKey);
        if (!$row) {
            return true;
        }

        $attemptedAt = strtotime((string) ($row['last_attempt_at'] ?? ''));
        if ($attemptedAt === false) {
            return true;
        }

        return (time() - $attemptedAt) >= $cooldownSeconds;
    }

    public function markAttempt(int $userId, string $featureKey, array $payload = []): void
    {
        $this->persistState($userId, $featureKey, 'running', $payload, false);
    }

    public function markResult(int $userId, string $featureKey, string $status, array $payload = [], bool $success = false): void
    {
        $this->persistState($userId, $featureKey, $status, $payload, $success);
    }

    public function getState(int $userId, string $featureKey): ?array
    {
        $this->ensureTable();
        return Database::queryOne(
            "SELECT *
             FROM session_automation_state
             WHERE user_id = ? AND feature_key = ?
             LIMIT 1",
            [$userId, $featureKey]
        );
    }

    public function pruneOldRows(int $retentionDays = 30): int
    {
        $this->ensureTable();
        $retentionDays = max(1, min(365, $retentionDays));

        return Database::execute(
            "DELETE FROM session_automation_state
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)"
        );
    }

    private function persistState(int $userId, string $featureKey, string $status, array $payload, bool $success): void
    {
        $this->ensureTable();
        if ($userId <= 0 || $featureKey === '') {
            return;
        }

        $allowedStatuses = ['idle', 'running', 'ok', 'skipped', 'failed'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'failed';
        }

        $sql = "INSERT INTO session_automation_state
                    (user_id, feature_key, last_status, last_attempt_at, last_success_at, last_result_json)
                VALUES (?, ?, ?, NOW(), ?, ?)
                ON DUPLICATE KEY UPDATE
                    last_status = VALUES(last_status),
                    last_attempt_at = NOW(),
                    last_success_at = VALUES(last_success_at),
                    last_result_json = VALUES(last_result_json)";

        Database::execute($sql, [
            $userId,
            $featureKey,
            $status,
            $success ? date('Y-m-d H:i:s') : null,
            json_encode($payload),
        ]);
    }

    private function ensureTable(): void
    {
        if (self::$tableReady === true) {
            return;
        }

        $exists = Database::queryOne(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'session_automation_state'"
        );

        if ($exists) {
            self::$tableReady = true;
            return;
        }

        Database::execute(
            "CREATE TABLE IF NOT EXISTS session_automation_state (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                feature_key VARCHAR(100) NOT NULL,
                last_status ENUM('idle', 'running', 'ok', 'skipped', 'failed') NOT NULL DEFAULT 'idle',
                last_attempt_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_result_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_session_automation_user_feature (user_id, feature_key),
                INDEX idx_session_automation_user_attempt (user_id, last_attempt_at),
                INDEX idx_session_automation_feature_attempt (feature_key, last_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$tableReady = true;
    }
}
