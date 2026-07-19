<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Security;

class WorkspaceAIProviderConfigService
{
    public const MODE_ENABLED = 'enabled';
    public const MODE_DISABLED = 'disabled';
    public const SCOPE_GENERAL = 'general';
    public const SCOPE_CONTENT_GENERATION = 'content_generation';
    public const CREDENTIAL_SCOPES = [
        self::SCOPE_GENERAL,
        self::SCOPE_CONTENT_GENERATION,
    ];

    public function isAvailable(): bool
    {
        return Database::tableExists('workspace_ai_provider_configs');
    }

    public function defaultWorkspaceId(): int
    {
        try {
            return (new DefaultWorkspaceService())->id();
        } catch (\Throwable $e) {
            return DefaultWorkspaceService::DEFAULT_ID;
        }
    }

    public function save(int $workspaceId, array $settings, ?int $userId = null): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('workspace_ai_provider_configs table is missing. Run migrations first.');
        }

        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to save AI provider settings.');
        }
        if ($workspaceId !== $this->defaultWorkspaceId()
            && !(new WorkspacePlanEntitlementService())->canUseFeature($workspaceId, WorkspacePlanEntitlementService::FEATURE_PERSONAL_API_KEY)
        ) {
            throw new \RuntimeException('Personal API key access unlocks on Growth Studio and higher plans.');
        }

        $credentialScope = $this->normalizeCredentialScope((string) ($settings['credential_scope'] ?? self::SCOPE_GENERAL));
        $existing = $this->get($workspaceId, true, $credentialScope);
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $encryptedApiKey = $apiKey !== ''
            ? $this->encryptSecret($apiKey)
            : ($existing['encrypted_api_key'] ?? null);
        $fingerprint = $apiKey !== ''
            ? $this->fingerprint($apiKey)
            : ($existing['api_key_fingerprint'] ?? null);
        $apiUrl = trim((string) ($settings['api_url'] ?? $existing['api_url'] ?? ''));
        $model = trim((string) ($settings['model'] ?? $existing['model'] ?? ''));
        $providerKey = $this->normalizeProviderKey((string) ($settings['provider_key'] ?? $existing['provider_key'] ?? 'openai'));
        if (array_key_exists('mode', $settings)) {
            $mode = (string) $settings['mode'] === self::MODE_DISABLED ? self::MODE_DISABLED : self::MODE_ENABLED;
        } elseif (array_key_exists('enabled', $settings)) {
            $mode = !empty($settings['enabled']) ? self::MODE_ENABLED : self::MODE_DISABLED;
        } else {
            $mode = (string) ($existing['mode'] ?? self::MODE_ENABLED) === self::MODE_DISABLED
                ? self::MODE_DISABLED
                : self::MODE_ENABLED;
        }
        $cap = max(0, (int) ($settings['shared_daily_token_cap'] ?? $existing['shared_daily_token_cap'] ?? 0));
        $metadata = is_array($settings['settings'] ?? null) ? $settings['settings'] : (array) ($existing['settings'] ?? []);

        Database::execute(
            "INSERT INTO workspace_ai_provider_configs
                (workspace_id, credential_scope, provider_key, mode, api_url, model, encrypted_api_key, api_key_fingerprint,
                 shared_daily_token_cap, settings_json, created_by_user_id, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                provider_key = VALUES(provider_key),
                mode = VALUES(mode),
                api_url = VALUES(api_url),
                model = VALUES(model),
                encrypted_api_key = VALUES(encrypted_api_key),
                api_key_fingerprint = VALUES(api_key_fingerprint),
                shared_daily_token_cap = VALUES(shared_daily_token_cap),
                settings_json = VALUES(settings_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $workspaceId,
                $credentialScope,
                $providerKey,
                $mode,
                $apiUrl !== '' ? $apiUrl : null,
                $model !== '' ? $model : null,
                $encryptedApiKey,
                $fingerprint,
                $cap,
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $userId && $userId > 0 ? $userId : null,
                $userId && $userId > 0 ? $userId : null,
            ]
        );

        return $this->get($workspaceId, false, $credentialScope);
    }

    public function get(int $workspaceId, bool $includeSecret = false, string $credentialScope = self::SCOPE_GENERAL): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        $credentialScope = $this->normalizeCredentialScope($credentialScope);

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_ai_provider_configs
             WHERE workspace_id = ? AND credential_scope = ?
             LIMIT 1",
            [$workspaceId, $credentialScope]
        );

        return $row ? $this->hydrate($row, $includeSecret) : [];
    }

    public function getDefaultWorkspaceConfig(bool $includeSecret = false, string $credentialScope = self::SCOPE_GENERAL): array
    {
        return $this->get($this->defaultWorkspaceId(), $includeSecret, $credentialScope);
    }

    /** @return array<string,array<string,mixed>> */
    public function getCredentialSlots(int $workspaceId, bool $includeSecret = false): array
    {
        $slots = [];
        foreach (self::CREDENTIAL_SCOPES as $credentialScope) {
            $slots[$credentialScope] = $this->get($workspaceId, $includeSecret, $credentialScope);
        }

        return $slots;
    }

    public function normalizeCredentialScope(string $credentialScope): string
    {
        $credentialScope = strtolower(trim($credentialScope));
        return in_array($credentialScope, self::CREDENTIAL_SCOPES, true)
            ? $credentialScope
            : self::SCOPE_GENERAL;
    }

    private function hydrate(array $row, bool $includeSecret): array
    {
        $settings = [];
        if (!empty($row['settings_json'])) {
            $decoded = json_decode((string) $row['settings_json'], true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        $apiKey = '';
        if ($includeSecret && !empty($row['encrypted_api_key'])) {
            $apiKey = $this->decryptSecret((string) $row['encrypted_api_key']);
        }

        $apiUrl = trim((string) ($row['api_url'] ?? ''));
        $model = trim((string) ($row['model'] ?? ''));
        $normalizedApiUrl = AIRuntimeConfig::normalizeApiUrl($apiUrl, $apiKey);
        $providerType = AIRuntimeConfig::detectProviderType($normalizedApiUrl);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'credential_scope' => $this->normalizeCredentialScope((string) ($row['credential_scope'] ?? self::SCOPE_GENERAL)),
            'provider_key' => $this->normalizeProviderKey((string) ($row['provider_key'] ?? 'openai')),
            'mode' => (string) ($row['mode'] ?? self::MODE_DISABLED),
            'enabled' => (string) ($row['mode'] ?? '') === self::MODE_ENABLED,
            'api_url' => $apiUrl,
            'normalized_api_url' => $normalizedApiUrl,
            'normalized_probe_url' => AIRuntimeConfig::deriveProbeUrl($normalizedApiUrl),
            'provider_type' => $providerType,
            'model' => $model !== '' ? $model : ($providerType === 'openai_compatible' ? 'gpt-4o-mini' : 'mistral:7b'),
            'api_key_present' => !empty($row['encrypted_api_key']),
            'api_key_fingerprint' => (string) ($row['api_key_fingerprint'] ?? ''),
            'api_key' => $includeSecret ? $apiKey : null,
            'encrypted_api_key' => $includeSecret ? (string) ($row['encrypted_api_key'] ?? '') : null,
            'shared_daily_token_cap' => max(0, (int) ($row['shared_daily_token_cap'] ?? 0)),
            'last_verified_at' => $row['last_verified_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
            'settings' => $settings,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function normalizeProviderKey(string $providerKey): string
    {
        $providerKey = strtolower(trim($providerKey));
        return preg_match('/^[a-z0-9_\-]+$/', $providerKey) ? $providerKey : 'openai';
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
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }
}
