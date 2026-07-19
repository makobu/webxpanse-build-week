<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;

class PlatformWorkspaceOperationsService
{
    private SaaSBillingService $billing;
    private WorkspaceWalletService $wallets;
    private OperatorAuditService $operatorAudit;
    private WorkspaceLaunchReadinessService $readiness;

    public function __construct(
        ?SaaSBillingService $billing = null,
        ?WorkspaceWalletService $wallets = null,
        ?OperatorAuditService $operatorAudit = null,
        ?WorkspaceLaunchReadinessService $readiness = null
    ) {
        $this->billing = $billing ?? new SaaSBillingService();
        $this->wallets = $wallets ?? new WorkspaceWalletService();
        $this->operatorAudit = $operatorAudit ?? new OperatorAuditService();
        $this->readiness = $readiness ?? new WorkspaceLaunchReadinessService();
    }

    public static function isPlatformAdmin(?array $user): bool
    {
        if (!is_array($user) || $user === []) {
            return false;
        }

        return Authorization::isSuperAdmin($user);
    }

    public function getWorkspaceOperationsData(int $workspaceId): array
    {
        $workspace = $this->getWorkspaceRow($workspaceId);
        $launchStatus = $this->readiness->getWorkspaceLaunchStatus($workspaceId);
        $readiness = (array) ($launchStatus['surfaces'] ?? []);
        $billingPortal = $this->defaultBillingPortal();
        if (!empty($readiness['billing_portal']['ready'])) {
            $billingPortal = $this->billing->getWorkspaceBillingPortalData($workspaceId, 25);
        } elseif (!empty($this->readiness->checkWorkspaceSaasReadiness('billing_snapshot')['ready'])) {
            $billingPortal['snapshot'] = $this->billing->getWorkspaceSnapshot($workspaceId);
        }

        $providerEvents = !empty($readiness['billing_finalize']['ready'])
            ? $this->billing->listProviderEvents($workspaceId, 25)
            : [];
        $lastProviderFailure = null;
        foreach ($providerEvents as $providerEvent) {
            if ((string) ($providerEvent['processing_status'] ?? '') === 'failed') {
                $lastProviderFailure = $providerEvent;
                break;
            }
        }

        return [
            'workspace' => $workspace,
            'billing_portal' => $billingPortal,
            'active_subscription' => (array) ($billingPortal['snapshot']['subscription'] ?? []),
            'wallet_summary' => $this->wallets->getSummary($workspaceId),
            'provider_events' => $providerEvents,
            'last_provider_failure' => $lastProviderFailure,
            'subscription_prices' => $this->listSubscriptionPricesForOperators(),
            'token_pack_prices' => $this->listTokenPackPricesForOperators(),
            'memberships' => $this->listWorkspaceMemberships($workspaceId),
            'operator_audit' => $this->operatorAudit->listForWorkspace($workspaceId, 25),
            'saas_readiness' => $readiness,
            'launch_status' => $launchStatus,
        ];
    }

    public function updateWorkspaceLifecycle(int $workspaceId, string $action, int $actorUserId, ?string $reason = null): array
    {
        $workspace = $this->getWorkspaceRow($workspaceId);
        $action = trim($action);
        $currentStatus = (string) ($workspace['status'] ?? 'active');
        $newStatus = match ($action) {
            'suspend' => 'suspended',
            'reinstate' => 'active',
            'archive' => 'archived',
            'restore' => 'active',
            default => throw new \RuntimeException('Unsupported workspace lifecycle action.'),
        };

        Database::execute(
            "UPDATE workspaces
             SET status = ?,
                 suspended_at = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $newStatus,
                $newStatus === 'suspended' ? date('Y-m-d H:i:s') : null,
                $workspaceId,
            ]
        );

        $this->operatorAudit->log(
            'workspace_' . $action,
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'previous_status' => $currentStatus,
                'new_status' => $newStatus,
                'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            ]
        );
        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'workspace_' . $action);

        return [
            'workspace' => $this->getWorkspaceRow($workspaceId),
            'snapshot' => $this->billing->getWorkspaceSnapshot($workspaceId),
        ];
    }

    public function adjustWorkspaceWallet(int $workspaceId, int $tokenDelta, int $actorUserId, ?string $reason = null): array
    {
        if ($tokenDelta === 0) {
            throw new \RuntimeException('A non-zero token adjustment is required.');
        }

        $workspace = $this->getWorkspaceRow($workspaceId);
        $before = $this->wallets->getSummary($workspaceId);
        $referenceId = 'operator_' . $workspaceId . '_' . $actorUserId . '_' . bin2hex(random_bytes(6));
        $metadata = [
            'reason' => $reason,
            'operator_user_id' => $actorUserId,
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
        ];

        if ($tokenDelta > 0) {
            $result = $this->wallets->creditTokens(
                $workspaceId,
                $tokenDelta,
                'manual_adjustment',
                $referenceId,
                $actorUserId,
                $metadata
            );
        } else {
            $result = $this->wallets->debitTokens(
                $workspaceId,
                abs($tokenDelta),
                'manual_adjustment',
                $referenceId,
                $actorUserId,
                $metadata,
                'Manual operator wallet adjustment'
            );
        }

        $after = $this->wallets->getSummary($workspaceId);
        $this->operatorAudit->log(
            'wallet_manual_adjustment',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'token_delta' => $tokenDelta,
                'before_balance' => (int) ($before['token_balance'] ?? 0),
                'after_balance' => (int) ($after['token_balance'] ?? 0),
                'ledger_entry_id' => (int) ($result['ledger_entry_id'] ?? 0),
                'reference_id' => $referenceId,
            ]
        );

        return [
            'before' => $before,
            'after' => $after,
            'result' => $result,
        ];
    }

    public function updateSubscriptionPrice(
        int $billingPlanPriceId,
        float $amount,
        string $currency,
        int $includedTokens,
        string $intervalUnit,
        int $intervalCount,
        bool $isActive,
        bool $isDefault,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadSubscriptionPrice($billingPlanPriceId, false);
        $amount = $this->normalizeAmount($amount, true);
        $currency = $this->normalizeCurrency($currency);
        $includedTokens = max(0, $includedTokens);
        $intervalUnit = $this->normalizeIntervalUnit($intervalUnit, false);
        $intervalCount = max(1, $intervalCount);
        $this->assertUniqueSubscriptionCadence((int) ($before['plan_id'] ?? 0), $intervalUnit, $intervalCount, $billingPlanPriceId);

        Database::beginTransaction();
        try {
            if ($isDefault) {
                Database::execute(
                    "UPDATE billing_plan_prices bpp
                     JOIN billing_plans bp ON bp.id = bpp.plan_id
                     SET bpp.is_default = 0
                     WHERE bp.billing_type = 'subscription'"
                );
            }

            Database::execute(
                "UPDATE billing_plan_prices
                 SET amount = ?,
                     currency = ?,
                     included_tokens = ?,
                     interval_unit = ?,
                     interval_count = ?,
                     is_active = ?,
                     is_default = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$amount, $currency, $includedTokens, $intervalUnit, $intervalCount, $isActive ? 1 : 0, $isDefault ? 1 : 0, $billingPlanPriceId]
            );

            $after = $this->loadSubscriptionPrice($billingPlanPriceId, false);
            $this->operatorAudit->log(
                'subscription_price_updated',
                $actorUserId,
                null,
                $reason,
                [
                    'billing_plan_price_id' => $billingPlanPriceId,
                    'plan_code' => (string) ($after['plan_code'] ?? $before['plan_code'] ?? ''),
                    'before' => $this->auditPriceSnapshot($before),
                    'after' => $this->auditPriceSnapshot($after),
                ]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    public function updateSubscriptionPlanCommercials(
        int $billingPlanPriceId,
        float $amount,
        string $currency,
        int $includedCredits,
        string $intervalUnit,
        int $intervalCount,
        int $seatLimit,
        bool $canTopUp,
        bool $businessIntelligenceEnabled,
        bool $personalApiKeyEnabled,
        int $creditExpiryDays,
        bool $isCustom,
        string $maturityTier,
        string $displayName,
        string $displayCopy,
        int $displayOrder,
        bool $isActive,
        bool $isDefault,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadSubscriptionPrice($billingPlanPriceId, false);
        $amount = $this->normalizeAmount($amount, true);
        $currency = $this->normalizeCurrency($currency);
        $includedCredits = max(0, $includedCredits);
        $intervalUnit = $this->normalizeIntervalUnit($intervalUnit, false);
        $intervalCount = max(1, $intervalCount);
        $this->assertUniqueSubscriptionCadence((int) ($before['plan_id'] ?? 0), $intervalUnit, $intervalCount, $billingPlanPriceId);
        $seatLimit = max(0, $seatLimit);
        $creditExpiryDays = max(1, $creditExpiryDays);
        $maturityTier = trim($maturityTier) !== '' ? trim($maturityTier) : 'custom';
        $displayName = trim($displayName) !== '' ? trim($displayName) : (string) ($before['plan_name'] ?? '');
        $displayCopy = trim($displayCopy);
        $metadata = $this->decodeMetadata($before['metadata_json'] ?? null);
        $metadata['display_name'] = $displayName;
        $metadata['display_copy'] = $displayCopy;
        $metadata['public_summary'] = $displayCopy;
        $metadata['display_order'] = $displayOrder;
        $metadata['credits_label'] = 'AI Credits';
        $metadata['entitlements'] = array_merge((array) ($metadata['entitlements'] ?? []), [
            'seat_limit' => $seatLimit,
            'included_credits' => $includedCredits,
            'can_top_up' => $canTopUp,
            'business_intelligence_enabled' => $businessIntelligenceEnabled,
            'personal_api_key_enabled' => $personalApiKeyEnabled,
            'credit_expiry_days' => $creditExpiryDays,
            'is_custom' => $isCustom,
            'maturity_tier' => $maturityTier,
            'display_name' => $displayName,
            'public_summary' => $displayCopy,
            'public_display_name' => $displayName,
            'public_display_copy' => $displayCopy,
        ]);

        Database::beginTransaction();
        try {
            if ($isDefault) {
                Database::execute(
                    "UPDATE billing_plan_prices bpp
                     JOIN billing_plans bp ON bp.id = bpp.plan_id
                     SET bpp.is_default = 0
                     WHERE bp.billing_type = 'subscription'"
                );
            }

            Database::execute(
                "UPDATE billing_plans
                 SET description = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $displayCopy !== '' ? $displayCopy : (string) ($before['description'] ?? ''),
                    (int) ($before['plan_id'] ?? 0),
                ]
            );
            Database::execute(
                "UPDATE billing_plan_prices
                 SET amount = ?,
                     currency = ?,
                     included_tokens = ?,
                     interval_unit = ?,
                     interval_count = ?,
                     metadata_json = ?,
                     is_active = ?,
                     is_default = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $amount,
                    $currency,
                    $includedCredits,
                    $intervalUnit,
                    $intervalCount,
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $isActive ? 1 : 0,
                    $isDefault ? 1 : 0,
                    $billingPlanPriceId,
                ]
            );
            $this->savePackageFeatureValuesInternal((int) ($before['plan_id'] ?? 0), [
                'seat_limit' => $seatLimit,
                'can_top_up' => $canTopUp,
                'business_intelligence' => $businessIntelligenceEnabled,
                'personal_api_key' => $personalApiKeyEnabled,
                'credit_expiry_days' => $creditExpiryDays,
            ]);

            $after = $this->loadSubscriptionPrice($billingPlanPriceId, false);
            $this->operatorAudit->log(
                'subscription_plan_commercials_updated',
                $actorUserId,
                null,
                $reason,
                [
                    'billing_plan_price_id' => $billingPlanPriceId,
                    'plan_code' => (string) ($after['plan_code'] ?? $before['plan_code'] ?? ''),
                    'before' => $this->auditPriceSnapshot($before),
                    'after' => $this->auditPriceSnapshot($after),
                    'before_metadata' => $this->decodeMetadata($before['metadata_json'] ?? null),
                    'after_metadata' => $this->decodeMetadata($after['metadata_json'] ?? null),
                ]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    public function updateTokenPackPrice(
        int $tokenPackPriceId,
        float $amount,
        string $currency,
        int $tokenQuantity,
        int $sortOrder,
        bool $isActive,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadTokenPack($tokenPackPriceId, false);
        $amount = $this->normalizeAmount($amount);
        $currency = $this->normalizeCurrency($currency);
        if ($tokenQuantity <= 0) {
            throw new \RuntimeException('Token quantity must be greater than zero.');
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE billing_plan_prices
                 SET amount = ?,
                     currency = ?,
                     interval_unit = 'one_time',
                     interval_count = 1,
                     included_tokens = ?,
                     is_active = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$amount, $currency, $tokenQuantity, $isActive ? 1 : 0, (int) $before['billing_plan_price_id']]
            );

            Database::execute(
                "UPDATE token_pack_prices
                 SET token_quantity = ?,
                     sort_order = ?,
                     is_active = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$tokenQuantity, $sortOrder, $isActive ? 1 : 0, $tokenPackPriceId]
            );

            $after = $this->loadTokenPack($tokenPackPriceId, false);
            $this->operatorAudit->log(
                'token_pack_price_updated',
                $actorUserId,
                null,
                $reason,
                [
                    'token_pack_price_id' => $tokenPackPriceId,
                    'billing_plan_price_id' => (int) ($after['billing_plan_price_id'] ?? 0),
                    'plan_code' => (string) ($after['plan_code'] ?? $before['plan_code'] ?? ''),
                    'before' => $this->auditTokenPackSnapshot($before),
                    'after' => $this->auditTokenPackSnapshot($after),
                ]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    /**
     * @param array<string,mixed> $analyticsFilters
     * @return array<string,mixed>
     */
    public function listBillingCatalogForOperators(array $analyticsFilters = [], array $negotiatedFilters = []): array
    {
        $entitlements = new WorkspacePlanEntitlementService();
        $paymentReadiness = $this->billing->catalogPaymentReadiness();
        $negotiatedService = Database::tableExists('workspace_negotiated_package_offers')
            ? new WorkspaceNegotiatedPackageService()
            : null;
        $subscriptionPrices = array_map(function (array $row) use ($entitlements): array {
            $row['metadata'] = $this->decodeMetadata($row['metadata_json'] ?? null);
            $row['entitlements'] = $entitlements->entitlementsForPriceRow($row);
            $paymentModes = $this->paymentMethodsForPrice((int) ($row['id'] ?? 0));
            $row['allowed_payment_modes'] = $paymentModes;
            $row['payment_modes_scope'] = $paymentModes === [] ? 'all' : 'custom';
            return $row;
        }, $this->listSubscriptionPricesForOperators());
        $subscriptionPrices = array_map(fn(array $row): array => $this->decorateOperatorPaymentAvailability($row, $paymentReadiness), $subscriptionPrices);

        $tokenPacks = array_map(function (array $row): array {
            $metadata = $this->decodeMetadata($row['metadata_json'] ?? null);
            $row['metadata'] = $metadata;
            $row['credit_quantity'] = (int) ($row['token_quantity'] ?? $row['included_tokens'] ?? 0);
            $row['display_name'] = (string) ($metadata['display_name'] ?? $row['plan_name'] ?? 'AI Credit Pack');
            $row['credit_expiry_days'] = (int) ($metadata['credit_expiry_days'] ?? 180);
            $paymentModes = $this->paymentMethodsForPrice((int) ($row['billing_plan_price_id'] ?? 0));
            $row['allowed_payment_modes'] = $paymentModes;
            $row['payment_modes_scope'] = $paymentModes === [] ? 'all' : 'custom';
            return $row;
        }, $this->listTokenPackPricesForOperators());
        $tokenPacks = array_map(fn(array $row): array => $this->decorateOperatorPaymentAvailability($row, $paymentReadiness), $tokenPacks);

        $audit = [];
        try {
            $audit = Database::query(
                "SELECT oal.*, actor.email AS actor_email
                 FROM operator_audit_log oal
                 LEFT JOIN users actor ON actor.id = oal.actor_user_id
                 WHERE oal.action_type IN (
                    'subscription_price_updated',
                    'subscription_plan_commercials_updated',
                    'subscription_package_created',
                    'subscription_package_updated',
                    'subscription_package_deleted',
                    'subscription_package_archived',
                    'subscription_price_created',
                    'subscription_price_deleted',
                    'subscription_price_archived',
                    'subscription_price_payment_methods_updated',
                    'billing_feature_catalog_saved',
                    'billing_plan_feature_values_saved',
                    'token_pack_created',
                    'token_pack_deleted',
                    'token_pack_archived',
                    'token_pack_price_updated',
                    'billing_payment_methods_updated',
                    'workspace_negotiated_offer_created',
                    'workspace_negotiated_offer_updated',
                    'workspace_negotiated_offer_offered',
                    'workspace_negotiated_offer_active',
                    'workspace_negotiated_offer_archived',
                    'workspace_negotiated_offer_activated',
                    'wallet_manual_adjustment',
                    'workspace_subscription_changed',
                    'workspace_downgrade_scheduled'
                 )
                 ORDER BY oal.id DESC
                 LIMIT 80"
            );
        } catch (\Throwable $e) {
            $audit = [];
        }

        return [
            'subscription_prices' => $subscriptionPrices,
            'subscription_packages' => $this->groupSubscriptionPackages($subscriptionPrices),
            'negotiated_offers' => $negotiatedService
                ? $negotiatedService->listWorkspaceOffers(null, 200, $negotiatedFilters)
                : [],
            'negotiated_workspace_summaries' => $negotiatedService
                ? $negotiatedService->listWorkspaceOfferSummaries($negotiatedFilters)
                : [],
            'negotiated_offer_metrics' => $negotiatedService
                ? $negotiatedService->negotiatedOfferMetrics()
                : [],
            'workspaces' => $this->listWorkspacesForPackageSettings(),
            'token_pack_prices' => $tokenPacks,
            'feature_catalog' => $this->listFeatureCatalog(),
            'catalog_health' => $this->listCatalogHealth(),
            'package_analytics' => $this->listPackageAnalytics($analyticsFilters),
            'operator_audit' => $audit,
            'payment_readiness' => $paymentReadiness,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createSubscriptionPackage(array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $code = $this->normalizeCatalogCode((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Package name is required.');
        }
        if ($code === '') {
            $code = $this->normalizeCatalogCode($name);
        }
        $this->assertUniqueBillingPlanCode($code);
        $description = trim((string) ($input['description'] ?? ''));

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO billing_plans (code, name, description, billing_type, is_active)
                 VALUES (?, ?, ?, 'subscription', ?)",
                [$code, $name, $description, array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1]
            );
            $planId = (int) Database::lastInsertId();
            $price = $this->insertSubscriptionPrice($planId, $input, $name, $description);
            if (!empty($input['features']) && is_array($input['features'])) {
                $this->savePackageFeatureValuesInternal($planId, (array) $input['features']);
            }
            if (array_key_exists('payment_modes', $input)) {
                $modes = is_array($input['payment_modes']) ? (array) $input['payment_modes'] : null;
                $this->savePricePaymentMethodsInternal((int) ($price['id'] ?? 0), $modes);
            }
            $after = $this->loadSubscriptionPackage($planId);
            $this->operatorAudit->log(
                'subscription_package_created',
                $actorUserId,
                null,
                $reason,
                ['plan' => $after, 'initial_price' => $this->auditPriceSnapshot($price)]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateSubscriptionPackage(int $planId, array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadSubscriptionPackage($planId);
        $name = trim((string) ($input['name'] ?? $before['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Package name is required.');
        }
        $description = trim((string) ($input['description'] ?? $before['description'] ?? ''));
        $isActive = array_key_exists('is_active', $input) ? !empty($input['is_active']) : !empty($before['is_active']);

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE billing_plans
                 SET name = ?,
                     description = ?,
                     is_active = ?,
                     updated_at = NOW()
                 WHERE id = ? AND billing_type = 'subscription'",
                [$name, $description, $isActive ? 1 : 0, $planId]
            );
            $this->syncPlanDisplayMetadata($planId, $name, $description, (int) ($input['display_order'] ?? 0));
            if (!empty($input['features']) && is_array($input['features'])) {
                $this->savePackageFeatureValuesInternal($planId, (array) $input['features']);
            }
            $after = $this->loadSubscriptionPackage($planId);
            $this->operatorAudit->log(
                'subscription_package_updated',
                $actorUserId,
                null,
                $reason,
                ['before' => $before, 'after' => $after]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    /**
     * @return array<string,mixed>
     */
    public function cloneSubscriptionPackage(int $planId, string $newCode, string $newName, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $source = $this->loadSubscriptionPackage($planId);
        $newCode = $this->normalizeCatalogCode($newCode !== '' ? $newCode : $newName);
        $newName = trim($newName) !== '' ? trim($newName) : (string) ($source['name'] ?? 'Package copy');
        if ($newCode === '') {
            throw new \RuntimeException('Package code is required.');
        }
        $this->assertUniqueBillingPlanCode($newCode);

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO billing_plans (code, name, description, billing_type, is_active)
                 VALUES (?, ?, ?, 'subscription', 0)",
                [$newCode, $newName, (string) ($source['description'] ?? '')]
            );
            $newPlanId = (int) Database::lastInsertId();
            $this->savePackageFeatureValuesInternal($newPlanId, $this->listPackageFeatureValues($planId));

            foreach (Database::query("SELECT * FROM billing_plan_prices WHERE plan_id = ? ORDER BY id ASC", [$planId]) as $sourcePrice) {
                $metadata = $this->decodeMetadata($sourcePrice['metadata_json'] ?? null);
                $metadata['display_name'] = $newName;
                $metadata['public_display_name'] = $newName;
                $metadata['entitlements'] = array_merge((array) ($metadata['entitlements'] ?? []), [
                    'display_name' => $newName,
                    'public_display_name' => $newName,
                ]);
                $metadata['lifecycle'] = array_merge((array) ($metadata['lifecycle'] ?? []), [
                    'cloned_from_plan_id' => $planId,
                    'cloned_from_price_id' => (int) ($sourcePrice['id'] ?? 0),
                    'cloned_at' => date('c'),
                    'cloned_by' => $actorUserId,
                    'clone_status' => 'draft_inactive',
                ]);
                $priceCode = $this->uniquePriceCode($newCode . '-' . (string) ($sourcePrice['interval_unit'] ?? 'price'));
                Database::execute(
                    "INSERT INTO billing_plan_prices
                        (plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens,
                         metadata_json, provider, provider_plan_code, provider_plan_id, provider_plan_status,
                         provider_plan_synced_at, is_default, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, 0, 0)",
                    [
                        $newPlanId,
                        $priceCode,
                        (string) ($sourcePrice['currency'] ?? 'KES'),
                        (string) ($sourcePrice['interval_unit'] ?? 'monthly'),
                        max(1, (int) ($sourcePrice['interval_count'] ?? 1)),
                        (float) ($sourcePrice['amount'] ?? 0),
                        max(0, (int) ($sourcePrice['included_tokens'] ?? 0)),
                        json_encode($metadata, JSON_UNESCAPED_SLASHES),
                        (string) ($sourcePrice['provider'] ?? 'paystack'),
                    ]
                );
                $newPriceId = (int) Database::lastInsertId();
                $this->savePricePaymentMethodsInternal($newPriceId, $this->paymentMethodsForPrice((int) ($sourcePrice['id'] ?? 0)));
            }

            $this->operatorAudit->log(
                'subscription_package_cloned',
                $actorUserId,
                null,
                $reason,
                ['source_plan_id' => $planId, 'new_plan_id' => $newPlanId, 'new_code' => $newCode]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->loadSubscriptionPackage($newPlanId);
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteSubscriptionPackage(int $planId, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadSubscriptionPackage($planId);
        $references = $this->referenceCountsForPlan($planId);
        $hardDelete = array_sum($references) === 0 && empty($before['is_active']) && !$this->planHasActivePrices($planId);

        Database::beginTransaction();
        try {
            if ($hardDelete) {
                foreach (Database::query("SELECT id FROM billing_plan_prices WHERE plan_id = ?", [$planId]) as $price) {
                    Database::execute("DELETE FROM billing_plan_price_payment_methods WHERE billing_plan_price_id = ?", [(int) $price['id']]);
                    if (Database::tableExists('billing_plan_price_feature_values')) {
                        Database::execute("DELETE FROM billing_plan_price_feature_values WHERE billing_plan_price_id = ?", [(int) $price['id']]);
                    }
                }
                Database::execute("DELETE FROM billing_plan_feature_values WHERE plan_id = ?", [$planId]);
                Database::execute("DELETE FROM billing_plan_prices WHERE plan_id = ?", [$planId]);
                Database::execute("DELETE FROM billing_plans WHERE id = ? AND billing_type = 'subscription'", [$planId]);
                $action = 'subscription_package_deleted';
            } else {
                Database::execute("UPDATE billing_plans SET is_active = 0, updated_at = NOW() WHERE id = ?", [$planId]);
                Database::execute("UPDATE billing_plan_prices SET is_active = 0, is_default = 0, updated_at = NOW() WHERE plan_id = ?", [$planId]);
                $this->archivePriceMetadataForPlan($planId, $actorUserId, $reason);
                $action = 'subscription_package_archived';
            }
            $this->operatorAudit->log(
                $action,
                $actorUserId,
                null,
                $reason,
                ['plan' => $before, 'reference_counts' => $references, 'hard_delete' => $hardDelete]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['hard_deleted' => $hardDelete, 'archived' => !$hardDelete, 'reference_counts' => $references];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createSubscriptionPrice(int $planId, array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $plan = $this->loadSubscriptionPackage($planId);

        Database::beginTransaction();
        try {
            $price = $this->insertSubscriptionPrice($planId, $input, (string) ($plan['name'] ?? ''), (string) ($plan['description'] ?? ''));
            if (array_key_exists('payment_modes', $input)) {
                $modes = is_array($input['payment_modes']) ? (array) $input['payment_modes'] : null;
                $this->savePricePaymentMethodsInternal((int) ($price['id'] ?? 0), $modes);
            }
            $this->operatorAudit->log(
                'subscription_price_created',
                $actorUserId,
                null,
                $reason,
                ['plan_id' => $planId, 'price' => $this->auditPriceSnapshot($price)]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $price;
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteSubscriptionPrice(int $billingPlanPriceId, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadSubscriptionPrice($billingPlanPriceId, false);
        $references = $this->referenceCountsForPrice($billingPlanPriceId);
        $hardDelete = array_sum($references) === 0 && empty($before['is_active']);

        Database::beginTransaction();
        try {
            if ($hardDelete) {
                Database::execute("DELETE FROM billing_plan_price_payment_methods WHERE billing_plan_price_id = ?", [$billingPlanPriceId]);
                if (Database::tableExists('billing_plan_price_feature_values')) {
                    Database::execute("DELETE FROM billing_plan_price_feature_values WHERE billing_plan_price_id = ?", [$billingPlanPriceId]);
                }
                Database::execute("DELETE FROM billing_plan_prices WHERE id = ?", [$billingPlanPriceId]);
                $action = 'subscription_price_deleted';
            } else {
                Database::execute(
                    "UPDATE billing_plan_prices
                     SET is_active = 0,
                         is_default = 0,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$billingPlanPriceId]
                );
                $this->archivePriceMetadata($billingPlanPriceId, $actorUserId, $reason);
                $action = 'subscription_price_archived';
            }
            if (!$this->planHasActivePrices((int) ($before['plan_id'] ?? 0))) {
                Database::execute("UPDATE billing_plans SET is_active = 0, updated_at = NOW() WHERE id = ?", [(int) ($before['plan_id'] ?? 0)]);
            }
            $this->operatorAudit->log(
                $action,
                $actorUserId,
                null,
                $reason,
                ['price' => $this->auditPriceSnapshot($before), 'reference_counts' => $references, 'hard_delete' => $hardDelete]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['hard_deleted' => $hardDelete, 'archived' => !$hardDelete, 'reference_counts' => $references];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createTokenPack(array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $name = trim((string) ($input['name'] ?? $input['display_name'] ?? 'AI Credit Pack'));
        $code = $this->normalizeCatalogCode((string) ($input['code'] ?? $name));
        if ($code === '') {
            throw new \RuntimeException('Token pack code is required.');
        }
        $this->assertUniqueBillingPlanCode($code);
        $credits = max(1, (int) ($input['token_quantity'] ?? $input['credit_quantity'] ?? $input['included_credits'] ?? 0));
        $amount = $this->normalizeAmount((float) ($input['amount'] ?? 0));
        $currency = $this->normalizeCurrency((string) ($input['currency'] ?? 'KES'));
        $sortOrder = (int) ($input['sort_order'] ?? 0);
        $isActive = array_key_exists('is_active', $input) ? !empty($input['is_active']) : true;

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO billing_plans (code, name, description, billing_type, is_active)
                 VALUES (?, ?, ?, 'token_pack', ?)",
                [$code, $name, trim((string) ($input['description'] ?? 'One-time AI Credit top-up pack.')), $isActive ? 1 : 0]
            );
            $planId = (int) Database::lastInsertId();
            $metadata = [
                'credit_pack' => true,
                'display_name' => $name,
                'credit_expiry_days' => max(1, (int) ($input['credit_expiry_days'] ?? 180)),
                'price_per_credit' => $credits > 0 ? $amount / $credits : 0,
            ];
            Database::execute(
                "INSERT INTO billing_plan_prices
                    (plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens, metadata_json, provider, is_default, is_active)
                 VALUES (?, ?, ?, 'one_time', 1, ?, ?, ?, 'paystack', 0, ?)",
                [$planId, $code, $currency, $amount, $credits, json_encode($metadata, JSON_UNESCAPED_SLASHES), $isActive ? 1 : 0]
            );
            $priceId = (int) Database::lastInsertId();
            Database::execute(
                "INSERT INTO token_pack_prices (billing_plan_price_id, token_quantity, sort_order, is_active)
                 VALUES (?, ?, ?, ?)",
                [$priceId, $credits, $sortOrder, $isActive ? 1 : 0]
            );
            $packId = (int) Database::lastInsertId();
            if (array_key_exists('payment_modes', $input)) {
                $modes = is_array($input['payment_modes']) ? (array) $input['payment_modes'] : null;
                $this->savePricePaymentMethodsInternal($priceId, $modes);
            }
            $pack = $this->loadTokenPack($packId, false);
            $this->operatorAudit->log(
                'token_pack_created',
                $actorUserId,
                null,
                $reason,
                ['token_pack' => $this->auditTokenPackSnapshot($pack)]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $pack;
    }

    /**
     * @return array<string,mixed>
     */
    public function deleteTokenPack(int $tokenPackPriceId, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadTokenPack($tokenPackPriceId, false);
        $references = $this->referenceCountsForPack($tokenPackPriceId);
        $hardDelete = array_sum($references) === 0 && empty($before['is_active']);

        Database::beginTransaction();
        try {
            if ($hardDelete) {
                $planId = (int) (Database::queryOne(
                    "SELECT plan_id FROM billing_plan_prices WHERE id = ? LIMIT 1",
                    [(int) $before['billing_plan_price_id']]
                )['plan_id'] ?? 0);
                Database::execute("DELETE FROM billing_plan_price_payment_methods WHERE billing_plan_price_id = ?", [(int) $before['billing_plan_price_id']]);
                Database::execute("DELETE FROM token_pack_prices WHERE id = ?", [$tokenPackPriceId]);
                Database::execute("DELETE FROM billing_plan_prices WHERE id = ?", [(int) $before['billing_plan_price_id']]);
                if ($planId > 0) {
                    Database::execute("DELETE FROM billing_plans WHERE id = ? AND billing_type = 'token_pack'", [$planId]);
                }
                $action = 'token_pack_deleted';
            } else {
                Database::execute("UPDATE token_pack_prices SET is_active = 0, updated_at = NOW() WHERE id = ?", [$tokenPackPriceId]);
                Database::execute("UPDATE billing_plan_prices SET is_active = 0, updated_at = NOW() WHERE id = ?", [(int) $before['billing_plan_price_id']]);
                Database::execute("UPDATE billing_plans SET is_active = 0, updated_at = NOW() WHERE code = ?", [(string) ($before['plan_code'] ?? '')]);
                $this->archivePriceMetadata((int) $before['billing_plan_price_id'], $actorUserId, $reason);
                $action = 'token_pack_archived';
            }
            $this->operatorAudit->log(
                $action,
                $actorUserId,
                null,
                $reason,
                ['token_pack' => $this->auditTokenPackSnapshot($before), 'reference_counts' => $references, 'hard_delete' => $hardDelete]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return ['hard_deleted' => $hardDelete, 'archived' => !$hardDelete, 'reference_counts' => $references];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveFeatureCatalog(array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $featureKey = $this->normalizeCatalogCode((string) ($input['feature_key'] ?? ''));
        $featureKey = str_replace('-', '_', $featureKey);
        if ($featureKey === '') {
            throw new \RuntimeException('Feature key is required.');
        }
        $label = trim((string) ($input['label'] ?? $featureKey));
        $valueType = (string) ($input['value_type'] ?? 'boolean');
        if (!in_array($valueType, ['boolean', 'integer', 'decimal', 'text'], true)) {
            throw new \RuntimeException('Choose a valid feature value type.');
        }
        $defaultValue = $this->normalizeFeatureInputValue($valueType, $input['default_value'] ?? null);
        $isActive = array_key_exists('is_active', $input) ? !empty($input['is_active']) : true;
        $existingFeature = Database::queryOne(
            "SELECT feature_key, value_type, is_core
             FROM billing_package_features
             WHERE feature_key = ?
             LIMIT 1",
            [$featureKey]
        );
        if ($existingFeature && !empty($existingFeature['is_core'])) {
            if (!$isActive) {
                throw new \RuntimeException('Core package features cannot be disabled.');
            }
            if ((string) ($existingFeature['value_type'] ?? '') !== $valueType) {
                throw new \RuntimeException('Core package feature value types cannot be changed.');
            }
        }

        Database::execute(
            "INSERT INTO billing_package_features
                (feature_key, label, description, category, value_type, default_value_json, is_core, is_active, display_order)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                description = VALUES(description),
                category = VALUES(category),
                value_type = VALUES(value_type),
                default_value_json = VALUES(default_value_json),
                is_active = VALUES(is_active),
                display_order = VALUES(display_order),
                updated_at = NOW()",
            [
                $featureKey,
                $label,
                trim((string) ($input['description'] ?? '')),
                trim((string) ($input['category'] ?? 'feature')) ?: 'feature',
                $valueType,
                json_encode(['value' => $defaultValue], JSON_UNESCAPED_SLASHES),
                $isActive ? 1 : 0,
                (int) ($input['display_order'] ?? 0),
            ]
        );

        $this->operatorAudit->log(
            'billing_feature_catalog_saved',
            $actorUserId,
            null,
            $reason,
            ['feature_key' => $featureKey, 'label' => $label, 'value_type' => $valueType, 'is_active' => $isActive]
        );

        return $this->listFeatureCatalog();
    }

    /**
     * @param array<string,mixed> $featureValues
     * @return array<string,mixed>
     */
    public function savePackageFeatureValues(int $planId, array $featureValues, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $plan = $this->loadSubscriptionPackage($planId);

        Database::beginTransaction();
        try {
            $this->savePackageFeatureValuesInternal($planId, $featureValues);
            $this->operatorAudit->log(
                'billing_plan_feature_values_saved',
                $actorUserId,
                null,
                $reason,
                ['plan_id' => $planId, 'plan_code' => (string) ($plan['code'] ?? ''), 'features' => $featureValues]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->loadSubscriptionPackage($planId);
    }

    /**
     * @param list<string>|null $modes
     * @return list<string>
     */
    public function savePricePaymentMethods(int $billingPlanPriceId, ?array $modes, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $price = $this->loadAnyBillingPrice($billingPlanPriceId);
        $saved = $this->savePricePaymentMethodsInternal($billingPlanPriceId, $modes);
        $this->operatorAudit->log(
            'subscription_price_payment_methods_updated',
            $actorUserId,
            null,
            $reason,
            ['billing_plan_price_id' => $billingPlanPriceId, 'price_code' => (string) ($price['price_code'] ?? ''), 'allowed_payment_modes' => $saved]
        );

        return $saved;
    }

    public function activateWorkspaceSubscriptionPlan(
        int $workspaceId,
        int $billingPlanPriceId,
        int $actorUserId,
        ?string $reason = null
    ): array {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $workspace = $this->getWorkspaceRow($workspaceId);
        $price = $this->loadSubscriptionPrice($billingPlanPriceId, true);
        $priceWorkspaceId = (int) ($price['workspace_id'] ?? 0);
        if ($priceWorkspaceId > 0 && $priceWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('This private negotiated package belongs to another workspace.');
        }
        $entitlements = (new WorkspacePlanEntitlementService())->entitlementsForPriceRow($price);
        $now = date('Y-m-d H:i:s');
        $nowTimestamp = strtotime($now) ?: time();
        $periodStart = $now;
        $periodEnd = $this->calculatePeriodEnd($periodStart, (string) ($price['interval_unit'] ?? 'monthly'), (int) ($price['interval_count'] ?? 1));
        $before = Database::queryOne(
            "SELECT *
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY FIELD(subscription_status, 'active', 'trialing', 'past_due', 'cancelled', 'expired'), id DESC
             LIMIT 1",
            [$workspaceId]
        );

        Database::beginTransaction();
        try {
            $activationChanged = true;
            $creditResult = [];
            $billingInvoice = null;
            $existing = Database::queryOne(
                "SELECT *
                 FROM workspace_subscriptions
                 WHERE workspace_id = ?
                 ORDER BY FIELD(subscription_status, 'active', 'trialing', 'past_due', 'cancelled', 'expired'), id DESC
                 LIMIT 1
                 FOR UPDATE",
                [$workspaceId]
            );

            $existingPeriodEnd = trim((string) ($existing['current_period_end'] ?? ''));
            $existingPeriodEndTimestamp = $existingPeriodEnd !== '' ? strtotime($existingPeriodEnd) : false;
            $sameActiveCurrentPeriod = $existing
                && (int) ($existing['billing_plan_price_id'] ?? 0) === (int) $price['id']
                && (string) ($existing['subscription_status'] ?? '') === 'active'
                && $existingPeriodEndTimestamp !== false
                && $existingPeriodEndTimestamp > $nowTimestamp;

            if ($sameActiveCurrentPeriod) {
                $subscriptionId = (int) $existing['id'];
                $periodStart = trim((string) ($existing['current_period_start'] ?? '')) !== ''
                    ? (string) $existing['current_period_start']
                    : $periodStart;
                $periodEnd = $existingPeriodEnd;
                $activationChanged = false;
                $creditResult = [
                    'ledger_entry_id' => 0,
                    'credit_lot_id' => 0,
                    'credited_tokens' => 0,
                    'credited_credits' => 0,
                    'credit_grant_status' => 'duplicate_active_period',
                ];
            } elseif ($existing) {
                Database::execute(
                    "UPDATE workspace_subscriptions
                     SET billing_plan_price_id = ?,
                         provider = 'operator',
                         provider_subscription_status = 'active',
                         renewal_status = 'manual',
                         subscription_status = 'active',
                         current_period_start = ?,
                         current_period_end = ?,
                         trial_starts_at = NULL,
                         trial_ends_at = NULL,
                         next_billing_at = ?,
                         scheduled_billing_plan_price_id = NULL,
                         scheduled_change_type = NULL,
                         scheduled_change_at = NULL,
                         scheduled_change_metadata_json = NULL,
                         updated_at = NOW()
                     WHERE id = ?",
                    [(int) $price['id'], $periodStart, $periodEnd, $periodEnd, (int) $existing['id']]
                );
                $subscriptionId = (int) $existing['id'];
            } else {
                Database::execute(
                    "INSERT INTO workspace_subscriptions
                     (workspace_id, billing_plan_price_id, provider, provider_subscription_status, renewal_status,
                      subscription_status, current_period_start, current_period_end, next_billing_at, created_by)
                     VALUES (?, ?, 'operator', 'active', 'manual', 'active', ?, ?, ?, ?)",
                    [$workspaceId, (int) $price['id'], $periodStart, $periodEnd, $periodEnd, $actorUserId]
                );
                $subscriptionId = (int) Database::lastInsertId();
            }

            if ($activationChanged) {
                Database::execute(
                    "UPDATE workspaces
                     SET plan_status = 'active',
                         status = CASE WHEN status IN ('suspended', 'archived') THEN status ELSE 'active' END,
                         trial_starts_at = NULL,
                         trial_ends_at = NULL,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$workspaceId]
                );

                $creditResult = $this->grantOperatorPeriodCredits($workspaceId, $subscriptionId, $price, $periodStart, $periodEnd, $actorUserId, $reason);
                $this->recordOperatorSubscriptionCycle($workspaceId, $subscriptionId, $price, $periodStart, $periodEnd, (int) ($creditResult['credit_lot_id'] ?? 0), [
                    'source' => 'operator_plan_activation',
                    'operator_user_id' => $actorUserId,
                    'reason' => $reason,
                    'credit_grant_status' => (string) ($creditResult['credit_grant_status'] ?? ''),
                ]);
                $billingInvoice = (new WorkspacePackageBillingInvoiceService())->issueForOperatorActivation(
                    $workspaceId,
                    $subscriptionId,
                    (int) $price['id'],
                    $actorUserId,
                    null,
                    $reason
                );
            }
            $after = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE id = ? LIMIT 1", [$subscriptionId]);
            $this->operatorAudit->log(
                $activationChanged ? 'workspace_subscription_changed' : 'workspace_subscription_activation_skipped',
                $actorUserId,
                $workspaceId,
                $reason,
                [
                    'workspace_slug' => (string) ($workspace['slug'] ?? ''),
                    'billing_plan_price_id' => (int) $price['id'],
                    'plan_code' => (string) ($price['plan_code'] ?? ''),
                    'included_credits' => (int) ($entitlements['included_credits'] ?? $price['included_tokens'] ?? 0),
                    'credit_lot_id' => (int) ($creditResult['credit_lot_id'] ?? 0),
                    'credit_grant_status' => (string) ($creditResult['credit_grant_status'] ?? ''),
                    'changed' => $activationChanged,
                    'before' => $this->auditSubscriptionSnapshot($before),
                    'after' => $this->auditSubscriptionSnapshot($after),
                ]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'workspace' => $this->getWorkspaceRow($workspaceId),
            'snapshot' => $this->billing->getWorkspaceSnapshot($workspaceId),
            'subscription' => Database::queryOne("SELECT * FROM workspace_subscriptions WHERE id = ? LIMIT 1", [$subscriptionId]) ?? [],
            'credit_result' => $creditResult,
            'billing_invoice' => (array) ($billingInvoice['summary'] ?? []),
            'billing_invoice_id' => (int) ($billingInvoice['id'] ?? 0),
            'changed' => $activationChanged,
        ];
    }

    public function scheduleWorkspaceDowngrade(
        int $workspaceId,
        int $billingPlanPriceId,
        int $actorUserId,
        ?string $reason = null,
        bool $overrideSeatLimit = false
    ): array {
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $workspace = $this->getWorkspaceRow($workspaceId);
        $price = $this->loadSubscriptionPrice($billingPlanPriceId, true);
        $entitlements = (new WorkspacePlanEntitlementService())->entitlementsForPriceRow($price);
        $seatLimit = (int) ($entitlements['seat_limit'] ?? 0);
        $seatFootprint = $this->activeSeatFootprint($workspaceId);
        if (!$overrideSeatLimit && $seatLimit > 0 && $seatFootprint > $seatLimit) {
            throw new \RuntimeException('Seat cleanup is required before scheduling this downgrade. Current active members plus pending invites exceed the target package limit.');
        }

        Database::beginTransaction();
        try {
            $subscription = Database::queryOne(
                "SELECT *
                 FROM workspace_subscriptions
                 WHERE workspace_id = ?
                   AND subscription_status IN ('active', 'trialing', 'past_due')
                 ORDER BY FIELD(subscription_status, 'active', 'trialing', 'past_due'), id DESC
                 LIMIT 1
                 FOR UPDATE",
                [$workspaceId]
            );
            if (!$subscription) {
                throw new \RuntimeException('This workspace does not have an active subscription to schedule.');
            }

            $scheduledAt = (string) ($subscription['current_period_end'] ?? '');
            if (trim($scheduledAt) === '') {
                $scheduledAt = $this->calculatePeriodEnd(date('Y-m-d H:i:s'), (string) ($price['interval_unit'] ?? 'monthly'), (int) ($price['interval_count'] ?? 1));
            }
            $metadata = [
                'operator_user_id' => $actorUserId,
                'reason' => $reason,
                'target_plan_code' => (string) ($price['plan_code'] ?? ''),
                'target_seat_limit' => $seatLimit,
                'current_seat_footprint' => $seatFootprint,
                'override_seat_limit' => $overrideSeatLimit,
            ];

            Database::execute(
                "UPDATE workspace_subscriptions
                 SET scheduled_billing_plan_price_id = ?,
                     scheduled_change_type = 'downgrade',
                     scheduled_change_at = ?,
                     scheduled_change_metadata_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    (int) $price['id'],
                    $scheduledAt,
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    (int) $subscription['id'],
                ]
            );

            $after = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE id = ? LIMIT 1", [(int) $subscription['id']]);
            $this->operatorAudit->log(
                'workspace_downgrade_scheduled',
                $actorUserId,
                $workspaceId,
                $reason,
                [
                    'workspace_slug' => (string) ($workspace['slug'] ?? ''),
                    'target_billing_plan_price_id' => (int) $price['id'],
                    'target_plan_code' => (string) ($price['plan_code'] ?? ''),
                    'scheduled_at' => $scheduledAt,
                    'seat_limit' => $seatLimit,
                    'seat_footprint' => $seatFootprint,
                    'override_seat_limit' => $overrideSeatLimit,
                    'before' => $this->auditSubscriptionSnapshot($subscription),
                    'after' => $this->auditSubscriptionSnapshot($after),
                ]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'workspace' => $this->getWorkspaceRow($workspaceId),
            'subscription' => Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]) ?? [],
        ];
    }

    public function replayProviderEvent(int $workspaceId, int $providerEventId, int $actorUserId, ?string $reason = null): array
    {
        $workspace = $this->getWorkspaceRow($workspaceId);
        $event = $this->billing->getProviderEventById($providerEventId);
        if ($event === null || (int) ($event['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('Provider event not found for this workspace.');
        }

        $result = $this->billing->replayProviderEvent($providerEventId);
        $this->operatorAudit->log(
            'billing_provider_event_replay',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'provider_event_id' => $providerEventId,
                'event_name' => (string) ($event['event_name'] ?? ''),
                'event_reference' => (string) ($event['event_reference'] ?? ''),
                'workspace_slug' => (string) ($workspace['slug'] ?? ''),
                'result_success' => !empty($result['success']),
                'result_message' => (string) ($result['message'] ?? ''),
            ]
        );

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function listCatalogHealth(): array
    {
        $duplicateCodes = [];
        $missingProviderPlans = [];
        $inactiveLegacyRows = [];
        $unsafeDeletes = [];

        try {
            $duplicateCodes = Database::query(
                "SELECT LOWER(TRIM(name)) AS duplicate_key, COUNT(*) AS row_count,
                        GROUP_CONCAT(CONCAT(id, ':', code) ORDER BY id SEPARATOR ', ') AS packages
                 FROM billing_plans
                 WHERE billing_type = 'subscription'
                 GROUP BY LOWER(TRIM(name))
                 HAVING COUNT(*) > 1
                 ORDER BY row_count DESC, duplicate_key ASC"
            );
        } catch (\Throwable $e) {
            $duplicateCodes = [];
        }

        $globalPaymentSettings = (new WorkspaceBillingSettings())->get();
        foreach ($this->listSubscriptionPricesForOperators() as $price) {
            if (empty($price['plan_is_active']) || empty($price['is_active']) || (float) ($price['amount'] ?? 0) <= 0) {
                continue;
            }
            $metadata = $this->decodeMetadata($price['metadata_json'] ?? null);
            $requiresProviderPlan = (bool) ($metadata['requires_provider_plan'] ?? true);
            $allowedModes = $this->paymentMethodsForPrice((int) ($price['id'] ?? 0));
            $cardAllowed = !empty($globalPaymentSettings['payment_card_enabled'])
                && ($allowedModes === [] || in_array(WorkspaceBillingPaymentModeService::MODE_CARD, $allowedModes, true));
            if ($requiresProviderPlan && $cardAllowed && trim((string) ($price['provider_plan_code'] ?? '')) === '') {
                $missingProviderPlans[] = [
                    'id' => (int) ($price['id'] ?? 0),
                    'price_code' => (string) ($price['price_code'] ?? ''),
                    'amount' => (float) ($price['amount'] ?? 0),
                    'currency' => (string) ($price['currency'] ?? ''),
                    'interval_unit' => (string) ($price['interval_unit'] ?? ''),
                    'plan_code' => (string) ($price['plan_code'] ?? ''),
                    'plan_name' => (string) ($price['plan_name'] ?? ''),
                    'reason' => 'Card recurring checkout is allowed but no Paystack plan code is configured.',
                ];
            }
        }

        try {
            $inactiveLegacyRows = Database::query(
                "SELECT bpp.id, bpp.price_code, bpp.is_active, bp.code AS plan_code, bp.name AS plan_name, bp.is_active AS plan_active
                 FROM billing_plan_prices bpp
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 WHERE bp.billing_type = 'subscription'
                   AND (bp.is_active = 0 OR bpp.is_active = 0)
                 ORDER BY bp.name ASC, bpp.id ASC
                 LIMIT 100"
            );
        } catch (\Throwable $e) {
            $inactiveLegacyRows = [];
        }

        foreach ($this->listSubscriptionPricesForOperators() as $price) {
            $refs = $this->referenceCountsForPrice((int) ($price['id'] ?? 0));
            if (array_sum($refs) > 0) {
                $unsafeDeletes[] = [
                    'type' => 'price',
                    'id' => (int) ($price['id'] ?? 0),
                    'label' => (string) ($price['plan_name'] ?? '') . ' / ' . (string) ($price['price_code'] ?? ''),
                    'reference_counts' => $refs,
                ];
            }
        }

        return [
            'duplicate_packages' => $duplicateCodes,
            'missing_provider_plan_codes' => $missingProviderPlans,
            'inactive_legacy_rows' => $inactiveLegacyRows,
            'unsafe_deletes' => $unsafeDeletes,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function listPackageAnalytics(array $filters = []): array
    {
        $window = $this->normalizeAnalyticsWindow($filters);
        $dateClauses = $this->analyticsDateClauses($window);
        $subscriptions = [];
        $checkoutRows = [];
        $revenueRows = [];
        $packRows = [];

        try {
            $subscriptions = Database::query(
                "SELECT bp.id AS plan_id, bp.code AS plan_code, bp.name AS plan_name,
                        bpp.id AS billing_plan_price_id, bpp.price_code, bpp.currency, bpp.interval_unit,
                        COUNT(ws.id) AS active_subscriptions
                 FROM billing_plans bp
                 JOIN billing_plan_prices bpp ON bpp.plan_id = bp.id
                 LEFT JOIN workspace_subscriptions ws
                    ON ws.billing_plan_price_id = bpp.id
                   AND ws.subscription_status IN ('active', 'trialing', 'past_due')
                 WHERE bp.billing_type = 'subscription'
                 GROUP BY bp.id, bpp.id
                 ORDER BY active_subscriptions DESC, bp.name ASC"
            );
        } catch (\Throwable $e) {
            $subscriptions = [];
        }

        try {
            $checkoutRows = Database::query(
                "SELECT COALESCE(bp.code, pack_bp.code) AS package_code,
                        COALESCE(bp.name, pack_bp.name) AS package_name,
                        bcs.checkout_type,
                        bcs.currency,
                        COUNT(*) AS checkout_starts,
                        SUM(CASE WHEN bcs.status = 'paid' THEN 1 ELSE 0 END) AS paid_checkouts,
                        SUM(CASE WHEN bcs.status IN ('failed', 'cancelled', 'expired') THEN 1 ELSE 0 END) AS failed_or_cancelled,
                        ROUND(100 * SUM(CASE WHEN bcs.status = 'paid' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 2) AS conversion_rate
                 FROM billing_checkout_sessions bcs
                 LEFT JOIN billing_plan_prices bpp ON bpp.id = bcs.billing_plan_price_id
                 LEFT JOIN billing_plans bp ON bp.id = bpp.plan_id
                 LEFT JOIN token_pack_prices tpp ON tpp.id = bcs.token_pack_price_id
                 LEFT JOIN billing_plan_prices pack_bpp ON pack_bpp.id = tpp.billing_plan_price_id
                 LEFT JOIN billing_plans pack_bp ON pack_bp.id = pack_bpp.plan_id
                 WHERE {$dateClauses['checkouts']}
                 GROUP BY package_code, package_name, bcs.checkout_type, bcs.currency
                 ORDER BY paid_checkouts DESC, checkout_starts DESC",
                $dateClauses['checkout_params']
            );
        } catch (\Throwable $e) {
            $checkoutRows = [];
        }

        try {
            $revenueRows = Database::query(
                "SELECT COALESCE(bp.code, pack_bp.code, 'unattributed') AS package_code,
                        COALESCE(bp.name, pack_bp.name, 'Unattributed') AS package_name,
                        bt.transaction_type,
                        bt.currency,
                        DATE(bt.created_at) AS revenue_date,
                        COUNT(*) AS transaction_count,
                        SUM(CASE WHEN bt.transaction_status = 'succeeded' THEN bt.amount ELSE 0 END) AS revenue
                 FROM billing_transactions bt
                 LEFT JOIN billing_checkout_sessions bcs ON bcs.id = bt.checkout_session_id
                 LEFT JOIN billing_plan_prices bpp ON bpp.id = bcs.billing_plan_price_id
                 LEFT JOIN billing_plans bp ON bp.id = bpp.plan_id
                 LEFT JOIN token_pack_prices tpp ON tpp.id = bcs.token_pack_price_id
                 LEFT JOIN billing_plan_prices pack_bpp ON pack_bpp.id = tpp.billing_plan_price_id
                 LEFT JOIN billing_plans pack_bp ON pack_bp.id = pack_bpp.plan_id
                 WHERE {$dateClauses['transactions']}
                 GROUP BY package_code, package_name, bt.transaction_type, bt.currency, DATE(bt.created_at)
                 ORDER BY revenue_date DESC, revenue DESC
                 LIMIT 200",
                $dateClauses['transaction_params']
            );
        } catch (\Throwable $e) {
            $revenueRows = [];
        }

        try {
            $packRows = Database::query(
                "SELECT tpp.id AS token_pack_price_id, bp.code AS plan_code, bp.name AS pack_name,
                        bpp.currency,
                        COUNT(bcs.id) AS checkout_starts,
                        SUM(CASE WHEN bcs.status = 'paid' THEN 1 ELSE 0 END) AS paid_purchases,
                        SUM(CASE WHEN bt.transaction_status = 'succeeded' THEN bt.amount ELSE 0 END) AS revenue
                 FROM token_pack_prices tpp
                 JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 LEFT JOIN billing_checkout_sessions bcs ON bcs.token_pack_price_id = tpp.id AND {$dateClauses['token_pack_checkouts']}
                 LEFT JOIN billing_transactions bt ON bt.checkout_session_id = bcs.id
                 GROUP BY tpp.id
                 ORDER BY paid_purchases DESC, revenue DESC, tpp.sort_order ASC",
                $dateClauses['token_pack_checkout_params']
            );
        } catch (\Throwable $e) {
            $packRows = [];
        }

        $popularity = [];
        foreach ($subscriptions as $row) {
            $key = (string) ($row['plan_code'] ?? '');
            if ($key === '') {
                continue;
            }
            $popularity[$key] ??= [
                'plan_code' => $key,
                'plan_name' => (string) ($row['plan_name'] ?? ''),
                'active_subscriptions' => 0,
                'paid_checkouts' => 0,
                'score' => 0,
            ];
            $popularity[$key]['active_subscriptions'] += (int) ($row['active_subscriptions'] ?? 0);
            $popularity[$key]['score'] += ((int) ($row['active_subscriptions'] ?? 0)) * 3;
        }
        foreach ($checkoutRows as $row) {
            $key = (string) ($row['package_code'] ?? '');
            if ($key === '') {
                continue;
            }
            $popularity[$key] ??= [
                'plan_code' => $key,
                'plan_name' => (string) ($row['package_name'] ?? ''),
                'active_subscriptions' => 0,
                'paid_checkouts' => 0,
                'score' => 0,
            ];
            $popularity[$key]['paid_checkouts'] += (int) ($row['paid_checkouts'] ?? 0);
            $popularity[$key]['score'] += (int) ($row['paid_checkouts'] ?? 0);
        }
        usort($popularity, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string) $a['plan_name'], (string) $b['plan_name']));

        return [
            'window' => $window,
            'subscriptions' => $subscriptions,
            'checkouts' => $checkoutRows,
            'revenue' => $revenueRows,
            'token_packs' => $packRows,
            'popularity' => array_values($popularity),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{from:?string,to:?string,label:string}
     */
    private function normalizeAnalyticsWindow(array $filters): array
    {
        $from = $this->normalizeDateFilter($filters['from'] ?? $filters['analytics_from'] ?? null, false);
        $to = $this->normalizeDateFilter($filters['to'] ?? $filters['analytics_to'] ?? null, true);
        if ($from !== null && $to !== null && strtotime($from) > strtotime($to)) {
            throw new \RuntimeException('Analytics start date must be before the end date.');
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => $from === null && $to === null
                ? 'All time'
                : trim(($from ?? 'Beginning') . ' to ' . ($to ?? 'Now')),
        ];
    }

    private function normalizeDateFilter(mixed $value, bool $endOfDay): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \RuntimeException('Choose a valid analytics date.');
        }

        return date('Y-m-d ' . ($endOfDay ? '23:59:59' : '00:00:00'), $timestamp);
    }

    /**
     * @param array{from:?string,to:?string,label:string} $window
     * @return array<string,mixed>
     */
    private function analyticsDateClauses(array $window): array
    {
        $build = static function (string $column) use ($window): array {
            $clauses = [];
            $params = [];
            if ($window['from'] !== null) {
                $clauses[] = $column . ' >= ?';
                $params[] = $window['from'];
            }
            if ($window['to'] !== null) {
                $clauses[] = $column . ' <= ?';
                $params[] = $window['to'];
            }

            return [
                'sql' => $clauses === [] ? '1=1' : implode(' AND ', $clauses),
                'params' => $params,
            ];
        };

        $checkouts = $build('bcs.created_at');
        $transactions = $build('bt.created_at');
        $packCheckouts = $build('bcs.created_at');

        return [
            'checkouts' => $checkouts['sql'],
            'transactions' => $transactions['sql'],
            'token_pack_checkouts' => $packCheckouts['sql'],
            'checkout_params' => $checkouts['params'],
            'transaction_params' => $transactions['params'],
            'token_pack_checkout_params' => $packCheckouts['params'],
        ];
    }

    private function grantOperatorPeriodCredits(
        int $workspaceId,
        int $subscriptionId,
        array $price,
        string $periodStart,
        ?string $periodEnd,
        int $actorUserId,
        string $reason
    ): array {
        $entitlements = (new WorkspacePlanEntitlementService())->entitlementsForPriceRow($price);
        $credits = max(0, (int) ($price['included_tokens'] ?? $entitlements['included_credits'] ?? 0));
        if ($credits <= 0) {
            return ['credited_tokens' => 0, 'credited_credits' => 0, 'credit_grant_status' => 'not_applicable'];
        }

        $referenceId = 'operator_subscription_period:' . $this->subscriptionPeriodKey($subscriptionId, $periodStart);
        $existing = Database::queryOne(
            "SELECT id
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'subscription_included_tokens'
               AND reference_id = ?
               AND entry_type = 'credit'
             LIMIT 1",
            [$workspaceId, $referenceId]
        );
        if ($existing) {
            return [
                'ledger_entry_id' => (int) $existing['id'],
                'credited_tokens' => 0,
                'credited_credits' => 0,
                'credit_grant_status' => 'duplicate_period',
            ];
        }

        return $this->wallets->creditTokens(
            $workspaceId,
            $credits,
            'subscription_included_tokens',
            $referenceId,
            $actorUserId,
            [
                'subscription_id' => $subscriptionId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'plan_code' => (string) ($price['plan_code'] ?? ''),
                'credit_expiry_days' => (int) ($entitlements['credit_expiry_days'] ?? 180),
                'source' => 'operator_subscription_included_credits',
                'reason' => $reason,
            ]
        ) + [
            'credited_tokens' => $credits,
            'credited_credits' => $credits,
            'credit_grant_status' => 'granted',
        ];
    }

    private function recordOperatorSubscriptionCycle(
        int $workspaceId,
        int $subscriptionId,
        array $price,
        string $periodStart,
        ?string $periodEnd,
        int $creditLotId,
        array $metadata
    ): void {
        if (!Database::tableExists('workspace_subscription_cycles')) {
            return;
        }

        $periodKey = $this->subscriptionPeriodKey($subscriptionId, $periodStart);
        Database::execute(
            "INSERT INTO workspace_subscription_cycles
                (workspace_id, subscription_id, billing_plan_price_id, period_key, period_start, period_end,
                 included_credits, credit_lot_id, status, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
             ON DUPLICATE KEY UPDATE
                billing_plan_price_id = VALUES(billing_plan_price_id),
                period_end = VALUES(period_end),
                included_credits = VALUES(included_credits),
                credit_lot_id = COALESCE(VALUES(credit_lot_id), workspace_subscription_cycles.credit_lot_id),
                status = 'active',
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()",
            [
                $workspaceId,
                $subscriptionId,
                (int) ($price['id'] ?? $price['billing_plan_price_id'] ?? 0),
                $periodKey,
                $periodStart,
                $periodEnd,
                (int) ($price['included_tokens'] ?? 0),
                $creditLotId > 0 ? $creditLotId : null,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    private function subscriptionPeriodKey(int $subscriptionId, string $periodStart): string
    {
        return sprintf('%d:%s', $subscriptionId, date('YmdHis', strtotime($periodStart) ?: time()));
    }

    private function calculatePeriodEnd(string $periodStart, string $intervalUnit, int $intervalCount): string
    {
        $timestamp = strtotime($periodStart) ?: time();
        $intervalCount = max(1, $intervalCount);
        $unit = match ($intervalUnit) {
            'weekly' => 'week',
            'quarterly' => 'month',
            'yearly' => 'year',
            default => 'month',
        };
        $count = $intervalUnit === 'quarterly' ? $intervalCount * 3 : $intervalCount;

        return date('Y-m-d H:i:s', strtotime('+' . $count . ' ' . $unit, $timestamp) ?: ($timestamp + 2592000));
    }

    private function activeSeatFootprint(int $workspaceId): int
    {
        $activeMembers = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'",
            [$workspaceId]
        )['c'] ?? 0);
        $pendingInvites = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_invites
             WHERE workspace_id = ?
               AND invite_status = 'pending'
               AND expires_at > NOW()",
            [$workspaceId]
        )['c'] ?? 0);

        return $activeMembers + $pendingInvites;
    }

    public function listWorkspaceMemberships(int $workspaceId): array
    {
        return Database::query(
            "SELECT wm.*, u.uuid AS user_uuid, u.first_name, u.last_name, u.email, u.role AS user_role
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
             ORDER BY wm.is_owner DESC, wm.membership_status ASC, u.email ASC",
            [$workspaceId]
        );
    }

    /**
     * @param list<array<string,mixed>> $prices
     * @return list<array<string,mixed>>
     */
    private function groupSubscriptionPackages(array $prices): array
    {
        $packages = [];
        foreach ($prices as $price) {
            $planId = (int) ($price['plan_id'] ?? 0);
            if ($planId <= 0) {
                continue;
            }
            if (!isset($packages[$planId])) {
                $metadata = (array) ($price['metadata'] ?? []);
                $entitlements = (array) ($price['entitlements'] ?? []);
                $packages[$planId] = [
                    'id' => $planId,
                    'code' => (string) ($price['plan_code'] ?? ''),
                    'name' => (string) ($price['plan_name'] ?? 'Package'),
                    'description' => (string) ($price['description'] ?? $entitlements['public_summary'] ?? ''),
                    'is_active' => (int) ($price['plan_is_active'] ?? 1),
                    'display_order' => (int) ($metadata['display_order'] ?? 0),
                    'features' => (array) ($entitlements['features'] ?? []),
                    'feature_details' => (array) ($entitlements['feature_details'] ?? []),
                    'prices' => [],
                ];
            }
            $packages[$planId]['prices'][] = $price;
        }

        usort($packages, static function (array $a, array $b): int {
            return ((int) ($a['display_order'] ?? 0) <=> (int) ($b['display_order'] ?? 0))
                ?: strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_values($packages);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listFeatureCatalog(): array
    {
        if (!Database::tableExists('billing_package_features')) {
            return [];
        }

        return array_map(function (array $row): array {
            $default = $this->decodeMetadata($row['default_value_json'] ?? null);
            $row['default_value'] = $default['value'] ?? null;
            return $row;
        }, Database::query(
            "SELECT *
             FROM billing_package_features
             ORDER BY display_order ASC, label ASC"
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    private function decorateOperatorPaymentAvailability(array $row, array $readiness): array
    {
        $allowlist = array_values((array) ($row['allowed_payment_modes'] ?? []));
        $globalAvailability = (array) ($readiness['global_availability'] ?? []);
        $paystackReady = !empty($readiness['paystack_ready']);
        $mpesaReady = !empty($readiness['mpesa_ready']);
        $currency = strtoupper((string) ($row['currency'] ?? 'KES'));
        $amount = (float) ($row['amount'] ?? 0);
        $providerPlanConfigured = trim((string) ($row['provider_plan_code'] ?? '')) !== '';
        $requiresProviderPlan = true;
        $metadata = (array) ($row['metadata'] ?? $this->decodeMetadata($row['metadata_json'] ?? null));
        $isTokenPack = !empty($metadata['credit_pack']) || array_key_exists('token_quantity', $row);
        if (array_key_exists('requires_provider_plan', $metadata)) {
            $requiresProviderPlan = !empty($metadata['requires_provider_plan']);
        } elseif ($isTokenPack) {
            $requiresProviderPlan = false;
        } elseif ($amount <= 0) {
            $requiresProviderPlan = false;
        }

        $baseModes = (new WorkspaceBillingPaymentModeService())->listAvailableModes($currency, $paystackReady, $mpesaReady, $globalAvailability);
        $effective = [];
        foreach ($baseModes as $mode) {
            $key = (string) ($mode['key'] ?? '');
            if ($allowlist !== [] && !in_array($key, $allowlist, true)) {
                $mode['available'] = false;
                $mode['reason'] = 'This method is not enabled on this package.';
            }
            if ($key === WorkspaceBillingPaymentModeService::MODE_CARD && $amount > 0 && $requiresProviderPlan && !$providerPlanConfigured) {
                $mode['available'] = false;
                $mode['reason'] = 'Paystack recurring plan code is missing.';
            }
            $effective[] = $mode;
        }

        $row['provider_plan_configured'] = $providerPlanConfigured;
        $row['requires_provider_plan'] = $requiresProviderPlan;
        $row['card_recurring_ready'] = $this->modeAvailable($effective, WorkspaceBillingPaymentModeService::MODE_CARD);
        $row['manual_payment_available'] = $this->modeAvailable($effective, WorkspaceBillingPaymentModeService::MODE_MPESA)
            || $this->modeAvailable($effective, WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER);
        $row['effective_payment_modes'] = $effective;

        return $row;
    }

    /**
     * @param list<array<string,mixed>> $modes
     */
    private function modeAvailable(array $modes, string $modeKey): bool
    {
        foreach ($modes as $mode) {
            if ((string) ($mode['key'] ?? '') === $modeKey && !empty($mode['available'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSubscriptionPackage(int $planId): array
    {
        $plan = Database::queryOne(
            "SELECT *
             FROM billing_plans
             WHERE id = ?
               AND billing_type = 'subscription'
             LIMIT 1",
            [$planId]
        );
        if (!$plan) {
            throw new \RuntimeException('Subscription package was not found.');
        }

        return $plan;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadAnyBillingPrice(int $billingPlanPriceId): array
    {
        $price = Database::queryOne(
            "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.billing_type
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.id = ?
             LIMIT 1",
            [$billingPlanPriceId]
        );
        if (!$price) {
            throw new \RuntimeException('Billing price was not found.');
        }

        return $price;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function insertSubscriptionPrice(int $planId, array $input, string $planName, string $planDescription): array
    {
        $amount = $this->normalizeAmount((float) ($input['amount'] ?? 0), true);
        $currency = $this->normalizeCurrency((string) ($input['currency'] ?? 'KES'));
        $intervalUnit = $this->normalizeIntervalUnit((string) ($input['interval_unit'] ?? 'monthly'), false);
        $intervalCount = max(1, (int) ($input['interval_count'] ?? 1));
        $this->assertUniqueSubscriptionCadence($planId, $intervalUnit, $intervalCount);
        $includedCredits = max(0, (int) ($input['included_credits'] ?? $input['included_tokens'] ?? 0));
        $isActive = array_key_exists('is_active', $input) ? !empty($input['is_active']) : true;
        $isDefault = !empty($input['is_default']);
        $plan = $this->loadSubscriptionPackage($planId);
        $priceCode = $this->normalizeCatalogCode((string) ($input['price_code'] ?? ''));
        if ($priceCode === '') {
            $suffix = $intervalUnit === 'yearly' ? 'annual' : $intervalUnit;
            $priceCode = $this->uniquePriceCode((string) ($plan['code'] ?? 'package') . '-' . $suffix);
        }
        $displayName = trim((string) ($input['display_name'] ?? $planName));
        $displayCopy = trim((string) ($input['display_copy'] ?? $input['description'] ?? $planDescription));
        $metadata = [
            'checkout_available' => true,
            'requires_provider_plan' => $amount > 0,
            'display_name' => $displayName,
            'display_copy' => $displayCopy,
            'public_summary' => $displayCopy,
            'public_display_name' => $displayName,
            'public_display_copy' => $displayCopy,
            'display_order' => (int) ($input['display_order'] ?? 0),
            'credits_label' => 'AI Credits',
            'entitlements' => [
                'seat_limit' => max(0, (int) ($input['seat_limit'] ?? 1)),
                'included_credits' => $includedCredits,
                'can_top_up' => !empty($input['can_top_up']),
                'business_intelligence_enabled' => !empty($input['business_intelligence']),
                'personal_api_key_enabled' => !empty($input['personal_api_key']),
                'credit_expiry_days' => max(1, (int) ($input['credit_expiry_days'] ?? 180)),
                'is_custom' => !empty($input['is_custom']),
                'maturity_tier' => trim((string) ($input['maturity_tier'] ?? 'custom')) ?: 'custom',
                'display_name' => $displayName,
                'public_summary' => $displayCopy,
                'public_display_name' => $displayName,
                'public_display_copy' => $displayCopy,
            ],
        ];

        if ($isDefault) {
            Database::execute(
                "UPDATE billing_plan_prices bpp
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 SET bpp.is_default = 0
                 WHERE bp.billing_type = 'subscription'"
            );
        }

        Database::execute(
            "INSERT INTO billing_plan_prices
                (plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens, metadata_json, provider, is_default, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paystack', ?, ?)",
            [$planId, $priceCode, $currency, $intervalUnit, $intervalCount, $amount, $includedCredits, json_encode($metadata, JSON_UNESCAPED_SLASHES), $isDefault ? 1 : 0, $isActive ? 1 : 0]
        );

        return $this->loadSubscriptionPrice((int) Database::lastInsertId(), false);
    }

    private function syncPlanDisplayMetadata(int $planId, string $displayName, string $displayCopy, int $displayOrder): void
    {
        foreach (Database::query("SELECT id, metadata_json FROM billing_plan_prices WHERE plan_id = ?", [$planId]) as $price) {
            $metadata = $this->decodeMetadata($price['metadata_json'] ?? null);
            $metadata['display_name'] = $displayName;
            $metadata['display_copy'] = $displayCopy;
            $metadata['public_summary'] = $displayCopy;
            $metadata['public_display_name'] = $displayName;
            $metadata['public_display_copy'] = $displayCopy;
            if ($displayOrder !== 0) {
                $metadata['display_order'] = $displayOrder;
            }
            $metadata['entitlements'] = array_merge((array) ($metadata['entitlements'] ?? []), [
                'display_name' => $displayName,
                'public_summary' => $displayCopy,
                'public_display_name' => $displayName,
                'public_display_copy' => $displayCopy,
            ]);
            Database::execute(
                "UPDATE billing_plan_prices SET metadata_json = ?, updated_at = NOW() WHERE id = ?",
                [json_encode($metadata, JSON_UNESCAPED_SLASHES), (int) $price['id']]
            );
        }
    }

    /**
     * @param array<string,mixed> $featureValues
     */
    private function savePackageFeatureValuesInternal(int $planId, array $featureValues): void
    {
        if ($planId <= 0 || !Database::tableExists('billing_package_features') || !Database::tableExists('billing_plan_feature_values')) {
            return;
        }

        $features = Database::query("SELECT * FROM billing_package_features");
        foreach ($features as $feature) {
            $key = (string) ($feature['feature_key'] ?? '');
            if ($key === '' || !array_key_exists($key, $featureValues)) {
                continue;
            }
            $valueType = (string) ($feature['value_type'] ?? 'boolean');
            $value = $this->normalizeFeatureInputValue($valueType, $featureValues[$key]);
            $isEnabled = $valueType === 'boolean' ? !empty($value) : true;
            Database::execute(
                "INSERT INTO billing_plan_feature_values (plan_id, feature_id, value_json, is_enabled)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    value_json = VALUES(value_json),
                    is_enabled = VALUES(is_enabled),
                    updated_at = NOW()",
                [$planId, (int) $feature['id'], json_encode(['value' => $value], JSON_UNESCAPED_SLASHES), $isEnabled ? 1 : 0]
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function listPackageFeatureValues(int $planId): array
    {
        if (!Database::tableExists('billing_package_features') || !Database::tableExists('billing_plan_feature_values')) {
            return [];
        }

        $values = [];
        foreach (Database::query(
            "SELECT bpf.feature_key, bpf.value_type, bpfv.value_json, bpfv.is_enabled
             FROM billing_plan_feature_values bpfv
             JOIN billing_package_features bpf ON bpf.id = bpfv.feature_id
             WHERE bpfv.plan_id = ?",
            [$planId]
        ) as $row) {
            $payload = $this->decodeMetadata($row['value_json'] ?? null);
            $values[(string) $row['feature_key']] = (string) ($row['value_type'] ?? 'boolean') === 'boolean'
                ? !empty($row['is_enabled'])
                : ($payload['value'] ?? null);
        }

        return $values;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeFeatureInputValue(string $valueType, $value)
    {
        return match ($valueType) {
            'boolean' => !empty($value),
            'integer' => max(0, (int) $value),
            'decimal' => max(0, (float) $value),
            default => trim((string) $value),
        };
    }

    /**
     * @param list<string>|null $modes
     * @return list<string>
     */
    private function savePricePaymentMethodsInternal(int $billingPlanPriceId, ?array $modes): array
    {
        if ($billingPlanPriceId <= 0 || !Database::tableExists('billing_plan_price_payment_methods')) {
            return [];
        }

        Database::execute("DELETE FROM billing_plan_price_payment_methods WHERE billing_plan_price_id = ?", [$billingPlanPriceId]);
        $validModes = (new WorkspaceBillingPaymentModeService())->supportedModes();
        $saved = [];
        foreach (($modes ?? []) as $mode) {
            $mode = (new WorkspaceBillingPaymentModeService())->normalize((string) $mode);
            if (!in_array($mode, $validModes, true) || in_array($mode, $saved, true)) {
                continue;
            }
            Database::execute(
                "INSERT INTO billing_plan_price_payment_methods (billing_plan_price_id, payment_mode, is_enabled)
                 VALUES (?, ?, 1)",
                [$billingPlanPriceId, $mode]
            );
            $saved[] = $mode;
        }

        return $saved;
    }

    /**
     * @return list<string>
     */
    private function paymentMethodsForPrice(int $billingPlanPriceId): array
    {
        if ($billingPlanPriceId <= 0 || !Database::tableExists('billing_plan_price_payment_methods')) {
            return [];
        }

        return array_values(array_map(
            static fn(array $row): string => (string) $row['payment_mode'],
            Database::query(
                "SELECT payment_mode
                 FROM billing_plan_price_payment_methods
                 WHERE billing_plan_price_id = ?
                   AND is_enabled = 1
                 ORDER BY payment_mode ASC",
                [$billingPlanPriceId]
            )
        ));
    }

    private function archivePriceMetadataForPlan(int $planId, int $actorUserId, string $reason): void
    {
        foreach (Database::query("SELECT id FROM billing_plan_prices WHERE plan_id = ?", [$planId]) as $price) {
            $this->archivePriceMetadata((int) $price['id'], $actorUserId, $reason);
        }
    }

    private function archivePriceMetadata(int $billingPlanPriceId, int $actorUserId, string $reason): void
    {
        $row = Database::queryOne("SELECT metadata_json FROM billing_plan_prices WHERE id = ? LIMIT 1", [$billingPlanPriceId]);
        if (!$row) {
            return;
        }
        $metadata = $this->decodeMetadata($row['metadata_json'] ?? null);
        $metadata['lifecycle'] = array_merge((array) ($metadata['lifecycle'] ?? []), [
            'archived_at' => date('c'),
            'archived_by' => $actorUserId,
            'archive_reason' => $reason,
        ]);
        Database::execute(
            "UPDATE billing_plan_prices SET metadata_json = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($metadata, JSON_UNESCAPED_SLASHES), $billingPlanPriceId]
        );
    }

    /**
     * @return array<string,int>
     */
    private function referenceCountsForPlan(int $planId): array
    {
        $counts = ['subscriptions' => 0, 'checkouts' => 0, 'cycles' => 0, 'transactions' => 0];
        foreach (Database::query("SELECT id FROM billing_plan_prices WHERE plan_id = ?", [$planId]) as $price) {
            foreach ($this->referenceCountsForPrice((int) $price['id']) as $key => $count) {
                $counts[$key] = ($counts[$key] ?? 0) + $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string,int>
     */
    private function referenceCountsForPrice(int $billingPlanPriceId): array
    {
        $counts = ['subscriptions' => 0, 'checkouts' => 0, 'cycles' => 0, 'transactions' => 0];
        if ($billingPlanPriceId <= 0) {
            return $counts;
        }
        $counts['subscriptions'] = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_subscriptions WHERE billing_plan_price_id = ?",
            [$billingPlanPriceId]
        )['c'] ?? 0);
        $counts['checkouts'] = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM billing_checkout_sessions WHERE billing_plan_price_id = ?",
            [$billingPlanPriceId]
        )['c'] ?? 0);
        if (Database::tableExists('workspace_subscription_cycles')) {
            $counts['cycles'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM workspace_subscription_cycles WHERE billing_plan_price_id = ?",
                [$billingPlanPriceId]
            )['c'] ?? 0);
        }
        $counts['transactions'] = (int) (Database::queryOne(
            "SELECT COUNT(DISTINCT bt.id) AS c
             FROM billing_transactions bt
             LEFT JOIN workspace_subscriptions ws ON ws.id = bt.subscription_id
             LEFT JOIN billing_checkout_sessions bcs ON bcs.id = bt.checkout_session_id
             WHERE ws.billing_plan_price_id = ?
                OR bcs.billing_plan_price_id = ?",
            [$billingPlanPriceId, $billingPlanPriceId]
        )['c'] ?? 0);

        return $counts;
    }

    /**
     * @return array<string,int>
     */
    private function referenceCountsForPack(int $tokenPackPriceId): array
    {
        $counts = ['checkouts' => 0, 'transactions' => 0];
        if ($tokenPackPriceId <= 0) {
            return $counts;
        }
        $counts['checkouts'] = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM billing_checkout_sessions WHERE token_pack_price_id = ?",
            [$tokenPackPriceId]
        )['c'] ?? 0);
        $counts['transactions'] = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions bt
             JOIN billing_checkout_sessions bcs ON bcs.id = bt.checkout_session_id
             WHERE bcs.token_pack_price_id = ?",
            [$tokenPackPriceId]
        )['c'] ?? 0);

        return $counts;
    }

    private function planHasActivePrices(int $planId): bool
    {
        if ($planId <= 0) {
            return false;
        }

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM billing_plan_prices WHERE plan_id = ? AND is_active = 1",
            [$planId]
        )['c'] ?? 0) > 0;
    }

    private function normalizeCatalogCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function assertUniqueBillingPlanCode(string $code, ?int $ignorePlanId = null): void
    {
        $code = $this->normalizeCatalogCode($code);
        if ($code === '') {
            throw new \RuntimeException('Package code is required.');
        }

        $params = [$code];
        $sql = "SELECT id FROM billing_plans WHERE code = ? LIMIT 1";
        if ($ignorePlanId !== null && $ignorePlanId > 0) {
            $sql = "SELECT id FROM billing_plans WHERE code = ? AND id <> ? LIMIT 1";
            $params[] = $ignorePlanId;
        }
        if (Database::queryOne($sql, $params)) {
            throw new \RuntimeException('A package or pack with this code already exists.');
        }
    }

    private function assertUniqueSubscriptionCadence(int $planId, string $intervalUnit, int $intervalCount, ?int $ignorePriceId = null): void
    {
        if ($planId <= 0) {
            return;
        }

        $params = [$planId, $intervalUnit, max(1, $intervalCount)];
        $sql = "SELECT id
                FROM billing_plan_prices
                WHERE plan_id = ?
                  AND interval_unit = ?
                  AND interval_count = ?";
        if ($ignorePriceId !== null && $ignorePriceId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $ignorePriceId;
        }
        $sql .= " LIMIT 1";

        if (Database::queryOne($sql, $params)) {
            throw new \RuntimeException('This package already has a cadence with that interval and cycle count.');
        }
    }

    private function uniquePriceCode(string $baseCode): string
    {
        $baseCode = $this->normalizeCatalogCode($baseCode);
        $candidate = $baseCode !== '' ? $baseCode : 'package-price';
        $suffix = 2;
        while (Database::queryOne("SELECT id FROM billing_plan_prices WHERE price_code = ? LIMIT 1", [$candidate])) {
            $candidate = $baseCode . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function getWorkspaceRow(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required.');
        }

        $workspace = Database::queryOne(
            "SELECT id, uuid, name, slug, status, plan_status, trial_starts_at, trial_ends_at, suspended_at, created_at, updated_at
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );

        if ($workspace === null) {
            throw new \RuntimeException('Workspace not found.');
        }

        return $workspace;
    }

    private function requireSuperAdmin(int $actorUserId): void
    {
        $actor = $actorUserId > 0
            ? Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId])
            : null;

        if (!Authorization::isSuperAdmin($actor ?: null)) {
            throw new \RuntimeException('Only Super Admin can perform this billing operator action.');
        }
    }

    private function requireReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required for billing operator actions.');
        }

        return $reason;
    }

    private function loadSubscriptionPrice(int $billingPlanPriceId, bool $activeOnly = true): array
    {
        if ($billingPlanPriceId <= 0) {
            throw new \RuntimeException('Choose a subscription price.');
        }

        $sql = "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description, bp.billing_type
                FROM billing_plan_prices bpp
                JOIN billing_plans bp ON bp.id = bpp.plan_id
                WHERE bpp.id = ?
                  AND bp.billing_type = 'subscription'";
        if ($activeOnly) {
            $sql .= " AND bpp.is_active = 1 AND bp.is_active = 1";
        }
        $sql .= " LIMIT 1";

        $price = Database::queryOne($sql, [$billingPlanPriceId]);
        if (!$price) {
            throw new \RuntimeException('Subscription price was not found.');
        }

        return $price;
    }

    private function loadTokenPack(int $tokenPackPriceId, bool $activeOnly = true): array
    {
        if ($tokenPackPriceId <= 0) {
            throw new \RuntimeException('Choose a token pack.');
        }

        $sql = "SELECT tpp.*, bpp.price_code, bpp.currency, bpp.amount, bpp.included_tokens, bpp.metadata_json,
                       bp.code AS plan_code, bp.name AS plan_name, bp.description
                FROM token_pack_prices tpp
                JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
                JOIN billing_plans bp ON bp.id = bpp.plan_id
                WHERE tpp.id = ?";
        if ($activeOnly) {
            $sql .= " AND tpp.is_active = 1 AND bpp.is_active = 1 AND bp.is_active = 1";
        }
        $sql .= " LIMIT 1";

        $pack = Database::queryOne($sql, [$tokenPackPriceId]);
        if (!$pack) {
            throw new \RuntimeException('Token pack was not found.');
        }

        return $pack;
    }

    private function normalizeAmount(float $amount, bool $allowZero = false): float
    {
        $amount = round($amount, 2);
        if ($allowZero ? $amount < 0 : $amount <= 0) {
            throw new \RuntimeException('Price amount must be greater than zero.');
        }

        return $amount;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) {
            throw new \RuntimeException('Currency must be a valid uppercase currency code.');
        }

        return $currency;
    }

    private function normalizeIntervalUnit(string $intervalUnit, bool $allowOneTime): string
    {
        $allowed = $allowOneTime
            ? ['one_time', 'weekly', 'monthly', 'quarterly', 'yearly']
            : ['weekly', 'monthly', 'quarterly', 'yearly'];
        if (!in_array($intervalUnit, $allowed, true)) {
            throw new \RuntimeException('Choose a valid billing interval.');
        }

        return $intervalUnit;
    }

    private function auditSubscriptionSnapshot(?array $subscription): ?array
    {
        if (!$subscription) {
            return null;
        }

        return [
            'id' => (int) ($subscription['id'] ?? 0),
            'billing_plan_price_id' => (int) ($subscription['billing_plan_price_id'] ?? 0),
            'subscription_status' => (string) ($subscription['subscription_status'] ?? ''),
            'trial_starts_at' => $subscription['trial_starts_at'] ?? null,
            'trial_ends_at' => $subscription['trial_ends_at'] ?? null,
            'next_billing_at' => $subscription['next_billing_at'] ?? null,
        ];
    }

    private function auditPriceSnapshot(?array $price): ?array
    {
        if (!$price) {
            return null;
        }

        return [
            'id' => (int) ($price['id'] ?? 0),
            'price_code' => (string) ($price['price_code'] ?? ''),
            'currency' => (string) ($price['currency'] ?? ''),
            'amount' => (float) ($price['amount'] ?? 0),
            'included_tokens' => (int) ($price['included_tokens'] ?? 0),
            'interval_unit' => (string) ($price['interval_unit'] ?? ''),
            'interval_count' => (int) ($price['interval_count'] ?? 0),
            'is_active' => (int) ($price['is_active'] ?? 0),
            'is_default' => (int) ($price['is_default'] ?? 0),
        ];
    }

    private function auditTokenPackSnapshot(?array $pack): ?array
    {
        if (!$pack) {
            return null;
        }

        return array_merge($this->auditPriceSnapshot($pack) ?? [], [
            'token_pack_price_id' => (int) ($pack['id'] ?? 0),
            'billing_plan_price_id' => (int) ($pack['billing_plan_price_id'] ?? 0),
            'token_quantity' => (int) ($pack['token_quantity'] ?? 0),
            'sort_order' => (int) ($pack['sort_order'] ?? 0),
        ]);
    }

    private function decodeMetadata(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function listSubscriptionPricesForOperators(): array
    {
        try {
            $privateFilter = Database::columnExists('billing_plan_prices', 'workspace_id')
                ? 'AND bpp.workspace_id IS NULL'
                : '';
            return Database::query(
                "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description, bp.is_active AS plan_is_active
                 FROM billing_plan_prices bpp
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 WHERE bp.billing_type = 'subscription'
                   {$privateFilter}
                 ORDER BY bp.name ASC, bpp.is_default DESC, bpp.amount ASC, bpp.id ASC"
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listWorkspacesForPackageSettings(): array
    {
        try {
            return Database::query(
                "SELECT id, name, slug, status, plan_status
                 FROM workspaces
                 ORDER BY name ASC, id ASC
                 LIMIT 250"
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function listTokenPackPricesForOperators(): array
    {
        try {
            return Database::query(
                "SELECT tpp.*, bpp.price_code, bpp.currency, bpp.amount, bpp.included_tokens, bpp.metadata_json,
                        bp.code AS plan_code, bp.name AS plan_name, bp.description
                 FROM token_pack_prices tpp
                 JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 ORDER BY tpp.sort_order ASC, tpp.id ASC"
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function defaultBillingPortal(): array
    {
        return [
            'snapshot' => [
                'subscription_status' => 'inactive',
                'billing_blocked' => false,
                'token_balance' => 0,
                'available_tokens' => 0,
                'is_trial_active' => false,
                'trial_starts_at' => null,
                'trial_ends_at' => null,
                'ai_blocked_reason' => null,
                'subscription' => [],
            ],
            'plans' => [],
            'token_packs' => [],
            'ledger' => [],
            'transactions' => [],
            'checkout_sessions' => [],
            'ai_usage' => [
                'overview' => [
                    'total_billable_tokens' => 0,
                    'total_provider_cost' => 0,
                ],
                'by_feature' => [],
                'by_user' => [],
            ],
        ];
    }

    private function notifyDefaultWorkspaceLifecycle(int $workspaceId, int $actorUserId, string $reason): void
    {
        try {
            (new DefaultWorkspaceLifecycleObserverService())->workspaceChanged($workspaceId, $actorUserId, $reason);
        } catch (\Throwable $e) {
            error_log('Default workspace lifecycle observer failed: ' . $e->getMessage());
        }
    }
}
