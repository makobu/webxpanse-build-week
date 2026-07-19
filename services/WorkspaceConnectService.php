<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Security;

class WorkspaceConnectService
{
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';
    public const STATUS_NOT_CONNECTED = 'not_connected';
    public const STATUS_DISABLED = 'disabled';

    public function canManageConnections(?array $user = null, ?int $workspaceId = null): bool
    {
        $user = $user ?: (class_exists(\CRM\Auth::class) ? \CRM\Auth::user() : null);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            return false;
        }

        if ((new PresentationWorkspaceGuardService())->isBlocked('integrations', $resolvedWorkspaceId)) {
            return false;
        }

        if (Authorization::isSuperAdmin($user)) {
            return true;
        }

        $membership = (new WorkspaceMembershipService())->getActiveMembership($resolvedWorkspaceId, $userId);
        if (!$membership) {
            return false;
        }

        $roleSlug = strtolower(trim((string) ($membership['role_slug'] ?? '')));
        return !empty($membership['is_owner']) || in_array($roleSlug, ['owner', 'admin'], true);
    }

    public function canManageWhatsAppConnection(?array $user = null, ?int $workspaceId = null): bool
    {
        if ($this->canManageConnections($user, $workspaceId)) {
            return true;
        }

        $user = $user ?: (class_exists(\CRM\Auth::class) ? \CRM\Auth::user() : null);
        $userId = (int) ($user['id'] ?? 0);
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($userId <= 0 || $resolvedWorkspaceId <= 0) {
            return false;
        }
        if ((new PresentationWorkspaceGuardService())->isBlocked('integrations', $resolvedWorkspaceId)) {
            return false;
        }
        if (!(new WorkspaceMembershipService())->getActiveMembership($resolvedWorkspaceId, $userId)) {
            return false;
        }

        return Authorization::can('settings.whatsapp.manage_connection', $user);
    }

    public function requireWhatsAppManager(?array $user = null, ?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        (new PresentationWorkspaceGuardService())->assertAllowed('integrations', $resolvedWorkspaceId);
        if ($resolvedWorkspaceId <= 0 || !$this->canManageWhatsAppConnection($user, $resolvedWorkspaceId)) {
            throw new \RuntimeException('WhatsApp connection management access is required.');
        }

        return $resolvedWorkspaceId;
    }

    public function requireWorkspaceAdmin(?array $user = null, ?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        (new PresentationWorkspaceGuardService())->assertAllowed('integrations', $resolvedWorkspaceId);
        if ($resolvedWorkspaceId <= 0 || !$this->canManageConnections($user, $resolvedWorkspaceId)) {
            throw new \RuntimeException('Workspace admin access is required.');
        }

        return $resolvedWorkspaceId;
    }

    public function canUseCalendarConnections(?array $user = null, ?int $workspaceId = null): bool
    {
        $user = $user ?: (class_exists(\CRM\Auth::class) ? \CRM\Auth::user() : null);
        $userId = (int) ($user['id'] ?? 0);
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($userId <= 0 || $resolvedWorkspaceId <= 0) {
            return false;
        }

        if (!Authorization::isSuperAdmin($user) && !(new WorkspaceMembershipService())->getActiveMembership($resolvedWorkspaceId, $userId)) {
            return false;
        }

        try {
            $catalog = new WorkspaceSkillCatalogService();
            if ($catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
                return false;
            }
            if (!Authorization::isSuperAdmin($user)
                && !(new WorkspaceSkillInstallService($catalog))->isInstalled($resolvedWorkspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    public function requireCalendarConnectionAccess(?array $user = null, ?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        (new PresentationWorkspaceGuardService())->assertAllowed('integrations', $resolvedWorkspaceId);
        if ($resolvedWorkspaceId <= 0 || !$this->canUseCalendarConnections($user, $resolvedWorkspaceId)) {
            throw new \RuntimeException('Calendar & Meetings access is required to connect a calendar.');
        }

        return $resolvedWorkspaceId;
    }

    public function buildHubState(?array $user = null, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $canManage = $this->canManageConnections($user, $resolvedWorkspaceId);

        return [
            'workspace_id' => $resolvedWorkspaceId,
            'can_manage' => $canManage,
            'platform' => $this->platformReadiness(),
            'email' => $this->emailState($resolvedWorkspaceId),
            'calendar' => $this->calendarState($resolvedWorkspaceId),
            'whatsapp' => $this->whatsappState($resolvedWorkspaceId),
            'meetings' => $this->meetingState($resolvedWorkspaceId),
        ];
    }

    public function getActiveWhatsAppIntegration(?int $workspaceId = null): ?array
    {
        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            return null;
        }

        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_whatsapp_integrations
             WHERE workspace_id = ?
               AND connection_status = 'connected'
               AND disconnected_at IS NULL
             LIMIT 1",
            [$resolvedWorkspaceId]
        );

        return $row ? $this->hydrateWhatsAppIntegrationRow($row) : null;
    }

    /**
     * @return array{webhook_token:string,webhook_verify_token:string}
     */
    public function ensureWhatsAppWebhookSettings(int $workspaceId, int $userId = 0): array
    {
        if ($workspaceId <= 0 || !$this->whatsAppWebhookColumnsReady()) {
            return ['webhook_token' => '', 'webhook_verify_token' => ''];
        }

        $row = Database::queryOne(
            "SELECT id, webhook_token, webhook_verify_token
             FROM workspace_whatsapp_integrations
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );
        if (!$row) {
            return ['webhook_token' => '', 'webhook_verify_token' => ''];
        }

        $webhookToken = trim((string) ($row['webhook_token'] ?? ''));
        $verifyToken = $this->decryptToken($row['webhook_verify_token'] ?? '');
        $updates = [];
        $params = [];

        if ($webhookToken === '') {
            $webhookToken = $this->generateUniqueWebhookToken();
            $updates[] = 'webhook_token = ?';
            $params[] = $webhookToken;
        }

        if ($verifyToken === '') {
            $verifyToken = bin2hex(random_bytes(24));
            $updates[] = 'webhook_verify_token = ?';
            $params[] = $this->encryptToken($verifyToken);
        }

        if ($updates !== []) {
            $updates[] = "webhook_last_status = COALESCE(webhook_last_status, 'created')";
            $updates[] = 'webhook_last_error = NULL';
            $updates[] = 'updated_at = NOW()';
            $params[] = $workspaceId;
            Database::execute(
                "UPDATE workspace_whatsapp_integrations
                 SET " . implode(', ', $updates) . "
                 WHERE workspace_id = ?",
                $params
            );
        }

        return [
            'webhook_token' => $webhookToken,
            'webhook_verify_token' => $verifyToken,
        ];
    }

    public function buildWhatsAppWebhookCallbackUrl(string $webhookToken): string
    {
        $webhookToken = trim($webhookToken);
        if ($webhookToken === '') {
            return '';
        }

        $path = function_exists('apiUrl') ? \apiUrl('webhooks/whatsapp.php') : '/api/webhooks/whatsapp.php';
        return rtrim($this->publicAppOrigin(), '/') . $path . '?w=' . rawurlencode($webhookToken);
    }

    public function getWhatsAppIntegrationByWebhookToken(string $webhookToken): ?array
    {
        if (!$this->whatsAppWebhookColumnsReady()) {
            return null;
        }

        $webhookToken = trim($webhookToken);
        if ($webhookToken === '' || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $webhookToken)) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_whatsapp_integrations
             WHERE webhook_token = ?
               AND connection_status = 'connected'
               AND disconnected_at IS NULL
             LIMIT 1",
            [$webhookToken]
        );

        return $row ? $this->hydrateWhatsAppIntegrationRow($row) : null;
    }

    public function verifyWhatsAppWebhookChallenge(string $webhookToken, string $verifyToken): ?array
    {
        $integration = $this->getWhatsAppIntegrationByWebhookToken($webhookToken);
        if (!$integration) {
            return null;
        }

        $workspaceId = (int) ($integration['workspace_id'] ?? 0);
        $expected = trim((string) ($integration['webhook_verify_token'] ?? ''));
        if ($expected === '' || !hash_equals($expected, trim($verifyToken))) {
            $this->recordWhatsAppWebhookEvent($workspaceId, 'verification_failed', 'Verify token did not match this workspace.');
            return null;
        }

        if ($workspaceId > 0 && $this->columnExists('workspace_whatsapp_integrations', 'webhook_verified_at')) {
            Database::execute(
                "UPDATE workspace_whatsapp_integrations
                 SET webhook_verified_at = NOW(),
                     webhook_last_status = 'verified',
                     webhook_last_error = NULL,
                     webhook_last_event_at = NOW(),
                     updated_at = NOW()
                 WHERE workspace_id = ?",
                [$workspaceId]
            );
        }

        return $integration;
    }

    public function getWhatsAppIntegrationByWebhookMetadata(array $metadata): ?array
    {
        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            return null;
        }

        $phoneNumberId = $this->normalizeWebhookIdentifier((string) ($metadata['phone_number_id'] ?? ''));
        $wabaId = $this->normalizeWebhookIdentifier((string) (
            $metadata['whatsapp_business_account_id']
            ?? $metadata['business_account_id']
            ?? $metadata['waba_id']
            ?? ''
        ));

        if ($phoneNumberId !== '') {
            $matches = Database::query(
                "SELECT *
                 FROM workspace_whatsapp_integrations
                 WHERE phone_number_id = ?
                   AND connection_status = 'connected'
                   AND disconnected_at IS NULL
                 ORDER BY workspace_id ASC
                 LIMIT 3",
                [$phoneNumberId]
            );

            if (count($matches) === 1) {
                return $this->hydrateWhatsAppIntegrationRow($matches[0]);
            }

            if ($wabaId !== '' && count($matches) > 1) {
                $filtered = array_values(array_filter(
                    $matches,
                    static fn(array $row): bool => trim((string) ($row['whatsapp_business_account_id'] ?? '')) === $wabaId
                ));
                if (count($filtered) === 1) {
                    return $this->hydrateWhatsAppIntegrationRow($filtered[0]);
                }
            }
        }

        if ($wabaId !== '') {
            $matches = Database::query(
                "SELECT *
                 FROM workspace_whatsapp_integrations
                 WHERE whatsapp_business_account_id = ?
                   AND connection_status = 'connected'
                   AND disconnected_at IS NULL
                 ORDER BY workspace_id ASC
                 LIMIT 3",
                [$wabaId]
            );

            if (count($matches) === 1) {
                return $this->hydrateWhatsAppIntegrationRow($matches[0]);
            }
        }

        return null;
    }

    public function validateWhatsAppWebhookPayloadForWorkspace(int $workspaceId, array $payload): array
    {
        $integration = $this->getActiveWhatsAppIntegration($workspaceId);
        if (!$integration) {
            return [
                'ok' => false,
                'status' => 'unknown_workspace_token',
                'error' => 'Workspace WhatsApp integration is not connected.',
            ];
        }

        $expectedPhoneNumberId = $this->normalizeWebhookIdentifier((string) ($integration['phone_number_id'] ?? ''));
        $expectedWabaId = $this->normalizeWebhookIdentifier((string) ($integration['whatsapp_business_account_id'] ?? ''));
        $metadataRows = $this->extractWebhookPayloadMetadata($payload);

        if ($metadataRows === []) {
            return [
                'ok' => false,
                'status' => 'metadata_mismatch',
                'error' => 'Webhook payload is missing WhatsApp phone number metadata.',
            ];
        }

        foreach ($metadataRows as $metadata) {
            $phoneNumberId = $this->normalizeWebhookIdentifier((string) ($metadata['phone_number_id'] ?? ''));
            $wabaId = $this->normalizeWebhookIdentifier((string) (
                $metadata['whatsapp_business_account_id']
                ?? $metadata['business_account_id']
                ?? $metadata['waba_id']
                ?? ''
            ));

            if ($phoneNumberId === '' && $wabaId === '') {
                return [
                    'ok' => false,
                    'status' => 'metadata_mismatch',
                    'error' => 'Webhook payload is missing WhatsApp phone number metadata.',
                ];
            }

            if ($phoneNumberId !== '' && ($expectedPhoneNumberId === '' || $phoneNumberId !== $expectedPhoneNumberId)) {
                return [
                    'ok' => false,
                    'status' => 'metadata_mismatch',
                    'error' => 'Webhook phone number ID does not match this workspace.',
                ];
            }

            if ($wabaId !== '' && $expectedWabaId !== '' && $wabaId !== $expectedWabaId) {
                return [
                    'ok' => false,
                    'status' => 'metadata_mismatch',
                    'error' => 'Webhook WABA ID does not match this workspace.',
                ];
            }

            if ($phoneNumberId === '' && $expectedWabaId === '') {
                return [
                    'ok' => false,
                    'status' => 'metadata_mismatch',
                    'error' => 'Webhook payload must include the workspace phone number ID.',
                ];
            }
        }

        return ['ok' => true, 'status' => 'matched'];
    }

    public function storeWhatsAppEmbeddedSignup(int $workspaceId, int $userId, array $payload): array
    {
        (new PresentationWorkspaceGuardService())->assertAllowed('integrations', $workspaceId);
        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            throw new \RuntimeException('workspace_whatsapp_integrations table is missing. Run migrations first.');
        }

        $connectionMode = (string) ($payload['connection_mode'] ?? $payload['mode'] ?? WhatsAppConnectionResolver::MODE_SELF_MANAGED);
        $connectionMode = $connectionMode === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            ? WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            : WhatsAppConnectionResolver::MODE_SELF_MANAGED;

        if ($connectionMode === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED) {
            return (new ManagedWhatsAppProvisioningService())->beginManagedConnection($workspaceId, $userId, $payload);
        }

        $code = trim((string) ($payload['code'] ?? ''));
        $tokenData = [];
        $lastError = null;
        if ($code !== '') {
            try {
                $tokenData = $this->exchangeMetaCode($code);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $accessToken = trim((string) ($tokenData['access_token'] ?? $payload['access_token'] ?? ''));
        $expiresIn = (int) ($tokenData['expires_in'] ?? 0);
        $tokenExpiresAt = $expiresIn > 0 ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

        $wabaId = trim((string) ($payload['whatsapp_business_account_id'] ?? $payload['waba_id'] ?? ''));
        $phoneNumberId = trim((string) ($payload['phone_number_id'] ?? ''));
        $displayNumber = trim((string) ($payload['display_phone_number'] ?? $payload['phone_number'] ?? ''));
        $verifiedName = trim((string) ($payload['verified_name'] ?? ''));
        $metaBusinessId = trim((string) ($payload['meta_business_id'] ?? $payload['business_id'] ?? ''));

        if ($accessToken !== '' && ($wabaId === '' || $phoneNumberId === '')) {
            $detected = $this->detectWhatsAppResources($accessToken, $metaBusinessId, $wabaId);
            $metaBusinessId = $metaBusinessId !== '' ? $metaBusinessId : (string) ($detected['meta_business_id'] ?? '');
            $wabaId = $wabaId !== '' ? $wabaId : (string) ($detected['whatsapp_business_account_id'] ?? '');
            $phoneNumberId = $phoneNumberId !== '' ? $phoneNumberId : (string) ($detected['phone_number_id'] ?? '');
            $displayNumber = $displayNumber !== '' ? $displayNumber : (string) ($detected['display_phone_number'] ?? '');
            $verifiedName = $verifiedName !== '' ? $verifiedName : (string) ($detected['verified_name'] ?? '');
        }

        $status = ($phoneNumberId !== '' && ($accessToken !== '' || trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '')) !== ''))
            ? self::STATUS_CONNECTED
            : self::STATUS_NEEDS_ATTENTION;

        if ($status === self::STATUS_CONNECTED) {
            $this->assertWhatsAppPhoneNumberAvailable($phoneNumberId, $workspaceId);
        }

        $settings = [
            'embedded_signup_payload' => $this->redactPayload($payload),
            'setup_mode' => 'embedded_signup',
            'platform_app_id' => trim((string) ($_ENV['META_APP_ID'] ?? '')),
            'embedded_signup_config_id' => trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? '')),
        ];

        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, meta_business_id, whatsapp_business_account_id, phone_number_id,
                 display_phone_number, verified_name, access_token, token_expires_at, connection_status, last_error,
                 settings_json, connection_mode, managed_status, managed_billing_status, connected_at, disconnected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'self_managed', 'not_applicable', 'not_applicable', NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                connected_by_user_id = VALUES(connected_by_user_id),
                meta_business_id = VALUES(meta_business_id),
                whatsapp_business_account_id = VALUES(whatsapp_business_account_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                verified_name = VALUES(verified_name),
                access_token = VALUES(access_token),
                token_expires_at = VALUES(token_expires_at),
                connection_status = VALUES(connection_status),
                last_error = VALUES(last_error),
                settings_json = VALUES(settings_json),
                connection_mode = VALUES(connection_mode),
                managed_status = VALUES(managed_status),
                managed_billing_status = VALUES(managed_billing_status),
                connected_at = NOW(),
                disconnected_at = NULL,
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $metaBusinessId !== '' ? $metaBusinessId : null,
                $wabaId !== '' ? $wabaId : null,
                $phoneNumberId !== '' ? $phoneNumberId : null,
                $displayNumber !== '' ? $displayNumber : null,
                $verifiedName !== '' ? $verifiedName : null,
                $accessToken !== '' ? $this->encryptToken($accessToken) : null,
                $tokenExpiresAt,
                $status,
                $lastError,
                json_encode($settings, JSON_UNESCAPED_SLASHES),
            ]
        );

        $this->ensureWhatsAppWebhookSettings($workspaceId, $userId);

        return $this->whatsappState($workspaceId);
    }

    public function storeManualWhatsAppIntegration(int $workspaceId, int $userId, array $settings): array
    {
        (new PresentationWorkspaceGuardService())->assertAllowed('integrations', $workspaceId);
        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            throw new \RuntimeException('workspace_whatsapp_integrations table is missing. Run migrations first.');
        }

        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to save WhatsApp settings.');
        }

        $existing = $this->getActiveWhatsAppIntegration($workspaceId) ?: [];
        $accessToken = trim((string) ($settings['access_token'] ?? ''));
        $phoneNumberId = trim((string) ($settings['phone_number_id'] ?? ''));
        $displayNumber = trim((string) ($settings['display_phone_number'] ?? $settings['phone_number'] ?? ''));
        $verifiedName = trim((string) ($settings['verified_name'] ?? ''));
        $wabaId = trim((string) ($settings['whatsapp_business_account_id'] ?? $settings['waba_id'] ?? ''));
        $metaBusinessId = trim((string) ($settings['meta_business_id'] ?? $settings['business_id'] ?? ''));

        $accessToken = $accessToken !== '' ? $accessToken : trim((string) ($existing['access_token'] ?? ''));
        $phoneNumberId = $phoneNumberId !== '' ? $phoneNumberId : trim((string) ($existing['phone_number_id'] ?? ''));
        $displayNumber = $displayNumber !== '' ? $displayNumber : trim((string) ($existing['display_phone_number'] ?? ''));
        $verifiedName = $verifiedName !== '' ? $verifiedName : trim((string) ($existing['verified_name'] ?? ''));
        $wabaId = $wabaId !== '' ? $wabaId : trim((string) ($existing['whatsapp_business_account_id'] ?? ''));
        $metaBusinessId = $metaBusinessId !== '' ? $metaBusinessId : trim((string) ($existing['meta_business_id'] ?? ''));

        if ($accessToken === '' || $phoneNumberId === '') {
            throw new \RuntimeException('Manual WhatsApp setup requires an access token and phone number ID.');
        }

        $this->assertWhatsAppPhoneNumberAvailable($phoneNumberId, $workspaceId);

        $metadata = [
            'setup_mode' => 'manual',
            'provider' => 'meta_cloud',
            'manual_notes' => trim((string) ($settings['notes'] ?? '')),
            'saved_at' => gmdate('c'),
        ];

        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, meta_business_id, whatsapp_business_account_id, phone_number_id,
                 display_phone_number, verified_name, access_token, token_expires_at, connection_status, last_error,
                 settings_json, connection_mode, managed_status, managed_billing_status, connected_at, disconnected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 'self_managed', 'not_applicable', 'not_applicable', NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                connected_by_user_id = VALUES(connected_by_user_id),
                meta_business_id = VALUES(meta_business_id),
                whatsapp_business_account_id = VALUES(whatsapp_business_account_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                verified_name = VALUES(verified_name),
                access_token = VALUES(access_token),
                token_expires_at = VALUES(token_expires_at),
                connection_status = VALUES(connection_status),
                last_error = NULL,
                settings_json = VALUES(settings_json),
                connection_mode = VALUES(connection_mode),
                managed_status = VALUES(managed_status),
                managed_billing_status = VALUES(managed_billing_status),
                connected_at = NOW(),
                disconnected_at = NULL,
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $metaBusinessId !== '' ? $metaBusinessId : null,
                $wabaId !== '' ? $wabaId : null,
                $phoneNumberId,
                $displayNumber !== '' ? $displayNumber : null,
                $verifiedName !== '' ? $verifiedName : null,
                $this->encryptToken($accessToken),
                $this->normalizeManualExpiry($settings['token_expires_at'] ?? null),
                self::STATUS_CONNECTED,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );

        $this->ensureWhatsAppWebhookSettings($workspaceId, $userId);

        return $this->whatsappState($workspaceId);
    }

    public function disconnectWhatsApp(int $workspaceId): void
    {
        (new PresentationWorkspaceGuardService())->assertAllowed('integrations', $workspaceId);
        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            return;
        }

        Database::execute(
            "UPDATE workspace_whatsapp_integrations
             SET connection_status = 'disconnected',
                 access_token = NULL,
                 disconnected_at = NOW(),
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [$workspaceId]
        );
    }

    public function recordWhatsAppWebhookEvent(int $workspaceId, string $status, ?string $error = null): void
    {
        if ($workspaceId <= 0 || !$this->whatsAppWebhookColumnsReady()) {
            return;
        }

        Database::execute(
            "UPDATE workspace_whatsapp_integrations
             SET webhook_last_status = ?,
                 webhook_last_error = ?,
                 webhook_last_event_at = NOW(),
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [
                substr($status, 0, 50),
                $error !== null && trim($error) !== '' ? substr($error, 0, 1000) : null,
                $workspaceId,
            ]
        );
    }

    private function platformReadiness(): array
    {
        $metaAppId = trim((string) ($_ENV['META_APP_ID'] ?? ''));
        $metaAppSecret = trim((string) ($_ENV['META_APP_SECRET'] ?? ''));
        $embeddedSignupConfigId = trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? ''));
        $platformWhatsAppToken = trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? ''));
        $platformWabaId = trim((string) ($_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? ''));
        $legacyGoogleFallback = trim((string) ($_ENV['ALLOW_LEGACY_GOOGLE_OAUTH_FALLBACK'] ?? '')) === '1';
        $hasClient = static function (string $prefix, ?string $legacyIdKey = null, ?string $legacySecretKey = null) use ($legacyGoogleFallback): bool {
            $clientId = trim((string) ($_ENV[$prefix . '_CLIENT_ID'] ?? ''));
            $clientSecret = trim((string) ($_ENV[$prefix . '_CLIENT_SECRET'] ?? ''));
            if (($clientId === '' || $clientSecret === '') && $legacyGoogleFallback && $legacyIdKey && $legacySecretKey) {
                $clientId = $clientId !== '' ? $clientId : trim((string) ($_ENV[$legacyIdKey] ?? ''));
                $clientSecret = $clientSecret !== '' ? $clientSecret : trim((string) ($_ENV[$legacySecretKey] ?? ''));
            }

            return $clientId !== '' && $clientSecret !== '';
        };
        $gmailSendReady = $hasClient('GOOGLE_GMAIL_SEND', 'GMAIL_MAIL_CLIENT_ID', 'GMAIL_MAIL_CLIENT_SECRET');
        $gmailInboxReady = $hasClient('GOOGLE_GMAIL_INBOX', 'GMAIL_MAIL_CLIENT_ID', 'GMAIL_MAIL_CLIENT_SECRET');
        $assistantGmailReady = $hasClient('GOOGLE_ASSISTANT_GMAIL', 'GMAIL_ASSISTANT_MAIL_CLIENT_ID', 'GMAIL_ASSISTANT_MAIL_CLIENT_SECRET');
        $calendarReady = $hasClient('GOOGLE_CALENDAR');

        return [
            'gmail_send' => $gmailSendReady,
            'gmail_inbox' => $gmailInboxReady,
            'gmail' => $gmailSendReady,
            'assistant_gmail' => $assistantGmailReady,
            'google_workspace' => $gmailSendReady,
            'google_calendar' => $calendarReady,
            'whatsapp_meta_app' => $metaAppId !== '' && $metaAppSecret !== '',
            'whatsapp_embedded_signup' => $metaAppId !== ''
                && $metaAppSecret !== ''
                && $embeddedSignupConfigId !== '',
            'whatsapp_managed_prerequisites' => $metaAppId !== ''
                && $metaAppSecret !== ''
                && $embeddedSignupConfigId !== ''
                && $platformWhatsAppToken !== ''
                && $platformWabaId !== '',
        ];
    }

    private function emailState(int $workspaceId): array
    {
        $platform = $this->platformReadiness();
        $emailIntegrations = new EmailIntegrationService();
        $integration = $emailIntegrations->getActiveOutreachIntegration($workspaceId)
            ?: $emailIntegrations->getActiveNurtureIntegration($workspaceId);
        if (!$integration) {
            return [
                'status' => ($platform['gmail'] || $platform['google_workspace']) ? self::STATUS_NOT_CONNECTED : self::STATUS_DISABLED,
                'label' => 'Not connected',
                'provider' => null,
                'email' => null,
            ];
        }

        return [
            'status' => self::STATUS_CONNECTED,
            'label' => 'Connected',
            'provider' => (string) ($integration['provider'] ?? ''),
            'email' => (string) ($integration['email_address'] ?? ''),
        ];
    }

    private function calendarState(int $workspaceId): array
    {
        $platform = $this->platformReadiness();
        if (!$this->tableExists('calendar_integrations')) {
            return ['status' => self::STATUS_DISABLED, 'label' => 'Disabled by platform setup'];
        }

        $row = Database::queryOne(
            "SELECT *
             FROM calendar_integrations
             WHERE workspace_id = ?
               AND provider = 'google'
             ORDER BY sync_enabled DESC, updated_at DESC, id DESC
             LIMIT 1",
            [$workspaceId]
        );

        if (!$row) {
            return [
                'status' => $platform['google_calendar'] ? self::STATUS_NOT_CONNECTED : self::STATUS_DISABLED,
                'label' => $platform['google_calendar'] ? 'Not connected' : 'Disabled by platform setup',
            ];
        }

        return [
            'status' => !empty($row['sync_enabled']) ? self::STATUS_CONNECTED : self::STATUS_NEEDS_ATTENTION,
            'label' => !empty($row['sync_enabled']) ? 'Connected' : 'Needs attention',
            'calendar_name' => (string) ($row['calendar_name'] ?? $row['calendar_id'] ?? 'Primary calendar'),
            'last_sync_at' => $row['last_sync_at'] ?? null,
        ];
    }

    private function whatsappState(int $workspaceId): array
    {
        $platform = $this->platformReadiness();
        $managedReadiness = [
            'available' => !empty($platform['whatsapp_managed_prerequisites']),
            'missing' => [],
        ];
        try {
            $managedReadiness = (new ManagedWhatsAppProvisioningService())->platformReadiness($workspaceId);
        } catch (\Throwable $e) {
            $managedReadiness['available'] = false;
            $managedReadiness['missing'][] = 'managed_provisioning_service';
        }

        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            return ['status' => self::STATUS_DISABLED, 'label' => 'Disabled by platform setup'];
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_whatsapp_integrations
             WHERE workspace_id = ?
             LIMIT 1",
            [$workspaceId]
        );

        if ($row) {
            $row = $this->hydrateWhatsAppIntegrationRow($row);
        }

        if (!$row || (string) ($row['connection_status'] ?? '') === 'disconnected') {
            return [
                'status' => self::STATUS_NOT_CONNECTED,
                'label' => 'Not connected',
                'connection_mode' => WhatsAppConnectionResolver::MODE_SELF_MANAGED,
                'managed_available' => !empty($managedReadiness['available']),
                'managed_missing_configuration' => (array) ($managedReadiness['missing'] ?? []),
                'managed_label' => !empty($managedReadiness['available']) ? 'Available' : 'Managed WhatsApp is not available yet',
                'app_id' => trim((string) ($_ENV['META_APP_ID'] ?? '')),
                'config_id' => trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? '')),
                'embedded_signup_configured' => !empty($platform['whatsapp_embedded_signup']),
                'template_center_url' => 'whatsapp_templates.php',
            ];
        }

        $status = (string) ($row['connection_status'] ?? self::STATUS_NEEDS_ATTENTION);
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        $mode = (string) ($row['connection_mode'] ?? WhatsAppConnectionResolver::MODE_SELF_MANAGED);
        $mode = $mode === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            ? WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            : WhatsAppConnectionResolver::MODE_SELF_MANAGED;
        $creditSummary = [];
        if (
            $mode === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            && class_exists(WorkspaceWhatsAppCreditService::class)
            && (new WhatsAppFeatureGate())->managedBillingEnabled($workspaceId)
        ) {
            try {
                $creditSummary = (new WorkspaceWhatsAppCreditService())->summary($workspaceId);
            } catch (\Throwable $e) {
                $creditSummary = ['error' => $e->getMessage()];
            }
        }

        return [
            'status' => in_array($status, [self::STATUS_CONNECTING, self::STATUS_CONNECTED, self::STATUS_NEEDS_ATTENTION], true) ? $status : self::STATUS_NEEDS_ATTENTION,
            'label' => ucwords(str_replace('_', ' ', $status)),
            'connection_mode' => $mode,
            'connection_mode_label' => $mode === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED ? 'Managed WhatsApp' : 'Self-managed Meta account',
            'setup_mode' => (string) ($settings['setup_mode'] ?? (!empty($settings['embedded_signup_payload']) ? 'embedded_signup' : 'manual')),
            'phone_number_id' => (string) ($row['phone_number_id'] ?? ''),
            'display_phone_number' => (string) ($row['display_phone_number'] ?? ''),
            'verified_name' => (string) ($row['verified_name'] ?? ''),
            'meta_business_id' => (string) ($row['meta_business_id'] ?? ''),
            'whatsapp_business_account_id' => (string) ($row['whatsapp_business_account_id'] ?? ''),
            'access_token_saved' => trim((string) ($row['access_token'] ?? '')) !== '',
            'token_expires_at' => (string) ($row['token_expires_at'] ?? ''),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'app_id' => trim((string) ($_ENV['META_APP_ID'] ?? '')),
            'config_id' => trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? '')),
            'embedded_signup_configured' => !empty($platform['whatsapp_embedded_signup']),
            'managed_available' => !empty($managedReadiness['available']),
            'managed_missing_configuration' => (array) ($managedReadiness['missing'] ?? []),
            'managed_status' => (string) ($row['managed_status'] ?? ''),
            'managed_billing_status' => (string) ($row['managed_billing_status'] ?? ''),
            'managed_provider_reference' => (string) ($row['managed_provider_reference'] ?? ''),
            'managed_credit_balance' => (float) ($row['managed_credit_balance'] ?? 0),
            'managed_credit_reserved' => (float) ($row['managed_credit_reserved'] ?? 0),
            'managed_currency' => (string) ($row['managed_currency'] ?? 'KES'),
            'managed_low_balance_threshold' => (float) ($row['managed_low_balance_threshold'] ?? 100),
            'managed_daily_spend_cap' => $row['managed_daily_spend_cap'] ?? null,
            'managed_monthly_spend_cap' => $row['managed_monthly_spend_cap'] ?? null,
            'managed_auto_topup_enabled' => !empty($row['managed_auto_topup_enabled']),
            'managed_auto_topup_threshold' => $row['managed_auto_topup_threshold'] ?? null,
            'managed_auto_topup_amount' => $row['managed_auto_topup_amount'] ?? null,
            'managed_last_health_at' => (string) ($row['managed_last_health_at'] ?? ''),
            'managed_last_health_status' => (string) ($row['managed_last_health_status'] ?? ''),
            'managed_last_health_error' => (string) ($row['managed_last_health_error'] ?? ''),
            'credit_summary' => $creditSummary,
            'template_center_url' => 'whatsapp_templates.php',
            'webhook_token' => (string) ($row['webhook_token'] ?? ''),
            'webhook_verify_token' => (string) ($row['webhook_verify_token'] ?? ''),
            'webhook_verify_token_saved' => trim((string) ($row['webhook_verify_token'] ?? '')) !== '',
            'webhook_verified_at' => (string) ($row['webhook_verified_at'] ?? ''),
            'webhook_last_status' => (string) ($row['webhook_last_status'] ?? ''),
            'webhook_last_error' => (string) ($row['webhook_last_error'] ?? ''),
            'webhook_last_event_at' => (string) ($row['webhook_last_event_at'] ?? ''),
        ];
    }

    private function meetingState(int $workspaceId): array
    {
        $calendar = $this->calendarState($workspaceId);
        if (($calendar['status'] ?? '') !== self::STATUS_CONNECTED) {
            return [
                'status' => self::STATUS_NOT_CONNECTED,
                'label' => 'Connect calendar first',
                'google_meet_ready' => false,
                'zoom_ready' => false,
            ];
        }

        $googleMeetCount = $this->countMeetingLinks($workspaceId, 'meet.google.com');
        $zoomCount = $this->countMeetingLinks($workspaceId, 'zoom.us');

        return [
            'status' => self::STATUS_CONNECTED,
            'label' => 'Calendar-connected',
            'google_meet_ready' => $googleMeetCount > 0,
            'zoom_ready' => $zoomCount > 0,
            'google_meet_count' => $googleMeetCount,
            'zoom_count' => $zoomCount,
        ];
    }

    private function countMeetingLinks(int $workspaceId, string $needle): int
    {
        if (!$this->tableExists('events')) {
            return 0;
        }

        $searchColumns = array_values(array_filter(
            ['location', 'description', 'custom_fields'],
            fn(string $column): bool => $this->columnExists('events', $column)
        ));
        if (empty($searchColumns)) {
            return 0;
        }

        $like = '%' . $needle . '%';
        $where = implode(' OR ', array_map(static fn(string $column): string => "`{$column}` LIKE ?", $searchColumns));
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM events
             WHERE workspace_id = ?
               AND ({$where})",
            array_merge([$workspaceId], array_fill(0, count($searchColumns), $like))
        );

        return (int) ($row['c'] ?? 0);
    }

    private function exchangeMetaCode(string $code): array
    {
        $appId = trim((string) ($_ENV['META_APP_ID'] ?? ''));
        $appSecret = trim((string) ($_ENV['META_APP_SECRET'] ?? ''));
        if ($appId === '' || $appSecret === '') {
            throw new \RuntimeException('Meta app credentials are not configured.');
        }

        $url = 'https://graph.facebook.com/v24.0/oauth/access_token?' . http_build_query([
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'code' => $code,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            throw new \RuntimeException($error ?: 'Meta authorization failed.');
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function detectWhatsAppResources(string $accessToken, string $businessId = '', string $wabaId = ''): array
    {
        $result = [];
        if ($businessId === '') {
            $businesses = $this->graphGet('me/businesses?fields=id,name', $accessToken);
            $businessId = (string) ($businesses['data'][0]['id'] ?? '');
        }
        if ($businessId !== '') {
            $result['meta_business_id'] = $businessId;
        }

        if ($wabaId === '' && $businessId !== '') {
            $wabas = $this->graphGet($businessId . '/owned_whatsapp_business_accounts?fields=id,name', $accessToken);
            $wabaId = (string) ($wabas['data'][0]['id'] ?? '');
        }
        if ($wabaId !== '') {
            $result['whatsapp_business_account_id'] = $wabaId;
            $phones = $this->graphGet($wabaId . '/phone_numbers?fields=id,display_phone_number,verified_name', $accessToken);
            $phone = is_array($phones['data'][0] ?? null) ? $phones['data'][0] : [];
            $result['phone_number_id'] = (string) ($phone['id'] ?? '');
            $result['display_phone_number'] = (string) ($phone['display_phone_number'] ?? '');
            $result['verified_name'] = (string) ($phone['verified_name'] ?? '');
        }

        return $result;
    }

    private function graphGet(string $endpoint, string $accessToken): array
    {
        $url = 'https://graph.facebook.com/v24.0/' . ltrim($endpoint, '/');
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(['access_token' => $accessToken]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode >= 400) {
            return [];
        }
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function redactPayload(array $payload): array
    {
        foreach (['access_token', 'code', 'authResponse'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }
        return $payload;
    }

    private function hydrateWhatsAppIntegrationRow(array $row): array
    {
        if (array_key_exists('access_token', $row)) {
            $row['access_token'] = $this->decryptToken($row['access_token']);
        }
        if (array_key_exists('webhook_verify_token', $row)) {
            $row['webhook_verify_token'] = $this->decryptToken($row['webhook_verify_token']);
        }

        return $row;
    }

    private function encryptToken(string $token): string
    {
        return (string) json_encode([
            'encrypted' => true,
            'value' => Security::encryptSensitiveData($token, $this->encryptionKey()),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function decryptToken(mixed $token): string
    {
        $raw = is_string($token) ? trim($token) : '';
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && !empty($decoded['encrypted']) && isset($decoded['value'])) {
            try {
                return Security::decryptSensitiveData((string) $decoded['value'], $this->encryptionKey());
            } catch (\Throwable $e) {
                return '';
            }
        }

        return $raw;
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }

    private function normalizeManualExpiry(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function generateUniqueWebhookToken(): string
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $token = bin2hex(random_bytes(24));
            $existing = Database::queryOne(
                "SELECT 1
                 FROM workspace_whatsapp_integrations
                 WHERE webhook_token = ?
                 LIMIT 1",
                [$token]
            );
            if (!$existing) {
                return $token;
            }
        }

        return bin2hex(random_bytes(32));
    }

    public function assertWhatsAppPhoneNumberAvailable(string $phoneNumberId, int $workspaceId): void
    {
        if (!$this->tableExists('workspace_whatsapp_integrations')) {
            return;
        }

        $phoneNumberId = $this->normalizeWebhookIdentifier($phoneNumberId);
        if ($phoneNumberId === '' || $workspaceId <= 0) {
            return;
        }

        $row = Database::queryOne(
            "SELECT workspace_id
             FROM workspace_whatsapp_integrations
             WHERE phone_number_id = ?
               AND workspace_id <> ?
               AND connection_status = 'connected'
               AND disconnected_at IS NULL
             LIMIT 1",
            [$phoneNumberId, $workspaceId]
        );

        if ($row) {
            throw new \RuntimeException('This WhatsApp phone number ID is already connected to another active workspace.');
        }
    }

    private function extractWebhookPayloadMetadata(array $payload): array
    {
        $metadataRows = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryWabaId = (string) ($entry['id'] ?? '');
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $value = (array) ($change['value'] ?? []);
                $metadata = (array) ($value['metadata'] ?? []);
                if (!isset($metadata['whatsapp_business_account_id']) && $entryWabaId !== '') {
                    $metadata['whatsapp_business_account_id'] = $entryWabaId;
                }
                $metadataRows[] = $metadata;
            }
        }

        return $metadataRows;
    }

    private function publicAppOrigin(): string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        if ($appUrl !== '') {
            $parts = parse_url($appUrl);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = (string) $parts['scheme'] . '://' . (string) $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . (string) $parts['port'];
                }
                return $origin;
            }
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return $protocol . '://' . ($host !== '' ? $host : 'localhost');
    }

    private function normalizeWebhookIdentifier(string $value): string
    {
        return preg_replace('/\s+/', '', trim($value)) ?? '';
    }

    private function whatsAppWebhookColumnsReady(): bool
    {
        return $this->tableExists('workspace_whatsapp_integrations')
            && $this->columnExists('workspace_whatsapp_integrations', 'webhook_token')
            && $this->columnExists('workspace_whatsapp_integrations', 'webhook_verify_token');
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );

            return (int) ($row['c'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
