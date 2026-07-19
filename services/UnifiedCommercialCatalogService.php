<?php

namespace CRM\Services;

use CRM\Modules\Products;

class UnifiedCommercialCatalogService
{
    /**
     * @return array{offers:list<array<string,mixed>>,packages:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function catalogForWorkspace(int $workspaceId): array
    {
        $offers = array_map([$this, 'normalizeOffer'], (new Products())->list());
        $packages = $this->groupPackages((new SaaSBillingService())->listSubscriptionPrices($workspaceId));
        $recommendation = (new WorkspacePackageRecommendationService())->recommend($workspaceId, $packages);
        if ($recommendation !== null) {
            foreach ($packages as &$package) {
                if ((string) ($package['catalog_key'] ?? '') === (string) ($recommendation['catalog_key'] ?? '')) {
                    $package['recommendation'] = $recommendation;
                }
            }
            unset($package);
        }

        return [
            'offers' => $offers,
            'packages' => $packages,
            'counts' => [
                'offers' => count($offers),
                'packages' => count($packages),
                'package_price_variants' => array_sum(array_map(static fn(array $package): int => count((array) ($package['price_variants'] ?? [])), $packages)),
            ],
            'package_recommendation' => $recommendation,
        ];
    }

    /** @return array<string,mixed> */
    public function normalizeOffer(array $product): array
    {
        return [
            'catalog_key' => 'offer:' . (int) ($product['id'] ?? 0),
            'source_type' => 'workspace_offer',
            'source_id' => (int) ($product['id'] ?? 0),
            'name' => (string) ($product['name'] ?? ''),
            'description' => (string) ($product['description'] ?? ''),
            'category' => (string) ($product['category'] ?? ''),
            'target_audience' => (string) ($product['target_audience'] ?? ''),
            'benefits' => (string) ($product['benefits'] ?? ''),
            'features' => $this->decodeJsonList($product['features'] ?? null),
            'unit_price' => (float) ($product['unit_price'] ?? 0),
            'pricing_info' => (string) ($product['pricing_info'] ?? ''),
            'is_recommendable' => true,
            'action' => 'edit_offer',
            'raw' => $product,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function groupPackages(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $planId = (int) ($row['plan_id'] ?? 0);
            if ($planId <= 0) {
                continue;
            }
            $entitlements = (array) ($row['entitlements'] ?? []);
            if (!isset($grouped[$planId])) {
                $grouped[$planId] = [
                    'catalog_key' => 'package:' . $planId,
                    'source_type' => 'workspace_package',
                    'source_id' => $planId,
                    'plan_code' => (string) ($row['plan_code'] ?? ''),
                    'name' => (string) ($row['display_name'] ?? $row['plan_name'] ?? 'Workspace package'),
                    'description' => (string) ($row['public_summary'] ?? $row['summary'] ?? $row['description'] ?? ''),
                    'best_for' => (string) ($row['best_for'] ?? $row['ideal_customer'] ?? ''),
                    'feature_highlights' => array_values((array) ($row['feature_highlights'] ?? [])),
                    'entitlements' => $entitlements,
                    'price_variants' => [],
                    'is_recommendable' => true,
                    'action' => 'manage_package',
                ];
            }
            $grouped[$planId]['price_variants'][] = [
                'billing_plan_price_id' => (int) ($row['id'] ?? 0),
                'price_code' => (string) ($row['price_code'] ?? ''),
                'currency' => strtoupper((string) ($row['currency'] ?? 'KES')),
                'amount' => (float) ($row['amount'] ?? 0),
                'interval_unit' => (string) ($row['interval_unit'] ?? 'monthly'),
                'interval_count' => (int) ($row['interval_count'] ?? 1),
                'included_credits' => (int) ($row['included_tokens'] ?? $entitlements['included_credits'] ?? 0),
                'checkout_available' => !empty($row['checkout_available']),
                'provider_plan_configured' => !empty($row['provider_plan_configured']) || (float) ($row['amount'] ?? 0) <= 0,
            ];
        }

        $packages = array_values($grouped);
        usort($packages, static function (array $left, array $right): int {
            $leftTier = (string) (($left['entitlements']['maturity_tier'] ?? '') ?: $left['plan_code']);
            $rightTier = (string) (($right['entitlements']['maturity_tier'] ?? '') ?: $right['plan_code']);
            $rank = static fn(string $tier): int => match (strtolower(str_replace('_', '-', $tier))) {
                'free', 'compass-free' => 0,
                'solo', 'solo-launch' => 10,
                'founder', 'founder-plus' => 20,
                'growth', 'growth-studio' => 30,
                'scale', 'scale-custom' => 40,
                default => 100,
            };
            return $rank($leftTier) <=> $rank($rightTier);
        });
        return $packages;
    }

    /** @return list<string> */
    private function decodeJsonList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
