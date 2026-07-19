<?php

namespace CRM\Services;

use CRM\Database;

final class QueueWorkerHeartbeatService
{
    private string $workerId;
    private int $lastTouchAt = 0;

    public function __construct(private readonly string $workerName, ?string $workerId = null)
    {
        $configured = trim((string) (getenv('QUEUE_WORKER_ID') ?: ($_ENV['QUEUE_WORKER_ID'] ?? '')));
        $this->workerId = substr(
            $workerId ?: ($configured ?: ((gethostname() ?: 'unknown-host') . ':' . (string) getmypid())),
            0,
            191
        );
    }

    public function start(array $metadata = []): void
    {
        Database::execute(
            "INSERT INTO queue_worker_heartbeats
                (worker_name, worker_id, hostname, process_id, status, started_at, heartbeat_at, stopped_at, metadata_json)
             VALUES (?, ?, ?, ?, 'running', NOW(), NOW(), NULL, ?)
             ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                process_id = VALUES(process_id),
                status = 'running',
                started_at = NOW(),
                heartbeat_at = NOW(),
                stopped_at = NULL,
                metadata_json = VALUES(metadata_json)",
            [
                substr($this->workerName, 0, 80),
                $this->workerId,
                substr(gethostname() ?: 'unknown-host', 0, 191),
                getmypid(),
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );
        $this->lastTouchAt = time();
    }

    public function touch(array $metadata = [], bool $force = false): void
    {
        if (!$force && $this->lastTouchAt > 0 && time() - $this->lastTouchAt < 10) {
            return;
        }

        Database::execute(
            "UPDATE queue_worker_heartbeats
             SET status = 'running', heartbeat_at = NOW(), stopped_at = NULL,
                 metadata_json = COALESCE(?, metadata_json)
             WHERE worker_name = ? AND worker_id = ?",
            [
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                substr($this->workerName, 0, 80),
                $this->workerId,
            ]
        );
        $this->lastTouchAt = time();
    }

    public function stop(string $status = 'stopped', array $metadata = []): void
    {
        $status = $status === 'failed' ? 'failed' : 'stopped';
        Database::execute(
            "UPDATE queue_worker_heartbeats
             SET status = ?, heartbeat_at = NOW(), stopped_at = NOW(),
                 metadata_json = COALESCE(?, metadata_json)
             WHERE worker_name = ? AND worker_id = ?",
            [
                $status,
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                substr($this->workerName, 0, 80),
                $this->workerId,
            ]
        );
    }

    public function workerId(): string
    {
        return $this->workerId;
    }
}
