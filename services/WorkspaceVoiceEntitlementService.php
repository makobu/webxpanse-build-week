<?php

namespace CRM\Services;

class WorkspaceVoiceEntitlementService
{
    private WorkspacePlanEntitlementService $plans;

    public function __construct(?WorkspacePlanEntitlementService $plans = null)
    {
        $this->plans = $plans ?? new WorkspacePlanEntitlementService();
    }

    /** @return array<string,mixed> */
    public function forWorkspace(int $workspaceId): array
    {
        $entitlements = $this->plans->entitlementsForWorkspace($workspaceId);
        $features = (array) ($entitlements['features'] ?? []);
        $packageExempt = !empty($entitlements['package_exempt']);
        $enabled = $packageExempt || !empty($features[WorkspacePlanEntitlementService::FEATURE_VOICE_CALL_CENTER]);

        return [
            'enabled' => $enabled,
            'concurrent_calls' => $enabled
                ? $this->boundedInt($features[WorkspacePlanEntitlementService::FEATURE_VOICE_CONCURRENT_CALLS] ?? ($packageExempt ? 40 : 0), 1, 40)
                : 0,
            'agent_limit' => $enabled
                ? $this->boundedInt($features[WorkspacePlanEntitlementService::FEATURE_VOICE_AGENT_LIMIT] ?? ($packageExempt ? 40 : 0), 1, 1000)
                : 0,
            'recording' => $enabled && ($packageExempt || !empty($features[WorkspacePlanEntitlementService::FEATURE_VOICE_RECORDING])),
            'transcription' => $enabled && ($packageExempt || !empty($features[WorkspacePlanEntitlementService::FEATURE_VOICE_TRANSCRIPTION])),
            'customer_voice' => $enabled && ($packageExempt || !empty($features[WorkspacePlanEntitlementService::FEATURE_VOICE_CUSTOMER_VOICE])),
            'plan_code' => (string) ($entitlements['plan_code'] ?? ''),
            'package_exempt' => $packageExempt,
        ];
    }

    public function assertEnabled(int $workspaceId): array
    {
        $entitlements = $this->forWorkspace($workspaceId);
        if (empty($entitlements['enabled'])) {
            throw new \RuntimeException('Voice & Call Center is not enabled for this workspace package.');
        }
        return $entitlements;
    }

    private function boundedInt($value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }
}
