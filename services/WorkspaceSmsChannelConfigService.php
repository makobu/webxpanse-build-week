<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Security;

class WorkspaceSmsChannelConfigService
{
    public function isAvailable(): bool
    {
        return Database::tableExists('workspace_sms_channel_configs');
    }

    public function save(int $workspaceId, array $settings, int $userId = 0): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('workspace_sms_channel_configs table is missing. Run migrations first.');
        }
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to save SMS channel settings.');
        }

        $existing = $this->get($workspaceId, true);
        $authToken = trim((string) ($settings['auth_token'] ?? ''));
        $encryptedAuthToken = $authToken !== ''
            ? $this->encryptSecret($authToken)
            : ($existing['encrypted_auth_token'] ?? null);
        $fingerprint = $authToken !== ''
            ? $this->fingerprint($authToken)
            : ($existing['auth_token_fingerprint'] ?? null);
        $metadata = is_array($settings['settings'] ?? null)
            ? $settings['settings']
            : (array) ($existing['settings'] ?? []);

        Database::execute(
            "INSERT INTO workspace_sms_channel_configs
                (workspace_id, enabled, provider, account_sid, encrypted_auth_token, auth_token_fingerprint,
                 from_number, webhook_enabled, status_callbacks_enabled, settings_json,
                 created_by_user_id, updated_by_user_id)
             VALUES (?, ?, 'twilio', ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                provider = VALUES(provider),
                account_sid = VALUES(account_sid),
                encrypted_auth_token = VALUES(encrypted_auth_token),
                auth_token_fingerprint = VALUES(auth_token_fingerprint),
                from_number = VALUES(from_number),
                webhook_enabled = VALUES(webhook_enabled),
                status_callbacks_enabled = VALUES(status_callbacks_enabled),
                settings_json = VALUES(settings_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $workspaceId,
                !empty($settings['enabled']) ? 1 : 0,
                $this->nullableString((string) ($settings['account_sid'] ?? $existing['account_sid'] ?? '')),
                $encryptedAuthToken,
                $fingerprint,
                $this->nullableString($this->normalizePhone((string) ($settings['from_number'] ?? $existing['from_number'] ?? ''))),
                !empty($settings['webhook_enabled']) ? 1 : 0,
                !empty($settings['status_callbacks_enabled']) ? 1 : 0,
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ]
        );

        return $this->get($workspaceId);
    }

    public function get(int $workspaceId, bool $includeSecret = false): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_sms_channel_configs
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );

        return $row ? $this->hydrate($row, $includeSecret) : [];
    }

    public function runtimeConfig(int $workspaceId, bool $allowLegacyFallback = false): array
    {
        $config = $this->get($workspaceId, true);
        if ($config !== []) {
            $accountSid = trim((string) ($config['account_sid'] ?? ''));
            $authToken = trim((string) ($config['auth_token'] ?? ''));
            $fromNumber = trim((string) ($config['from_number'] ?? ''));

            return [
                'workspace_id' => $workspaceId,
                'source' => 'workspace',
                'enabled' => !empty($config['enabled']),
                'ready' => !empty($config['enabled']) && $accountSid !== '' && $authToken !== '' && $fromNumber !== '',
                'account_sid' => $accountSid,
                'auth_token' => $authToken,
                'from_number' => $fromNumber,
                'provider' => (string) ($config['provider'] ?? 'twilio'),
                'webhook_enabled' => !empty($config['webhook_enabled']),
                'status_callbacks_enabled' => !empty($config['status_callbacks_enabled']),
                'status_callback_url' => $this->webhookUrl(),
            ];
        }

        if ($allowLegacyFallback && $this->legacyEnvReady()) {
            return [
                'workspace_id' => $workspaceId,
                'source' => 'env',
                'enabled' => true,
                'ready' => true,
                'account_sid' => $this->envValue('TWILIO_ACCOUNT_SID'),
                'auth_token' => $this->envValue('TWILIO_AUTH_TOKEN'),
                'from_number' => $this->normalizePhone($this->envValue('TWILIO_FROM_NUMBER')),
                'provider' => 'twilio',
                'webhook_enabled' => false,
                'status_callbacks_enabled' => false,
                'status_callback_url' => $this->webhookUrl(),
            ];
        }

        return [
            'workspace_id' => $workspaceId,
            'source' => 'workspace',
            'enabled' => false,
            'ready' => false,
            'account_sid' => '',
            'auth_token' => '',
            'from_number' => '',
            'provider' => 'twilio',
            'webhook_enabled' => false,
            'status_callbacks_enabled' => false,
            'status_callback_url' => $this->webhookUrl(),
        ];
    }

    public function webhookUrl(): string
    {
        $appUrl = rtrim($this->envValue('APP_URL'), '/');
        return $appUrl !== '' ? $appUrl . '/api/webhooks/sms.php' : '';
    }

    public function readiness(int $workspaceId): array
    {
        $this->importLegacyEnvForInstalledWorkspace($workspaceId);
        $config = $this->get($workspaceId, true);
        $enabled = !empty($config['enabled']);
        $sidReady = trim((string) ($config['account_sid'] ?? '')) !== '';
        $tokenReady = trim((string) ($config['auth_token'] ?? '')) !== '';
        $fromReady = trim((string) ($config['from_number'] ?? '')) !== '';
        $webhookEnabled = !empty($config['webhook_enabled']);
        $callbackEnabled = !empty($config['status_callbacks_enabled']);
        $webhookUrl = $this->webhookUrl();
        $webhookStorageReady = Database::tableExists('sms_messages');
        $webhookReady = $webhookEnabled && $webhookStorageReady && $webhookUrl !== '';
        $callbackReady = !$callbackEnabled || ($webhookStorageReady && $webhookUrl !== '');
        [$queued, $failed] = $this->queueCounts($workspaceId);
        $ready = $enabled && $sidReady && $tokenReady && $fromReady;

        $checks = [
            ['label' => 'Workspace SMS enabled', 'ok' => $enabled, 'required' => true, 'detail' => $enabled ? 'SMS Channel is enabled for this workspace.' : 'Enable SMS Channel for this workspace.'],
            ['label' => 'Twilio account SID', 'ok' => $sidReady, 'required' => true, 'detail' => 'Identifies the Twilio account used by this workspace.'],
            ['label' => 'Twilio auth token', 'ok' => $tokenReady, 'required' => true, 'detail' => 'Required before this workspace can send SMS through Twilio.'],
            ['label' => 'Sender number', 'ok' => $fromReady, 'required' => true, 'detail' => 'Required for this workspace outbound SMS identity.'],
            ['label' => 'Inbound webhook', 'ok' => $webhookReady, 'required' => $webhookEnabled, 'detail' => $webhookReady ? 'Signed inbound callbacks can route to this workspace.' : 'Enable the webhook and configure APP_URL before accepting inbound SMS.'],
            ['label' => 'Delivery callbacks', 'ok' => $callbackReady, 'required' => $callbackEnabled, 'detail' => $callbackReady ? 'Delivery status callbacks are configured.' : 'Configure APP_URL before enabling delivery callbacks.'],
            ['label' => 'Queue pressure', 'ok' => $failed === 0, 'required' => false, 'detail' => $failed === 0 ? 'No failed queue items detected for this workspace.' : $failed . ' failed queue items detected for this workspace.'],
        ];

        $lastError = trim((string) ($config['last_error'] ?? ''));

        return [
            'enabled' => $enabled,
            'ready' => $ready,
            'outbound_ready' => $ready,
            'inbound_ready' => $webhookReady,
            'status_callbacks_ready' => $callbackReady,
            'webhook_url' => $webhookUrl,
            'status' => $ready ? 'ready' : ($config === [] ? 'not_configured' : 'needs_setup'),
            'source' => $config === [] ? 'none' : 'workspace',
            'queued_messages' => $queued,
            'failed_messages' => $failed,
            'last_error' => $lastError,
            'message' => $ready
                ? 'SMS Channel has workspace Twilio credentials and can send messages.'
                : 'SMS Channel needs workspace Twilio credentials, sender number, and enablement.',
            'checks' => $checks,
            'blockers' => array_values(array_map(
                static fn(array $check): string => (string) $check['label'],
                array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
            )),
            'next_action' => $ready ? 'Run an explicit-recipient SMS test before enabling bulk sends.' : 'Save workspace Twilio credentials and sender number.',
            'config' => $this->get($workspaceId, false),
        ];
    }

    public function importLegacyEnvForInstalledWorkspace(int $workspaceId, int $userId = 0): void
    {
        if (!$this->isAvailable() || $workspaceId <= 0 || !$this->legacyEnvReady() || $this->get($workspaceId, false) !== []) {
            return;
        }
        if (!Database::tableExists('workspace_skill_installs')) {
            return;
        }

        $installed = Database::queryOne(
            "SELECT 1
             FROM workspace_skill_installs
             WHERE workspace_id = ?
               AND skill_key = ?
               AND status = 'installed'
               AND uninstalled_at IS NULL
             LIMIT 1",
            [$workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );
        if (!$installed) {
            return;
        }

        $this->save($workspaceId, [
            'enabled' => true,
            'account_sid' => $this->envValue('TWILIO_ACCOUNT_SID'),
            'auth_token' => $this->envValue('TWILIO_AUTH_TOKEN'),
            'from_number' => $this->envValue('TWILIO_FROM_NUMBER'),
            'settings' => ['legacy_env_imported' => true],
        ], $userId);
    }

    public function importLegacyEnvForInstalledWorkspaces(int $userId = 0): int
    {
        if (!$this->isAvailable() || !$this->legacyEnvReady() || !Database::tableExists('workspace_skill_installs')) {
            return 0;
        }

        $rows = Database::query(
            "SELECT DISTINCT wsi.workspace_id
             FROM workspace_skill_installs wsi
             LEFT JOIN workspace_sms_channel_configs cfg ON cfg.workspace_id = wsi.workspace_id
             WHERE wsi.skill_key = ?
               AND wsi.status = 'installed'
               AND wsi.uninstalled_at IS NULL
               AND wsi.workspace_id IS NOT NULL
               AND cfg.workspace_id IS NULL",
            [WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL]
        );

        $imported = 0;
        foreach ($rows as $row) {
            $workspaceId = (int) ($row['workspace_id'] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }
            $this->importLegacyEnvForInstalledWorkspace($workspaceId, $userId);
            if ($this->get($workspaceId, false) !== []) {
                $imported++;
            }
        }

        return $imported;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function queueCounts(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('sms_queue')) {
            return [0, 0];
        }

        $queued = (int) ((Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM sms_queue
             WHERE workspace_id = ?
               AND status = 'pending'",
            [$workspaceId]
        )['c'] ?? 0));
        $failed = (int) ((Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM sms_queue
             WHERE workspace_id = ?
               AND status = 'failed'",
            [$workspaceId]
        )['c'] ?? 0));

        return [$queued, $failed];
    }

    private function hydrate(array $row, bool $includeSecret): array
    {
        $settings = [];
        if (!empty($row['settings_json'])) {
            $decoded = json_decode((string) $row['settings_json'], true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        $authToken = '';
        if ($includeSecret && !empty($row['encrypted_auth_token'])) {
            $authToken = $this->decryptSecret((string) $row['encrypted_auth_token']);
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'enabled' => !empty($row['enabled']),
            'provider' => (string) ($row['provider'] ?? 'twilio'),
            'account_sid' => (string) ($row['account_sid'] ?? ''),
            'auth_token' => $includeSecret ? $authToken : null,
            'auth_token_saved' => !empty($row['encrypted_auth_token']),
            'encrypted_auth_token' => $includeSecret ? (string) ($row['encrypted_auth_token'] ?? '') : null,
            'auth_token_fingerprint' => (string) ($row['auth_token_fingerprint'] ?? ''),
            'from_number' => (string) ($row['from_number'] ?? ''),
            'webhook_enabled' => !empty($row['webhook_enabled']),
            'status_callbacks_enabled' => !empty($row['status_callbacks_enabled']),
            'last_verified_at' => $row['last_verified_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
            'settings' => $settings,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function legacyEnvReady(): bool
    {
        return $this->envValue('TWILIO_ACCOUNT_SID') !== ''
            && $this->envValue('TWILIO_AUTH_TOKEN') !== ''
            && $this->envValue('TWILIO_FROM_NUMBER') !== '';
    }

    private function envValue(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return trim(is_string($value) ? $value : '');
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $normalized = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if ($normalized !== '' && $normalized[0] !== '+') {
            $normalized = '+' . ltrim($normalized, '+');
        }
        return $normalized;
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
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

    private function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 16);
    }

    private function encryptionKey(): string
    {
        $seed = '';
        foreach (['APP_KEY', 'APP_SECRET', 'DB_PASS'] as $key) {
            $candidate = $this->envValue($key);
            if ($candidate !== '') {
                $seed = $candidate;
                break;
            }
        }
        if ($seed === '' || hash_equals('crm-local-secret', $seed)) {
            throw new \RuntimeException('APP_KEY or APP_SECRET is required to protect SMS credentials.');
        }
        return hash('sha256', $seed, true);
    }
}
