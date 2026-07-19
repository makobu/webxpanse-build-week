<?php

namespace CRM\Services;

use CRM\Database;

class ManagedWhatsAppProvisioningService
{
    public function platformReadiness(int $workspaceId = 0): array
    {
        $gate = new WhatsAppFeatureGate();
        $appId = trim((string) ($_ENV['META_APP_ID'] ?? ''));
        $appSecret = trim((string) ($_ENV['META_APP_SECRET'] ?? ''));
        $embeddedConfigId = trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? ''));
        $platformToken = trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? ''));
        $wabaId = trim((string) ($_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? ''));
        $missing = [];
        if (!$gate->managedBillingEnabled($workspaceId)) {
            $missing[] = 'WHATSAPP_MANAGED_BILLING_FEATURE_FLAG';
        }
        if ($appId === '') {
            $missing[] = 'META_APP_ID';
        }
        if ($appSecret === '') {
            $missing[] = 'META_APP_SECRET';
        }
        if ($embeddedConfigId === '') {
            $missing[] = 'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID';
        }
        if ($platformToken === '') {
            $missing[] = 'WHATSAPP_ACCESS_TOKEN';
        }
        if ($wabaId === '') {
            $missing[] = 'WHATSAPP_BUSINESS_ACCOUNT_ID';
        }
        $missingLabels = array_map(
            static fn(string $key): string => str_replace('_', ' ', $key),
            $missing
        );

        return [
            'ready' => $missing === [],
            'available' => $missing === [],
            'missing' => $missing,
            'missing_labels' => $missingLabels,
            'feature_flags' => $gate->snapshot($workspaceId),
            'app_id' => $appId,
            'config_id' => $embeddedConfigId,
            'message' => $missing === []
                ? 'Managed WhatsApp is ready for workspace onboarding.'
                : 'Managed WhatsApp is not available yet.',
        ];
    }

    public function beginManagedConnection(int $workspaceId, int $userId, array $payload = []): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for Managed WhatsApp.');
        }
        if (!Database::tableExists('workspace_whatsapp_integrations')) {
            throw new \RuntimeException('WhatsApp integrations table is missing. Run migrations first.');
        }
        (new WhatsAppFeatureGate())->assertManagedBillingEnabled($workspaceId);

        $readiness = $this->platformReadiness($workspaceId);
        if (!$readiness['ready']) {
            return [
                'success' => false,
                'status' => 'unavailable',
                'message' => 'Managed WhatsApp is not available yet.',
                'missing' => $readiness['missing'],
            ];
        }

        $settings = [
            'setup_mode' => 'managed',
            'provider' => 'meta_cloud',
            'managed_requested_at' => gmdate('c'),
            'embedded_signup_config_id' => $readiness['config_id'],
            'embedded_signup_payload' => $this->redactPayload($payload),
        ];

        $wabaId = trim((string) ($payload['whatsapp_business_account_id'] ?? $payload['waba_id'] ?? $_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? ''));
        $phoneNumberId = trim((string) ($payload['phone_number_id'] ?? $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? ''));
        $displayNumber = trim((string) ($payload['display_phone_number'] ?? $payload['phone_number'] ?? ''));
        $verifiedName = trim((string) ($payload['verified_name'] ?? ''));
        $metaBusinessId = trim((string) ($payload['meta_business_id'] ?? $payload['business_id'] ?? ''));
        $accessToken = trim((string) ($payload['access_token'] ?? $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? ''));

        $connectionStatus = $phoneNumberId !== '' && $accessToken !== ''
            ? WorkspaceConnectService::STATUS_CONNECTED
            : WorkspaceConnectService::STATUS_NEEDS_ATTENTION;
        $managedStatus = $connectionStatus === WorkspaceConnectService::STATUS_CONNECTED ? 'active' : 'needs_review';
        $connect = new WorkspaceConnectService();
        if ($connectionStatus === WorkspaceConnectService::STATUS_CONNECTED) {
            $connect->assertWhatsAppPhoneNumberAvailable($phoneNumberId, $workspaceId);
        }

        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connected_by_user_id, connection_mode, meta_business_id, whatsapp_business_account_id,
                 phone_number_id, display_phone_number, verified_name, access_token, connection_status, managed_status,
                 managed_billing_status, managed_currency, managed_provider_reference, managed_provider_metadata_json,
                 settings_json, connected_at, disconnected_at)
             VALUES (?, ?, 'platform_managed', ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                connected_by_user_id = VALUES(connected_by_user_id),
                connection_mode = 'platform_managed',
                meta_business_id = VALUES(meta_business_id),
                whatsapp_business_account_id = VALUES(whatsapp_business_account_id),
                phone_number_id = VALUES(phone_number_id),
                display_phone_number = VALUES(display_phone_number),
                verified_name = VALUES(verified_name),
                access_token = VALUES(access_token),
                connection_status = VALUES(connection_status),
                managed_status = VALUES(managed_status),
                managed_billing_status = VALUES(managed_billing_status),
                managed_currency = VALUES(managed_currency),
                managed_provider_reference = VALUES(managed_provider_reference),
                managed_provider_metadata_json = VALUES(managed_provider_metadata_json),
                settings_json = VALUES(settings_json),
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
                $connectionStatus,
                $managedStatus,
                strtoupper(substr((string) ($payload['currency'] ?? $_ENV['WHATSAPP_MANAGED_CURRENCY'] ?? 'KES'), 0, 8)) ?: 'KES',
                $wabaId !== '' ? 'waba:' . $wabaId : 'workspace:' . $workspaceId,
                json_encode(['readiness' => $readiness, 'payload' => $this->redactPayload($payload)], JSON_UNESCAPED_SLASHES),
                json_encode($settings, JSON_UNESCAPED_SLASHES),
            ]
        );

        $connect->ensureWhatsAppWebhookSettings($workspaceId, $userId);
        $integration = $connect->getActiveWhatsAppIntegration($workspaceId) ?: [];
        (new WorkspaceWhatsAppCreditService())->ensureWallet($workspaceId, (int) ($integration['id'] ?? 0), (string) ($payload['currency'] ?? 'KES'));
        $this->recordAudit($workspaceId, $userId, 'managed_connection_started', ['status' => $managedStatus]);

        return [
            'success' => true,
            'status' => $managedStatus,
            'whatsapp' => $connect->buildHubState(null, $workspaceId)['whatsapp'] ?? [],
        ];
    }

    public function requestModeSwitch(int $workspaceId, int $userId, string $targetMode, string $notes = ''): array
    {
        $targetMode = $targetMode === WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            ? WhatsAppConnectionResolver::MODE_PLATFORM_MANAGED
            : WhatsAppConnectionResolver::MODE_SELF_MANAGED;
        Database::execute(
            "UPDATE workspace_whatsapp_integrations
             SET mode_switch_requested_at = NOW(),
                 mode_switch_notes = ?,
                 managed_last_health_status = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [trim($notes), 'mode_switch_requested:' . $targetMode, $workspaceId]
        );
        $this->recordAudit($workspaceId, $userId, 'mode_switch_requested', ['target_mode' => $targetMode, 'notes' => $notes]);

        return ['success' => true, 'target_mode' => $targetMode];
    }

    public function healthCheck(int $workspaceId): array
    {
        (new WhatsAppFeatureGate())->assertManagedBillingEnabled($workspaceId);
        $connection = (new WhatsAppConnectionResolver())->resolve($workspaceId);
        if (empty($connection['available'])) {
            return ['status' => 'not_connected', 'message' => 'WhatsApp is not connected.'];
        }

        $issues = [];
        if (trim((string) ($connection['phone_number_id'] ?? '')) === '') {
            $issues[] = 'Phone number ID is missing.';
        }
        if (trim((string) ($connection['whatsapp_business_account_id'] ?? '')) === '') {
            $issues[] = 'WABA ID is missing.';
        }
        if (trim((string) ($connection['access_token'] ?? '')) === '') {
            $issues[] = 'Access token is missing.';
        }
        if (!empty($connection['is_platform_managed'])) {
            $wallet = (array) (($connection['billing']['wallet'] ?? []));
            if (($wallet['available_credits'] ?? 0) <= 0) {
                $issues[] = 'Managed WhatsApp credit balance is depleted.';
            }
        }

        $status = $issues === [] ? 'healthy' : 'needs_attention';
        Database::execute(
            "UPDATE workspace_whatsapp_integrations
             SET managed_last_health_at = NOW(),
                 managed_last_health_status = ?,
                 managed_last_health_error = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [$status, $issues !== [] ? implode(' ', $issues) : null, $workspaceId]
        );

        return ['status' => $status, 'issues' => $issues, 'connection' => $connection];
    }

    public function adminSummary(int $limit = 50): array
    {
        if (!Database::tableExists('workspace_whatsapp_integrations')) {
            return ['rows' => [], 'totals' => []];
        }

        $rows = Database::query(
            "SELECT wwi.*, w.name AS workspace_name, wallet.credit_balance, wallet.reserved_credits, wallet.billing_status
             FROM workspace_whatsapp_integrations wwi
             LEFT JOIN workspaces w ON w.id = wwi.workspace_id
             LEFT JOIN workspace_whatsapp_credit_wallets wallet ON wallet.workspace_id = wwi.workspace_id
             WHERE wwi.connection_mode = 'platform_managed'
             ORDER BY FIELD(wwi.managed_status, 'needs_review', 'provisioning', 'active', 'suspended'), wwi.updated_at DESC
             LIMIT ?",
            [max(1, min(200, $limit))]
        );
        $totals = Database::queryOne(
            "SELECT COUNT(*) AS managed_count,
                    COALESCE(SUM(wallet.credit_balance), 0) AS credit_liability,
                    COALESCE(SUM(wallet.reserved_credits), 0) AS reserved_liability
             FROM workspace_whatsapp_integrations wwi
             LEFT JOIN workspace_whatsapp_credit_wallets wallet ON wallet.workspace_id = wwi.workspace_id
             WHERE wwi.connection_mode = 'platform_managed'"
        ) ?? [];

        return ['rows' => $rows, 'totals' => $totals, 'readiness' => $this->platformReadiness()];
    }

    private function encryptToken(string $token): string
    {
        return (string) json_encode([
            'encrypted' => true,
            'value' => \CRM\Security::encryptSensitiveData($token, $this->encryptionKey()),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
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

    private function recordAudit(int $workspaceId, int $userId, string $event, array $metadata = []): void
    {
        if (!Database::tableExists('operator_audit_log')) {
            return;
        }
        try {
            Database::execute(
                "INSERT INTO operator_audit_log (workspace_id, user_id, action, entity_type, entity_id, metadata_json, created_at)
                 VALUES (?, ?, ?, 'whatsapp', ?, ?, NOW())",
                [$workspaceId, $userId > 0 ? $userId : null, $event, (string) $workspaceId, json_encode($metadata, JSON_UNESCAPED_SLASHES)]
            );
        } catch (\Throwable $e) {
            // Audit logging should not block setup.
        }
    }
}
