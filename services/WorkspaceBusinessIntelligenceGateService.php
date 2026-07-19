<?php

namespace CRM\Services;

class WorkspaceBusinessIntelligenceGateService
{
    public const ERROR_CODE = 'business_intelligence_required';
    public const ACTION_URL = 'billing_payment_required.php?tab=packages#workspace-packages';

    public function __construct(
        private ?WorkspacePlanEntitlementService $planEntitlements = null,
        private ?SuperAdminDefaultWorkspaceModuleAccessService $adminBypass = null
    ) {
        $this->planEntitlements = $planEntitlements ?? new WorkspacePlanEntitlementService();
        $this->adminBypass = $adminBypass ?? new SuperAdminDefaultWorkspaceModuleAccessService();
    }

    /**
     * @param array<string,mixed>|null $user
     */
    public function canAccess(int $workspaceId, ?array $user = null): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        return $this->adminBypass->canBypassModuleAccessGates($workspaceId, $user)
            || $this->planEntitlements->canUseFeature(
                $workspaceId,
                WorkspacePlanEntitlementService::FEATURE_BUSINESS_INTELLIGENCE
            );
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    public function status(int $workspaceId, ?array $user = null, string $feature = 'Business Intelligence'): array
    {
        $adminBypass = $workspaceId > 0 && $this->adminBypass->canBypassModuleAccessGates($workspaceId, $user);
        $entitled = $workspaceId > 0 && $this->planEntitlements->canUseFeature(
            $workspaceId,
            WorkspacePlanEntitlementService::FEATURE_BUSINESS_INTELLIGENCE
        );
        $allowed = $adminBypass || $entitled;

        return [
            'allowed' => $allowed,
            'locked' => !$allowed,
            'feature' => $this->normalizeFeature($feature),
            'error' => $this->lockedMessage($feature),
            'error_code' => self::ERROR_CODE,
            'billing_required' => !$allowed,
            'action_url' => self::ACTION_URL,
            'admin_bypass' => $adminBypass,
        ];
    }

    /**
     * @param array<string,mixed>|null $user
     */
    public function enforceWeb(int $workspaceId, ?array $user = null, string $feature = 'Business Intelligence'): void
    {
        if ($this->canAccess($workspaceId, $user)) {
            return;
        }

        header('Location: ' . $this->publicUrl(self::ACTION_URL));
        exit;
    }

    /**
     * @param array<string,mixed>|null $user
     */
    public function enforceJson(int $workspaceId, ?array $user = null, string $feature = 'Business Intelligence'): void
    {
        if ($this->canAccess($workspaceId, $user)) {
            return;
        }

        http_response_code(402);
        echo json_encode($this->jsonBlockPayload($workspaceId, $user, $feature));
        exit;
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    public function jsonBlockPayload(int $workspaceId, ?array $user = null, string $feature = 'Business Intelligence'): array
    {
        $status = $this->status($workspaceId, $user, $feature);

        return [
            'success' => false,
            'error' => (string) $status['error'],
            'error_code' => self::ERROR_CODE,
            'billing_required' => true,
            'feature' => (string) $status['feature'],
            'action_url' => self::ACTION_URL,
        ];
    }

    private function lockedMessage(string $feature): string
    {
        return $this->normalizeFeature($feature) . ' unlocks on Founder Plus and higher plans.';
    }

    private function normalizeFeature(string $feature): string
    {
        $feature = trim($feature);
        return $feature !== '' ? $feature : 'Business Intelligence';
    }

    private function publicUrl(string $path): string
    {
        return function_exists('publicUrl') ? publicUrl($path) : $path;
    }
}
