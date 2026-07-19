<?php

namespace CRM\Services;

use CRM\Database;

class WorkspacePlanEntitlementService
{
    public const PLAN_COMPASS_FREE = 'compass-free';
    public const PLAN_SOLO_LAUNCH = 'solo-launch';
    public const PLAN_FOUNDER_PLUS = 'founder-plus';
    public const PLAN_GROWTH_STUDIO = 'growth-studio';
    public const PLAN_SCALE_CUSTOM = 'scale-custom';

    public const FEATURE_BUSINESS_INTELLIGENCE = 'business_intelligence';
    public const FEATURE_PERSONAL_API_KEY = 'personal_api_key';
    public const FEATURE_VOICE_CALL_CENTER = 'voice_call_center';
    public const FEATURE_VOICE_CONCURRENT_CALLS = 'voice_concurrent_calls';
    public const FEATURE_VOICE_AGENT_LIMIT = 'voice_agent_limit';
    public const FEATURE_VOICE_RECORDING = 'voice_recording_enabled';
    public const FEATURE_VOICE_TRANSCRIPTION = 'voice_transcription_enabled';
    public const FEATURE_VOICE_CUSTOMER_VOICE = 'voice_customer_voice_enabled';

    private DefaultWorkspacePackageExemptionService $packageExemptions;

    public function __construct(?DefaultWorkspacePackageExemptionService $packageExemptions = null)
    {
        $this->packageExemptions = $packageExemptions ?? new DefaultWorkspacePackageExemptionService();
    }

    /**
     * @return array<string,mixed>
     */
    public function entitlementsForWorkspace(int $workspaceId): array
    {
        if ($this->packageExemptions->isExempt($workspaceId)) {
            return $this->packageExemptEntitlements();
        }

        if ($workspaceId <= 0 || !Database::tableExists('workspace_subscriptions')) {
            return $this->defaultsForPlan(self::PLAN_COMPASS_FREE);
        }

        $row = Database::queryOne(
            "SELECT ws.id AS subscription_id, ws.subscription_status, ws.current_period_start, ws.current_period_end,
                    ws.next_billing_at, ws.scheduled_billing_plan_price_id, ws.scheduled_change_type,
                    ws.scheduled_change_at, ws.scheduled_change_metadata_json,
                    bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description AS plan_description
             FROM workspace_subscriptions ws
             JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE ws.workspace_id = ?
             ORDER BY CASE ws.subscription_status
                    WHEN 'active' THEN 0
                    WHEN 'trialing' THEN 1
                    WHEN 'past_due' THEN 2
                    WHEN 'expired' THEN 3
                    WHEN 'cancelled' THEN 4
                    ELSE 5
                END,
                ws.id DESC
             LIMIT 1",
            [$workspaceId]
        );

        if (!$row) {
            return $this->defaultsForPlan(self::PLAN_COMPASS_FREE);
        }

        $entitlements = $this->entitlementsForPriceRow($row);
        $entitlements['subscription_id'] = (int) ($row['subscription_id'] ?? 0);
        $entitlements['subscription_status'] = (string) ($row['subscription_status'] ?? '');
        $entitlements['current_period_start'] = $row['current_period_start'] ?? null;
        $entitlements['current_period_end'] = $row['current_period_end'] ?? null;
        $entitlements['next_billing_at'] = $row['next_billing_at'] ?? null;
        $entitlements['scheduled_change'] = $this->scheduledChangeFromRow($row);

        return $entitlements;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function entitlementsForPriceRow(array $row): array
    {
        $planCode = trim((string) ($row['plan_code'] ?? ''));
        $metadata = $this->decodeJson($row['metadata'] ?? $row['metadata_json'] ?? null);
        $raw = is_array($metadata['entitlements'] ?? null) ? (array) $metadata['entitlements'] : [];
        $defaults = $this->defaultsForPlan($planCode !== '' ? $planCode : self::PLAN_COMPASS_FREE);

        $merged = array_merge($defaults, $raw);
        $dynamic = $this->featureValuesForPlan((int) ($row['plan_id'] ?? 0));
        $priceDynamic = $this->featureValuesForPrice((int) ($row['id'] ?? $row['billing_plan_price_id'] ?? 0));
        $dynamicFeatures = array_merge(
            (array) ($dynamic['features'] ?? []),
            (array) ($priceDynamic['features'] ?? [])
        );
        $featureDetails = $this->mergeFeatureDetails(
            (array) ($dynamic['feature_details'] ?? []),
            (array) ($priceDynamic['feature_details'] ?? [])
        );
        if (array_key_exists('seat_limit', $dynamicFeatures)) {
            $merged['seat_limit'] = (int) $dynamicFeatures['seat_limit'];
        }
        if (array_key_exists('can_top_up', $dynamicFeatures)) {
            $merged['can_top_up'] = !empty($dynamicFeatures['can_top_up']);
        }
        if (array_key_exists(self::FEATURE_BUSINESS_INTELLIGENCE, $dynamicFeatures)) {
            $merged['business_intelligence_enabled'] = !empty($dynamicFeatures[self::FEATURE_BUSINESS_INTELLIGENCE]);
        }
        if (array_key_exists(self::FEATURE_PERSONAL_API_KEY, $dynamicFeatures)) {
            $merged['personal_api_key_enabled'] = !empty($dynamicFeatures[self::FEATURE_PERSONAL_API_KEY]);
        }
        if (array_key_exists('credit_expiry_days', $dynamicFeatures)) {
            $merged['credit_expiry_days'] = (int) $dynamicFeatures['credit_expiry_days'];
        }
        $includedCredits = (int) ($merged['included_credits'] ?? $row['included_tokens'] ?? 0);
        $features = array_merge([
            self::FEATURE_BUSINESS_INTELLIGENCE => !empty($merged['business_intelligence_enabled']),
            self::FEATURE_PERSONAL_API_KEY => !empty($merged['personal_api_key_enabled']),
        ], $dynamicFeatures);
        $features[self::FEATURE_BUSINESS_INTELLIGENCE] = !empty($merged['business_intelligence_enabled']);
        $features[self::FEATURE_PERSONAL_API_KEY] = !empty($merged['personal_api_key_enabled']);

        return [
            'plan_code' => $planCode !== '' ? $planCode : (string) $defaults['plan_code'],
            'plan_name' => (string) ($row['plan_name'] ?? $merged['display_name'] ?? $defaults['plan_name']),
            'billing_plan_price_id' => (int) ($row['id'] ?? $row['billing_plan_price_id'] ?? 0),
            'price_code' => (string) ($row['price_code'] ?? ''),
            'currency' => (string) ($row['currency'] ?? 'KES'),
            'amount' => (float) ($row['amount'] ?? 0),
            'interval_unit' => (string) ($row['interval_unit'] ?? 'monthly'),
            'interval_count' => max(1, (int) ($row['interval_count'] ?? 1)),
            'seat_limit' => max(0, (int) ($merged['seat_limit'] ?? 1)),
            'seat_limit_label' => (int) ($merged['seat_limit'] ?? 1) <= 0 ? 'Unlimited' : (string) (int) ($merged['seat_limit'] ?? 1),
            'included_credits' => max(0, $includedCredits),
            'can_top_up' => !empty($merged['can_top_up']),
            'business_intelligence_enabled' => !empty($merged['business_intelligence_enabled']),
            'personal_api_key_enabled' => !empty($merged['personal_api_key_enabled']),
            'credit_expiry_days' => max(1, (int) ($merged['credit_expiry_days'] ?? 180)),
            'is_custom' => !empty($merged['is_custom']),
            'maturity_tier' => (string) ($merged['maturity_tier'] ?? 'free'),
            'display_name' => (string) ($merged['display_name'] ?? $row['plan_name'] ?? $defaults['display_name']),
            'public_display_name' => (string) ($merged['public_display_name'] ?? $merged['display_name'] ?? $row['plan_name'] ?? $defaults['display_name']),
            'public_display_copy' => (string) ($merged['public_display_copy'] ?? $merged['public_summary'] ?? $defaults['public_summary']),
            'public_summary' => (string) ($merged['public_summary'] ?? $defaults['public_summary']),
            'features' => $features,
            'feature_details' => $featureDetails,
            'package_exempt' => !empty($merged['package_exempt']),
            'package_exemption_reason' => (string) ($merged['package_exemption_reason'] ?? ''),
        ];
    }

    public function canTopUp(int $workspaceId): bool
    {
        if ($this->packageExemptions->isExempt($workspaceId)) {
            return true;
        }

        return !empty($this->entitlementsForWorkspace($workspaceId)['can_top_up']);
    }

    public function seatLimit(int $workspaceId): int
    {
        if ($this->packageExemptions->isExempt($workspaceId)) {
            return 0;
        }

        return (int) ($this->entitlementsForWorkspace($workspaceId)['seat_limit'] ?? 1);
    }

    public function canUseFeature(int $workspaceId, string $feature): bool
    {
        $feature = trim($feature);
        if ($feature === '') {
            return false;
        }

        if ($this->packageExemptions->isExempt($workspaceId)) {
            return true;
        }

        $entitlements = $this->entitlementsForWorkspace($workspaceId);
        if ($feature === self::FEATURE_BUSINESS_INTELLIGENCE) {
            return !empty($entitlements['business_intelligence_enabled']);
        }
        if ($feature === self::FEATURE_PERSONAL_API_KEY) {
            return !empty($entitlements['personal_api_key_enabled']);
        }

        $features = (array) ($entitlements['features'] ?? []);
        return !empty($features[$feature]);
    }

    public function compassFreePrice(): ?array
    {
        return $this->priceByCode('compass-free-monthly');
    }

    public function priceByCode(string $priceCode): ?array
    {
        $priceCode = trim($priceCode);
        if ($priceCode === '' || !Database::tableExists('billing_plan_prices')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description AS plan_description
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.price_code = ?
             LIMIT 1",
            [$priceCode]
        );

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultsForPlan(string $planCode): array
    {
        $defaults = [
            self::PLAN_COMPASS_FREE => [
                'plan_code' => self::PLAN_COMPASS_FREE,
                'plan_name' => 'Compass Free',
                'display_name' => 'Compass Free',
                'public_summary' => 'Core workspace access with 50,000 one-time onboarding AI Credits.',
                'seat_limit' => 1,
                'included_credits' => 50000,
                'can_top_up' => false,
                'business_intelligence_enabled' => false,
                'personal_api_key_enabled' => false,
                'credit_expiry_days' => 180,
                'is_custom' => false,
                'maturity_tier' => 'free',
            ],
            self::PLAN_SOLO_LAUNCH => [
                'plan_code' => self::PLAN_SOLO_LAUNCH,
                'plan_name' => 'Solo Launch',
                'display_name' => 'Solo Launch',
                'public_summary' => 'One founder, full access, top-ups, and 1,000,000 AI Credits per billing cycle.',
                'seat_limit' => 1,
                'included_credits' => 1000000,
                'can_top_up' => true,
                'business_intelligence_enabled' => false,
                'personal_api_key_enabled' => false,
                'credit_expiry_days' => 180,
                'is_custom' => false,
                'maturity_tier' => 'solo',
            ],
            self::PLAN_FOUNDER_PLUS => [
                'plan_code' => self::PLAN_FOUNDER_PLUS,
                'plan_name' => 'Founder Plus',
                'display_name' => 'Founder Plus',
                'public_summary' => 'Three seats, Business Intelligence access, and 3,500,000 AI Credits per billing cycle.',
                'seat_limit' => 3,
                'included_credits' => 3500000,
                'can_top_up' => true,
                'business_intelligence_enabled' => true,
                'personal_api_key_enabled' => false,
                'credit_expiry_days' => 180,
                'is_custom' => false,
                'maturity_tier' => 'founder',
            ],
            self::PLAN_GROWTH_STUDIO => [
                'plan_code' => self::PLAN_GROWTH_STUDIO,
                'plan_name' => 'Growth Studio',
                'display_name' => 'Growth Studio',
                'public_summary' => 'Fifteen seats, Business Intelligence, personal API key access, and 6,000,000 AI Credits per billing cycle.',
                'seat_limit' => 15,
                'included_credits' => 6000000,
                'can_top_up' => true,
                'business_intelligence_enabled' => true,
                'personal_api_key_enabled' => true,
                'credit_expiry_days' => 180,
                'is_custom' => false,
                'maturity_tier' => 'growth',
            ],
            self::PLAN_SCALE_CUSTOM => [
                'plan_code' => self::PLAN_SCALE_CUSTOM,
                'plan_name' => 'Scale Custom',
                'display_name' => 'Scale Custom',
                'public_summary' => 'Unlimited seats by agreement, all plugins, and 20,000,000 AI Credits per billing cycle.',
                'seat_limit' => 0,
                'included_credits' => 20000000,
                'can_top_up' => true,
                'business_intelligence_enabled' => true,
                'personal_api_key_enabled' => true,
                'credit_expiry_days' => 180,
                'is_custom' => true,
                'maturity_tier' => 'scale',
            ],
        ];

        $selected = $defaults[$planCode] ?? $defaults[self::PLAN_COMPASS_FREE];
        $selected['package_exempt'] = false;
        $selected['package_exemption_reason'] = '';
        return $selected;
    }

    /**
     * @return array<string,mixed>
     */
    private function packageExemptEntitlements(): array
    {
        $entitlements = $this->defaultsForPlan(self::PLAN_SCALE_CUSTOM);
        $entitlements['plan_code'] = 'default-workspace';
        $entitlements['plan_name'] = 'Default Workspace';
        $entitlements['display_name'] = 'Default workspace package exempt';
        $entitlements['public_summary'] = DefaultWorkspacePackageExemptionService::REASON;
        $entitlements['billing_plan_price_id'] = 0;
        $entitlements['price_code'] = 'default-workspace-exempt';
        $entitlements['amount'] = 0.0;
        $entitlements['interval_unit'] = 'none';
        $entitlements['interval_count'] = 1;
        $entitlements['seat_limit'] = 0;
        $entitlements['seat_limit_label'] = 'Unlimited';
        $entitlements['included_credits'] = 0;
        $entitlements['can_top_up'] = true;
        $entitlements['business_intelligence_enabled'] = true;
        $entitlements['personal_api_key_enabled'] = true;
        $entitlements['credit_expiry_days'] = 180;
        $entitlements['is_custom'] = true;
        $entitlements['maturity_tier'] = 'scale';
        $entitlements['features'] = [
            self::FEATURE_BUSINESS_INTELLIGENCE => true,
            self::FEATURE_PERSONAL_API_KEY => true,
        ];
        $entitlements['package_exempt'] = true;
        $entitlements['package_exemption_reason'] = DefaultWorkspacePackageExemptionService::REASON;

        return $entitlements;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function scheduledChangeFromRow(array $row): ?array
    {
        $priceId = (int) ($row['scheduled_billing_plan_price_id'] ?? 0);
        if ($priceId <= 0) {
            return null;
        }

        return [
            'billing_plan_price_id' => $priceId,
            'type' => (string) ($row['scheduled_change_type'] ?? ''),
            'effective_at' => $row['scheduled_change_at'] ?? null,
            'metadata' => $this->decodeJson($row['scheduled_change_metadata_json'] ?? null),
        ];
    }

    /**
     * @return array{features:array<string,mixed>,feature_details:list<array<string,mixed>>}
     */
    private function featureValuesForPlan(int $planId): array
    {
        if ($planId <= 0
            || !Database::tableExists('billing_package_features')
            || !Database::tableExists('billing_plan_feature_values')) {
            return ['features' => [], 'feature_details' => []];
        }

        $rows = Database::query(
            "SELECT bpf.feature_key, bpf.label, bpf.category, bpf.value_type, bpf.default_value_json,
                    bpf.display_order, bpf.is_core, bpfv.value_json, bpfv.is_enabled
             FROM billing_package_features bpf
             LEFT JOIN billing_plan_feature_values bpfv
                ON bpfv.feature_id = bpf.id
               AND bpfv.plan_id = ?
             WHERE bpf.is_active = 1
             ORDER BY bpf.display_order ASC, bpf.label ASC",
            [$planId]
        );

        $features = [];
        $details = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['feature_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $value = $this->featureValueFromRow($row);
            $features[$key] = $value;
            $details[] = [
                'feature_key' => $key,
                'label' => (string) ($row['label'] ?? $key),
                'category' => (string) ($row['category'] ?? 'feature'),
                'value_type' => (string) ($row['value_type'] ?? 'boolean'),
                'value' => $value,
                'is_enabled' => !empty($row['is_enabled']),
                'is_core' => !empty($row['is_core']),
                'display_order' => (int) ($row['display_order'] ?? 0),
            ];
        }

        return ['features' => $features, 'feature_details' => $details];
    }

    /**
     * @return array{features:array<string,mixed>,feature_details:list<array<string,mixed>>}
     */
    private function featureValuesForPrice(int $billingPlanPriceId): array
    {
        if ($billingPlanPriceId <= 0
            || !Database::tableExists('billing_package_features')
            || !Database::tableExists('billing_plan_price_feature_values')) {
            return ['features' => [], 'feature_details' => []];
        }

        $rows = Database::query(
            "SELECT bpf.feature_key, bpf.label, bpf.category, bpf.value_type, bpf.default_value_json,
                    bpf.display_order, bpf.is_core, bppfv.value_json, bppfv.is_enabled
             FROM billing_plan_price_feature_values bppfv
             JOIN billing_package_features bpf ON bpf.id = bppfv.feature_id
             WHERE bppfv.billing_plan_price_id = ?
               AND bpf.is_active = 1
             ORDER BY bpf.display_order ASC, bpf.label ASC",
            [$billingPlanPriceId]
        );

        $features = [];
        $details = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['feature_key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $value = $this->featureValueFromRow($row);
            $features[$key] = $value;
            $details[] = [
                'feature_key' => $key,
                'label' => (string) ($row['label'] ?? $key),
                'category' => (string) ($row['category'] ?? 'feature'),
                'value_type' => (string) ($row['value_type'] ?? 'boolean'),
                'value' => $value,
                'is_enabled' => !empty($row['is_enabled']),
                'is_core' => !empty($row['is_core']),
                'display_order' => (int) ($row['display_order'] ?? 0),
                'override_scope' => 'price',
            ];
        }

        return ['features' => $features, 'feature_details' => $details];
    }

    /**
     * @param list<array<string,mixed>> $planDetails
     * @param list<array<string,mixed>> $priceDetails
     * @return list<array<string,mixed>>
     */
    private function mergeFeatureDetails(array $planDetails, array $priceDetails): array
    {
        $byKey = [];
        foreach (array_merge($planDetails, $priceDetails) as $detail) {
            $key = trim((string) ($detail['feature_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $byKey[$key] = $detail;
        }

        usort($byKey, static function (array $a, array $b): int {
            $orderA = (int) ($a['display_order'] ?? 0);
            $orderB = (int) ($b['display_order'] ?? 0);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return strcmp((string) ($a['label'] ?? $a['feature_key'] ?? ''), (string) ($b['label'] ?? $b['feature_key'] ?? ''));
        });

        return array_values($byKey);
    }

    /**
     * @param array<string,mixed> $row
     * @return mixed
     */
    private function featureValueFromRow(array $row)
    {
        $valueType = (string) ($row['value_type'] ?? 'boolean');
        $isEnabled = !empty($row['is_enabled']);
        if ($valueType === 'boolean') {
            return $isEnabled;
        }

        $payload = $this->decodeJson($row['value_json'] ?? null);
        if ($payload === []) {
            $payload = $this->decodeJson($row['default_value_json'] ?? null);
        }
        $value = $payload['value'] ?? null;

        return match ($valueType) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            default => (string) $value,
        };
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>
     */
    private function decodeJson($json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
