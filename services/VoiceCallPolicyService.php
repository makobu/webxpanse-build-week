<?php

namespace CRM\Services;

use CRM\Database;

class VoiceCallPolicyService
{
    private const ACTIVE_STATES = ['requested', 'queued', 'received', 'consent_pending', 'dialing_agent', 'agent_answered', 'dialing_customer', 'ringing', 'in_progress'];

    public function assertRuntimeReady(int $workspaceId, string $direction): array
    {
        if (!$this->platformEnabled()) {
            throw new \RuntimeException('The platform voice kill switch is disabled.');
        }
        $entitlements = (new WorkspaceVoiceEntitlementService())->assertEnabled($workspaceId);
        if (!(new WorkspaceSkillInstallService())->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)) {
            throw new \RuntimeException('Voice & Call Center is not installed for this workspace.');
        }
        $config = (new WorkspaceVoiceConfigService())->get($workspaceId, true);
        if (empty($config['enabled'])) {
            throw new \RuntimeException('Voice is disabled for this workspace.');
        }
        $directionFlag = $direction === 'inbound' ? 'inbound_enabled' : 'outbound_enabled';
        if (empty($config[$directionFlag])) {
            throw new \RuntimeException(ucfirst($direction) . ' voice calling is disabled.');
        }
        // A package downgrade must take effect immediately even when an older
        // workspace configuration row still has premium switches enabled.
        $config['recording_enabled'] = !empty($config['recording_enabled'])
            && !empty($entitlements['recording'])
            && !empty($config['recording_acknowledgement_valid']);
        $config['transcription_enabled'] = !empty($config['transcription_enabled'])
            && !empty($entitlements['transcription'])
            && !empty($config['recording_enabled']);
        $config['customer_voice_enabled'] = !empty($config['customer_voice_enabled']) && !empty($entitlements['customer_voice']);
        return ['config' => $config, 'entitlements' => $entitlements];
    }

    public function assertDestinationAllowed(int $workspaceId, string $destination, array $config): string
    {
        $normalizer = new WorkspaceVoiceConfigService();
        $destination = $normalizer->normalizePhone($destination);
        if ($destination === '') {
            throw new \InvalidArgumentException('A valid international-format destination number is required.');
        }
        foreach ((array) ($config['blocked_prefixes'] ?? []) as $prefix) {
            if ($prefix !== '' && str_starts_with($destination, (string) $prefix)) {
                throw new \RuntimeException('This destination is blocked by workspace policy.');
            }
        }
        $premiumPrefixes = ['+254900', '+254903', '+254909'];
        foreach ($premiumPrefixes as $prefix) {
            if (str_starts_with($destination, $prefix)) {
                throw new \RuntimeException('Premium and special-rate destinations are blocked.');
            }
        }
        $allowed = false;
        foreach ((array) ($config['allowed_country_codes'] ?? ['+254']) as $prefix) {
            if ($prefix !== '' && str_starts_with($destination, (string) $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \RuntimeException('The destination country is not enabled for this workspace.');
        }
        return $destination;
    }

    public function assertCapacity(int $workspaceId, array $config, array $entitlements): void
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATES), '?'));
        $active = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM voice_calls WHERE workspace_id = ? AND state IN ({$placeholders})",
            array_merge([$workspaceId], self::ACTIVE_STATES)
        )['c'] ?? 0);
        if ($active >= (int) ($entitlements['concurrent_calls'] ?? 0)) {
            throw new \RuntimeException('The workspace concurrent voice-call limit has been reached.');
        }
        $hourly = (int) (Database::queryOne(
            'SELECT COUNT(*) AS c FROM voice_calls WHERE workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$workspaceId]
        )['c'] ?? 0);
        if ($hourly >= (int) ($config['hourly_call_limit'] ?? 60)) {
            throw new \RuntimeException('The workspace hourly call limit has been reached.');
        }
        $dailyMinutes = (int) (Database::queryOne(
            'SELECT COALESCE(SUM(estimated_billable_minutes), 0) AS minutes FROM voice_usage_ledger WHERE workspace_id = ? AND created_at >= CURDATE()', [$workspaceId]
        )['minutes'] ?? 0);
        if ($dailyMinutes >= (int) ($config['daily_minute_limit'] ?? 1000)) {
            throw new \RuntimeException('The workspace daily voice-minute limit has been reached.');
        }
    }

    public function platformEnabled(): bool
    {
        $value = $_ENV['VOICE_CALL_CENTER_ENABLED'] ?? getenv('VOICE_CALL_CENTER_ENABLED');
        return filter_var($value === false ? 'false' : $value, FILTER_VALIDATE_BOOL);
    }
}
