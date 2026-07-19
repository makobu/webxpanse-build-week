<?php
/**
 * Super Admin setup state for Google platform services.
 */

namespace CRM\Services;

use CRM\Database;

class GoogleServicesSetupService
{
    private string $envFile;

    public function __construct(?string $envFile = null)
    {
        $this->envFile = $envFile ?: dirname(__DIR__) . '/.env';
    }

    public function summary(): array
    {
        $clients = $this->credentialFields();
        $features = [
            [
                'key' => 'gmail_send',
                'label' => 'Gmail Sending',
                'grant_type' => GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND,
                'client_key' => 'GOOGLE_GMAIL_SEND',
                'redirect_uri' => $this->redirectUri('/api/email/gmail/callback.php'),
            ],
            [
                'key' => 'gmail_inbox',
                'label' => 'Gmail Inbox Sync',
                'grant_type' => GoogleOAuthScopeCatalog::GRANT_GMAIL_INBOX,
                'client_key' => 'GOOGLE_GMAIL_INBOX',
                'redirect_uri' => $this->redirectUri('/api/email/gmail/callback.php'),
            ],
            [
                'key' => 'assistant_gmail',
                'label' => 'Assistant Gmail',
                'grant_type' => GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL,
                'client_key' => 'GOOGLE_ASSISTANT_GMAIL',
                'redirect_uri' => $this->redirectUri('/api/email/assistant_gmail/callback.php'),
            ],
            [
                'key' => 'calendar_import',
                'label' => 'Google Calendar Import',
                'grant_type' => GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT,
                'client_key' => 'GOOGLE_CALENDAR',
                'redirect_uri' => $this->redirectUri('/api/calendar/google/callback.php'),
            ],
            [
                'key' => 'calendar_outbound',
                'label' => 'Google Calendar Outbound',
                'grant_type' => GoogleOAuthScopeCatalog::GRANT_CALENDAR_WRITE,
                'client_key' => 'GOOGLE_CALENDAR',
                'redirect_uri' => $this->redirectUri('/api/calendar/google/callback.php'),
            ],
        ];

        $legacyFallback = $this->legacyFallbackEnabled();
        foreach ($features as &$feature) {
            $catalog = GoogleOAuthScopeCatalog::forGrant((string) $feature['grant_type']);
            $clientKey = (string) $feature['client_key'];
            $client = $clients[$clientKey] ?? [];
            $hasClient = !empty($client['client_id']) && !empty($client['secret_saved']);
            $cleanConnections = $this->cleanConnectionCount((string) $feature['key']);
            $legacyConnections = $this->legacyConnectionCount((string) $feature['key']);
            $feature['scopes'] = $catalog['scopes'];
            $feature['client_label'] = (string) ($client['label'] ?? $clientKey);
            $feature['configured'] = $hasClient;
            $feature['clean_connections'] = $cleanConnections;
            $feature['legacy_connections'] = $legacyConnections;
            $feature['status'] = $this->featureStatus($hasClient, $cleanConnections, $legacyConnections, $legacyFallback);
            $feature['status_label'] = match ($feature['status']) {
                'ready' => 'Ready',
                'legacy_fallback' => 'Legacy fallback',
                'reconnect_recommended' => 'Reconnect recommended',
                'not_connected' => 'Not connected',
                default => 'Missing client',
            };
        }
        unset($feature);

        return [
            'clients' => $clients,
            'features' => $features,
            'legacy_fallback_enabled' => $legacyFallback,
            'token_safety' => $this->tokenSafetySummary(),
            'recent_audit' => $this->recentAudit(12),
            'cli_encrypt_command' => 'php cli/encrypt_google_oauth_tokens.php',
        ];
    }

    public function saveCredentials(array $payload): void
    {
        $settings = [
            'GOOGLE_GMAIL_SEND_CLIENT_ID' => trim((string) ($payload['google_gmail_send_client_id'] ?? '')),
            'GOOGLE_GMAIL_SEND_CLIENT_SECRET' => trim((string) ($payload['google_gmail_send_client_secret'] ?? '')),
            'GOOGLE_GMAIL_INBOX_CLIENT_ID' => trim((string) ($payload['google_gmail_inbox_client_id'] ?? '')),
            'GOOGLE_GMAIL_INBOX_CLIENT_SECRET' => trim((string) ($payload['google_gmail_inbox_client_secret'] ?? '')),
            'GOOGLE_ASSISTANT_GMAIL_CLIENT_ID' => trim((string) ($payload['google_assistant_gmail_client_id'] ?? '')),
            'GOOGLE_ASSISTANT_GMAIL_CLIENT_SECRET' => trim((string) ($payload['google_assistant_gmail_client_secret'] ?? '')),
            'GOOGLE_CALENDAR_CLIENT_ID' => trim((string) ($payload['google_calendar_client_id'] ?? '')),
            'GOOGLE_CALENDAR_CLIENT_SECRET' => trim((string) ($payload['google_calendar_client_secret'] ?? '')),
            'ALLOW_LEGACY_GOOGLE_OAUTH_FALLBACK' => !empty($payload['allow_legacy_google_oauth_fallback']) ? '1' : '0',
        ];
        $secretKeys = [
            'GOOGLE_GMAIL_SEND_CLIENT_SECRET' => true,
            'GOOGLE_GMAIL_INBOX_CLIENT_SECRET' => true,
            'GOOGLE_ASSISTANT_GMAIL_CLIENT_SECRET' => true,
            'GOOGLE_CALENDAR_CLIENT_SECRET' => true,
        ];

        $envContent = file_exists($this->envFile) ? (string) file_get_contents($this->envFile) : '';
        $applied = [];
        foreach ($settings as $key => $value) {
            if (isset($secretKeys[$key]) && $value === '') {
                continue;
            }

            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $key . '=' . $value, $envContent) ?? $envContent;
            } else {
                $envContent .= ($envContent !== '' && !str_ends_with($envContent, "\n") ? "\n" : '') . $key . '=' . $value . "\n";
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
            $applied[$key] = $value;
        }

        file_put_contents($this->envFile, $envContent);
    }

    public function tokenSafetySummary(): array
    {
        return [
            'gmail_encrypted' => $this->countTokens('email_integrations', "provider IN ('gmail_oauth', 'google_workspace') AND token_encrypted = 1"),
            'gmail_plaintext' => $this->countTokens('email_integrations', "provider IN ('gmail_oauth', 'google_workspace') AND (token_encrypted = 0 OR token_encrypted IS NULL) AND (access_token IS NOT NULL OR refresh_token IS NOT NULL)"),
            'calendar_encrypted' => $this->countTokens('calendar_integrations', "provider = 'google' AND token_encrypted = 1"),
            'calendar_plaintext' => $this->countTokens('calendar_integrations', "provider = 'google' AND (token_encrypted = 0 OR token_encrypted IS NULL) AND (access_token IS NOT NULL OR refresh_token IS NOT NULL)"),
        ];
    }

    public function recentAudit(int $limit = 12): array
    {
        if (!Database::tableExists('oauth_connection_audit_log')) {
            return [];
        }

        return Database::query(
            "SELECT provider, surface, oauth_grant_type, oauth_client_key, status, operation, message, created_at
             FROM oauth_connection_audit_log
             ORDER BY created_at DESC, id DESC
             LIMIT " . max(1, min(50, $limit))
        );
    }

    public function credentialFields(): array
    {
        return [
            'GOOGLE_GMAIL_SEND' => $this->client('Gmail Sending', 'google_gmail_send', 'GOOGLE_GMAIL_SEND'),
            'GOOGLE_GMAIL_INBOX' => $this->client('Gmail Inbox Sync', 'google_gmail_inbox', 'GOOGLE_GMAIL_INBOX'),
            'GOOGLE_ASSISTANT_GMAIL' => $this->client('Assistant Gmail', 'google_assistant_gmail', 'GOOGLE_ASSISTANT_GMAIL'),
            'GOOGLE_CALENDAR' => $this->client('Google Calendar', 'google_calendar', 'GOOGLE_CALENDAR'),
        ];
    }

    private function client(string $label, string $fieldPrefix, string $envPrefix): array
    {
        return [
            'label' => $label,
            'field_prefix' => $fieldPrefix,
            'env_prefix' => $envPrefix,
            'client_id_key' => $envPrefix . '_CLIENT_ID',
            'client_secret_key' => $envPrefix . '_CLIENT_SECRET',
            'client_id' => trim((string) ($_ENV[$envPrefix . '_CLIENT_ID'] ?? getenv($envPrefix . '_CLIENT_ID') ?: '')),
            'secret_saved' => trim((string) ($_ENV[$envPrefix . '_CLIENT_SECRET'] ?? getenv($envPrefix . '_CLIENT_SECRET') ?: '')) !== '',
        ];
    }

    private function legacyFallbackEnabled(): bool
    {
        return trim((string) ($_ENV['ALLOW_LEGACY_GOOGLE_OAUTH_FALLBACK'] ?? getenv('ALLOW_LEGACY_GOOGLE_OAUTH_FALLBACK') ?: '')) === '1';
    }

    private function redirectUri(string $path): string
    {
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost/crm'), '/');
        return $appUrl . $path;
    }

    private function countTokens(string $table, string $where): int
    {
        if (!Database::tableExists($table)) {
            return 0;
        }

        $row = Database::queryOne("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}");
        return (int) ($row['c'] ?? 0);
    }

    private function featureStatus(bool $hasClient, int $cleanConnections, int $legacyConnections, bool $legacyFallback): string
    {
        if (!$hasClient) {
            return $legacyFallback && $legacyConnections > 0 ? 'legacy_fallback' : 'missing_client';
        }

        if ($cleanConnections > 0) {
            return 'ready';
        }

        if ($legacyConnections > 0) {
            return 'reconnect_recommended';
        }

        return 'not_connected';
    }

    private function cleanConnectionCount(string $featureKey): int
    {
        return match ($featureKey) {
            'gmail_send' => $this->countTokens('email_integrations', "provider IN ('gmail_oauth', 'google_workspace') AND scope = 'main_email' AND oauth_grant_type = 'gmail_send' AND is_active = 1"),
            'gmail_inbox' => $this->countTokens('email_integrations', "provider IN ('gmail_oauth', 'google_workspace') AND scope = 'main_email' AND oauth_grant_type IN ('gmail_inbox', 'gmail_modify') AND is_active = 1"),
            'assistant_gmail' => $this->countTokens('email_integrations', "provider = 'gmail_oauth' AND scope = 'assistant_email' AND oauth_grant_type = 'assistant_gmail' AND is_active = 1"),
            'calendar_import' => $this->countTokens('calendar_integrations', "provider = 'google' AND oauth_grant_type IN ('calendar_import', 'calendar_write') AND sync_enabled = 1"),
            'calendar_outbound' => $this->countTokens('calendar_integrations', "provider = 'google' AND oauth_grant_type = 'calendar_write' AND sync_enabled = 1"),
            default => 0,
        };
    }

    private function legacyConnectionCount(string $featureKey): int
    {
        return match ($featureKey) {
            'gmail_send', 'gmail_inbox' => $this->countTokens('email_integrations', "provider IN ('gmail_oauth', 'google_workspace') AND scope = 'main_email' AND oauth_grant_type = 'legacy_combined' AND is_active = 1"),
            'assistant_gmail' => $this->countTokens('email_integrations', "provider = 'gmail_oauth' AND scope = 'assistant_email' AND oauth_grant_type = 'legacy_combined' AND is_active = 1"),
            'calendar_import', 'calendar_outbound' => $this->countTokens('calendar_integrations', "provider = 'google' AND oauth_grant_type = 'legacy_calendar' AND sync_enabled = 1"),
            default => 0,
        };
    }
}
