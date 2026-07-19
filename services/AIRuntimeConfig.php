<?php

namespace CRM\Services;

class AIRuntimeConfig
{
    public static function validate(): array
    {
        $provider = self::buildProviderConfig();
        $assistant = self::buildAssistantConfig();
        $fallback = self::buildFallbackConfig();

        return [
            'core_ai_provider_ready' => empty($provider['missing']) && $provider['api_key_present'],
            'email_assistant_outbound_ready' => $assistant['outbound_ok'] && $assistant['identity_ok'],
            'email_assistant_inbound_ready' => $assistant['inbound_ok'],
            'fallback_only_mode' => (!empty($provider['missing']) || !$provider['api_key_present']) && $fallback['enabled'],
            'provider' => $provider,
            'email_assistant' => $assistant,
            'fallback' => $fallback,
            'message' => self::buildMessage($provider, $assistant, $fallback),
        ];
    }

    public static function getProviderConfig(): array
    {
        return self::validate()['provider'];
    }

    public static function getEmailAssistantConfig(): array
    {
        return self::validate()['email_assistant'];
    }

    public static function getFallbackConfig(): array
    {
        return self::validate()['fallback'];
    }

    private static function buildProviderConfig(): array
    {
        $apiKey = trim((string) ($_ENV['AI_API_KEY'] ?? ''));
        $apiUrl = trim((string) ($_ENV['AI_SERVICE_URL'] ?? ''));
        $normalizedApiUrl = self::normalizeApiUrl($apiUrl, $apiKey);
        $normalizedProbeUrl = self::deriveProbeUrl($normalizedApiUrl);
        $providerType = self::detectProviderType($normalizedApiUrl);
        $defaultModel = $providerType === 'openai_compatible' ? 'gpt-3.5-turbo' : 'mistral:7b';
        $model = trim((string) ($_ENV['AI_MODEL'] ?? ''));
        if ($model === '') {
            $model = $defaultModel;
        }

        $missing = [];
        if ($apiKey === '') {
            $missing[] = 'AI_API_KEY';
        }
        if ($normalizedApiUrl === '') {
            $missing[] = 'AI_SERVICE_URL';
        }

        return [
            'api_key_present' => $apiKey !== '',
            'configured_api_url' => $apiUrl,
            'normalized_api_url' => $normalizedApiUrl,
            'normalized_probe_url' => $normalizedProbeUrl,
            'provider_type' => $providerType,
            'model' => $model,
            'missing' => $missing,
            'message' => empty($missing)
                ? 'Core AI provider is configured.'
                : 'Core AI provider is missing ' . implode(', ', $missing) . '. Configure in Settings -> AI Services.',
        ];
    }

    private static function buildAssistantConfig(): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $settings = [];
        $smtp = [];
        $oauthReady = false;

        try {
            if ($workspaceId > 0) {
                $assistantConfig = (new WorkspaceAssistantConfigService())->get($workspaceId, 'email', true);
                $settings = (array) ($assistantConfig['settings'] ?? []);
                $smtp = (new WorkspaceAssistantConfigService())->emailSmtpConfig($workspaceId);
                $summary = (new EmailIntegrationService())->getAssistantProviderSummary($workspaceId);
                $oauthReady = !empty($summary['is_active'])
                    && in_array((string) ($summary['readiness'] ?? ''), ['ready', 'warning'], true);
            }
        } catch (\Throwable $e) {
            $settings = [];
            $smtp = [];
            $oauthReady = false;
        }

        $outboundMissing = [];
        if (!$oauthReady && trim((string) ($smtp['host'] ?? '')) === '') {
            $outboundMissing[] = 'assistant SMTP host';
        }
        if (!$oauthReady && trim((string) ($smtp['username'] ?? '')) === '') {
            $outboundMissing[] = 'assistant SMTP username';
        }
        if (!$oauthReady && trim((string) ($smtp['password'] ?? '')) === '') {
            $outboundMissing[] = 'assistant SMTP password';
        }

        $identityMissing = [];
        if (!$oauthReady && trim((string) ($smtp['from_email'] ?? $settings['system_email'] ?? '')) === '') {
            $identityMissing[] = 'assistant From Email';
        }

        $imapEnabled = !empty($settings['imap_enabled']);
        $inboundMissing = [];
        if ($imapEnabled && !$oauthReady) {
            if (trim((string) ($settings['imap_host'] ?? '')) === '') {
                $inboundMissing[] = 'assistant IMAP host';
            }
            if (trim((string) ($settings['imap_username'] ?? '')) === '') {
                $inboundMissing[] = 'assistant IMAP username';
            }
            if (trim((string) ($settings['imap_password'] ?? '')) === '') {
                $inboundMissing[] = 'assistant IMAP password';
            }
        }

        $outboundOk = $oauthReady || empty($outboundMissing);
        $identityOk = $oauthReady || empty($identityMissing);
        $inboundOk = $oauthReady || !$imapEnabled || empty($inboundMissing);

        $parts = [];
        if (!$outboundOk || !$identityOk) {
            $parts[] = 'Outbound SMTP: missing ' . implode(', ', array_merge($outboundMissing, $identityMissing)) . '. Configure in Marketplace -> Email Assistant.';
        }
        if (!$inboundOk) {
            $parts[] = 'Inbound IMAP: missing ' . implode(', ', $inboundMissing) . '. Configure in Marketplace -> Email Assistant.';
        }

        return [
            'outbound_ok' => $outboundOk,
            'inbound_ok' => $inboundOk,
            'identity_ok' => $identityOk,
            'imap_enabled' => $imapEnabled,
            'outbound_missing' => $outboundMissing,
            'inbound_missing' => $inboundMissing,
            'identity_missing' => $identityMissing,
            'oauth_ready' => $oauthReady,
            'source' => 'workspace_assistant_configs',
            'legacy_env_ignored' => true,
            'message' => empty($parts) ? 'Assistant email is configured.' : implode(' ', $parts),
        ];
    }

    private static function buildFallbackConfig(): array
    {
        $ollamaUrl = trim((string) ($_ENV['OLLAMA_URL'] ?? 'http://localhost:11434'));

        return [
            'enabled' => true,
            'provider' => 'ollama',
            'ollama_url' => $ollamaUrl,
            'message' => 'Local fallback remains available for degraded AI operation.',
        ];
    }

    private static function buildMessage(array $provider, array $assistant, array $fallback): string
    {
        $parts = [$provider['message']];
        $parts[] = $assistant['message'];

        if (!empty($provider['missing'])) {
            $parts[] = 'Runtime will fall back to ' . $fallback['provider'] . ' when available.';
        }

        return implode(' ', array_filter($parts));
    }

    public static function normalizeApiUrl(string $apiUrl, string $apiKey = ''): string
    {
        $apiUrl = trim($apiUrl);
        $apiKey = trim($apiKey);

        if ($apiUrl === '' && $apiKey !== '') {
            return 'https://api.openai.com/v1/chat/completions';
        }

        if ($apiUrl === '') {
            return '';
        }

        $apiUrl = rtrim($apiUrl, '/');
        if (strpos($apiUrl, 'openai.com') !== false && strpos($apiUrl, '/chat/completions') === false) {
            $apiUrl .= '/chat/completions';
        }

        return $apiUrl;
    }

    public static function deriveProbeUrl(string $normalizedApiUrl): string
    {
        if ($normalizedApiUrl === '') {
            return '';
        }
        if (str_ends_with($normalizedApiUrl, '/chat/completions')) {
            return substr($normalizedApiUrl, 0, -strlen('/chat/completions')) . '/models';
        }

        return $normalizedApiUrl;
    }

    public static function detectProviderType(string $normalizedApiUrl): string
    {
        if ($normalizedApiUrl === '') {
            return 'unconfigured';
        }
        if (strpos($normalizedApiUrl, 'openai.com') !== false || strpos($normalizedApiUrl, '/chat/completions') !== false) {
            return 'openai_compatible';
        }

        return 'generic';
    }

    private static function envFlag(string $name): bool
    {
        return strtolower(trim((string) ($_ENV[$name] ?? 'false'))) === 'true';
    }
}
