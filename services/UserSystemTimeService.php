<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Session;

class UserSystemTimeService
{
    private const HEARTBEAT_INTERVAL_SECONDS = 60;
    private const ACTIVE_GAP_CAP_SECONDS = 300;

    public function startSession(int $userId, ?int $workspaceId = null, array $metadata = []): void
    {
        if ($userId <= 0 || !$this->available()) {
            return;
        }

        $sessionHash = $this->sessionHash();
        if ($sessionHash === '') {
            return;
        }

        $workspaceId = $workspaceId !== null && $workspaceId > 0 ? $workspaceId : null;

        try {
            $this->closeOpenSessionByHash($sessionHash, 'replaced');
            Database::execute(
                "INSERT INTO user_system_sessions (
                    workspace_id, user_id, session_id_hash, started_at, last_seen_at,
                    ip_address, user_agent, metadata_json
                 ) VALUES (?, ?, ?, NOW(), NOW(), ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    $sessionHash,
                    $this->clientIp(),
                    $this->userAgent(),
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                ]
            );

            $row = Database::queryOne("SELECT LAST_INSERT_ID() AS id");
            $sessionId = (int) ($row['id'] ?? 0);
            if ($sessionId > 0) {
                Session::set('system_session_activity_id', $sessionId);
                Session::set('system_session_last_heartbeat_ts', time());
            }
        } catch (\Throwable $e) {
            error_log('User system session start failed: ' . $e->getMessage());
        }
    }

    public function heartbeat(?int $userId = null, ?int $workspaceId = null): void
    {
        if (!$this->available()) {
            return;
        }

        $now = time();
        $lastHeartbeat = (int) Session::get('system_session_last_heartbeat_ts', 0);
        if ($lastHeartbeat > 0 && ($now - $lastHeartbeat) < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        $sessionActivityId = (int) Session::get('system_session_activity_id', 0);
        if ($sessionActivityId <= 0) {
            if ($userId !== null && $userId > 0) {
                $this->startSession($userId, $workspaceId, ['source' => 'heartbeat_recovery']);
            }
            return;
        }

        try {
            Database::execute(
                "UPDATE user_system_sessions
                 SET active_seconds = active_seconds + LEAST(GREATEST(TIMESTAMPDIFF(SECOND, COALESCE(last_seen_at, started_at), NOW()), 0), ?),
                     last_seen_at = NOW(),
                     workspace_id = COALESCE(workspace_id, ?),
                     duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                 WHERE id = ?
                   AND ended_at IS NULL",
                [self::ACTIVE_GAP_CAP_SECONDS, $workspaceId !== null && $workspaceId > 0 ? $workspaceId : null, $sessionActivityId]
            );
            Session::set('system_session_last_heartbeat_ts', $now);
        } catch (\Throwable $e) {
            error_log('User system session heartbeat failed: ' . $e->getMessage());
        }
    }

    public function endSession(?int $userId = null, string $reason = 'logout'): void
    {
        if (!$this->available()) {
            return;
        }

        $sessionActivityId = (int) Session::get('system_session_activity_id', 0);
        $sessionHash = $this->sessionHash();

        try {
            if ($sessionActivityId > 0) {
                Database::execute(
                    "UPDATE user_system_sessions
                     SET active_seconds = active_seconds + LEAST(GREATEST(TIMESTAMPDIFF(SECOND, COALESCE(last_seen_at, started_at), NOW()), 0), ?),
                         last_seen_at = NOW(),
                         ended_at = NOW(),
                         duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW())),
                         end_reason = ?
                     WHERE id = ?
                       AND ended_at IS NULL",
                    [self::ACTIVE_GAP_CAP_SECONDS, $this->safeReason($reason), $sessionActivityId]
                );
            } elseif ($sessionHash !== '') {
                $this->closeOpenSessionByHash($sessionHash, $reason, $userId);
            }
        } catch (\Throwable $e) {
            error_log('User system session end failed: ' . $e->getMessage());
        } finally {
            Session::remove('system_session_activity_id');
            Session::remove('system_session_last_heartbeat_ts');
        }
    }

    private function closeOpenSessionByHash(string $sessionHash, string $reason, ?int $userId = null): void
    {
        if ($sessionHash === '') {
            return;
        }

        $where = 'session_id_hash = ? AND ended_at IS NULL';
        $params = [$sessionHash];
        if ($userId !== null && $userId > 0) {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }

        Database::execute(
            "UPDATE user_system_sessions
             SET active_seconds = active_seconds + LEAST(GREATEST(TIMESTAMPDIFF(SECOND, COALESCE(last_seen_at, started_at), NOW()), 0), ?),
                 last_seen_at = NOW(),
                 ended_at = NOW(),
                 duration_seconds = GREATEST(duration_seconds, TIMESTAMPDIFF(SECOND, started_at, NOW())),
                 end_reason = ?
             WHERE {$where}",
            array_merge([self::ACTIVE_GAP_CAP_SECONDS, $this->safeReason($reason)], $params)
        );
    }

    private function available(): bool
    {
        return Database::tableExists('user_system_sessions');
    }

    private function sessionHash(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }

        $sessionId = session_id();
        return $sessionId !== '' ? hash('sha256', $sessionId) : '';
    }

    private function clientIp(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip !== '' ? substr($ip, 0, 45) : null;
    }

    private function userAgent(): ?string
    {
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return $userAgent !== '' ? substr($userAgent, 0, 500) : null;
    }

    private function safeReason(string $reason): string
    {
        $reason = strtolower(trim($reason));
        return $reason !== '' ? substr($reason, 0, 40) : 'logout';
    }
}
