<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceOpsEventService
{
    private const ACTIVE_STATUSES = ['open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider'];

    private DefaultWorkspaceService $defaultWorkspace;
    private OperatorAuditService $audit;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null, ?OperatorAuditService $audit = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->audit = $audit ?: new OperatorAuditService();
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshAll(?int $actorUserId = null): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'warnings' => [],
        ];

        if (!Database::tableExists('default_workspace_ops_events')) {
            $result['warnings'][] = 'Default workspace ops events table is missing.';
            return $result;
        }

        $signals = $this->detectSignals();
        $activeSignalKeys = [];
        foreach ($signals as $signal) {
            try {
                $signalType = substr(trim((string) ($signal['signal_type'] ?? '')), 0, 80);
                $fingerprint = substr(trim((string) ($signal['signal_fingerprint'] ?? '')), 0, 191);
                if ($signalType !== '' && $fingerprint !== '') {
                    $activeSignalKeys[] = $this->activeSignalKey($signalType, $fingerprint);
                }
                $status = $this->upsertEvent($signal);
                if ($status === 'created') {
                    $result['created']++;
                } elseif ($status === 'updated') {
                    $result['updated']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['warnings'][] = $e->getMessage();
            }
        }

        try {
            $cleared = (new DefaultWorkspaceOpsAutomationService($this->defaultWorkspace, null, null, $this->audit))->resolveClearedAutomatedEvents($activeSignalKeys, $actorUserId);
            if ((int) ($cleared['resolved'] ?? 0) > 0) {
                $result['auto_resolved'] = (int) $cleared['resolved'];
            }
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Automated ops-event clear check failed: ' . $e->getMessage();
        }

        if ($actorUserId !== null && ($result['created'] > 0 || $result['updated'] > 0)) {
            try {
                $this->audit->log('default_workspace_ops_events_refreshed', $actorUserId, $this->defaultWorkspace->id(), 'Default workspace ops events refreshed.', $result);
            } catch (\Throwable $ignored) {
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $signal
     */
    public function upsertEvent(array $signal): string
    {
        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $ownerWorkspaceId = (int) ($signal['owner_workspace_id'] ?? 0);
        if ($ownerWorkspaceId > 0 && $this->defaultWorkspace->isDefaultWorkspace($ownerWorkspaceId)) {
            return 'skipped';
        }

        $signalType = substr(trim((string) ($signal['signal_type'] ?? '')), 0, 80);
        $fingerprint = substr(trim((string) ($signal['signal_fingerprint'] ?? '')), 0, 191);
        if ($signalType === '' || $fingerprint === '') {
            throw new \RuntimeException('Ops event signal type and fingerprint are required.');
        }

        $activeKey = $this->activeSignalKey($signalType, $fingerprint);
        $existing = Database::queryOne(
            "SELECT id, status
             FROM default_workspace_ops_events
             WHERE default_workspace_id = ? AND active_signal_key = ?
             LIMIT 1",
            [$defaultWorkspaceId, $activeKey]
        );

        $payload = [
            'owner_workspace_id' => $ownerWorkspaceId > 0 ? $ownerWorkspaceId : null,
            'owner_user_id' => !empty($signal['owner_user_id']) ? (int) $signal['owner_user_id'] : null,
            'contact_id' => !empty($signal['contact_id']) ? (int) $signal['contact_id'] : null,
            'severity' => $this->enum((string) ($signal['severity'] ?? 'warning'), ['info', 'warning', 'critical'], 'warning'),
            'priority' => $this->enum((string) ($signal['priority'] ?? 'medium'), ['low', 'medium', 'high', 'urgent'], 'medium'),
            'due_at' => $signal['due_at'] ?? null,
            'metadata_json' => json_encode((array) ($signal['metadata'] ?? []), JSON_UNESCAPED_SLASHES),
        ];

        if ($existing) {
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET owner_workspace_id = ?,
                     owner_user_id = ?,
                     contact_id = ?,
                     severity = ?,
                     priority = ?,
                     last_seen_at = NOW(),
                     due_at = ?,
                     metadata_json = ?
                 WHERE id = ?",
                [
                    $payload['owner_workspace_id'],
                    $payload['owner_user_id'],
                    $payload['contact_id'],
                    $payload['severity'],
                    $payload['priority'],
                    $payload['due_at'],
                    $payload['metadata_json'],
                    (int) $existing['id'],
                ]
            );
            return 'updated';
        }

        Database::execute(
            "INSERT INTO default_workspace_ops_events
                (default_workspace_id, owner_workspace_id, owner_user_id, contact_id, signal_type, signal_fingerprint,
                 active_signal_key, severity, priority, status, detected_at, last_seen_at, due_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW(), ?, ?)",
            [
                $defaultWorkspaceId,
                $payload['owner_workspace_id'],
                $payload['owner_user_id'],
                $payload['contact_id'],
                $signalType,
                $fingerprint,
                $activeKey,
                $payload['severity'],
                $payload['priority'],
                $payload['due_at'],
                $payload['metadata_json'],
            ]
        );

        return 'created';
    }

    /**
     * @param array<string,string|int|null> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listEvents(array $filters = [], int $limit = 50): array
    {
        if (!Database::tableExists('default_workspace_ops_events')) {
            return [];
        }

        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $where = ['e.default_workspace_id = ?'];
        $params = [$defaultWorkspaceId];

        foreach (['status', 'signal_type', 'severity'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "e.{$key} = ?";
                $params[] = $value;
            }
        }

        if (!empty($filters['assigned_user_id'])) {
            $where[] = 'e.assigned_user_id = ?';
            $params[] = (int) $filters['assigned_user_id'];
        }

        $limit = max(1, min(200, $limit));
        $rows = Database::query(
            "SELECT e.*, w.name AS owner_workspace_name, w.slug AS owner_workspace_slug,
                    u.email AS owner_email, c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email
             FROM default_workspace_ops_events e
             LEFT JOIN workspaces w ON w.id = e.owner_workspace_id
             LEFT JOIN users u ON u.id = e.owner_user_id
             LEFT JOIN contacts c ON c.id = e.contact_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(e.status, 'open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider', 'resolved', 'dismissed'),
                      FIELD(e.priority, 'urgent', 'high', 'medium', 'low'),
                      e.last_seen_at DESC,
                      e.id DESC
             LIMIT {$limit}",
            $params
        );

        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
            return $row;
        }, $rows);
    }

    public function transitionEvent(int $eventId, string $newStatus, int $actorUserId, string $reason, ?string $resolutionSummary = null): bool
    {
        $newStatus = $this->enum($newStatus, ['open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider', 'resolved', 'dismissed'], '');
        if ($eventId <= 0 || $newStatus === '') {
            throw new \RuntimeException('A valid ops event and status are required.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Ops event status changes require a reason.');
        }

        $event = Database::queryOne(
            "SELECT * FROM default_workspace_ops_events WHERE id = ? AND default_workspace_id = ? LIMIT 1",
            [$eventId, $this->defaultWorkspace->id()]
        );
        if (!$event) {
            throw new \RuntimeException('Ops event was not found.');
        }

        $activeKey = in_array($newStatus, self::ACTIVE_STATUSES, true)
            ? $this->activeSignalKey((string) $event['signal_type'], (string) $event['signal_fingerprint'])
            : null;
        Database::execute(
            "UPDATE default_workspace_ops_events
             SET status = ?,
                 active_signal_key = ?,
                 resolved_at = CASE WHEN ? IN ('resolved','dismissed') THEN NOW() ELSE NULL END,
                 resolution_summary = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [$newStatus, $activeKey, $newStatus, $resolutionSummary, $eventId]
        );

        $this->audit->log(
            'default_workspace_ops_event_status_changed',
            $actorUserId,
            $this->defaultWorkspace->id(),
            $reason,
            [
                'event_id' => $eventId,
                'signal_type' => (string) ($event['signal_type'] ?? ''),
                'previous_status' => (string) ($event['status'] ?? ''),
                'new_status' => $newStatus,
                'resolution_summary' => $resolutionSummary,
            ],
            !empty($event['owner_user_id']) ? (int) $event['owner_user_id'] : null
        );

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function detectSignals(): array
    {
        $signals = [];
        $defaultWorkspaceId = $this->defaultWorkspace->id();

        if (Database::tableExists('workspace_onboarding_state')) {
            foreach (Database::query(
                "SELECT w.id, w.name, w.slug, wos.status, wos.current_step, wos.readiness_score,
                        wos.updated_at AS onboarding_updated_at
                 FROM workspace_onboarding_state wos
                 JOIN workspaces w ON w.id = wos.workspace_id
                 WHERE wos.workspace_id <> ?
                   AND wos.status <> 'completed'
                   AND w.status NOT IN ('archived', 'suspended', 'deleted')
                 ORDER BY wos.updated_at ASC
                 LIMIT 200",
                [$defaultWorkspaceId]
            ) as $row) {
                $signals[] = $this->workspaceSignal('stuck_onboarding', (int) $row['id'], 'warning', 'high', $row);
            }
        }

        if (Database::tableExists('workspace_wallets')) {
            foreach (Database::query(
                "SELECT ww.workspace_id AS id, w.name, w.slug, (ww.token_balance - ww.reserved_tokens) AS available_tokens
                 FROM workspace_wallets ww
                 JOIN workspaces w ON w.id = ww.workspace_id
                 WHERE ww.workspace_id <> ?
                   AND (ww.token_balance - ww.reserved_tokens) < 10000
                 ORDER BY available_tokens ASC
                 LIMIT 200",
                [$defaultWorkspaceId]
            ) as $row) {
                $signals[] = $this->workspaceSignal('low_token_balance', (int) $row['id'], 'warning', 'high', $row);
            }
        }

        if (Database::tableExists('billing_provider_events')) {
            foreach (Database::query(
                "SELECT workspace_id AS id, COUNT(*) AS failed_events, MAX(created_at) AS last_failed_at
                 FROM billing_provider_events
                 WHERE workspace_id IS NOT NULL
                   AND workspace_id <> ?
                   AND processing_status = 'failed'
                 GROUP BY workspace_id
                 ORDER BY last_failed_at DESC
                 LIMIT 200",
                [$defaultWorkspaceId]
            ) as $row) {
                $signals[] = $this->workspaceSignal('failed_billing_provider_event', (int) $row['id'], 'critical', 'urgent', $row);
            }
        }

        if (Database::tableExists('email_integrations')) {
            foreach (Database::query(
                "SELECT w.id, w.name, w.slug, w.created_at AS workspace_created_at
                 FROM workspaces w
                 WHERE w.id <> ?
                   AND w.status NOT IN ('archived', 'suspended', 'deleted')
                   AND NOT EXISTS (
                       SELECT 1 FROM email_integrations ei WHERE ei.workspace_id = w.id AND ei.is_active = 1
                   )
                 ORDER BY w.created_at ASC
                 LIMIT 200",
                [$defaultWorkspaceId]
            ) as $row) {
                $signals[] = $this->workspaceSignal('channel_setup_gap', (int) $row['id'], 'warning', 'medium', $row);
            }
        }

        if (Database::tableExists('default_workspace_owner_contact_sync_errors')) {
            foreach (Database::query(
                "SELECT owner_workspace_id AS id, owner_user_id, COUNT(*) AS error_count, MAX(created_at) AS last_error_at, MAX(error_message) AS last_error
                 FROM default_workspace_owner_contact_sync_errors
                 WHERE default_workspace_id = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY owner_workspace_id, owner_user_id
                 ORDER BY last_error_at DESC
                 LIMIT 200",
                [$defaultWorkspaceId]
            ) as $row) {
                $signals[] = $this->workspaceSignal('owner_contact_sync_failure', (int) ($row['id'] ?? 0), 'warning', 'high', $row);
            }
        }

        try {
            $status = (new DefaultWorkspaceOperationalizationService())->status(0);
            $diagnostics = (array) ($status['diagnostics'] ?? []);
            foreach (array_slice((array) ($diagnostics['missing'] ?? []), 0, 100) as $missing) {
                $key = preg_replace('/[^a-z0-9_:-]+/i', '_', strtolower((string) $missing));
                $signals[] = [
                    'signal_type' => 'missing_platform_ops_asset',
                    'signal_fingerprint' => 'asset:' . $key,
                    'severity' => 'warning',
                    'priority' => 'medium',
                    'metadata' => ['missing' => (string) $missing],
                ];
            }
            foreach (array_slice((array) ($diagnostics['components']['ai_prompts']['missing'] ?? []), 0, 100) as $missingPrompt) {
                $key = preg_replace('/[^a-z0-9_:-]+/i', '_', strtolower((string) $missingPrompt));
                $signals[] = [
                    'signal_type' => 'missing_default_workspace_ai_prompt',
                    'signal_fingerprint' => 'prompt:' . $key,
                    'severity' => 'warning',
                    'priority' => 'medium',
                    'metadata' => ['missing_prompt' => (string) $missingPrompt],
                ];
            }
        } catch (\Throwable $ignored) {
        }

        return $signals;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function workspaceSignal(string $type, int $workspaceId, string $severity, string $priority, array $metadata, mixed $dueAt = null): array
    {
        $context = $workspaceId > 0 ? $this->ownerContext($workspaceId) : [];
        return [
            'owner_workspace_id' => $workspaceId > 0 ? $workspaceId : null,
            'owner_user_id' => $context['owner_user_id'] ?? ($metadata['owner_user_id'] ?? null),
            'contact_id' => $context['contact_id'] ?? null,
            'signal_type' => $type,
            'signal_fingerprint' => $type . ':' . ($workspaceId > 0 ? (string) $workspaceId : md5(json_encode($metadata))),
            'severity' => $severity,
            'priority' => $priority,
            'due_at' => $dueAt,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string,int|null>
     */
    private function ownerContext(int $workspaceId): array
    {
        if (Database::tableExists('default_workspace_owner_contacts')) {
            $mapped = Database::queryOne(
                "SELECT owner_user_id, contact_id
                 FROM default_workspace_owner_contacts
                 WHERE default_workspace_id = ? AND owner_workspace_id = ?
                 ORDER BY relationship_status = 'active' DESC, id ASC
                 LIMIT 1",
                [$this->defaultWorkspace->id(), $workspaceId]
            );
            if ($mapped) {
                return [
                    'owner_user_id' => !empty($mapped['owner_user_id']) ? (int) $mapped['owner_user_id'] : null,
                    'contact_id' => !empty($mapped['contact_id']) ? (int) $mapped['contact_id'] : null,
                ];
            }
        }

        $owner = Database::tableExists('workspace_memberships') ? Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ? AND membership_status = 'active'
             ORDER BY is_owner DESC, FIELD(role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), id ASC
             LIMIT 1",
            [$workspaceId]
        ) : null;

        return ['owner_user_id' => !empty($owner['user_id']) ? (int) $owner['user_id'] : null, 'contact_id' => null];
    }

    private function activeSignalKey(string $signalType, string $fingerprint): string
    {
        return substr($signalType . ':' . $fingerprint, 0, 191);
    }

    /**
     * @param array<int,string> $allowed
     */
    private function enum(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
