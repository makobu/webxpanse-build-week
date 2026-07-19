<?php

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;

class VoiceWebhookService
{
    private WorkspaceVoiceConfigService $configs;
    private VoiceProviderInterface $provider;
    private VoiceCallStateMachineService $states;

    public function __construct(
        ?WorkspaceVoiceConfigService $configs = null,
        ?VoiceProviderInterface $provider = null,
        ?VoiceCallStateMachineService $states = null
    ) {
        $this->configs = $configs ?? new WorkspaceVoiceConfigService();
        $this->provider = $provider ?? new AfricaTalkingVoiceProvider();
        $this->states = $states ?? new VoiceCallStateMachineService();
    }

    /** @return array{xml:string,status:int,duplicate:bool} */
    public function handle(string $callbackToken, array $payload, string $sourceIp = '', array $headers = []): array
    {
        $config = $this->configs->findByCallbackToken($callbackToken);
        if ($config === []) {
            return ['xml' => $this->rejectXml(), 'status' => 404, 'duplicate' => false];
        }
        $workspaceId = (int) $config['workspace_id'];
        $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
        $installer = new WorkspaceSkillInstallService();
        $catalog = new WorkspaceSkillCatalogService();
        $pluginAvailable = !empty($entitlements['enabled'])
            && $installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)
            && !$catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER);
        $runtimeEnabled = $pluginAvailable && !empty($config['enabled']) && (new VoiceCallPolicyService())->platformEnabled();
        $config['_runtime_enabled'] = $runtimeEnabled;
        $config['recording_enabled'] = $runtimeEnabled && !empty($config['recording_enabled']) && !empty($entitlements['recording']);
        $config['transcription_enabled'] = $runtimeEnabled && !empty($config['transcription_enabled']) && !empty($entitlements['transcription']);
        $config['customer_voice_enabled'] = $runtimeEnabled && !empty($config['customer_voice_enabled']) && !empty($entitlements['customer_voice']);
        $event = $this->provider->normalizeEvent($payload);
        $sessionId = (string) ($event['session_id'] ?? '');
        if ($sessionId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sessionId)) {
            $this->recordEvent($workspaceId, null, $event, $payload, false, 'invalid_session_id', $sourceIp);
            return ['xml' => $this->rejectXml(), 'status' => 422, 'duplicate' => false];
        }
        foreach (['from', 'to'] as $numberField) {
            if (strlen((string) ($event[$numberField] ?? '')) > 40) {
                $this->recordEvent($workspaceId, null, $event, $payload, false, 'invalid_number_length', $sourceIp);
                return ['xml' => $this->rejectXml(), 'status' => 422, 'duplicate' => false];
            }
        }
        if ($this->rateLimited($workspaceId, $sourceIp)) {
            return ['xml' => $this->rejectXml(), 'status' => 429, 'duplicate' => false];
        }
        $call = !empty($event['session_id']) ? Database::queryOne(
            'SELECT * FROM voice_calls WHERE workspace_id = ? AND provider = ? AND provider_session_id = ? LIMIT 1',
            [$workspaceId, 'africastalking', (string) $event['session_id']]
        ) : null;
        if (!$call && !empty($event['client_request_id']) && preg_match('/^[a-f0-9-]{36}$/i', (string) $event['client_request_id'])) {
            $call = Database::queryOne(
                'SELECT * FROM voice_calls WHERE workspace_id = ? AND uuid = ? LIMIT 1',
                [$workspaceId, (string) $event['client_request_id']]
            );
        }
        if (!$call && !empty($_GET['call'])) {
            $call = Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND uuid = ? LIMIT 1', [$workspaceId, substr((string) $_GET['call'], 0, 36)]);
        }
        $auth = $this->provider->scoreCallbackAuthenticity($config, $payload, $sourceIp, $headers);
        $event['_auth_score'] = (int) ($auth['score'] ?? 0);
        $event['_auth_reasons'] = array_values(array_map('strval', (array) ($auth['reasons'] ?? [])));
        $correlated = $call && (string) ($call['provider_session_id'] ?? '') !== '';
        $accepted = !empty($auth['accepted']) || ($correlated && (int) ($auth['score'] ?? 0) >= 35);
        if (!$accepted) {
            $this->recordEvent($workspaceId, $call ? (int) $call['id'] : null, $event, $payload, false, implode(',', (array) ($auth['reasons'] ?? [])), $sourceIp);
            return ['xml' => $this->rejectXml(), 'status' => 403, 'duplicate' => false];
        }

        if (!$call) {
            if (!$runtimeEnabled) {
                $this->recordEvent($workspaceId, null, $event, $payload, false, 'voice_runtime_disabled', $sourceIp);
                return ['xml' => $this->rejectXml(), 'status' => 403, 'duplicate' => false];
            }
            if ((string) ($event['direction'] ?? '') !== 'inbound') {
                $this->recordEvent($workspaceId, null, $event, $payload, false, 'uncorrelated_non_inbound_event', $sourceIp);
                return ['xml' => $this->rejectXml(), 'status' => 403, 'duplicate' => false];
            }
            if (empty($config['inbound_enabled'])) {
                $this->recordEvent($workspaceId, null, $event, $payload, false, 'inbound_disabled', $sourceIp);
                return ['xml' => $this->rejectXml(), 'status' => 200, 'duplicate' => false];
            }
            $call = $this->createInboundCall($config, $event);
        }
        $recorded = $this->recordEvent($workspaceId, (int) $call['id'], $event, $payload, true, '', $sourceIp);
        if (!$recorded) {
            if ((string) ($call['state'] ?? '') === 'completed') {
                $this->syncCompletedCall($config, $call);
            }
            return ['xml' => $this->instructions($config, $call), 'status' => 200, 'duplicate' => true];
        }

        if (!empty($event['session_id']) && empty($call['provider_session_id'])) {
            Database::execute('UPDATE voice_calls SET provider_session_id = ?, updated_at = NOW() WHERE workspace_id = ? AND id = ?', [(string) $event['session_id'], $workspaceId, (int) $call['id']]);
            $call['provider_session_id'] = (string) $event['session_id'];
        }
        if (!empty($event['state'])) {
            try {
                $attributes = [];
                if ((string) $event['state'] === 'completed') {
                    $attributes['duration_seconds'] = (int) ($event['duration_seconds'] ?? 0);
                }
                if (!empty($event['failure_category'])) {
                    $attributes['failure_category'] = (string) $event['failure_category'];
                }
                if ((string) $event['state'] === 'in_progress' && (string) $call['direction'] === 'outbound' && (string) $call['state'] === 'dialing_agent') {
                    $call = $this->states->transition($workspaceId, (int) $call['id'], 'agent_answered');
                    $call = $this->states->transition($workspaceId, (int) $call['id'], 'dialing_customer');
                } elseif ((string) $event['state'] === 'in_progress' && (string) $call['direction'] === 'inbound' && (string) $call['state'] === 'dialing_agent') {
                    $call = $this->states->transition($workspaceId, (int) $call['id'], 'ringing');
                    $call = $this->states->transition($workspaceId, (int) $call['id'], 'in_progress', $attributes);
                } else {
                    $call = $this->states->transition($workspaceId, (int) $call['id'], (string) $event['state'], $attributes);
                }
                EventBus::publish('voice.call.' . ((string) $event['state'] === 'in_progress' ? 'answered' : (string) $event['state']), [
                    'workspace_id' => $workspaceId, 'call_id' => (int) $call['id'],
                ]);
                if ((string) ($call['direction'] ?? '') === 'inbound'
                    && in_array((string) $event['state'], ['busy', 'no_answer', 'rejected', 'expired'], true)) {
                    (new VoiceMobileNotificationService())->notifyMissed($workspaceId, (int) $call['id']);
                } elseif ((string) $event['state'] === 'provider_failed') {
                    (new VoiceMobileNotificationService())->notifyFailure($workspaceId, (int) $call['id']);
                }
            } catch (\LogicException $e) {
                // Persist out-of-order evidence without moving the guarded state machine backwards.
            }
        }
        if (!empty($event['recording_url'])) {
            $this->storeRecording($config, $call, $event);
        }
        if ((string) ($call['state'] ?? '') === 'completed') {
            $this->syncCompletedCall($config, $call);
        }
        Database::execute('UPDATE workspace_voice_configs SET last_callback_at = NOW(), last_event_at = NOW() WHERE workspace_id = ?', [$workspaceId]);
        return ['xml' => $this->instructions($config, $call), 'status' => 200, 'duplicate' => false];
    }

    public function handleConsent(string $callbackToken, array $payload, string $sourceIp = ''): string
    {
        $config = $this->configs->findByCallbackToken($callbackToken);
        $sessionId = trim((string) ($payload['sessionId'] ?? $payload['SessionId'] ?? ''));
        $digits = trim((string) ($payload['dtmfDigits'] ?? $payload['digits'] ?? ''));
        if ($config === [] || !preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sessionId) || !preg_match('/^[0-9*#]{0,8}$/', $digits)) {
            return $this->rejectXml();
        }
        $workspaceId = (int) $config['workspace_id'];
        $allowedIps = (array) (($config['settings']['callback_ip_allowlist'] ?? []));
        if (($allowedIps !== [] && !in_array($sourceIp, $allowedIps, true)) || $this->rateLimited($workspaceId, $sourceIp)) {
            return $this->rejectXml();
        }
        $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
        $pluginAvailable = !empty($entitlements['enabled'])
            && (new WorkspaceSkillInstallService())->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)
            && !(new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER);
        $runtimeEnabled = $pluginAvailable && !empty($config['enabled']) && (new VoiceCallPolicyService())->platformEnabled();
        $config['_runtime_enabled'] = $runtimeEnabled;
        $config['recording_enabled'] = $runtimeEnabled && !empty($config['recording_enabled']) && !empty($entitlements['recording']);
        $config['transcription_enabled'] = !empty($config['recording_enabled']) && !empty($config['transcription_enabled']) && !empty($entitlements['transcription']);
        $call = Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND provider_session_id = ? LIMIT 1', [(int) $config['workspace_id'], $sessionId]);
        if (!$call) {
            return $this->rejectXml();
        }
        if ((string) ($call['direction'] ?? '') !== 'inbound') {
            return $this->rejectXml();
        }
        if ((string) ($call['consent_status'] ?? '') !== 'pending') {
            return $this->instructions($config, $call, (string) ($call['consent_status'] ?? '') === 'granted');
        }
        $granted = !empty($config['recording_enabled']) && str_starts_with($digits, '1');
        $transcriptionGranted = $granted && !empty($config['transcription_enabled']);
        $consentStatus = $granted ? 'granted' : ($digits === '' ? 'timed_out' : 'declined');
        Database::execute(
            "UPDATE voice_calls SET consent_status = ?, recording_status = IF(? = 1, 'recording', 'disabled'), transcription_status = IF(? = 1 AND transcription_status <> 'disabled', 'pending', 'disabled'), updated_at = NOW() WHERE workspace_id = ? AND id = ? AND consent_status = 'pending'",
            [$consentStatus, $granted ? 1 : 0, $transcriptionGranted ? 1 : 0, (int) $config['workspace_id'], (int) $call['id']]
        );
        $call['consent_status'] = $consentStatus;
        $call['recording_status'] = $granted ? 'recording' : 'disabled';
        $call['transcription_status'] = $transcriptionGranted ? 'pending' : 'disabled';
        if ((string) ($call['state'] ?? '') === 'consent_pending') {
            $call = $this->states->transition(
                (int) $call['workspace_id'],
                (int) $call['id'],
                !empty($call['agent_id']) ? 'dialing_agent' : 'queued'
            );
        }
        return $this->instructions($config, $call, $granted);
    }

    private function createInboundCall(array $config, array $event): array
    {
        $workspaceId = (int) $config['workspace_id'];
        $runtime = (new VoiceCallPolicyService())->assertRuntimeReady($workspaceId, 'inbound');
        Database::beginTransaction();
        try {
            Database::queryOne('SELECT workspace_id FROM workspace_voice_configs WHERE workspace_id = ? FOR UPDATE', [$workspaceId]);
            (new VoiceCallPolicyService())->assertCapacity($workspaceId, $config, (array) $runtime['entitlements']);
            $from = $this->configs->normalizePhone((string) ($event['from'] ?? ''));
            $to = $this->configs->normalizePhone((string) ($event['to'] ?? $config['virtual_number']));
            $contactId = $from !== '' ? (new VoiceContactPhoneIndexService())->findUniqueContactId($workspaceId, $from) : null;
            $uuid = $this->uuid();
            $consentStatus = empty($config['recording_enabled'])
                ? 'not_required'
                : ((string) $config['consent_mode'] === 'notice_only'
                    ? 'granted'
                    : ((string) $config['consent_mode'] === 'disabled' ? 'not_required' : 'pending'));
            Database::execute(
                "INSERT INTO voice_calls (workspace_id, uuid, provider, provider_session_id, direction, state, contact_id,
                    encrypted_from_number, from_number_hash, from_number_masked, encrypted_to_number, to_number_hash, to_number_masked,
                    consent_status, recording_status, transcription_status, requested_at)
                 VALUES (?, ?, 'africastalking', ?, 'inbound', 'received', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$workspaceId, $uuid, (string) ($event['session_id'] ?? ''), $contactId,
                    $from !== '' ? $this->configs->encryptValue($from) : null, $from !== '' ? $this->configs->numberHash($from) : null, $from !== '' ? $this->configs->maskPhone($from) : null,
                    $to !== '' ? $this->configs->encryptValue($to) : null, $to !== '' ? $this->configs->numberHash($to) : null, $to !== '' ? $this->configs->maskPhone($to) : null,
                    $consentStatus, !empty($config['recording_enabled']) ? 'pending' : 'disabled', !empty($config['transcription_enabled']) ? 'pending' : 'disabled']
            );
            $callId = (int) Database::lastInsertId();
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        $queues = new VoiceQueueService();
        $route = $queues->routeInboundCall($workspaceId, $callId);
        $agent = (array) ($route['agent'] ?? []);
        $nextState = $consentStatus === 'pending' ? 'consent_pending' : ($agent ? 'dialing_agent' : 'queued');
        $this->states->transition($workspaceId, $callId, $nextState);
        $call = Database::queryOne('SELECT * FROM voice_calls WHERE workspace_id = ? AND id = ?', [$workspaceId, $callId]) ?: [];
        if (!empty($agent['user_id'])) {
            (new VoiceMobileNotificationService())->notifyAssigned($workspaceId, $callId, (int) $agent['user_id']);
        }
        EventBus::publish('voice.call.received', ['workspace_id' => $workspaceId, 'call_id' => $callId, 'matched_contact' => !empty($call['contact_id']), 'assigned_agent' => !empty($agent)]);
        return $call;
    }

    private function instructions(array $config, array $call, ?bool $consentGranted = null): string
    {
        if ((new VoiceCallStateMachineService())->isTerminal((string) ($call['state'] ?? ''))) {
            return '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
        }
        if (array_key_exists('_runtime_enabled', $config) && empty($config['_runtime_enabled'])) {
            return $this->rejectXml();
        }
        $agent = !empty($call['agent_id']) ? (new VoiceAgentService($this->configs))->findById((int) $call['workspace_id'], (int) $call['agent_id'], true) : [];
        if ((string) $call['direction'] === 'outbound') {
            $destination = !empty($call['encrypted_to_number']) ? $this->configs->decryptValue((string) $call['encrypted_to_number']) : '';
            return $this->provider->renderOutboundBridgeInstructions([
                'destination' => $destination, 'record' => false,
                'consent_granted' => false,
                'max_duration' => (int) $config['max_call_duration_seconds'],
            ]);
        }
        $queues = new VoiceQueueService();
        $queue = !empty($call['queue_id'])
            ? $queues->find((int) $call['workspace_id'], (int) $call['queue_id'])
            : $queues->defaultQueue((int) $call['workspace_id']);
        $fallback = (string) ($queue['fallback_destination'] ?? '');
        if ((string) ($queue['fallback_action'] ?? 'reject') !== 'verified_number') {
            $fallback = '';
        }
        return $this->provider->renderInboundInstructions([
            'destination' => (string) ($agent['endpoint'] ?? ''), 'fallback' => $fallback,
            'consent_mode' => $consentGranted === null ? (string) $config['consent_mode'] : 'notice_only',
            'consent_notice' => $consentGranted === null ? (string) $config['consent_notice'] : '',
            'consent_callback_url' => $this->consentUrl((string) $config['callback_token']),
            'record' => $consentGranted === true || (string) $config['consent_mode'] === 'notice_only',
            'max_duration' => (int) $config['max_call_duration_seconds'],
        ]);
    }

    private function recordEvent(int $workspaceId, ?int $callId, array $event, array $payload, bool $accepted, string $reason, string $sourceIp): bool
    {
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '');
        $eventKey = trim((string) ($payload['eventId'] ?? $payload['requestId'] ?? ''));
        if ($eventKey === '') {
            $eventKey = hash('sha256', implode('|', [(string) ($event['session_id'] ?? ''), (string) ($event['event_type'] ?? ''), (string) ($event['state'] ?? ''), $payloadHash]));
        }
        $metadata = [
            'auth_score' => (int) ($event['_auth_score'] ?? 0),
            'auth_reasons' => array_slice((array) ($event['_auth_reasons'] ?? []), 0, 10),
            'duration_seconds' => (int) ($event['duration_seconds'] ?? 0),
            'has_recording' => !empty($event['recording_url']),
        ];
        $inserted = Database::execute(
            "INSERT IGNORE INTO voice_call_events (workspace_id, call_id, provider, provider_event_key, provider_session_id,
                event_type, normalized_state, payload_hash, metadata_json, source_ip_hash, accepted, rejection_reason, occurred_at)
             VALUES (?, ?, 'africastalking', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $callId, substr($eventKey, 0, 191), (string) ($event['session_id'] ?? ''), substr((string) ($event['event_type'] ?? 'unknown'), 0, 80),
                $event['state'] ?? null, $payloadHash, json_encode($metadata), $sourceIp !== '' ? hash('sha256', $sourceIp) : null,
                $accepted ? 1 : 0, $reason !== '' ? substr($reason, 0, 191) : null, $this->date((string) ($event['occurred_at'] ?? ''))]
        );
        return $inserted > 0;
    }

    private function storeRecording(array $config, array $call, array $event): void
    {
        if (empty($config['recording_enabled']) || (string) ($call['consent_status'] ?? '') !== 'granted') {
            return;
        }
        $url = (string) $event['recording_url'];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !$this->allowedRecordingHost($host)) {
            Database::execute("UPDATE voice_calls SET recording_status = 'failed', failure_category = 'recording_url_policy' WHERE workspace_id = ? AND id = ?", [(int) $call['workspace_id'], (int) $call['id']]);
            return;
        }
        $retainedUntil = date('Y-m-d H:i:s', time() + max(1, (int) $config['recording_retention_days']) * 86400);
        Database::execute(
            "INSERT INTO voice_recordings (workspace_id, call_id, provider, provider_recording_id, encrypted_provider_url, provider_host, status, duration_seconds, retained_until)
             VALUES (?, ?, 'africastalking', ?, ?, ?, 'ready', ?, ?)
             ON DUPLICATE KEY UPDATE provider_recording_id = VALUES(provider_recording_id), encrypted_provider_url = VALUES(encrypted_provider_url),
                provider_host = VALUES(provider_host), status = 'ready', duration_seconds = VALUES(duration_seconds), retained_until = VALUES(retained_until), last_error = NULL",
            [(int) $call['workspace_id'], (int) $call['id'], (string) ($event['recording_id'] ?? ''), $this->configs->encryptValue($url), $host, (int) ($event['duration_seconds'] ?? 0), $retainedUntil]
        );
        $recordingId = (int) (Database::queryOne('SELECT id FROM voice_recordings WHERE workspace_id = ? AND call_id = ?', [(int) $call['workspace_id'], (int) $call['id']])['id'] ?? 0);
        Database::execute("UPDATE voice_calls SET recording_status = 'ready' WHERE workspace_id = ? AND id = ?", [(int) $call['workspace_id'], (int) $call['id']]);
        if ($recordingId > 0 && !empty($config['transcription_enabled'])) {
            Database::execute(
                "INSERT IGNORE INTO voice_transcription_jobs (workspace_id, call_id, recording_id, status, available_at) VALUES (?, ?, ?, 'pending', NOW())",
                [(int) $call['workspace_id'], (int) $call['id'], $recordingId]
            );
            Database::execute("UPDATE voice_calls SET transcription_status = 'pending' WHERE workspace_id = ? AND id = ?", [(int) $call['workspace_id'], (int) $call['id']]);
        }
        EventBus::publish('voice.recording.ready', ['workspace_id' => (int) $call['workspace_id'], 'call_id' => (int) $call['id'], 'recording_id' => $recordingId]);
    }

    private function recordUsage(array $config, array $call): void
    {
        $sipAgent = false;
        if (!empty($call['agent_id'])) {
            $endpoint = Database::queryOne(
                'SELECT endpoint_type FROM voice_agents WHERE workspace_id = ? AND id = ? LIMIT 1',
                [(int) $call['workspace_id'], (int) $call['agent_id']]
            );
            $sipAgent = (string) ($endpoint['endpoint_type'] ?? '') === 'sip';
        }
        $estimate = $this->provider->estimateUsage(
            (string) $call['direction'],
            (int) ($call['duration_seconds'] ?? 0),
            $sipAgent
        );
        Database::execute(
            "INSERT IGNORE INTO voice_usage_ledger (workspace_id, call_id, direction, duration_seconds, estimated_billable_minutes, estimated_provider_cost, provider_currency, transcription_model, estimate_only)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [(int) $call['workspace_id'], (int) $call['id'], (string) $call['direction'], (int) ($call['duration_seconds'] ?? 0),
                (int) $estimate['billable_minutes'], (float) $estimate['amount'], (string) $estimate['currency'], (string) $config['transcription_model']]
        );
    }

    private function syncCompletedCall(array $config, array $call): void
    {
        try {
            $this->recordUsage($config, $call);
        } catch (\Throwable $e) {
            error_log('Voice completed-call usage sync failed: ' . $e->getMessage());
        }
        try {
            (new VoiceCrmContextService())->sync((int) $call['workspace_id'], (int) $call['id']);
        } catch (\Throwable $e) {
            error_log('Voice completed-call CRM sync failed: ' . $e->getMessage());
        }
    }

    private function allowedRecordingHost(string $host): bool
    {
        return $host !== '' && ($host === 'africastalking.com' || str_ends_with($host, '.africastalking.com'));
    }

    private function rateLimited(int $workspaceId, string $sourceIp): bool
    {
        if ($sourceIp === '') {
            return false;
        }
        $count = (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM voice_call_events WHERE workspace_id = ? AND source_ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
            [$workspaceId, hash('sha256', $sourceIp)]
        )['c'] ?? 0);
        return $count >= 240;
    }

    private function consentUrl(string $token): string
    {
        $base = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        return $base . '/api/webhooks/voice/consent.php?token=' . rawurlencode($token);
    }

    private function rejectXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Reject /></Response>';
    }

    private function date(string $value): string
    {
        $time = strtotime($value);
        return date('Y-m-d H:i:s', $time ?: time());
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
