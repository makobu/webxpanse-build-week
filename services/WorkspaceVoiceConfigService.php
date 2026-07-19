<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Security;
use CRM\Authorization;

class WorkspaceVoiceConfigService
{
    private const RECORDING_ACKNOWLEDGEMENT_VERSION = 'voice-recording-responsibility-v1';

    public function isAvailable(): bool
    {
        return Database::tableExists('workspace_voice_configs');
    }

    /** @return array<string,mixed> */
    public function save(int $workspaceId, array $settings, int $userId = 0): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Voice Call Center tables are missing. Run migrations first.');
        }
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to save voice settings.');
        }

        $existing = $this->get($workspaceId, true);
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $encryptedApiKey = $apiKey !== ''
            ? $this->encryptSecret($apiKey)
            : ($existing['encrypted_api_key'] ?? null);
        $fingerprint = $apiKey !== ''
            ? substr(hash('sha256', $apiKey), 0, 16)
            : ($existing['api_key_fingerprint'] ?? null);
        $callbackToken = (string) ($existing['callback_token'] ?? '');
        if ($callbackToken === '') {
            $callbackToken = bin2hex(random_bytes(24));
        }

        $consentMode = (string) ($settings['consent_mode'] ?? $existing['consent_mode'] ?? 'explicit_keypress');
        if (!in_array($consentMode, ['explicit_keypress', 'notice_only', 'disabled'], true)) {
            $consentMode = 'explicit_keypress';
        }
        $recordingEnabled = !empty($settings['recording_enabled']);
        $transcriptionEnabled = !empty($settings['transcription_enabled']);
        $aiApplicationEnabled = !empty($settings['ai_application_enabled']);
        $customerVoiceEnabled = !empty($settings['customer_voice_enabled']);
        $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
        if ($recordingEnabled && empty($entitlements['recording'])) {
            throw new \RuntimeException('Call recording is not enabled for this workspace package.');
        }
        if ($transcriptionEnabled && empty($entitlements['transcription'])) {
            throw new \RuntimeException('Voice transcription is not enabled for this workspace package.');
        }
        if ($customerVoiceEnabled && empty($entitlements['customer_voice'])) {
            throw new \RuntimeException('Customer Voice is not enabled for this workspace package.');
        }
        $consentNotice = trim((string) ($settings['consent_notice'] ?? $existing['consent_notice'] ?? ''));
        if ($recordingEnabled && ($consentMode === 'disabled' || $consentNotice === '')) {
            throw new \RuntimeException('Recording requires a configured consent method and notice.');
        }
        if ($transcriptionEnabled && !$recordingEnabled) {
            throw new \RuntimeException('Voice transcription requires recording to be enabled.');
        }
        if ($customerVoiceEnabled && (!$transcriptionEnabled || !$aiApplicationEnabled)) {
            throw new \RuntimeException('Customer Voice requires transcription and AI application to be enabled.');
        }

        $allowedCountries = $this->stringList($settings['allowed_country_codes'] ?? $existing['allowed_country_codes'] ?? ['+254']);
        if ($allowedCountries === []) {
            $allowedCountries = ['+254'];
        }
        $blockedPrefixes = $this->stringList($settings['blocked_prefixes'] ?? $existing['blocked_prefixes'] ?? []);
        $incomingMetadata = is_array($settings['settings'] ?? null) ? $settings['settings'] : [];
        $metadata = array_replace((array) ($existing['settings'] ?? []), $incomingMetadata);
        $automationPolicyInput = is_array($settings['automation_policy'] ?? null)
            ? $settings['automation_policy']
            : (array) ($metadata['automation_policy'] ?? []);
        $metadata['automation_policy'] = (new VoicePolicyDecisionService())->normalize($automationPolicyInput);
        $maxCallDuration = max(60, min(14400, (int) ($settings['max_call_duration_seconds'] ?? $existing['max_call_duration_seconds'] ?? 3600)));
        $hourlyCallLimit = max(1, (int) ($settings['hourly_call_limit'] ?? $existing['hourly_call_limit'] ?? 60));
        $dailyMinuteLimit = max(1, (int) ($settings['daily_minute_limit'] ?? $existing['daily_minute_limit'] ?? 1000));
        $recordingRetentionDays = max(1, (int) ($settings['recording_retention_days'] ?? $existing['recording_retention_days'] ?? 30));
        $transcriptRetentionDays = max(1, (int) ($settings['transcript_retention_days'] ?? $existing['transcript_retention_days'] ?? 180));
        $transcriptionModel = $this->model((string) ($settings['transcription_model'] ?? $existing['transcription_model'] ?? 'gpt-4o-mini-transcribe'));

        $recordingPolicyFingerprint = $this->recordingPolicyFingerprint([
            'consent_mode' => $consentMode,
            'consent_notice' => $consentNotice,
            'allowed_country_codes' => $allowedCountries,
            'recording_retention_days' => $recordingRetentionDays,
            'transcript_retention_days' => $transcriptRetentionDays,
            'transcription_enabled' => $transcriptionEnabled,
            'automation_policy' => $metadata['automation_policy'],
        ]);
        $existingAcknowledgement = (array) (($existing['settings']['recording_acknowledgement'] ?? null) ?: []);
        $acknowledgementCurrent = $recordingEnabled
            && !empty($existing['compliance_acknowledged_at'])
            && hash_equals((string) ($existingAcknowledgement['policy_fingerprint'] ?? ''), $recordingPolicyFingerprint)
            && (string) ($existingAcknowledgement['version'] ?? '') === self::RECORDING_ACKNOWLEDGEMENT_VERSION;
        if ($recordingEnabled && !$acknowledgementCurrent) {
            if (empty($settings['compliance_acknowledged'])) {
                throw new \RuntimeException('Recording requires a current workspace-owner compliance acknowledgement for the applicable call locations, consent method, and automation policy.');
            }
            $this->assertRecordingAcknowledgementOwner($workspaceId, $userId);
            $acknowledgedAt = date('Y-m-d H:i:s');
            $metadata['recording_acknowledgement'] = [
                'version' => self::RECORDING_ACKNOWLEDGEMENT_VERSION,
                'policy_fingerprint' => $recordingPolicyFingerprint,
                'acknowledged_at' => $acknowledgedAt,
                'acknowledged_by_user_id' => $userId,
                'responsibility' => 'workspace_owner',
            ];
            $acknowledgedByUserId = $userId;
        } elseif ($recordingEnabled) {
            $acknowledgedAt = (string) $existing['compliance_acknowledged_at'];
            $metadata['recording_acknowledgement'] = $existingAcknowledgement;
            $acknowledgedByUserId = (int) ($existing['compliance_acknowledged_by_user_id'] ?? $existingAcknowledgement['acknowledged_by_user_id'] ?? 0);
        } else {
            $acknowledgedAt = null;
            $acknowledgedByUserId = 0;
            unset($metadata['recording_acknowledgement']);
        }
        $complianceAcknowledged = $recordingEnabled && $acknowledgedAt !== null;
        $username = trim((string) ($settings['account_username'] ?? $existing['account_username'] ?? ''));
        $virtualNumber = $this->normalizePhone((string) ($settings['virtual_number'] ?? $existing['virtual_number'] ?? ''));
        $enabled = !empty($settings['enabled']);
        $status = $enabled && $username !== '' && $encryptedApiKey && $virtualNumber !== '' ? 'needs_setup' : ($enabled ? 'needs_setup' : 'disabled');

        Database::execute(
            "INSERT INTO workspace_voice_configs (
                workspace_id, provider, enabled, inbound_enabled, outbound_enabled,
                recording_enabled, transcription_enabled, ai_application_enabled, customer_voice_enabled,
                account_username, encrypted_api_key, api_key_fingerprint, virtual_number,
                encrypted_callback_token, callback_token_hash, consent_mode, consent_notice,
                compliance_acknowledged_at, compliance_acknowledged_by_user_id,
                allowed_country_codes_json, blocked_prefixes_json, max_call_duration_seconds,
                hourly_call_limit, daily_minute_limit, recording_retention_days,
                transcript_retention_days, transcription_model, status, settings_json,
                created_by_user_id, updated_by_user_id
             ) VALUES (?, 'africastalking', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), inbound_enabled = VALUES(inbound_enabled),
                outbound_enabled = VALUES(outbound_enabled), recording_enabled = VALUES(recording_enabled),
                transcription_enabled = VALUES(transcription_enabled), ai_application_enabled = VALUES(ai_application_enabled),
                customer_voice_enabled = VALUES(customer_voice_enabled), account_username = VALUES(account_username),
                encrypted_api_key = VALUES(encrypted_api_key), api_key_fingerprint = VALUES(api_key_fingerprint),
                virtual_number = VALUES(virtual_number), encrypted_callback_token = VALUES(encrypted_callback_token),
                callback_token_hash = VALUES(callback_token_hash), consent_mode = VALUES(consent_mode),
                consent_notice = VALUES(consent_notice), compliance_acknowledged_at = VALUES(compliance_acknowledged_at),
                compliance_acknowledged_by_user_id = VALUES(compliance_acknowledged_by_user_id),
                allowed_country_codes_json = VALUES(allowed_country_codes_json), blocked_prefixes_json = VALUES(blocked_prefixes_json),
                max_call_duration_seconds = VALUES(max_call_duration_seconds), hourly_call_limit = VALUES(hourly_call_limit),
                daily_minute_limit = VALUES(daily_minute_limit), recording_retention_days = VALUES(recording_retention_days),
                transcript_retention_days = VALUES(transcript_retention_days), transcription_model = VALUES(transcription_model),
                status = VALUES(status), settings_json = VALUES(settings_json), updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $workspaceId, $enabled ? 1 : 0, !empty($settings['inbound_enabled']) ? 1 : 0,
                !empty($settings['outbound_enabled']) ? 1 : 0, $recordingEnabled ? 1 : 0,
                $transcriptionEnabled ? 1 : 0, $aiApplicationEnabled ? 1 : 0,
                $customerVoiceEnabled ? 1 : 0, $username !== '' ? $username : null,
                $encryptedApiKey, $fingerprint, $virtualNumber !== '' ? $virtualNumber : null,
                $this->encryptSecret($callbackToken), hash('sha256', $callbackToken), $consentMode,
                $consentNotice !== '' ? $consentNotice : null,
                $complianceAcknowledged ? $acknowledgedAt : null,
                $complianceAcknowledged && $acknowledgedByUserId > 0 ? $acknowledgedByUserId : null,
                json_encode($allowedCountries, JSON_UNESCAPED_SLASHES),
                $blockedPrefixes === [] ? null : json_encode($blockedPrefixes, JSON_UNESCAPED_SLASHES),
                $maxCallDuration, $hourlyCallLimit, $dailyMinuteLimit, $recordingRetentionDays,
                $transcriptRetentionDays, $transcriptionModel,
                $status, $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null, $userId > 0 ? $userId : null,
            ]
        );

        return $this->get($workspaceId, false);
    }

    /** @return array<string,mixed> */
    public function get(int $workspaceId, bool $includeSecrets = false): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }
        $row = Database::queryOne('SELECT * FROM workspace_voice_configs WHERE workspace_id = ? LIMIT 1', [$workspaceId]);
        return $row ? $this->hydrate($row, $includeSecrets) : [];
    }

    /** @return array<string,mixed> */
    public function findByCallbackToken(string $token): array
    {
        if (!$this->isAvailable() || strlen($token) < 32) {
            return [];
        }
        $row = Database::queryOne(
            'SELECT * FROM workspace_voice_configs WHERE callback_token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );
        return $row ? $this->hydrate($row, true) : [];
    }

    /** @return array<string,mixed> */
    public function readiness(int $workspaceId): array
    {
        $config = $this->get($workspaceId, true);
        $entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
        $ai = (new WorkspaceAIProviderConfigService())->get($workspaceId, false);
        $callbackBase = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        $callbackParts = $callbackBase !== '' ? parse_url($callbackBase) : false;
        $callbackHost = is_array($callbackParts) ? strtolower((string) ($callbackParts['host'] ?? '')) : '';
        $callbackScheme = is_array($callbackParts) ? strtolower((string) ($callbackParts['scheme'] ?? '')) : '';
        $callbackReady = $callbackHost !== '' && ($callbackScheme === 'https'
            || ($callbackScheme === 'http' && in_array($callbackHost, ['localhost', '127.0.0.1', '::1'], true)));
        $agentCount = (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM voice_agents WHERE workspace_id = ? AND enabled = 1',
            [$workspaceId]
        )['c'] ?? 0);
        $queue = (new VoiceQueueService())->defaultQueue($workspaceId);
        $queueId = (int) ($queue['id'] ?? 0);
        $queueMemberCount = $queueId > 0 ? (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM voice_queue_members m
             INNER JOIN voice_agents a ON a.id = m.agent_id AND a.workspace_id = m.workspace_id
             WHERE m.workspace_id = ? AND m.queue_id = ? AND m.enabled = 1 AND a.enabled = 1",
            [$workspaceId, $queueId]
        )['c'] ?? 0) : 0;
        $fallbackReady = (string) ($queue['fallback_action'] ?? '') === 'verified_number'
            && trim((string) ($queue['fallback_destination'] ?? '')) !== '';
        $callingEnabled = !empty($config['inbound_enabled']) || !empty($config['outbound_enabled']);
        $checks = [
            ['label' => 'Platform voice flag', 'ok' => (new VoiceCallPolicyService())->platformEnabled(), 'required' => true],
            ['label' => 'Package entitlement', 'ok' => !empty($entitlements['enabled']), 'required' => true],
            ['label' => 'Voice plugin enabled', 'ok' => !empty($config['enabled']), 'required' => true],
            ['label' => 'Africa\'s Talking username', 'ok' => trim((string) ($config['account_username'] ?? '')) !== '', 'required' => true],
            ['label' => 'Africa\'s Talking API key', 'ok' => !empty($config['api_key_saved']), 'required' => true],
            ['label' => 'Virtual number', 'ok' => trim((string) ($config['virtual_number'] ?? '')) !== '', 'required' => true],
            ['label' => 'Public HTTPS callback base URL', 'ok' => $callbackReady, 'required' => true],
            ['label' => 'Provider credentials verified', 'ok' => (string) ($config['status'] ?? '') === 'ready' && !empty($config['last_verified_at']), 'required' => true],
            ['label' => 'Voice agent endpoint', 'ok' => $agentCount > 0, 'required' => $callingEnabled],
            ['label' => 'Inbound queue route', 'ok' => $queueId > 0 && ($queueMemberCount > 0 || $fallbackReady), 'required' => !empty($config['inbound_enabled'])],
            ['label' => 'Recording package entitlement', 'ok' => empty($config['recording_enabled']) || !empty($entitlements['recording']), 'required' => !empty($config['recording_enabled'])],
            ['label' => 'Transcription package entitlement', 'ok' => empty($config['transcription_enabled']) || !empty($entitlements['transcription']), 'required' => !empty($config['transcription_enabled'])],
            ['label' => 'Customer Voice package entitlement', 'ok' => empty($config['customer_voice_enabled']) || !empty($entitlements['customer_voice']), 'required' => !empty($config['customer_voice_enabled'])],
            ['label' => 'Consent configured', 'ok' => empty($config['recording_enabled']) || ((string) ($config['consent_mode'] ?? '') !== 'disabled' && trim((string) ($config['consent_notice'] ?? '')) !== ''), 'required' => !empty($config['recording_enabled'])],
            ['label' => 'Workspace-owner recording acknowledgement', 'ok' => empty($config['recording_enabled']) || !empty($config['recording_acknowledgement_valid']), 'required' => !empty($config['recording_enabled'])],
            ['label' => 'Workspace AI key in Settings', 'ok' => !empty($ai['enabled']) && !empty($ai['api_key_present']), 'required' => !empty($config['transcription_enabled'])],
            ['label' => 'Transcription model', 'ok' => (new OpenAIVoiceTranscriptionProvider())->supportsModel((string) ($config['transcription_model'] ?? '')), 'required' => !empty($config['transcription_enabled'])],
            ['label' => 'Voice workers', 'ok' => $this->workerHealthy($workspaceId), 'required' => !empty($config['transcription_enabled'])],
        ];
        $blockers = array_values(array_map(
            static fn(array $check): string => (string) $check['label'],
            array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
        ));
        return [
            'ready' => $blockers === [],
            'status' => $blockers === [] ? 'ready' : ($config === [] ? 'not_configured' : 'needs_setup'),
            'checks' => $checks,
            'blockers' => $blockers,
            'message' => $blockers === [] ? 'Voice & Call Center is ready.' : 'Complete the required voice setup checks.',
            'config' => $this->get($workspaceId, false),
            'entitlements' => $entitlements,
        ];
    }

    public function markVerified(int $workspaceId, bool $ok, string $error = '', array $metadata = []): void
    {
        $config = $this->get($workspaceId, false);
        $settings = (array) ($config['settings'] ?? []);
        $settings['provider_verification'] = [
            'verified_at' => date(DATE_ATOM),
            'environment' => substr((string) ($metadata['environment'] ?? ''), 0, 20),
            'balance' => substr((string) ($metadata['balance'] ?? ''), 0, 80),
        ];
        Database::execute(
            "UPDATE workspace_voice_configs SET status = ?, last_verified_at = NOW(), last_error = ?, settings_json = ?, updated_at = NOW() WHERE workspace_id = ?",
            [$ok ? 'ready' : 'degraded', $error !== '' ? substr($error, 0, 500) : null,
                json_encode($settings, JSON_UNESCAPED_SLASHES), $workspaceId]
        );
    }

    private function hydrate(array $row, bool $includeSecrets): array
    {
        $json = static function ($value): array {
            $decoded = $value ? json_decode((string) $value, true) : [];
            return is_array($decoded) ? $decoded : [];
        };
        $apiKey = $includeSecrets && !empty($row['encrypted_api_key']) ? $this->decryptSecret((string) $row['encrypted_api_key']) : '';
        $callbackToken = $includeSecrets && !empty($row['encrypted_callback_token']) ? $this->decryptSecret((string) $row['encrypted_callback_token']) : '';
        $settings = $json($row['settings_json'] ?? null);
        $config = [
            'workspace_id' => (int) $row['workspace_id'], 'provider' => (string) $row['provider'],
            'enabled' => !empty($row['enabled']), 'inbound_enabled' => !empty($row['inbound_enabled']),
            'outbound_enabled' => !empty($row['outbound_enabled']), 'recording_enabled' => !empty($row['recording_enabled']),
            'transcription_enabled' => !empty($row['transcription_enabled']), 'ai_application_enabled' => !empty($row['ai_application_enabled']),
            'customer_voice_enabled' => !empty($row['customer_voice_enabled']), 'account_username' => (string) ($row['account_username'] ?? ''),
            'api_key_saved' => !empty($row['encrypted_api_key']), 'api_key_fingerprint' => (string) ($row['api_key_fingerprint'] ?? ''),
            'api_key' => $includeSecrets ? $apiKey : null, 'encrypted_api_key' => $includeSecrets ? (string) ($row['encrypted_api_key'] ?? '') : null,
            'virtual_number' => (string) ($row['virtual_number'] ?? ''), 'callback_token' => $includeSecrets ? $callbackToken : null,
            'consent_mode' => (string) $row['consent_mode'], 'consent_notice' => (string) ($row['consent_notice'] ?? ''),
            'compliance_acknowledged_at' => $row['compliance_acknowledged_at'] ?? null,
            'compliance_acknowledged_by_user_id' => !empty($row['compliance_acknowledged_by_user_id']) ? (int) $row['compliance_acknowledged_by_user_id'] : null,
            'allowed_country_codes' => $json($row['allowed_country_codes_json'] ?? null),
            'blocked_prefixes' => $json($row['blocked_prefixes_json'] ?? null),
            'max_call_duration_seconds' => (int) $row['max_call_duration_seconds'], 'hourly_call_limit' => (int) $row['hourly_call_limit'],
            'daily_minute_limit' => (int) $row['daily_minute_limit'], 'recording_retention_days' => (int) $row['recording_retention_days'],
            'transcript_retention_days' => (int) $row['transcript_retention_days'], 'transcription_model' => (string) $row['transcription_model'],
            'status' => (string) $row['status'], 'last_verified_at' => $row['last_verified_at'] ?? null,
            'last_callback_at' => $row['last_callback_at'] ?? null, 'last_event_at' => $row['last_event_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''), 'settings' => $settings,
        ];
        $config['automation_policy'] = (new VoicePolicyDecisionService())->normalize((array) ($settings['automation_policy'] ?? []));
        $config['recording_acknowledgement'] = (array) ($settings['recording_acknowledgement'] ?? []);
        $config['recording_acknowledgement_valid'] = $this->recordingAcknowledgementIsCurrent($config);
        return $config;
    }

    private function workerHealthy(int $workspaceId): bool
    {
        if (!Database::tableExists('voice_worker_heartbeats')) {
            return false;
        }
        return (bool) Database::queryOne(
            "SELECT 1 FROM voice_worker_heartbeats WHERE (workspace_id = ? OR workspace_id IS NULL) AND heartbeat_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1",
            [$workspaceId]
        );
    }

    private function stringList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\r\n]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(static fn($item): string => trim((string) $item), $value))));
    }

    public function normalizePhone(string $number): string
    {
        $number = preg_replace('/[^0-9+]/', '', trim($number)) ?? '';
        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        } elseif ($number !== '' && $number[0] !== '+') {
            $number = str_starts_with($number, '0') ? '+254' . substr($number, 1) : '+' . $number;
        }
        return preg_match('/^\+[1-9][0-9]{7,14}$/', $number) ? $number : '';
    }

    public function numberHash(string $number): string
    {
        return hash_hmac('sha256', $this->normalizePhone($number), $this->encryptionKey());
    }

    public function encryptValue(string $value): string
    {
        return $this->encryptSecret($value);
    }

    public function decryptValue(string $value): string
    {
        return $this->decryptSecret($value);
    }

    public function maskPhone(string $number): string
    {
        $number = $this->normalizePhone($number);
        return strlen($number) > 7 ? substr($number, 0, 4) . str_repeat('*', max(3, strlen($number) - 7)) . substr($number, -3) : '***';
    }

    private function model(string $model): string
    {
        $model = trim($model);
        if (!(new OpenAIVoiceTranscriptionProvider())->supportsModel($model)) {
            throw new \InvalidArgumentException('Choose a supported OpenAI transcription model.');
        }
        return $model;
    }

    /** @param array<string,mixed> $policy */
    private function recordingPolicyFingerprint(array $policy): string
    {
        $canonical = [
            'consent_mode' => (string) ($policy['consent_mode'] ?? ''),
            'consent_notice' => trim((string) ($policy['consent_notice'] ?? '')),
            'allowed_country_codes' => array_values((array) ($policy['allowed_country_codes'] ?? [])),
            'recording_retention_days' => (int) ($policy['recording_retention_days'] ?? 0),
            'transcript_retention_days' => (int) ($policy['transcript_retention_days'] ?? 0),
            'transcription_enabled' => !empty($policy['transcription_enabled']),
            'automation_policy' => (new VoicePolicyDecisionService())->normalize((array) ($policy['automation_policy'] ?? [])),
        ];
        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string,mixed> $config */
    private function recordingAcknowledgementIsCurrent(array $config): bool
    {
        if (empty($config['recording_enabled']) || empty($config['compliance_acknowledged_at'])) {
            return false;
        }
        $acknowledgement = (array) ($config['recording_acknowledgement'] ?? []);
        if ((string) ($acknowledgement['version'] ?? '') !== self::RECORDING_ACKNOWLEDGEMENT_VERSION) {
            return false;
        }
        $expected = $this->recordingPolicyFingerprint([
            'consent_mode' => $config['consent_mode'] ?? '',
            'consent_notice' => $config['consent_notice'] ?? '',
            'allowed_country_codes' => $config['allowed_country_codes'] ?? [],
            'recording_retention_days' => $config['recording_retention_days'] ?? 0,
            'transcript_retention_days' => $config['transcript_retention_days'] ?? 0,
            'transcription_enabled' => $config['transcription_enabled'] ?? false,
            'automation_policy' => $config['automation_policy'] ?? [],
        ]);
        $stored = (string) ($acknowledgement['policy_fingerprint'] ?? '');
        return $stored !== '' && hash_equals($stored, $expected);
    }

    private function assertRecordingAcknowledgementOwner(int $workspaceId, int $userId): void
    {
        if ($userId <= 0) {
            throw new \RuntimeException('A workspace owner must acknowledge recording responsibility.');
        }
        $globalRole = Authorization::getGlobalUserRole($userId);
        if ((string) ($globalRole['slug'] ?? '') === 'superadmin') {
            return;
        }
        $membership = Database::queryOne(
            "SELECT role_slug, is_owner FROM workspace_memberships
             WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1",
            [$workspaceId, $userId]
        );
        if ($membership && (!empty($membership['is_owner']) || (string) ($membership['role_slug'] ?? '') === 'owner')) {
            return;
        }
        $workspaceRole = Authorization::getWorkspaceUserRole($userId, $workspaceId);
        if ((string) ($workspaceRole['slug'] ?? '') === 'owner') {
            return;
        }
        throw new \RuntimeException('Only a workspace owner can acknowledge recording responsibility.');
    }

    private function encryptSecret(string $secret): string
    {
        return Security::encryptSensitiveData($secret, $this->encryptionKey());
    }

    private function decryptSecret(string $secret): string
    {
        try {
            return Security::decryptSensitiveData($secret, $this->encryptionKey());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }
}
