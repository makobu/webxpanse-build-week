<?php

namespace CRM\Services;

use CRM\Database;

class VoiceQueueService
{
    public function saveDefault(int $workspaceId, array $settings): array
    {
        $existing = $this->defaultQueue($workspaceId);
        $settings['is_default'] = true;

        return $this->saveQueue($workspaceId, $settings, !empty($existing['id']) ? (int) $existing['id'] : null);
    }

    public function saveQueue(int $workspaceId, array $settings, ?int $queueId = null, ?int $updatedByUserId = null): array
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('A valid workspace is required.');
        }
        $existing = $queueId !== null && $queueId > 0 ? $this->find($workspaceId, $queueId) : [];
        if ($queueId !== null && $queueId > 0 && $existing === []) {
            throw new \RuntimeException('The selected voice queue is outside this workspace.');
        }
        $name = trim((string) ($settings['name'] ?? 'Main queue')) ?: 'Main queue';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $fallbackAction = (string) ($settings['fallback_action'] ?? 'reject');
        if (!in_array($fallbackAction, ['verified_number', 'alternate_queue', 'reject'], true)) {
            $fallbackAction = 'reject';
        }
        $fallbackDestination = trim((string) ($settings['fallback_destination'] ?? ''));
        $fallbackQueueId = max(0, (int) ($settings['fallback_queue_id'] ?? 0));
        if ($fallbackAction === 'verified_number') {
            $fallbackDestination = (new WorkspaceVoiceConfigService())->normalizePhone($fallbackDestination);
            if ($fallbackDestination === '') throw new \InvalidArgumentException('A valid verified fallback number is required.');
            $fallbackQueueId = 0;
        } elseif ($fallbackAction === 'alternate_queue') {
            $fallbackQueue = $this->find($workspaceId, $fallbackQueueId);
            if ($fallbackQueue === [] || empty($fallbackQueue['enabled'])) {
                throw new \InvalidArgumentException('Choose an enabled alternate queue in this workspace.');
            }
            if ($queueId !== null && $queueId > 0 && $fallbackQueueId === $queueId) {
                throw new \InvalidArgumentException('A voice queue cannot fall back to itself.');
            }
            if ($queueId !== null && $queueId > 0) {
                $this->assertFallbackDoesNotCycle($workspaceId, $queueId, $fallbackQueueId);
            }
            $fallbackDestination = '';
        } else {
            $fallbackDestination = '';
            $fallbackQueueId = 0;
        }
        $crypto = new WorkspaceVoiceConfigService();
        $encryptedFallback = $fallbackDestination !== '' ? $crypto->encryptValue($fallbackDestination) : null;
        $maskedFallback = $fallbackDestination !== '' ? $crypto->maskPhone($fallbackDestination) : null;
        $businessHours = $this->normalizeBusinessHours($settings['business_hours'] ?? null);
        $isDefault = array_key_exists('is_default', $settings)
            ? !empty($settings['is_default'])
            : (!empty($existing['is_default']) || $this->listQueues($workspaceId, true) === []);
        $enabled = !array_key_exists('enabled', $settings) || !empty($settings['enabled']);
        $otherDefault = Database::queryOne(
            'SELECT id FROM voice_queues WHERE workspace_id = ? AND enabled = 1 AND is_default = 1 AND id <> ? LIMIT 1',
            [$workspaceId, (int) ($existing['id'] ?? 0)]
        );
        if (!$isDefault && !$otherDefault && $existing === []) {
            $isDefault = true;
        }
        if ($isDefault && !$enabled) {
            throw new \InvalidArgumentException('The default voice queue must remain enabled.');
        }
        if (!empty($existing['is_default']) && (!$isDefault || !$enabled)) {
            throw new \InvalidArgumentException('Assign another enabled default queue before disabling or demoting this queue.');
        }
        $slug = substr($slug ?: 'queue', 0, 120);
        $duplicate = Database::queryOne(
            'SELECT id FROM voice_queues WHERE workspace_id = ? AND slug = ? AND id <> ? LIMIT 1',
            [$workspaceId, $slug, (int) ($existing['id'] ?? 0)]
        );
        if ($duplicate) {
            throw new \InvalidArgumentException('Queue names must be unique within the workspace.');
        }

        Database::beginTransaction();
        try {
            if ($isDefault) {
                Database::execute('UPDATE voice_queues SET is_default = 0 WHERE workspace_id = ?', [$workspaceId]);
            }
            $params = [
                substr($name, 0, 191), $slug, $enabled ? 1 : 0, $isDefault ? 1 : 0,
                max(15, min(600, (int) ($settings['max_wait_seconds'] ?? 120))),
                $fallbackAction, $fallbackQueueId > 0 ? $fallbackQueueId : null,
                $encryptedFallback, $maskedFallback,
                $businessHours !== [] ? json_encode($businessHours, JSON_UNESCAPED_SLASHES) : null,
                $updatedByUserId,
            ];
            if ($existing !== []) {
                Database::execute(
                    "UPDATE voice_queues SET name = ?, slug = ?, enabled = ?, is_default = ?, max_wait_seconds = ?, fallback_action = ?, fallback_queue_id = ?, encrypted_fallback_target = ?, fallback_target_masked = ?, business_hours_json = ?, updated_by_user_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?",
                    array_merge($params, [$workspaceId, (int) $existing['id']])
                );
                $savedId = (int) $existing['id'];
            } else {
                Database::execute(
                    "INSERT INTO voice_queues (workspace_id, name, slug, enabled, is_default, routing_strategy, max_wait_seconds, fallback_action, fallback_queue_id, encrypted_fallback_target, fallback_target_masked, business_hours_json, created_by_user_id, updated_by_user_id) VALUES (?, ?, ?, ?, ?, 'priority_least_recent', ?, ?, ?, ?, ?, ?, ?, ?)",
                    array_merge([$workspaceId], $params, [$updatedByUserId])
                );
                $savedId = (int) Database::lastInsertId();
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $this->find($workspaceId, $savedId);
    }

    public function defaultQueue(int $workspaceId): array
    {
        return $this->hydrateQueue(Database::queryOne(
            'SELECT * FROM voice_queues WHERE workspace_id = ? AND enabled = 1 ORDER BY is_default DESC, id ASC LIMIT 1',
            [$workspaceId]
        ) ?: []);
    }

    public function find(int $workspaceId, int $queueId): array
    {
        if ($workspaceId <= 0 || $queueId <= 0) {
            return [];
        }

        return $this->hydrateQueue(Database::queryOne(
            'SELECT * FROM voice_queues WHERE workspace_id = ? AND id = ? LIMIT 1',
            [$workspaceId, $queueId]
        ) ?: []);
    }

    public function listQueues(int $workspaceId, bool $includeDisabled = false): array
    {
        if ($workspaceId <= 0) {
            return [];
        }
        $rows = Database::query(
            'SELECT * FROM voice_queues WHERE workspace_id = ?' . ($includeDisabled ? '' : ' AND enabled = 1') . ' ORDER BY is_default DESC, name ASC, id ASC',
            [$workspaceId]
        );

        return array_map(fn(array $row): array => $this->hydrateQueue($row), $rows);
    }

    /**
     * Route through the configured alternate-queue chain. The chain is short,
     * cycle-safe and workspace-scoped; the provider is never contacted here.
     */
    public function routeInboundCall(int $workspaceId, int $callId, ?int $queueId = null): array
    {
        $queue = $queueId ? $this->find($workspaceId, $queueId) : $this->defaultQueue($workspaceId);
        $visited = [];
        $agent = [];
        while ($queue !== [] && count($visited) < 10) {
            $currentId = (int) $queue['id'];
            if (isset($visited[$currentId])) {
                break;
            }
            $visited[$currentId] = true;
            if (!empty($queue['enabled']) && $this->isOpen($queue)) {
                $agent = (new VoiceRoutingService())->assignNextAgent($workspaceId, $callId, $currentId);
                if ($agent !== []) {
                    break;
                }
            }
            if ((string) ($queue['fallback_action'] ?? '') !== 'alternate_queue') {
                break;
            }
            $nextId = (int) ($queue['fallback_queue_id'] ?? 0);
            $queue = $nextId > 0 ? $this->find($workspaceId, $nextId) : [];
        }
        if ($queue !== []) {
            Database::execute(
                'UPDATE voice_calls SET queue_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
                [(int) $queue['id'], $workspaceId, $callId]
            );
        }

        return ['queue' => $queue, 'agent' => $agent, 'visited_queue_ids' => array_map('intval', array_keys($visited))];
    }

    public function runtimeSummary(int $workspaceId): array
    {
        $summaries = [];
        foreach ($this->listQueues($workspaceId) as $queue) {
            $queueId = (int) $queue['id'];
            $counts = Database::queryOne(
                "SELECT
                    SUM(CASE WHEN state IN ('received','consent_pending','queued') THEN 1 ELSE 0 END) AS waiting_calls,
                    SUM(CASE WHEN state IN ('dialing_agent','agent_answered','dialing_customer','ringing','in_progress') THEN 1 ELSE 0 END) AS active_calls,
                    MAX(CASE WHEN state IN ('received','consent_pending','queued') THEN TIMESTAMPDIFF(SECOND, COALESCE(queued_at, requested_at, created_at), NOW()) ELSE 0 END) AS oldest_wait_seconds
                 FROM voice_calls WHERE workspace_id = ? AND queue_id = ?",
                [$workspaceId, $queueId]
            ) ?: [];
            $agents = Database::queryOne(
                "SELECT COUNT(*) AS configured_agents,
                    SUM(CASE WHEN a.enabled = 1 AND a.presence_status = 'available' THEN 1 ELSE 0 END) AS available_agents
                 FROM voice_queue_members m
                 INNER JOIN voice_agents a ON a.workspace_id = m.workspace_id AND a.id = m.agent_id
                 WHERE m.workspace_id = ? AND m.queue_id = ? AND m.enabled = 1",
                [$workspaceId, $queueId]
            ) ?: [];
            $summaries[] = [
                'id' => $queueId,
                'name' => (string) $queue['name'],
                'is_default' => !empty($queue['is_default']),
                'is_open' => $this->isOpen($queue),
                'waiting_calls' => (int) ($counts['waiting_calls'] ?? 0),
                'active_calls' => (int) ($counts['active_calls'] ?? 0),
                'oldest_wait_seconds' => (int) ($counts['oldest_wait_seconds'] ?? 0),
                'configured_agents' => (int) ($agents['configured_agents'] ?? 0),
                'available_agents' => (int) ($agents['available_agents'] ?? 0),
                'max_wait_seconds' => (int) ($queue['max_wait_seconds'] ?? 0),
                'fallback_action' => (string) ($queue['fallback_action'] ?? 'reject'),
            ];
        }

        return $summaries;
    }

    public function setMembers(int $workspaceId, int $queueId, array $agentIds): void
    {
        Database::beginTransaction();
        try {
            Database::execute('DELETE FROM voice_queue_members WHERE workspace_id = ? AND queue_id = ?', [$workspaceId, $queueId]);
            foreach (array_values(array_unique(array_map('intval', $agentIds))) as $priority => $agentId) {
                if ($agentId <= 0 || !Database::queryOne('SELECT id FROM voice_agents WHERE workspace_id = ? AND id = ?', [$workspaceId, $agentId])) continue;
                Database::execute('INSERT INTO voice_queue_members (workspace_id, queue_id, agent_id, priority, enabled) VALUES (?, ?, ?, ?, 1)', [$workspaceId, $queueId, $agentId, $priority + 1]);
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack(); throw $e;
        }
    }

    public function members(int $workspaceId, int $queueId): array
    {
        if ($workspaceId <= 0 || $queueId <= 0) {
            return [];
        }

        return Database::query(
            "SELECT m.agent_id, m.priority, m.enabled, a.display_name, a.endpoint_masked
             FROM voice_queue_members m
             INNER JOIN voice_agents a ON a.id = m.agent_id AND a.workspace_id = m.workspace_id
             WHERE m.workspace_id = ? AND m.queue_id = ?
             ORDER BY m.priority ASC, a.display_name ASC, m.id ASC",
            [$workspaceId, $queueId]
        );
    }

    /**
     * Keep the production-v1 default queue useful without overwriting an
     * administrator's explicit ordering on subsequent setup saves.
     */
    public function ensureDefaultMembers(int $workspaceId, int $queueId): void
    {
        if ($queueId <= 0) {
            return;
        }
        $existing = (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM voice_queue_members WHERE workspace_id = ? AND queue_id = ?',
            [$workspaceId, $queueId]
        )['c'] ?? 0);
        if ($existing > 0) {
            return;
        }
        $agents = Database::query(
            'SELECT id FROM voice_agents WHERE workspace_id = ? AND enabled = 1 ORDER BY display_name ASC, id ASC',
            [$workspaceId]
        );
        $this->setMembers($workspaceId, $queueId, array_map('intval', array_column($agents, 'id')));
    }

    public function isOpen(array $queue, ?\DateTimeImmutable $now = null): bool
    {
        $hours = !empty($queue['business_hours_json']) ? json_decode((string) $queue['business_hours_json'], true) : [];
        if (!is_array($hours) || empty($hours['enabled'])) {
            return true;
        }
        try {
            $timezone = new \DateTimeZone((string) ($hours['timezone'] ?? 'Africa/Nairobi'));
        } catch (\Throwable $e) {
            return false;
        }
        $now = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        if (!in_array((int) $now->format('N'), array_map('intval', (array) ($hours['days'] ?? [])), true)) {
            return false;
        }
        $time = $now->format('H:i');
        return $time >= (string) ($hours['start'] ?? '08:00') && $time < (string) ($hours['end'] ?? '17:00');
    }

    private function normalizeBusinessHours($value): array
    {
        if (!is_array($value) || empty($value['enabled'])) {
            return [];
        }
        $timezone = (string) ($value['timezone'] ?? 'Africa/Nairobi');
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('A valid queue timezone is required.');
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($value['days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        $start = preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($value['start'] ?? '')) ? (string) $value['start'] : '';
        $end = preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', (string) ($value['end'] ?? '')) ? (string) $value['end'] : '';
        if ($days === [] || $start === '' || $end === '' || $start >= $end) {
            throw new \InvalidArgumentException('Queue business hours require days and a valid start/end window.');
        }
        return ['enabled' => true, 'timezone' => $timezone, 'days' => $days, 'start' => $start, 'end' => $end];
    }

    private function hydrateQueue(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $row['fallback_destination'] = !empty($row['encrypted_fallback_target'])
            ? (new WorkspaceVoiceConfigService())->decryptValue((string) $row['encrypted_fallback_target'])
            : '';

        return $row;
    }

    private function assertFallbackDoesNotCycle(int $workspaceId, int $queueId, int $fallbackQueueId): void
    {
        $visited = [];
        $cursor = $fallbackQueueId;
        while ($cursor > 0 && count($visited) < 20) {
            if ($cursor === $queueId || isset($visited[$cursor])) {
                throw new \InvalidArgumentException('Alternate queue fallbacks cannot contain a routing cycle.');
            }
            $visited[$cursor] = true;
            $row = Database::queryOne(
                'SELECT fallback_action, fallback_queue_id FROM voice_queues WHERE workspace_id = ? AND id = ? LIMIT 1',
                [$workspaceId, $cursor]
            ) ?: [];
            if ((string) ($row['fallback_action'] ?? '') !== 'alternate_queue') {
                break;
            }
            $cursor = (int) ($row['fallback_queue_id'] ?? 0);
        }
    }
}
