<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceAIProviderResolverService
{
    public const SOURCE_ENV = 'env';
    public const SOURCE_DEFAULT_WORKSPACE = 'default_workspace';
    public const SOURCE_WORKSPACE_API = 'workspace_api';
    public const SOURCE_LOCAL_FALLBACK = 'local_fallback';

    public function __construct(private ?WorkspaceAIProviderConfigService $configs = null)
    {
        $this->configs = $configs ?: new WorkspaceAIProviderConfigService();
    }

    public function resolveForWorkspace(
        int $workspaceId,
        string $featureKey = 'general',
        string $credentialScope = WorkspaceAIProviderConfigService::SCOPE_GENERAL
    ): array
    {
        $workspaceId = max(0, $workspaceId);
        $credentialScope = $this->configs->normalizeCredentialScope($credentialScope);
        $defaultWorkspaceId = $this->configs->defaultWorkspaceId();
        $default = $this->configs->getDefaultWorkspaceConfig(true, $credentialScope);
        $workspace = $workspaceId > 0 ? $this->configs->get($workspaceId, true, $credentialScope) : [];
        $isDefaultWorkspace = (new DefaultWorkspacePackageExemptionService())->isExempt($workspaceId);
        $cap = $this->commonDailyCap($default);
        $usedCommonToday = $workspaceId > 0 ? $this->commonUsageToday($workspaceId, $credentialScope) : 0;
        $capExceeded = !$isDefaultWorkspace && $cap > 0 && $usedCommonToday >= $cap;

        if (!$capExceeded && $this->isUsableConfig($default)) {
            return $this->resolved(
                self::SOURCE_DEFAULT_WORKSPACE,
                $default,
                $defaultWorkspaceId,
                $workspaceId,
                $cap,
                $usedCommonToday,
                $capExceeded,
                $featureKey,
                $credentialScope
            );
        }

        if (($capExceeded || !$this->isUsableConfig($default)) && $this->isUsableConfig($workspace)) {
            return $this->resolved(
                self::SOURCE_WORKSPACE_API,
                $workspace,
                $workspaceId,
                $workspaceId,
                $cap,
                $usedCommonToday,
                $capExceeded,
                $featureKey,
                $credentialScope
            );
        }

        if ($capExceeded) {
            return [
                'available' => false,
                'source' => self::SOURCE_WORKSPACE_API,
                'blocked_reason' => $this->requiredKeyReason($credentialScope),
                'message' => $credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
                    ? 'The common content-generation daily cap has been reached. Add this workspace Content Generation key to continue.'
                    : 'The common AI API daily cap has been reached for this workspace. Add a workspace AI API key to continue.',
                'workspace_id' => $workspaceId,
                'config_workspace_id' => null,
                'common_daily_token_cap' => $cap,
                'common_used_today' => $usedCommonToday,
                'feature_key' => $featureKey,
                'credential_scope' => $credentialScope,
            ];
        }

        $envConfig = AIRuntimeConfig::getProviderConfig();
        $envKey = trim((string) ($_ENV['AI_API_KEY'] ?? ''));
        if ($credentialScope === WorkspaceAIProviderConfigService::SCOPE_GENERAL
            && $envKey !== ''
            && (string) ($envConfig['normalized_api_url'] ?? '') !== ''
        ) {
            return [
                'available' => true,
                'source' => self::SOURCE_ENV,
                'workspace_id' => $workspaceId,
                'config_workspace_id' => null,
                'api_key' => $envKey,
                'provider_config' => $envConfig,
                'common_daily_token_cap' => $cap,
                'common_used_today' => $usedCommonToday,
                'feature_key' => $featureKey,
                'credential_scope' => $credentialScope,
                'message' => 'Using environment AI provider configuration.',
            ];
        }

        if ($credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION) {
            return [
                'available' => false,
                'source' => self::SOURCE_WORKSPACE_API,
                'blocked_reason' => $this->requiredKeyReason($credentialScope),
                'message' => 'Add and enable a Content Generation API key in the workspace AI API plugin before creating AI content.',
                'workspace_id' => $workspaceId,
                'config_workspace_id' => null,
                'common_daily_token_cap' => $cap,
                'common_used_today' => $usedCommonToday,
                'feature_key' => $featureKey,
                'credential_scope' => $credentialScope,
            ];
        }

        return [
            'available' => false,
            'source' => self::SOURCE_LOCAL_FALLBACK,
            'blocked_reason' => 'remote_ai_not_configured',
            'message' => (string) ($envConfig['message'] ?? 'Remote AI provider is not configured.'),
            'workspace_id' => $workspaceId,
            'config_workspace_id' => null,
            'common_daily_token_cap' => $cap,
            'common_used_today' => $usedCommonToday,
            'feature_key' => $featureKey,
            'credential_scope' => $credentialScope,
        ];
    }

    public function resolveWorkspaceOwned(
        int $workspaceId,
        string $featureKey = 'general',
        string $credentialScope = WorkspaceAIProviderConfigService::SCOPE_GENERAL
    ): array
    {
        $workspaceId = max(0, $workspaceId);
        $credentialScope = $this->configs->normalizeCredentialScope($credentialScope);
        $workspace = $workspaceId > 0 ? $this->configs->get($workspaceId, true, $credentialScope) : [];
        if ($this->isUsableConfig($workspace)) {
            return $this->resolved(
                self::SOURCE_WORKSPACE_API,
                $workspace,
                $workspaceId,
                $workspaceId,
                0,
                0,
                false,
                $featureKey,
                $credentialScope
            );
        }

        return [
            'available' => false,
            'source' => self::SOURCE_WORKSPACE_API,
            'blocked_reason' => $this->requiredKeyReason($credentialScope),
            'message' => $credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
                ? 'Save and enable this workspace Content Generation key before creating AI content.'
                : 'Save and enable this workspace OpenAI key in Settings before voice intelligence can run.',
            'workspace_id' => $workspaceId,
            'config_workspace_id' => null,
            'common_daily_token_cap' => 0,
            'common_used_today' => 0,
            'feature_key' => $featureKey,
            'credential_scope' => $credentialScope,
        ];
    }

    private function resolved(
        string $source,
        array $config,
        int $configWorkspaceId,
        int $workspaceId,
        int $cap,
        int $usedCommonToday,
        bool $capExceeded,
        string $featureKey,
        string $credentialScope
    ): array {
        return [
            'available' => true,
            'source' => $source,
            'workspace_id' => $workspaceId,
            'config_workspace_id' => $configWorkspaceId,
            'api_key' => (string) ($config['api_key'] ?? ''),
            'provider_config' => [
                'api_key_present' => trim((string) ($config['api_key'] ?? '')) !== '',
                'configured_api_url' => (string) ($config['api_url'] ?? ''),
                'normalized_api_url' => (string) ($config['normalized_api_url'] ?? ''),
                'normalized_probe_url' => (string) ($config['normalized_probe_url'] ?? ''),
                'provider_type' => (string) ($config['provider_type'] ?? 'openai_compatible'),
                'model' => (string) ($config['model'] ?? 'gpt-4o-mini'),
                'missing' => [],
                'message' => 'Workspace AI provider is configured.',
            ],
            'common_daily_token_cap' => $cap,
            'common_used_today' => $usedCommonToday,
            'common_cap_exceeded' => $capExceeded,
            'feature_key' => $featureKey,
            'credential_scope' => $credentialScope,
            'message' => $source === self::SOURCE_DEFAULT_WORKSPACE
                ? 'Using default workspace common AI provider.'
                : ($capExceeded
                    ? 'Using workspace AI provider after common cap.'
                    : 'Using workspace AI provider because the common provider is unavailable.'),
        ];
    }

    private function isUsableConfig(array $config): bool
    {
        return !empty($config)
            && !empty($config['enabled'])
            && trim((string) ($config['api_key'] ?? '')) !== ''
            && trim((string) ($config['normalized_api_url'] ?? '')) !== '';
    }

    private function commonDailyCap(array $default): int
    {
        $configured = max(0, (int) ($default['shared_daily_token_cap'] ?? 0));
        if ($configured > 0) {
            return $configured;
        }

        return max(0, (int) ($_ENV['AI_COMMON_DAILY_TOKEN_CAP'] ?? $_ENV['AI_DAILY_TOKEN_LIMIT'] ?? 0));
    }

    private function commonUsageToday(int $workspaceId, string $credentialScope): int
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_ai_usage') || !Database::columnExists('workspace_ai_usage', 'provider_source')) {
            return 0;
        }

        $hasCredentialScope = Database::columnExists('workspace_ai_usage', 'credential_scope');
        $row = Database::queryOne(
            "SELECT COALESCE(SUM(billable_tokens), 0) AS used_tokens
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND provider_source = ?
               AND created_at >= ?" . ($hasCredentialScope ? "\n               AND credential_scope = ?" : ''),
            array_merge(
                [$workspaceId, self::SOURCE_DEFAULT_WORKSPACE, date('Y-m-d 00:00:00')],
                $hasCredentialScope ? [$credentialScope] : []
            )
        );

        return (int) ($row['used_tokens'] ?? 0);
    }

    private function requiredKeyReason(string $credentialScope): string
    {
        return $credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
            ? 'content_generation_key_required'
            : 'workspace_ai_key_required';
    }
}
