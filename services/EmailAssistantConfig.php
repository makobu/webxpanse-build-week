<?php
/**
 * Email Assistant Configuration Validator
 *
 * Compatibility wrapper around the unified AI runtime config service.
 */

namespace CRM\Services;

class EmailAssistantConfig
{
    /**
     * @return array{outbound_ok: bool, inbound_ok: bool, identity_ok: bool, imap_enabled: bool, outbound_missing: string[], inbound_missing: string[], identity_missing: string[], message: string}
     */
    public static function validate(): array
    {
        return AIRuntimeConfig::getEmailAssistantConfig();
    }

    public static function isOutboundConfigured(): bool
    {
        $v = self::validate();
        return $v['outbound_ok'] && $v['identity_ok'];
    }

    public static function isInboundConfigured(): bool
    {
        $v = self::validate();
        return $v['inbound_ok'];
    }
}
