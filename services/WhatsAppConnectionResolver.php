<?php

namespace CRM\Services;

use CRM\Database;

class WhatsAppConnectionResolver
{
    public const MODE_SELF_MANAGED = 'self_managed';
    public const MODE_PLATFORM_MANAGED = 'platform_managed';

    public function resolve(?int $workspaceId = null): array
    {
        $workspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return $this->emptyConnection();
        }

        $integration = (new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId);
        if (!$integration) {
            return $this->emptyConnection($workspaceId);
        }

        $mode = $this->normalizeMode((string) ($integration['connection_mode'] ?? self::MODE_SELF_MANAGED));
        $settings = json_decode((string) ($integration['settings_json'] ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        $wallet = $mode === self::MODE_PLATFORM_MANAGED
            ? (new WorkspaceWhatsAppCreditService())->summary($workspaceId)
            : [];

        return [
            'available' => true,
            'workspace_id' => $workspaceId,
            'integration_id' => (int) ($integration['id'] ?? 0),
            'connection_mode' => $mode,
            'is_platform_managed' => $mode === self::MODE_PLATFORM_MANAGED,
            'phone_number_id' => (string) ($integration['phone_number_id'] ?? ''),
            'display_phone_number' => (string) ($integration['display_phone_number'] ?? ''),
            'verified_name' => (string) ($integration['verified_name'] ?? ''),
            'whatsapp_business_account_id' => (string) ($integration['whatsapp_business_account_id'] ?? ''),
            'meta_business_id' => (string) ($integration['meta_business_id'] ?? ''),
            'access_token' => (string) ($integration['access_token'] ?? ''),
            'credential_source' => trim((string) ($integration['access_token'] ?? '')) !== '' ? 'workspace' : 'environment',
            'connection_status' => (string) ($integration['connection_status'] ?? ''),
            'managed_status' => (string) ($integration['managed_status'] ?? ''),
            'managed_billing_status' => (string) ($integration['managed_billing_status'] ?? ''),
            'settings' => $settings,
            'billing' => [
                'enabled' => $mode === self::MODE_PLATFORM_MANAGED,
                'wallet' => $wallet,
                'currency' => (string) ($wallet['currency'] ?? $integration['managed_currency'] ?? 'KES'),
                'daily_spend_cap' => $this->floatOrNull($integration['managed_daily_spend_cap'] ?? null),
                'monthly_spend_cap' => $this->floatOrNull($integration['managed_monthly_spend_cap'] ?? null),
                'low_balance_threshold' => (float) ($wallet['low_balance_threshold'] ?? $integration['managed_low_balance_threshold'] ?? 100),
            ],
            'compliance' => [
                'requires_marketing_opt_in' => true,
                'respect_suppression_list' => true,
                'quiet_hours_enforced' => true,
            ],
        ];
    }

    public function connectionMode(?int $workspaceId = null): string
    {
        return (string) ($this->resolve($workspaceId)['connection_mode'] ?? self::MODE_SELF_MANAGED);
    }

    public function annotateMessage(int $workspaceId, int $messageId, array $options = []): void
    {
        if ($workspaceId <= 0 || $messageId <= 0 || !Database::tableExists('whatsapp_messages')) {
            return;
        }

        $connection = $this->resolve($workspaceId);
        $mode = (string) ($connection['connection_mode'] ?? self::MODE_SELF_MANAGED);
        $category = $this->normalizeCategory((string) ($options['template_category'] ?? $options['category'] ?? ''));
        $country = (string) ($options['recipient_country'] ?? '');
        $estimatedCost = 0.0;
        if ($mode === self::MODE_PLATFORM_MANAGED) {
            $estimatedCost = (new WorkspaceWhatsAppCreditService())->estimateCost($workspaceId, $category ?: 'utility', $country);
        }

        $updates = ['connection_mode = ?', 'billing_status = ?'];
        $params = [
            $mode,
            $mode === self::MODE_PLATFORM_MANAGED ? 'estimated' : 'not_applicable',
        ];
        if (Database::columnExists('whatsapp_messages', 'template_category')) {
            $updates[] = 'template_category = ?';
            $params[] = $category !== '' ? $category : null;
        }
        if (Database::columnExists('whatsapp_messages', 'recipient_country')) {
            $updates[] = 'recipient_country = ?';
            $params[] = $country !== '' ? strtoupper(substr($country, 0, 8)) : null;
        }
        if (Database::columnExists('whatsapp_messages', 'estimated_cost')) {
            $updates[] = 'estimated_cost = ?';
            $params[] = $estimatedCost;
        }
        $params[] = $workspaceId;
        $params[] = $messageId;

        Database::execute(
            "UPDATE whatsapp_messages SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
            $params
        );
    }

    private function emptyConnection(int $workspaceId = 0): array
    {
        return [
            'available' => false,
            'workspace_id' => $workspaceId,
            'integration_id' => 0,
            'connection_mode' => self::MODE_SELF_MANAGED,
            'is_platform_managed' => false,
            'billing' => ['enabled' => false, 'wallet' => []],
            'compliance' => [
                'requires_marketing_opt_in' => true,
                'respect_suppression_list' => true,
                'quiet_hours_enforced' => true,
            ],
        ];
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === self::MODE_PLATFORM_MANAGED ? self::MODE_PLATFORM_MANAGED : self::MODE_SELF_MANAGED;
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return in_array($category, ['marketing', 'utility', 'authentication', 'service'], true) ? $category : '';
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }
}
