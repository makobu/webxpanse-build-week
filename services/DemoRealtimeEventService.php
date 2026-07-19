<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class DemoRealtimeEventService
{
    public function publish(int $workspaceId, int $sessionId, string $eventType, ?string $entityType = null, ?int $entityId = null, array $payload = []): int
    {
        if ($workspaceId <= 0 || $sessionId <= 0 || trim($eventType) === '') {
            return 0;
        }

        Database::execute(
            "INSERT INTO demo_realtime_events (workspace_id, demo_session_id, event_type, entity_type, entity_id, payload_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                mb_substr(trim($eventType), 0, 80),
                $entityType !== null ? mb_substr(trim($entityType), 0, 80) : null,
                $entityId,
                $payload !== [] ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function poll(array $session, int $afterId = 0, int $limit = 50): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $limit = min(100, max(1, $limit));

        $events = Database::query(
            "SELECT *
             FROM demo_realtime_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND id > ?
             ORDER BY id ASC
             LIMIT {$limit}",
            [$workspaceId, $sessionId, max(0, $afterId)]
        );

        foreach ($events as &$event) {
            $payload = $event['payload_json'] ?? null;
            $event['payload'] = is_string($payload) && $payload !== '' ? (json_decode($payload, true) ?: []) : [];
            unset($event['payload_json']);
        }
        unset($event);

        return $events;
    }
}
