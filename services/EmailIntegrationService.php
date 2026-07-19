<?php
/**
 * Main email provider integration state and token lifecycle management.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceContext;

class EmailIntegrationService
{
    public const PROVIDER_GMAIL_OAUTH = 'gmail_oauth';
    public const PROVIDER_GOOGLE_WORKSPACE = 'google_workspace';
    public const PROVIDER_MANUAL_SMTP = 'manual_smtp';
    public const PROVIDER_MANUAL_IMAP = 'manual_imap';
    public const SCOPE_MAIN_EMAIL = 'main_email';
    public const SCOPE_OUTREACH_EMAIL = 'outreach_email';
    public const SCOPE_NURTURE_EMAIL = 'nurture_email';
    public const SCOPE_ASSISTANT_EMAIL = 'assistant_email';

    private static ?bool $tableExists = null;

    public function isAvailable(): bool
    {
        return $this->tableExists();
    }

    public function getActiveMainIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveScopeIntegration(self::SCOPE_MAIN_EMAIL, $workspaceId);
    }

    public function getActiveAssistantIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveScopeIntegration(self::SCOPE_ASSISTANT_EMAIL, $workspaceId);
    }

    public function getActiveOutreachIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveRoleIntegration('outreach', $workspaceId);
    }

    public function getActiveNurtureIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveRoleIntegration('nurture', $workspaceId);
    }

    public function getActiveRoleIntegration(string $role, ?int $workspaceId = null): ?array
    {
        return $this->getActiveScopeIntegration($this->scopeForRole($role), $workspaceId);
    }

    public function getActiveStrictRoleIntegration(string $role, ?int $workspaceId = null): ?array
    {
        return $this->getActiveScopeIntegration($this->scopeForRole($role), $workspaceId);
    }

    public function getActiveScopeIntegration(string $scope, ?int $workspaceId = null): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM email_integrations
             WHERE workspace_id = ?
               AND scope = ?
               AND is_active = 1
             ORDER BY
               CASE
                 WHEN provider = ? THEN 0
                 WHEN oauth_grant_type IN (?, ?, ?) THEN 1
                 ELSE 2
               END,
               updated_at DESC,
               id DESC
             LIMIT 1",
            [
                $resolvedWorkspaceId,
                $this->normalizeScope($scope),
                self::PROVIDER_MANUAL_SMTP,
                GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND,
                GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL,
                GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED,
            ]
        );

        return $row ? $this->hydrateIntegrationRow($row) : null;
    }

    public function getActiveGoogleWorkspaceIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveProviderIntegration(self::PROVIDER_GOOGLE_WORKSPACE, $workspaceId);
    }

    public function getActiveGmailIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $workspaceId);
    }

    public function getActiveAssistantGmailIntegration(?int $workspaceId = null): ?array
    {
        return $this->getActiveProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $workspaceId, self::SCOPE_ASSISTANT_EMAIL);
    }

    public function getAuthorizedGoogleWorkspaceIntegration(?int $workspaceId = null): ?array
    {
        return $this->getAuthorizedProviderIntegration(self::PROVIDER_GOOGLE_WORKSPACE, $workspaceId, self::SCOPE_MAIN_EMAIL, GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND)
            ?? $this->getAuthorizedProviderIntegration(self::PROVIDER_GOOGLE_WORKSPACE, $workspaceId, self::SCOPE_MAIN_EMAIL, GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED);
    }

    public function getAuthorizedGmailIntegration(?int $workspaceId = null): ?array
    {
        return $this->getAuthorizedProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $workspaceId, self::SCOPE_MAIN_EMAIL, GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND)
            ?? $this->getAuthorizedProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $workspaceId, self::SCOPE_MAIN_EMAIL, GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED);
    }

    public function getAuthorizedAssistantGmailIntegration(?int $workspaceId = null): ?array
    {
        return $this->getAuthorizedProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $workspaceId, self::SCOPE_ASSISTANT_EMAIL, GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL)
            ?? $this->getAuthorizedProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $workspaceId, self::SCOPE_ASSISTANT_EMAIL, GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED);
    }

    public function getAuthorizedMailSendIntegration(string $scope = self::SCOPE_MAIN_EMAIL, ?int $workspaceId = null): ?array
    {
        $scope = $this->normalizeScope($scope);
        $preferredGrant = $scope === self::SCOPE_ASSISTANT_EMAIL
            ? GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL
            : GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND;

        return $this->getAuthorizedGrantIntegration($scope, [$preferredGrant], $workspaceId)
            ?? $this->getAuthorizedGrantIntegration($scope, [GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED], $workspaceId);
    }

    public function getAuthorizedMailInboxIntegration(string $scope = self::SCOPE_MAIN_EMAIL, ?int $workspaceId = null): ?array
    {
        return $this->getAuthorizedGrantIntegration(
            $this->normalizeScope($scope),
            [GoogleOAuthScopeCatalog::GRANT_GMAIL_INBOX, GoogleOAuthScopeCatalog::GRANT_GMAIL_MODIFY],
            $workspaceId
        ) ?? $this->getAuthorizedGrantIntegration(
            $this->normalizeScope($scope),
            [GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED],
            $workspaceId
        );
    }

    public function getAuthorizedMainIntegration(?int $workspaceId = null): ?array
    {
        $integration = $this->getActiveMainIntegration($workspaceId);
        if (!$integration) {
            return null;
        }
        return $this->getAuthorizedMailSendIntegration(self::SCOPE_MAIN_EMAIL, (int) ($integration['workspace_id'] ?? 0));
    }

    public function getAuthorizedAssistantIntegration(?int $workspaceId = null): ?array
    {
        $integration = $this->getActiveAssistantIntegration($workspaceId);
        if (!$integration) {
            return null;
        }
        return $this->getAuthorizedMailSendIntegration(self::SCOPE_ASSISTANT_EMAIL, (int) ($integration['workspace_id'] ?? 0));
    }

    public function getAuthorizedRoleIntegration(string $role, ?int $workspaceId = null): ?array
    {
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        if (!$integration) {
            return null;
        }

        return $this->getAuthorizedMailSendIntegration(
            (string) ($integration['scope'] ?? $this->scopeForRole($role)),
            (int) ($integration['workspace_id'] ?? 0)
        );
    }

    public function getAuthorizedStrictRoleIntegration(string $role, ?int $workspaceId = null): ?array
    {
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        if (!$integration) {
            return null;
        }

        return $this->getAuthorizedMailSendIntegration(
            (string) ($integration['scope'] ?? $this->scopeForRole($role)),
            (int) ($integration['workspace_id'] ?? 0)
        );
    }

    public function storeGoogleWorkspaceIntegration(int $connectedByUserId, array $tokens, array $profile, ?int $workspaceId = null, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND, array $requestedScopes = [], ?string $oauthClientKey = null): int
    {
        return $this->storeMainProviderIntegration(self::PROVIDER_GOOGLE_WORKSPACE, $connectedByUserId, $tokens, $profile, $workspaceId, $grantType, $requestedScopes, $oauthClientKey);
    }

    public function storeGmailIntegration(int $connectedByUserId, array $tokens, array $profile, ?int $workspaceId = null, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND, array $requestedScopes = [], ?string $oauthClientKey = null): int
    {
        return $this->storeMainProviderIntegration(self::PROVIDER_GMAIL_OAUTH, $connectedByUserId, $tokens, $profile, $workspaceId, $grantType, $requestedScopes, $oauthClientKey);
    }

    public function storeAssistantGmailIntegration(int $connectedByUserId, array $tokens, array $profile, ?int $workspaceId = null, string $grantType = GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL, array $requestedScopes = [], ?string $oauthClientKey = null): int
    {
        return $this->storeProviderIntegration(self::PROVIDER_GMAIL_OAUTH, self::SCOPE_ASSISTANT_EMAIL, $connectedByUserId, $tokens, $profile, $workspaceId, $grantType, $requestedScopes, $oauthClientKey);
    }

    public function storeMainProviderIntegration(string $provider, int $connectedByUserId, array $tokens, array $profile, ?int $workspaceId = null, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND, array $requestedScopes = [], ?string $oauthClientKey = null): int
    {
        return $this->storeProviderIntegration($provider, self::SCOPE_MAIN_EMAIL, $connectedByUserId, $tokens, $profile, $workspaceId, $grantType, $requestedScopes, $oauthClientKey);
    }

    public function storeProviderIntegration(string $provider, string $scope, int $connectedByUserId, array $tokens, array $profile, ?int $workspaceId = null, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND, array $requestedScopes = [], ?string $oauthClientKey = null): int
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('email_integrations table is missing. Run migrations first.');
        }

        $resolvedWorkspaceId = $this->requireWorkspaceId($workspaceId);
        $provider = $this->normalizeProvider($provider);
        $scope = $this->normalizeScope($scope);
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType($grantType);
        $grantCatalog = GoogleOAuthScopeCatalog::forGrant($grantType);
        $requiredScopes = GoogleOAuthScopeCatalog::requiredScopes($grantType);
        $grantedScopes = GoogleOAuthScopeCatalog::parseScopes($tokens['scope'] ?? []);
        $scopeStatus = GoogleOAuthScopeCatalog::scopeStatus($grantedScopes, $requiredScopes);
        if ($requiredScopes !== [] && !GoogleOAuthScopeCatalog::hasRequiredScopes($grantedScopes, $requiredScopes)) {
            $this->recordOAuthAudit(
                $resolvedWorkspaceId,
                $connectedByUserId,
                $provider,
                (string) $grantCatalog['surface'],
                $grantType,
                $oauthClientKey ?: (string) $grantCatalog['client_key'],
                'failed',
                'scope_mismatch',
                'Google did not return every scope required for ' . $grantCatalog['label'] . '.',
                $requestedScopes !== [] ? $requestedScopes : $requiredScopes,
                $grantedScopes
            );
            throw new \RuntimeException($grantCatalog['label'] . ' is missing required Google permissions. Please reconnect and approve the requested access.');
        }

        $vault = new OAuthTokenVault();
        $accessToken = trim((string) ($tokens['access_token'] ?? ''));
        $refreshToken = trim((string) ($tokens['refresh_token'] ?? ''));
        $storedAccessToken = $vault->encrypt($accessToken);
        if ($accessToken === '' || $storedAccessToken === null) {
            throw new \RuntimeException($this->providerLabel($provider) . ' OAuth did not return an access token.');
        }
        $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)));
        $emailAddress = trim((string) ($profile['emailAddress'] ?? $profile['email'] ?? ''));
        $settings = [
            'gmail_history_id' => (string) ($profile['historyId'] ?? ''),
            'messages_total' => (int) ($profile['messagesTotal'] ?? 0),
            'threads_total' => (int) ($profile['threadsTotal'] ?? 0),
            'last_message_internal_ts' => 0,
        ];

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE email_integrations
                 SET is_active = 0, updated_at = NOW()
                 WHERE workspace_id = ?
                   AND scope = ?
                   AND oauth_grant_type = ?",
                [$resolvedWorkspaceId, $scope, $grantType]
            );

            $existing = Database::queryOne(
                "SELECT id
                 FROM email_integrations
                 WHERE workspace_id = ?
                   AND provider = ?
                   AND scope = ?
                   AND oauth_grant_type = ?
                 LIMIT 1",
                [$resolvedWorkspaceId, $provider, $scope, $grantType]
            );

            if ($existing) {
                $existingRow = Database::queryOne(
                    "SELECT refresh_token
                     FROM email_integrations
                     WHERE workspace_id = ?
                       AND id = ?
                     LIMIT 1",
                    [$resolvedWorkspaceId, (int) $existing['id']]
                );
                $storedRefreshToken = $refreshToken !== ''
                    ? $vault->encrypt($refreshToken)
                    : $vault->encrypt($vault->decrypt($existingRow['refresh_token'] ?? null));
                Database::execute(
                    "UPDATE email_integrations
                     SET access_token = ?,
                         refresh_token = ?,
                         token_expires_at = ?,
                         oauth_client_key = ?,
                         granted_scopes_json = ?,
                         required_scopes_json = ?,
                         scope_status = ?,
                         reconnect_required = ?,
                         token_encrypted = 1,
                         last_scope_verified_at = ?,
                         email_address = ?,
                         is_active = 1,
                         connected_by_user_id = ?,
                         settings_json = ?,
                         updated_at = NOW()
                     WHERE workspace_id = ?
                       AND id = ?",
                    [
                        $storedAccessToken,
                        $storedRefreshToken,
                        $expiresAt,
                        $oauthClientKey ?: (string) $grantCatalog['client_key'],
                        json_encode($grantedScopes, JSON_UNESCAPED_SLASHES),
                        json_encode($requiredScopes, JSON_UNESCAPED_SLASHES),
                        $scopeStatus,
                        $scopeStatus === 'missing_required' ? 1 : 0,
                        $scopeStatus === 'verified' ? date('Y-m-d H:i:s') : null,
                        $emailAddress !== '' ? $emailAddress : null,
                        $connectedByUserId > 0 ? $connectedByUserId : null,
                        json_encode($settings, JSON_UNESCAPED_SLASHES),
                        $resolvedWorkspaceId,
                        (int) $existing['id'],
                    ]
                );
                $integrationId = (int) $existing['id'];
            } else {
                Database::execute(
                    "INSERT INTO email_integrations
                        (workspace_id, provider, scope, oauth_grant_type, oauth_client_key, access_token, refresh_token, token_expires_at, granted_scopes_json, required_scopes_json, scope_status, reconnect_required, token_encrypted, last_scope_verified_at, email_address, is_active, connected_by_user_id, settings_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 1, ?, ?)",
                    [
                        $resolvedWorkspaceId,
                        $provider,
                        $scope,
                        $grantType,
                        $oauthClientKey ?: (string) $grantCatalog['client_key'],
                        $storedAccessToken,
                        $vault->encrypt($refreshToken),
                        $expiresAt,
                        json_encode($grantedScopes, JSON_UNESCAPED_SLASHES),
                        json_encode($requiredScopes, JSON_UNESCAPED_SLASHES),
                        $scopeStatus,
                        $scopeStatus === 'missing_required' ? 1 : 0,
                        $scopeStatus === 'verified' ? date('Y-m-d H:i:s') : null,
                        $emailAddress !== '' ? $emailAddress : null,
                        $connectedByUserId > 0 ? $connectedByUserId : null,
                        json_encode($settings, JSON_UNESCAPED_SLASHES),
                    ]
                );
                $integrationId = (int) Database::lastInsertId();
            }

            Database::commit();
            $this->recordOAuthAudit(
                $resolvedWorkspaceId,
                $connectedByUserId,
                $provider,
                (string) $grantCatalog['surface'],
                $grantType,
                $oauthClientKey ?: (string) $grantCatalog['client_key'],
                'success',
                $existing ? 'reconnect' : 'connect',
                $grantCatalog['label'] . ' connected.',
                $requestedScopes !== [] ? $requestedScopes : $requiredScopes,
                $grantedScopes,
                'email_integrations',
                $integrationId
            );
            return $integrationId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function storeManualMailIntegration(int $connectedByUserId, array $settings, ?int $workspaceId = null): int
    {
        return $this->storeManualMailIntegrationForScope(self::SCOPE_MAIN_EMAIL, $connectedByUserId, $settings, $workspaceId);
    }

    public function storeManualMailIntegrationForRole(string $role, int $connectedByUserId, array $settings, ?int $workspaceId = null): int
    {
        return $this->storeManualMailIntegrationForScope($this->scopeForRole($role), $connectedByUserId, $settings, $workspaceId);
    }

    private function storeManualMailIntegrationForScope(string $scope, int $connectedByUserId, array $settings, ?int $workspaceId = null): int
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('email_integrations table is missing. Run migrations first.');
        }

        $resolvedWorkspaceId = $this->requireWorkspaceId($workspaceId);
        $scope = $this->normalizeScope($scope);
        $emailAddress = trim((string) ($settings['from_email'] ?? $settings['smtp_username'] ?? ''));
        $payload = [
            'smtp_host' => trim((string) ($settings['smtp_host'] ?? '')),
            'smtp_port' => (int) ($settings['smtp_port'] ?? 587),
            'smtp_username' => trim((string) ($settings['smtp_username'] ?? '')),
            'smtp_encryption' => $this->normalizeEncryption((string) ($settings['smtp_encryption'] ?? 'tls')),
            'from_email' => $emailAddress,
            'from_name' => trim((string) ($settings['from_name'] ?? '')),
            'imap_enabled' => !empty($settings['imap_enabled']),
            'imap_host' => trim((string) ($settings['imap_host'] ?? '')),
            'imap_port' => (int) ($settings['imap_port'] ?? 993),
            'imap_username' => trim((string) ($settings['imap_username'] ?? '')),
            'imap_protocol' => trim((string) ($settings['imap_protocol'] ?? 'imap')) ?: 'imap',
            'imap_encryption' => $this->normalizeEncryption((string) ($settings['imap_encryption'] ?? 'ssl')),
            'imap_folder' => trim((string) ($settings['imap_folder'] ?? 'INBOX')) ?: 'INBOX',
            'connected_at' => gmdate('c'),
        ];

        if (trim((string) ($settings['smtp_password'] ?? '')) !== '') {
            $payload['smtp_password'] = $this->encryptSecret((string) $settings['smtp_password']);
        }
        if (trim((string) ($settings['imap_password'] ?? '')) !== '') {
            $payload['imap_password'] = $this->encryptSecret((string) $settings['imap_password']);
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE email_integrations
                 SET is_active = 0, updated_at = NOW()
                 WHERE workspace_id = ?
                   AND scope = ?",
                [$resolvedWorkspaceId, $scope]
            );

            $existing = Database::queryOne(
                "SELECT id, settings_json
                 FROM email_integrations
                 WHERE workspace_id = ?
                   AND provider = ?
                   AND scope = ?
                 LIMIT 1",
                [$resolvedWorkspaceId, self::PROVIDER_MANUAL_SMTP, $scope]
            );

            if ($existing) {
                $existingSettings = json_decode((string) ($existing['settings_json'] ?? '{}'), true);
                if (!is_array($existingSettings)) {
                    $existingSettings = [];
                }
                if (!isset($payload['smtp_password']) && isset($existingSettings['smtp_password'])) {
                    $payload['smtp_password'] = $existingSettings['smtp_password'];
                }
                if (!isset($payload['imap_password']) && isset($existingSettings['imap_password'])) {
                    $payload['imap_password'] = $existingSettings['imap_password'];
                }

                Database::execute(
                    "UPDATE email_integrations
                     SET email_address = ?,
                         is_active = 1,
                         connected_by_user_id = ?,
                         settings_json = ?,
                         updated_at = NOW()
                     WHERE workspace_id = ?
                       AND id = ?",
                    [
                        $emailAddress !== '' ? $emailAddress : null,
                        $connectedByUserId > 0 ? $connectedByUserId : null,
                        json_encode($payload, JSON_UNESCAPED_SLASHES),
                        $resolvedWorkspaceId,
                        (int) $existing['id'],
                    ]
                );
                $integrationId = (int) $existing['id'];
            } else {
                Database::execute(
                    "INSERT INTO email_integrations
                        (workspace_id, provider, scope, email_address, is_active, connected_by_user_id, settings_json)
                     VALUES (?, ?, ?, ?, 1, ?, ?)",
                    [
                        $resolvedWorkspaceId,
                        self::PROVIDER_MANUAL_SMTP,
                        $scope,
                        $emailAddress !== '' ? $emailAddress : null,
                        $connectedByUserId > 0 ? $connectedByUserId : null,
                        json_encode($payload, JSON_UNESCAPED_SLASHES),
                    ]
                );
                $integrationId = (int) Database::lastInsertId();
            }

            Database::commit();
            return $integrationId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function listActiveMainIntegrations(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = Database::query(
            "SELECT *
             FROM email_integrations
             WHERE workspace_id IS NOT NULL
               AND scope = ?
               AND is_active = 1
             ORDER BY workspace_id ASC, updated_at DESC, id DESC",
            [self::SCOPE_MAIN_EMAIL]
        );

        return array_map(fn(array $row): array => $this->hydrateIntegrationRow($row), $rows);
    }

    /**
     * @param array<int,string> $roles
     * @return array<int,array<string,mixed>>
     */
    public function listActiveRoleIntegrations(array $roles = ['outreach', 'nurture']): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $scopes = [];
        foreach ($roles as $role) {
            $scope = $this->scopeForRole((string) $role);
            if (in_array($scope, [self::SCOPE_OUTREACH_EMAIL, self::SCOPE_NURTURE_EMAIL], true)) {
                $scopes[$scope] = true;
            }
        }
        $scopes = array_keys($scopes);
        if ($scopes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($scopes), '?'));
        $rows = Database::query(
            "SELECT *
             FROM email_integrations
             WHERE workspace_id IS NOT NULL
               AND scope IN ({$placeholders})
               AND is_active = 1
             ORDER BY workspace_id ASC, scope ASC, updated_at DESC, id DESC",
            $scopes
        );

        return array_map(fn(array $row): array => $this->hydrateIntegrationRow($row), $rows);
    }

    public function deactivateMainGoogleWorkspace(?int $workspaceId = null): void
    {
        $this->deactivateMainProvider(self::PROVIDER_GOOGLE_WORKSPACE, $workspaceId);
    }

    public function deactivateMainGmail(?int $workspaceId = null): void
    {
        $this->deactivateMainProvider(self::PROVIDER_GMAIL_OAUTH, $workspaceId);
    }

    public function deactivateAssistantGmail(?int $workspaceId = null): void
    {
        $this->deactivateProviderForScope(self::PROVIDER_GMAIL_OAUTH, self::SCOPE_ASSISTANT_EMAIL, $workspaceId);
    }

    public function deactivateMainProvider(string $provider, ?int $workspaceId = null): void
    {
        $this->deactivateProviderForScope($provider, self::SCOPE_MAIN_EMAIL, $workspaceId);
    }

    public function deactivateProviderForScope(string $provider, string $scope, ?int $workspaceId = null): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return;
        }

        $provider = $this->normalizeProvider($provider);
        $scope = $this->normalizeScope($scope);

        Database::execute(
            "UPDATE email_integrations
             SET is_active = 0,
                 access_token = NULL,
                 refresh_token = NULL,
                 token_expires_at = NULL,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND provider = ?
               AND scope = ?",
            [$resolvedWorkspaceId, $provider, $scope]
        );
    }

    public function clearScopeIntegration(string $scope, ?int $workspaceId = null): void
    {
        if (!$this->tableExists()) {
            return;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return;
        }

        $scope = $this->normalizeScope($scope);
        Database::execute(
            "UPDATE email_integrations
             SET is_active = 0,
                 access_token = NULL,
                 refresh_token = NULL,
                 token_expires_at = NULL,
                 email_address = NULL,
                 settings_json = '{}',
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND scope = ?",
            [$resolvedWorkspaceId, $scope]
        );
    }

    public function mergeSettings(int $integrationId, array $settings, ?int $workspaceId = null): void
    {
        if (!$this->tableExists() || $integrationId <= 0) {
            return;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return;
        }

        $current = Database::queryOne(
            "SELECT settings_json
             FROM email_integrations
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$resolvedWorkspaceId, $integrationId]
        );

        $existing = [];
        if (!empty($current['settings_json'])) {
            $decoded = json_decode((string) $current['settings_json'], true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $merged = array_merge($existing, $settings);
        Database::execute(
            "UPDATE email_integrations
             SET settings_json = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND id = ?",
            [json_encode($merged, JSON_UNESCAPED_SLASHES), $resolvedWorkspaceId, $integrationId]
        );
    }

    public function getPreferredMainFromEmail(?string $fallback = null, ?int $workspaceId = null): string
    {
        $fallback = trim((string) $fallback);
        $platformDefault = (new PlatformEmailDefaultService())->smtpConfig(self::SCOPE_MAIN_EMAIL);
        $platformFromEmail = trim((string) ($platformDefault['from_email'] ?? ''));
        if ($platformFromEmail !== '') {
            return $platformFromEmail;
        }

        return $fallback !== '' ? $fallback : 'noreply@example.com';
    }

    public function getPreferredFromEmailForRole(string $role, ?string $fallback = null, ?int $workspaceId = null): string
    {
        $fallback = trim((string) $fallback);
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        $emailAddress = trim((string) ($integration['email_address'] ?? ''));

        if ($emailAddress !== '') {
            return $emailAddress;
        }

        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        $fromEmail = trim((string) ($settings['from_email'] ?? ''));
        return $fromEmail !== '' ? $fromEmail : $fallback;
    }

    public function getStrictPreferredFromEmailForRole(string $role, ?int $workspaceId = null): string
    {
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        $emailAddress = trim((string) ($integration['email_address'] ?? ''));
        if ($emailAddress !== '') {
            return $emailAddress;
        }

        return trim((string) ($settings['from_email'] ?? ''));
    }

    public function getPreferredMainFromName(?string $fallback = null): string
    {
        $fallback = trim((string) $fallback);
        $platformDefault = (new PlatformEmailDefaultService())->smtpConfig(self::SCOPE_MAIN_EMAIL);
        $platformFromName = trim((string) ($platformDefault['from_name'] ?? ''));
        if ($platformFromName !== '') {
            return $platformFromName;
        }

        return $fallback !== '' ? $fallback : brandProductName();
    }

    public function getPreferredFromNameForRole(string $role, ?string $fallback = null, ?int $workspaceId = null): string
    {
        $fallback = trim((string) $fallback);
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        $fromName = trim((string) ($settings['from_name'] ?? ''));

        if ($fromName !== '') {
            return $fromName;
        }

        return $fallback !== '' ? $fallback : brandProductName();
    }

    public function getStrictPreferredFromNameForRole(string $role, ?string $fallback = null, ?int $workspaceId = null): string
    {
        $fallback = trim((string) $fallback);
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        $fromName = trim((string) ($settings['from_name'] ?? ''));
        if ($fromName !== '') {
            return $fromName;
        }

        return $fallback !== '' ? $fallback : brandProductName();
    }

    public function getManualSmtpConfig(?int $workspaceId = null): array
    {
        return (new PlatformEmailDefaultService())->smtpConfig(self::SCOPE_MAIN_EMAIL);
    }

    public function getManualSmtpConfigForRole(string $role, ?int $workspaceId = null): array
    {
        return $this->manualSmtpConfigFromIntegration($this->getActiveStrictRoleIntegration($role, $workspaceId));
    }

    public function getStrictManualSmtpConfigForRole(string $role, ?int $workspaceId = null): array
    {
        return $this->manualSmtpConfigFromIntegration($this->getActiveStrictRoleIntegration($role, $workspaceId));
    }

    public function isStrictRoleOutboundReady(string $role, ?int $workspaceId = null): bool
    {
        $integration = $this->getActiveStrictRoleIntegration($role, $workspaceId);
        if (!$integration) {
            return false;
        }

        $provider = (string) ($integration['provider'] ?? self::PROVIDER_MANUAL_SMTP);
        if ($provider === self::PROVIDER_MANUAL_SMTP) {
            $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
            return trim((string) ($settings['smtp_host'] ?? '')) !== ''
                && trim((string) ($settings['smtp_username'] ?? '')) !== ''
                && !empty($settings['smtp_password'])
                && $this->getStrictPreferredFromEmailForRole($role, $workspaceId) !== '';
        }

        return $this->getAuthorizedStrictRoleIntegration($role, $workspaceId) !== null
            && $this->getStrictPreferredFromEmailForRole($role, $workspaceId) !== '';
    }

    private function manualSmtpConfigFromIntegration(?array $integration): array
    {
        if (!$integration || (string) ($integration['provider'] ?? '') !== self::PROVIDER_MANUAL_SMTP) {
            return [];
        }

        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        return [
            'host' => trim((string) ($settings['smtp_host'] ?? '')),
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'username' => trim((string) ($settings['smtp_username'] ?? '')),
            'password' => $this->decryptSecret($settings['smtp_password'] ?? ''),
            'encryption' => $this->normalizeEncryption((string) ($settings['smtp_encryption'] ?? 'tls')),
            'from_email' => trim((string) ($settings['from_email'] ?? $integration['email_address'] ?? '')),
            'from_name' => trim((string) ($settings['from_name'] ?? '')),
        ];
    }

    public function getManualImapConfig(?int $workspaceId = null): array
    {
        return (new PlatformEmailDefaultService())->imapConfig(self::SCOPE_MAIN_EMAIL);
    }

    public function getManualImapConfigForRole(string $role, ?int $workspaceId = null): array
    {
        return $this->manualImapConfigFromIntegration($this->getActiveStrictRoleIntegration($role, $workspaceId));
    }

    private function manualImapConfigFromIntegration(?array $integration): array
    {
        if (!$integration || (string) ($integration['provider'] ?? '') !== self::PROVIDER_MANUAL_SMTP) {
            return [];
        }

        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        return [
            'enabled' => !empty($settings['imap_enabled']),
            'host' => trim((string) ($settings['imap_host'] ?? '')),
            'port' => (int) ($settings['imap_port'] ?? 993),
            'username' => trim((string) ($settings['imap_username'] ?? '')),
            'password' => $this->decryptSecret($settings['imap_password'] ?? ''),
            'protocol' => trim((string) ($settings['imap_protocol'] ?? 'imap')) ?: 'imap',
            'encryption' => $this->normalizeEncryption((string) ($settings['imap_encryption'] ?? 'ssl')),
            'folder' => trim((string) ($settings['imap_folder'] ?? 'INBOX')) ?: 'INBOX',
        ];
    }

    public function getMainProviderSummary(?int $workspaceId = null): array
    {
        return $this->getProviderSummary(self::SCOPE_MAIN_EMAIL, $workspaceId);
    }

    public function getAssistantProviderSummary(?int $workspaceId = null): array
    {
        return $this->getProviderSummary(self::SCOPE_ASSISTANT_EMAIL, $workspaceId);
    }

    public function getOutreachProviderSummary(?int $workspaceId = null): array
    {
        return $this->getProviderSummaryForRole('outreach', $workspaceId);
    }

    public function getNurtureProviderSummary(?int $workspaceId = null): array
    {
        return $this->getProviderSummaryForRole('nurture', $workspaceId);
    }

    public function getProviderSummaryForRole(string $role, ?int $workspaceId = null): array
    {
        $scope = $this->scopeForRole($role);
        $summary = $this->getProviderSummary($scope, $workspaceId);
        return $summary + ['fallback_scope' => null];
    }

    public function getProviderSummary(string $scope = self::SCOPE_MAIN_EMAIL, ?int $workspaceId = null): array
    {
        $scope = $this->normalizeScope($scope);
        $isAssistant = $scope === self::SCOPE_ASSISTANT_EMAIL;
        $active = $scope === self::SCOPE_MAIN_EMAIL ? null : $this->getActiveScopeIntegration($scope, $workspaceId);
        $platformDefaults = new PlatformEmailDefaultService();
        $usesPlatformDefault = $scope === self::SCOPE_MAIN_EMAIL;
        $defaultScope = $isAssistant ? self::SCOPE_ASSISTANT_EMAIL : self::SCOPE_MAIN_EMAIL;
        $platformDefaultSummary = !$active && $usesPlatformDefault ? $platformDefaults->summary($defaultScope) : [];
        $smtpConfigured = !$active && !empty($platformDefaultSummary['smtp_configured']);
        $imapConfigured = !$active && !empty($platformDefaultSummary['imap_configured']);

        $details = [];
        $issues = [];
        $readiness = 'blocked';
        $lastFailure = null;
        $activeProviderKey = self::PROVIDER_MANUAL_SMTP;
        $activeProviderLabel = 'Manual SMTP / IMAP';
        $connectedEmail = '';
        $isActive = false;
        $integration = null;

        if ($active) {
            $integration = $active;
            $activeProviderKey = (string) ($active['provider'] ?? self::PROVIDER_MANUAL_SMTP);
            $activeProviderLabel = $this->providerLabel($activeProviderKey);
            $connectedEmail = trim((string) ($active['email_address'] ?? ''));
            $isActive = !empty($active['is_active']);
            $settings = is_array($active['settings'] ?? null) ? $active['settings'] : [];
            $lastFailure = isset($settings['last_failure']) ? trim((string) $settings['last_failure']) : null;
            $manualSmtpConfigured = $activeProviderKey === self::PROVIDER_MANUAL_SMTP
                && trim((string) ($settings['smtp_host'] ?? '')) !== ''
                && trim((string) ($settings['smtp_username'] ?? '')) !== ''
                && !empty($settings['smtp_password']);
            $manualImapConfigured = $activeProviderKey === self::PROVIDER_MANUAL_SMTP
                && !empty($settings['imap_enabled'])
                && trim((string) ($settings['imap_host'] ?? '')) !== ''
                && trim((string) ($settings['imap_username'] ?? '')) !== ''
                && !empty($settings['imap_password']);
            $oauthConfigured = false;
            if (!in_array($activeProviderKey, [self::PROVIDER_MANUAL_SMTP, self::PROVIDER_MANUAL_IMAP], true)) {
                $service = $isAssistant && $activeProviderKey === self::PROVIDER_GMAIL_OAUTH
                    ? new AssistantGmailMailService()
                    : $this->mailServiceForProvider($activeProviderKey);
                $oauthConfigured = $service->isConfigured();
            }

            $details[] = [
                'provider_key' => $activeProviderKey,
                'provider_label' => $activeProviderLabel,
                'delivery_method' => $activeProviderKey === self::PROVIDER_MANUAL_SMTP ? 'smtp' : 'gmail_api',
                'configured' => $activeProviderKey === self::PROVIDER_MANUAL_SMTP ? $manualSmtpConfigured : $oauthConfigured,
                'connected' => $connectedEmail !== '',
                'status' => ($activeProviderKey === self::PROVIDER_MANUAL_SMTP ? $manualSmtpConfigured : $oauthConfigured) ? 'ready' : 'blocked',
            ];

            if ($activeProviderKey === self::PROVIDER_MANUAL_SMTP && !$manualSmtpConfigured) {
                $issues[] = [
                    'type' => 'manual_smtp_incomplete',
                    'provider' => $activeProviderKey,
                    'message' => 'Manual SMTP is selected but the mail server, username, or password is missing.',
                ];
            } elseif (!$oauthConfigured && $activeProviderKey !== self::PROVIDER_MANUAL_SMTP) {
                $issues[] = [
                    'type' => 'oauth_not_configured',
                    'provider' => $activeProviderKey,
                    'message' => $activeProviderLabel . ' is selected but its OAuth client credentials are missing.',
                ];
            } elseif ($connectedEmail === '') {
                $issues[] = [
                    'type' => 'oauth_not_connected',
                    'provider' => $activeProviderKey,
                    'message' => $activeProviderLabel . ' is selected but the mailbox connection is incomplete.',
                ];
            }
        }

        $details[] = [
            'provider_key' => self::PROVIDER_MANUAL_SMTP,
            'provider_label' => $smtpConfigured ? 'Platform default SMTP' : 'Manual SMTP',
            'delivery_method' => 'smtp',
            'configured' => $smtpConfigured,
            'connected' => $smtpConfigured,
            'status' => $smtpConfigured ? 'ready' : 'blocked',
        ];
        $details[] = [
            'provider_key' => self::PROVIDER_MANUAL_IMAP,
            'provider_label' => $imapConfigured ? 'Platform default IMAP' : 'Manual IMAP',
            'delivery_method' => 'imap',
            'configured' => $imapConfigured,
            'connected' => $imapConfigured,
            'status' => $imapConfigured ? 'ready' : 'warning',
        ];

        $activeSettings = is_array($active['settings'] ?? null) ? $active['settings'] : [];
        $activeProvider = (string) ($active['provider'] ?? '');
        $activeIsManual = $activeProvider === self::PROVIDER_MANUAL_SMTP;
        $activeOauthService = $active && !$activeIsManual
            ? ($isAssistant && $activeProvider === self::PROVIDER_GMAIL_OAUTH ? new AssistantGmailMailService() : $this->mailServiceForProvider($activeProvider))
            : null;
        $activeOauthReady = $active && !$activeIsManual && $activeOauthService && $activeOauthService->isConfigured() && $connectedEmail !== '';
        $activeManualSmtpReady = $active && $activeIsManual
            && trim((string) ($activeSettings['smtp_host'] ?? '')) !== ''
            && trim((string) ($activeSettings['smtp_username'] ?? '')) !== ''
            && !empty($activeSettings['smtp_password']);
        $activeManualImapReady = $active && $activeIsManual
            && !empty($activeSettings['imap_enabled'])
            && trim((string) ($activeSettings['imap_host'] ?? '')) !== ''
            && trim((string) ($activeSettings['imap_username'] ?? '')) !== ''
            && !empty($activeSettings['imap_password']);

        $outboundReady = $activeOauthReady || $activeManualSmtpReady || $smtpConfigured;
        $incomingReady = $activeOauthReady || $activeManualImapReady || $imapConfigured;

        if ($outboundReady) {
            $readiness = $incomingReady ? 'ready' : 'warning';
            if (!$incomingReady) {
                $issues[] = [
                    'type' => 'incoming_fallback_missing',
                    'provider' => self::PROVIDER_MANUAL_IMAP,
                    'message' => 'Sending is ready, but the inbox is not connected. Enable IMAP or connect an inbound provider.',
                ];
            }
        } else {
            $missingProviderMessage = $scope === self::SCOPE_MAIN_EMAIL
                ? 'No viable System Mail SMTP provider is configured. Finish System Mail manual SMTP setup.'
                : 'No viable workspace mailbox is configured. Finish manual SMTP setup for this email role.';
            $issues[] = [
                'type' => 'outbound_provider_missing',
                'provider' => self::PROVIDER_MANUAL_SMTP,
                'message' => $missingProviderMessage,
            ];
        }

        if ($active) {
            return [
                'scope' => $scope,
                'provider_key' => $activeProviderKey,
                'provider_label' => $activeProviderLabel,
                'delivery_method' => $activeProviderKey === self::PROVIDER_MANUAL_SMTP ? 'smtp' : 'gmail_api',
                'connected_email' => $connectedEmail,
                'is_active' => $isActive,
                'smtp_fallback_configured' => $smtpConfigured || $activeManualSmtpReady,
                'incoming_fallback_configured' => $imapConfigured || $activeManualImapReady,
                'readiness' => $readiness,
                'last_failure' => $lastFailure,
                'last_successful_provider' => (string) (($active['settings']['last_successful_provider'] ?? $activeProviderKey) ?: $activeProviderKey),
                'active_direct_provider' => $activeProviderKey,
                'providers' => $details,
                'issues' => $issues,
                'integration' => $integration,
            ];
        }

        return [
            'scope' => $scope,
            'provider_key' => self::PROVIDER_MANUAL_SMTP,
            'provider_label' => 'Manual SMTP / IMAP',
            'delivery_method' => 'smtp',
            'connected_email' => '',
            'is_active' => false,
            'smtp_fallback_configured' => $smtpConfigured,
            'incoming_fallback_configured' => $imapConfigured,
            'readiness' => $readiness,
            'last_failure' => $lastFailure,
            'last_successful_provider' => $smtpConfigured ? self::PROVIDER_MANUAL_SMTP : '',
            'active_direct_provider' => '',
            'providers' => $details,
            'issues' => $issues,
            'integration' => null,
        ];
    }

    public function recordProviderFailure(int $integrationId, string $message, ?int $workspaceId = null): void
    {
        $message = trim($message);
        if (!$this->tableExists() || $integrationId <= 0 || $message === '') {
            return;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return;
        }

        $integration = Database::queryOne(
            "SELECT settings_json
             FROM email_integrations
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$resolvedWorkspaceId, $integrationId]
        );

        if (!$integration) {
            return;
        }

        $settings = [];
        if (!empty($integration['settings_json'])) {
            $decoded = json_decode((string) $integration['settings_json'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['last_failure'] = mb_substr($message, 0, 255);
        $settings['last_failure_at'] = gmdate('c');

        Database::execute(
            "UPDATE email_integrations
             SET settings_json = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND id = ?",
            [json_encode($settings, JSON_UNESCAPED_SLASHES), $resolvedWorkspaceId, $integrationId]
        );
    }

    public function recordProviderSuccess(int $integrationId, string $provider, string $deliveryMethod, ?int $workspaceId = null): void
    {
        if (!$this->tableExists() || $integrationId <= 0) {
            return;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return;
        }

        $integration = Database::queryOne(
            "SELECT settings_json
             FROM email_integrations
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$resolvedWorkspaceId, $integrationId]
        );

        if (!$integration) {
            return;
        }

        $settings = [];
        if (!empty($integration['settings_json'])) {
            $decoded = json_decode((string) $integration['settings_json'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        unset($settings['last_failure'], $settings['last_failure_at']);
        $settings['last_success_at'] = gmdate('c');
        $settings['last_successful_provider'] = $provider;
        $settings['last_delivery_method'] = $deliveryMethod;

        Database::execute(
            "UPDATE email_integrations
             SET settings_json = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND id = ?",
            [json_encode($settings, JSON_UNESCAPED_SLASHES), $resolvedWorkspaceId, $integrationId]
        );
    }

    public function providerLabel(string $provider): string
    {
        return match ($this->normalizeProvider($provider)) {
            self::PROVIDER_GMAIL_OAUTH => 'Gmail',
            self::PROVIDER_GOOGLE_WORKSPACE => 'Google Workspace',
            self::PROVIDER_MANUAL_SMTP => 'Manual SMTP',
            self::PROVIDER_MANUAL_IMAP => 'Manual IMAP',
            default => 'Email',
        };
    }

    public function mailServiceForProvider(string $provider): GoogleOAuthMailService
    {
        return match ($this->normalizeProvider($provider)) {
            self::PROVIDER_GMAIL_OAUTH => new GmailMailService(),
            self::PROVIDER_GOOGLE_WORKSPACE => new GoogleWorkspaceMailService(),
            default => throw new \InvalidArgumentException('Unsupported email provider: ' . $provider),
        };
    }

    private function hydrateIntegrationRow(array $row): array
    {
        $settings = [];
        if (!empty($row['settings_json'])) {
            $decoded = json_decode((string) $row['settings_json'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $vault = new OAuthTokenVault();
        if (array_key_exists('access_token', $row)) {
            $row['access_token'] = $vault->decrypt($row['access_token'] ?? null);
        }
        if (array_key_exists('refresh_token', $row)) {
            $row['refresh_token'] = $vault->decrypt($row['refresh_token'] ?? null);
        }
        foreach (['granted_scopes_json' => 'granted_scopes', 'required_scopes_json' => 'required_scopes'] as $jsonKey => $arrayKey) {
            $decoded = json_decode((string) ($row[$jsonKey] ?? '[]'), true);
            $row[$arrayKey] = is_array($decoded) ? GoogleOAuthScopeCatalog::parseScopes($decoded) : [];
        }

        $row['settings'] = $settings;
        return $row;
    }

    private function resolveWorkspaceId(?int $workspaceId = null): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    }

    private function requireWorkspaceId(?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('An active workspace is required for email integrations.');
        }

        return $resolvedWorkspaceId;
    }

    private function tableExists(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'email_integrations'"
            );
            self::$tableExists = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    private function getActiveProviderIntegration(string $provider, ?int $workspaceId = null, string $scope = self::SCOPE_MAIN_EMAIL, ?string $grantType = null): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return null;
        }

        $provider = $this->normalizeProvider($provider);
        $scope = $this->normalizeScope($scope);
        $params = [$resolvedWorkspaceId, $scope, $provider];
        $grantSql = '';
        if ($grantType !== null) {
            $grantSql = ' AND oauth_grant_type = ?';
            $params[] = GoogleOAuthScopeCatalog::normalizeGrantType($grantType);
        }

        $row = Database::queryOne(
            "SELECT *
             FROM email_integrations
             WHERE workspace_id = ?
               AND scope = ?
               AND provider = ?
               {$grantSql}
               AND is_active = 1
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            $params
        );

        return $row ? $this->hydrateIntegrationRow($row) : null;
    }

    private function getAuthorizedGrantIntegration(string $scope, array $grantTypes, ?int $workspaceId = null): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        if ($resolvedWorkspaceId <= 0) {
            return null;
        }

        $scope = $this->normalizeScope($scope);
        $grantTypes = array_values(array_unique(array_map(
            static fn(string $grantType): string => GoogleOAuthScopeCatalog::normalizeGrantType($grantType),
            $grantTypes
        )));
        if ($grantTypes === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($grantTypes), '?'));
        $rows = Database::query(
            "SELECT *
             FROM email_integrations
             WHERE workspace_id = ?
               AND scope = ?
               AND provider IN (?, ?)
               AND oauth_grant_type IN ({$placeholders})
               AND is_active = 1
             ORDER BY
               FIELD(oauth_grant_type, {$placeholders}),
               updated_at DESC,
               id DESC",
            array_merge(
                [$resolvedWorkspaceId, $scope, self::PROVIDER_GMAIL_OAUTH, self::PROVIDER_GOOGLE_WORKSPACE],
                $grantTypes,
                $grantTypes
            )
        );

        foreach ($rows as $row) {
            $integration = $this->hydrateIntegrationRow($row);
            $authorized = $this->authorizeIntegrationRow($integration);
            if ($authorized) {
                return $authorized;
            }
        }

        return null;
    }

    private function getAuthorizedProviderIntegration(string $provider, ?int $workspaceId = null, string $scope = self::SCOPE_MAIN_EMAIL, ?string $grantType = null): ?array
    {
        $provider = $this->normalizeProvider($provider);
        $scope = $this->normalizeScope($scope);
        $integration = $this->getActiveProviderIntegration($provider, $workspaceId, $scope, $grantType);
        if (!$integration) {
            return null;
        }

        return $this->authorizeIntegrationRow($integration);
    }

    private function authorizeIntegrationRow(array $integration): ?array
    {
        $provider = $this->normalizeProvider((string) ($integration['provider'] ?? ''));
        $scope = $this->normalizeScope((string) ($integration['scope'] ?? self::SCOPE_MAIN_EMAIL));
        $accessToken = trim((string) ($integration['access_token'] ?? ''));
        $expiresAt = trim((string) ($integration['token_expires_at'] ?? ''));
        $expiresSoon = true;

        if ($expiresAt !== '') {
            $expiresSoon = strtotime($expiresAt) <= (time() + 90);
        }

        if ($accessToken !== '' && !$expiresSoon) {
            return $integration;
        }

        $refreshToken = trim((string) ($integration['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            return null;
        }

        $mailService = $scope === self::SCOPE_ASSISTANT_EMAIL && $provider === self::PROVIDER_GMAIL_OAUTH
            ? new AssistantGmailMailService()
            : $this->mailServiceForProvider($provider);
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($integration['oauth_grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED));
        if (!$mailService->isConfigured($grantType)) {
            return null;
        }

        $tokens = $mailService->refreshToken($refreshToken, $grantType);
        $newAccessToken = trim((string) ($tokens['access_token'] ?? ''));
        if ($newAccessToken === '') {
            throw new \RuntimeException($this->providerLabel($provider) . ' token refresh returned no access token.');
        }

        $newRefreshToken = trim((string) ($tokens['refresh_token'] ?? ''));
        $newExpiresAt = date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)));
        $vault = new OAuthTokenVault();

        Database::execute(
            "UPDATE email_integrations
             SET access_token = ?,
                 refresh_token = ?,
                 token_expires_at = ?,
                 token_encrypted = 1,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [
                $vault->encrypt($newAccessToken),
                $vault->encrypt($newRefreshToken !== '' ? $newRefreshToken : $refreshToken),
                $newExpiresAt,
                (int) $integration['id'],
                (int) ($integration['workspace_id'] ?? 0),
            ]
        );

        $updated = $this->getActiveProviderIntegration($provider, (int) ($integration['workspace_id'] ?? 0), $scope, $grantType);
        if (!$updated) {
            throw new \RuntimeException($this->providerLabel($provider) . ' integration disappeared during token refresh.');
        }

        $this->recordOAuthAudit(
            (int) ($integration['workspace_id'] ?? 0),
            (int) ($integration['connected_by_user_id'] ?? 0),
            $provider,
            $scope === self::SCOPE_ASSISTANT_EMAIL ? 'email_assistant' : 'email',
            $grantType,
            (string) ($integration['oauth_client_key'] ?? ''),
            'success',
            'refresh',
            $this->providerLabel($provider) . ' OAuth token refreshed.',
            is_array($integration['required_scopes'] ?? null) ? $integration['required_scopes'] : [],
            is_array($integration['granted_scopes'] ?? null) ? $integration['granted_scopes'] : [],
            'email_integrations',
            (int) ($integration['id'] ?? 0)
        );

        return $updated;
    }

    /**
     * @param array<int,string> $requestedScopes
     * @param array<int,string> $grantedScopes
     */
    private function recordOAuthAudit(
        ?int $workspaceId,
        ?int $userId,
        ?string $provider,
        string $surface,
        string $grantType,
        ?string $clientKey,
        string $status,
        string $operation,
        ?string $message = null,
        array $requestedScopes = [],
        array $grantedScopes = [],
        ?string $integrationTable = null,
        ?int $integrationId = null
    ): void {
        if (!Database::tableExists('oauth_connection_audit_log')) {
            return;
        }

        Database::execute(
            "INSERT INTO oauth_connection_audit_log (
                workspace_id, user_id, provider, surface, oauth_grant_type, oauth_client_key,
                status, operation, message, requested_scopes_json, granted_scopes_json,
                integration_table, integration_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId && $workspaceId > 0 ? $workspaceId : null,
                $userId && $userId > 0 ? $userId : null,
                $provider,
                $surface,
                GoogleOAuthScopeCatalog::normalizeGrantType($grantType),
                $clientKey,
                in_array($status, ['success', 'warning', 'failed'], true) ? $status : 'warning',
                $operation,
                $message,
                json_encode(array_values($requestedScopes), JSON_UNESCAPED_SLASHES),
                json_encode(array_values($grantedScopes), JSON_UNESCAPED_SLASHES),
                $integrationTable,
                $integrationId && $integrationId > 0 ? $integrationId : null,
            ]
        );
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = trim($provider);

        return match ($provider) {
            self::PROVIDER_GMAIL_OAUTH,
            self::PROVIDER_GOOGLE_WORKSPACE,
            self::PROVIDER_MANUAL_SMTP,
            self::PROVIDER_MANUAL_IMAP => $provider,
            default => self::PROVIDER_GOOGLE_WORKSPACE,
        };
    }

    private function normalizeScope(string $scope): string
    {
        $scope = trim($scope);
        return match ($scope) {
            self::SCOPE_ASSISTANT_EMAIL => self::SCOPE_ASSISTANT_EMAIL,
            self::SCOPE_OUTREACH_EMAIL => self::SCOPE_OUTREACH_EMAIL,
            self::SCOPE_NURTURE_EMAIL => self::SCOPE_NURTURE_EMAIL,
            default => self::SCOPE_MAIN_EMAIL,
        };
    }

    private function scopeForRole(string $role): string
    {
        return match (strtolower(trim($role))) {
            'assistant', 'email_assistant', 'assistant_email' => self::SCOPE_ASSISTANT_EMAIL,
            'outreach', 'outbound', 'sales', 'outreach_email' => self::SCOPE_OUTREACH_EMAIL,
            'nurture', 'followup', 'follow_up', 'nurture_email' => self::SCOPE_NURTURE_EMAIL,
            default => self::SCOPE_MAIN_EMAIL,
        };
    }

    private function normalizeEncryption(string $encryption): string
    {
        $encryption = strtolower(trim($encryption));
        return in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'tls';
    }

    private function encryptSecret(string $secret): array
    {
        return [
            'encrypted' => true,
            'value' => Security::encryptSensitiveData($secret, $this->encryptionKey()),
        ];
    }

    private function decryptSecret(mixed $secret): string
    {
        if (is_array($secret) && !empty($secret['encrypted']) && isset($secret['value'])) {
            try {
                return Security::decryptSensitiveData((string) $secret['value'], $this->encryptionKey());
            } catch (\Throwable $e) {
                return '';
            }
        }

        return is_string($secret) ? $secret : '';
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }
}
