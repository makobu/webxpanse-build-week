<?php

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;

class VoiceCallService
{
    private VoiceCallPolicyService $policy;
    private WorkspaceVoiceConfigService $crypto;
    private VoiceCallStateMachineService $states;
    private VoiceProviderInterface $provider;

    public function __construct(
        ?VoiceCallPolicyService $policy = null,
        ?WorkspaceVoiceConfigService $crypto = null,
        ?VoiceCallStateMachineService $states = null,
        ?VoiceProviderInterface $provider = null
    ) {
        $this->policy = $policy ?? new VoiceCallPolicyService();
        $this->crypto = $crypto ?? new WorkspaceVoiceConfigService();
        $this->states = $states ?? new VoiceCallStateMachineService();
        $this->provider = $provider ?? new AfricaTalkingVoiceProvider();
    }

    public function requestOutbound(
        int $workspaceId,
        int $userId,
        string $destination,
        ?int $contactId = null,
        bool $viewAllContacts = false,
        bool $manualDestinationConfirmed = false
    ): array
    {
        $runtime = $this->policy->assertRuntimeReady($workspaceId, 'outbound');
        $config = (array) $runtime['config'];
        $entitlements = (array) $runtime['entitlements'];
        $destination = $this->policy->assertDestinationAllowed($workspaceId, $destination, $config);
        $agent = (new VoiceAgentService($this->crypto))->findForUser($workspaceId, $userId, true);
        if (!$agent || empty($agent['enabled'])) {
            throw new \RuntimeException('Your verified voice-agent endpoint is not configured.');
        }
        if ((string) ($agent['presence_status'] ?? '') !== 'available') {
            throw new \RuntimeException('Set your voice availability to Available before placing a call.');
        }
        if ($contactId !== null) {
            $contactParams = [$workspaceId, $contactId];
            $contactScope = '';
            if (!$viewAllContacts) {
                $contactScope = ' AND (assigned_to IS NULL OR assigned_to = ? OR created_by = ?)';
                $contactParams[] = $userId;
                $contactParams[] = $userId;
            }
            $contact = Database::queryOne(
                'SELECT id, phone FROM contacts WHERE workspace_id = ? AND id = ?' . $contactScope . ' LIMIT 1',
                $contactParams
            );
            if (!$contact) {
                throw new \RuntimeException('The selected contact is not available to this user.');
            }
            if ($this->crypto->normalizePhone((string) ($contact['phone'] ?? '')) !== $destination) {
                throw new \RuntimeException('The destination must match the selected contact phone number. Clear the contact and confirm a manual destination instead.');
            }
        } elseif (!$manualDestinationConfirmed) {
            throw new \RuntimeException('Confirm the manually entered destination before queuing the call.');
        }

        Database::beginTransaction();
        try {
            Database::queryOne('SELECT workspace_id FROM workspace_voice_configs WHERE workspace_id = ? FOR UPDATE', [$workspaceId]);
            $this->policy->assertCapacity($workspaceId, $config, $entitlements);
            $recentAgentRequests = (int) (Database::queryOne(
                'SELECT COUNT(*) AS c FROM voice_calls WHERE workspace_id = ? AND created_by_user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
                [$workspaceId, $userId]
            )['c'] ?? 0);
            if ($recentAgentRequests >= 5) {
                throw new \RuntimeException('The per-agent call initiation limit has been reached. Wait one minute before trying again.');
            }
            $activeAgent = Database::queryOne(
                "SELECT id FROM voice_calls WHERE workspace_id = ? AND agent_user_id = ? AND state IN ('requested','queued','dialing_agent','agent_answered','dialing_customer','ringing','in_progress') LIMIT 1 FOR UPDATE",
                [$workspaceId, $userId]
            );
            if ($activeAgent) {
                throw new \RuntimeException('An agent can handle only one active voice call in production v1.');
            }
            $uuid = $this->uuid();
            // The documented Africa's Talking agent-first bridge does not
            // expose a customer-leg consent callback before recording starts.
            // Keep outbound evidence disabled until a provider can prove that
            // capability; inbound consented calls remain recordable.
            $consentStatus = 'not_required';
            $recordingStatus = 'disabled';
            $transcriptionStatus = 'disabled';
            $providerMetadata = json_encode([
                'outbound_recording' => 'blocked_without_verified_prebridge_customer_consent',
            ], JSON_UNESCAPED_SLASHES);
            Database::execute(
                "INSERT INTO voice_calls (workspace_id, uuid, provider, direction, state, contact_id, agent_id, agent_user_id,
                    encrypted_from_number, from_number_hash, from_number_masked, encrypted_to_number, to_number_hash,
                    to_number_masked, consent_status, recording_status, transcription_status, requested_at, queued_at, provider_metadata_json, created_by_user_id)
                 VALUES (?, ?, 'africastalking', 'outbound', 'queued', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)",
                [$workspaceId, $uuid, $contactId, (int) $agent['id'], $userId,
                    $this->crypto->encryptValue((string) $config['virtual_number']), $this->crypto->numberHash((string) $config['virtual_number']), $this->crypto->maskPhone((string) $config['virtual_number']),
                    $this->crypto->encryptValue($destination), $this->crypto->numberHash($destination), $this->crypto->maskPhone($destination),
                    $consentStatus, $recordingStatus, $transcriptionStatus, $providerMetadata, $userId]
            );
            $callId = (int) Database::lastInsertId();
            Database::execute(
                "UPDATE voice_agents SET pre_call_presence_status = presence_status, presence_status = 'busy', last_assigned_at = NOW() WHERE workspace_id = ? AND id = ? AND presence_status = 'available'",
                [$workspaceId, (int) $agent['id']]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        $this->recordInternalEvent($workspaceId, $callId, 'voice.call.queued', 'queued');
        EventBus::publish('voice.call.queued', ['workspace_id' => $workspaceId, 'call_id' => $callId]);
        return $this->get($workspaceId, $callId, $userId, true);
    }

    public function dispatchNextOutbound(?int $workspaceId = null): ?array
    {
        if (!$this->policy->platformEnabled()) {
            return null;
        }
        $where = $workspaceId && $workspaceId > 0 ? 'AND c.workspace_id = ?' : '';
        $params = $workspaceId && $workspaceId > 0 ? [$workspaceId] : [];
        Database::beginTransaction();
        try {
            $call = Database::queryOne(
                "SELECT c.* FROM voice_calls c
                 INNER JOIN workspace_voice_configs cfg ON cfg.workspace_id = c.workspace_id
                 WHERE c.direction = 'outbound' AND c.state = 'queued' AND c.provider_session_id IS NULL {$where}
                 ORDER BY c.queued_at ASC, c.id ASC LIMIT 1 FOR UPDATE",
                $params
            );
            if (!$call) {
                Database::commit();
                return null;
            }
            $workspace = (int) $call['workspace_id'];
            try {
                $this->policy->assertRuntimeReady($workspace, 'outbound');
            } catch (\Throwable $policyError) {
                Database::execute(
                    "UPDATE voice_calls SET state = 'policy_blocked', failure_category = 'runtime_disabled', failure_message = ?, updated_at = NOW()
                     WHERE workspace_id = ? AND id = ? AND state = 'queued'",
                    [substr($policyError->getMessage(), 0, 500), $workspace, (int) $call['id']]
                );
                if (!empty($call['agent_id'])) {
                    Database::execute(
                        "UPDATE voice_agents SET presence_status = COALESCE(pre_call_presence_status, 'available'), pre_call_presence_status = NULL
                         WHERE workspace_id = ? AND id = ?",
                        [$workspace, (int) $call['agent_id']]
                    );
                }
                Database::commit();
                $this->recordInternalEvent($workspace, (int) $call['id'], 'voice.call.policy_blocked', 'policy_blocked');
                return $this->get($workspace, (int) $call['id'], 0, true);
            }
            Database::execute('INSERT IGNORE INTO voice_provider_rate_limits (workspace_id, next_release_at) VALUES (?, NULL)', [$workspace]);
            $limit = Database::queryOne('SELECT next_release_at FROM voice_provider_rate_limits WHERE workspace_id = ? FOR UPDATE', [$workspace]);
            $next = !empty($limit['next_release_at']) ? strtotime((string) $limit['next_release_at']) : 0;
            if ($next > time()) {
                Database::commit();
                return null;
            }
            Database::execute('UPDATE voice_provider_rate_limits SET next_release_at = DATE_ADD(NOW(6), INTERVAL 1 SECOND) WHERE workspace_id = ?', [$workspace]);
            Database::execute("UPDATE voice_calls SET state = 'dialing_agent', updated_at = NOW() WHERE workspace_id = ? AND id = ?", [$workspace, (int) $call['id']]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $config = $this->crypto->get((int) $call['workspace_id'], true);
        $agent = (new VoiceAgentService($this->crypto))->findById((int) $call['workspace_id'], (int) $call['agent_id'], true);
        try {
            $response = $this->provider->initiateAgentFirstOutboundBridge(
                $config,
                (string) $agent['endpoint'],
                $this->callbackUrl((string) $config['callback_token'], (string) $call['uuid'])
            );
            $sessionId = $this->sessionIdFromResponse($response);
            if ($sessionId === '') {
                throw new \RuntimeException('Voice provider did not return a call session identifier.');
            }
            Database::execute(
                'UPDATE voice_calls SET provider_session_id = ?, provider_metadata_json = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?',
                [$sessionId, json_encode($this->redactProviderMetadata($response), JSON_UNESCAPED_SLASHES), (int) $call['workspace_id'], (int) $call['id']]
            );
            $this->recordInternalEvent((int) $call['workspace_id'], (int) $call['id'], 'voice.call.dialing_agent', 'dialing_agent');
            return $this->get((int) $call['workspace_id'], (int) $call['id'], 0, true);
        } catch (\Throwable $e) {
            $this->states->transition((int) $call['workspace_id'], (int) $call['id'], 'provider_failed', [
                'failure_category' => 'provider_request', 'failure_message' => $e->getMessage(),
            ]);
            $this->recordInternalEvent((int) $call['workspace_id'], (int) $call['id'], 'voice.call.failed', 'provider_failed');
            (new VoiceMobileNotificationService())->notifyFailure((int) $call['workspace_id'], (int) $call['id']);
            throw $e;
        }
    }

    public function recoverStalledOutboundDispatches(?int $workspaceId = null, int $olderThanSeconds = 120, int $limit = 50): int
    {
        $olderThanSeconds = max(60, min(3600, $olderThanSeconds));
        $limit = max(1, min(200, $limit));
        $params = [$olderThanSeconds];
        $scope = '';
        if ($workspaceId && $workspaceId > 0) {
            $scope = ' AND workspace_id = ?';
            $params[] = $workspaceId;
        }
        $rows = Database::query(
            "SELECT id, workspace_id, agent_id FROM voice_calls
             WHERE direction = 'outbound' AND state = 'dialing_agent' AND provider_session_id IS NULL
               AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND){$scope}
             ORDER BY updated_at ASC, id ASC LIMIT {$limit}",
            $params
        );
        $recovered = 0;
        foreach ($rows as $row) {
            Database::beginTransaction();
            try {
                $updated = Database::execute(
                    "UPDATE voice_calls SET state = 'provider_failed', failure_category = 'dispatch_lease_expired',
                        failure_message = 'Provider initiation outcome was not confirmed before the dispatch lease expired.', updated_at = NOW()
                     WHERE workspace_id = ? AND id = ? AND state = 'dialing_agent' AND provider_session_id IS NULL
                       AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
                    [(int) $row['workspace_id'], (int) $row['id'], $olderThanSeconds]
                );
                if ($updated === 1 && !empty($row['agent_id'])) {
                    Database::execute(
                        "UPDATE voice_agents SET presence_status = COALESCE(pre_call_presence_status, 'available'), pre_call_presence_status = NULL
                         WHERE workspace_id = ? AND id = ?",
                        [(int) $row['workspace_id'], (int) $row['agent_id']]
                    );
                }
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }
            if ($updated === 1) {
                $recovered++;
                $this->recordInternalEvent((int) $row['workspace_id'], (int) $row['id'], 'voice.call.dispatch_expired', 'provider_failed');
                (new VoiceMobileNotificationService())->notifyFailure((int) $row['workspace_id'], (int) $row['id']);
            }
        }
        return $recovered;
    }

    public function get(int $workspaceId, int $callId, int $userId = 0, bool $viewAll = false): array
    {
        $params = [$workspaceId, $callId];
        $scope = '';
        if (!$viewAll && $userId > 0) {
            $scope = ' AND (agent_user_id = ? OR created_by_user_id = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }
        $row = Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ?' . $scope . ' LIMIT 1', $params);
        return $row ? $this->hydrateCall($row) : [];
    }

    public function list(int $workspaceId, int $userId, bool $viewAll, int $sinceEventId = 0, int $limit = 100): array
    {
        $params = [$workspaceId];
        $scope = '';
        if (!$viewAll) {
            $scope = ' AND (c.agent_user_id = ? OR c.created_by_user_id = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }
        $rows = Database::query(
            "SELECT c.*, a.display_name AS agent_name, q.name AS queue_name,
                    t.id AS transcript_id, i.summary AS insight_summary, i.review_status AS insight_review_status,
                    r.id AS recording_id
             FROM voice_calls c LEFT JOIN voice_agents a ON a.id = c.agent_id AND a.workspace_id = c.workspace_id
             LEFT JOIN voice_queues q ON q.id = c.queue_id AND q.workspace_id = c.workspace_id
             LEFT JOIN voice_call_transcripts t ON t.call_id = c.id AND t.workspace_id = c.workspace_id AND t.deleted_at IS NULL
             LEFT JOIN voice_call_insights i ON i.call_id = c.id AND i.workspace_id = c.workspace_id
             LEFT JOIN voice_recordings r ON r.call_id = c.id AND r.workspace_id = c.workspace_id AND r.status = 'ready' AND r.deleted_at IS NULL
             WHERE c.workspace_id = ? {$scope} ORDER BY c.id DESC LIMIT " . max(1, min(200, $limit)),
            $params
        );
        $eventParams = [$workspaceId, max(0, $sinceEventId)];
        $eventScope = '';
        if (!$viewAll) {
            $eventScope = ' AND (c.agent_user_id = ? OR c.created_by_user_id = ?)';
            $eventParams[] = $userId;
            $eventParams[] = $userId;
        }
        $events = Database::query(
            "SELECT e.id, e.call_id, e.event_type, e.normalized_state, e.accepted, e.rejection_reason, e.occurred_at, e.created_at
             FROM voice_call_events e
             INNER JOIN voice_calls c ON c.id = e.call_id AND c.workspace_id = e.workspace_id
             WHERE e.workspace_id = ? AND e.id > ? {$eventScope}
             ORDER BY e.id ASC LIMIT 250",
            $eventParams
        );
        return [
            'calls' => array_map(fn(array $row): array => $this->hydrateCall($row), $rows),
            'events' => $events,
            'last_event_id' => $events ? (int) end($events)['id'] : $sinceEventId,
            'active_count' => count(array_filter($rows, fn(array $row): bool => !$this->states->isTerminal((string) $row['state']))),
        ];
    }

    public function recordInternalEvent(int $workspaceId, int $callId, string $eventType, ?string $state): void
    {
        $key = 'internal:' . $callId . ':' . $eventType;
        Database::execute(
            "INSERT IGNORE INTO voice_call_events (workspace_id, call_id, provider, provider_event_key, event_type, normalized_state, payload_hash, metadata_json, accepted, occurred_at)
             VALUES (?, ?, 'crm', ?, ?, ?, ?, '{}', 1, NOW())",
            [$workspaceId, $callId, $key, $eventType, $state, hash('sha256', $key)]
        );
    }

    private function hydrateCall(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'uuid' => (string) $row['uuid'], 'direction' => (string) $row['direction'],
            'state' => (string) $row['state'], 'contact_id' => !empty($row['contact_id']) ? (int) $row['contact_id'] : null,
            'queue_id' => !empty($row['queue_id']) ? (int) $row['queue_id'] : null, 'queue_name' => (string) ($row['queue_name'] ?? ''),
            'agent_id' => !empty($row['agent_id']) ? (int) $row['agent_id'] : null, 'agent_user_id' => !empty($row['agent_user_id']) ? (int) $row['agent_user_id'] : null,
            'agent_name' => (string) ($row['agent_name'] ?? ''), 'from_number' => (string) ($row['from_number_masked'] ?? ''),
            'to_number' => (string) ($row['to_number_masked'] ?? ''), 'consent_status' => (string) $row['consent_status'],
            'recording_status' => (string) $row['recording_status'], 'transcription_status' => (string) $row['transcription_status'],
            'transcript_id' => !empty($row['transcript_id']) ? (int) $row['transcript_id'] : null,
            'recording_id' => !empty($row['recording_id']) ? (int) $row['recording_id'] : null,
            'insight_summary' => (string) ($row['insight_summary'] ?? ''),
            'insight_review_status' => (string) ($row['insight_review_status'] ?? ''),
            'disposition' => (string) ($row['disposition'] ?? ''), 'failure_category' => (string) ($row['failure_category'] ?? ''),
            'requested_at' => $row['requested_at'] ?? null, 'ringing_at' => $row['ringing_at'] ?? null,
            'answered_at' => $row['answered_at'] ?? null, 'completed_at' => $row['completed_at'] ?? null,
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0), 'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function sessionIdFromResponse(array $response): string
    {
        if (!empty($response['sessionId'])) {
            return trim((string) $response['sessionId']);
        }
        foreach ((array) ($response['entries'] ?? []) as $entry) {
            if (!empty($entry['sessionId'])) {
                return trim((string) $entry['sessionId']);
            }
        }
        return '';
    }

    private function redactProviderMetadata(array $response): array
    {
        unset($response['apiKey'], $response['api_key'], $response['token']);
        return $response;
    }

    private function callbackUrl(string $token, string $uuid): string
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        return $base . '/api/webhooks/voice/africastalking.php?token=' . rawurlencode($token) . '&call=' . rawurlencode($uuid);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
