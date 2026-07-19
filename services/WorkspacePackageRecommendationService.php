<?php

namespace CRM\Services;

use CRM\Database;

class WorkspacePackageRecommendationService
{
    /**
     * @param list<array<string,mixed>> $packages
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public function recommend(int $workspaceId, array $packages, array $context = []): ?array
    {
        if ($packages === []) {
            return null;
        }

        $requiredSeats = max(1, (int) ($context['required_seats'] ?? $this->activeSeatCount($workspaceId)));
        $needsBusinessIntelligence = !empty($context['business_intelligence']);
        $needsPersonalApiKey = !empty($context['personal_api_key']);

        foreach ($packages as $package) {
            $entitlements = (array) ($package['entitlements'] ?? []);
            $seatLimit = (int) ($entitlements['seat_limit'] ?? 0);
            if ($seatLimit > 0 && $seatLimit < $requiredSeats) {
                continue;
            }
            if ($needsBusinessIntelligence && empty($entitlements['business_intelligence_enabled'])) {
                continue;
            }
            if ($needsPersonalApiKey && empty($entitlements['personal_api_key_enabled'])) {
                continue;
            }

            $reasons = [$requiredSeats === 1 ? 'Fits the current single-user workspace.' : 'Supports the current ' . $requiredSeats . '-seat workspace.'];
            if ($needsBusinessIntelligence) {
                $reasons[] = 'Includes Business Intelligence.';
            }
            if ($needsPersonalApiKey) {
                $reasons[] = 'Includes personal API key access.';
            }
            return [
                'catalog_key' => (string) ($package['catalog_key'] ?? ''),
                'plan_code' => (string) ($package['plan_code'] ?? ''),
                'name' => (string) ($package['name'] ?? 'Workspace package'),
                'reason' => implode(' ', $reasons),
                'required_seats' => $requiredSeats,
                'source' => 'workspace_package_fit',
            ];
        }

        $fallback = end($packages);
        return is_array($fallback) ? [
            'catalog_key' => (string) ($fallback['catalog_key'] ?? ''),
            'plan_code' => (string) ($fallback['plan_code'] ?? ''),
            'name' => (string) ($fallback['name'] ?? 'Workspace package'),
            'reason' => 'Highest available package is the safest fit for the requested workspace requirements.',
            'required_seats' => $requiredSeats,
            'source' => 'workspace_package_fallback',
        ] : null;
    }

    private function activeSeatCount(int $workspaceId): int
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return 1;
        }
        return max(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_memberships WHERE workspace_id = ? AND membership_status = 'active'",
            [$workspaceId]
        )['c'] ?? 1));
    }
}
