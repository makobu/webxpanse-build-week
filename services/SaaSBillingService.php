<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;

class SaaSBillingService
{
    private ?PaystackGateway $gateway;
    private ?MpesaDarajaGateway $mpesaGateway;
    private WorkspaceWalletService $wallets;
    private WorkspacePlanEntitlementService $planEntitlements;
    private WorkspaceCreditLedgerService $creditLedger;
    private WorkspaceLaunchReadinessService $readiness;
    private WorkspaceBillingPaymentModeService $paymentModes;
    private BillingPaymentMarketService $paymentMarkets;
    private DefaultWorkspacePackageExemptionService $packageExemptions;

    public function __construct(
        ?PaystackGateway $gateway = null,
        ?WorkspaceWalletService $wallets = null,
        ?WorkspaceLaunchReadinessService $readiness = null,
        ?WorkspaceBillingPaymentModeService $paymentModes = null,
        ?MpesaDarajaGateway $mpesaGateway = null,
        ?BillingPaymentMarketService $paymentMarkets = null
    )
    {
        $this->gateway = $gateway;
        $this->mpesaGateway = $mpesaGateway;
        $this->wallets = $wallets ?? new WorkspaceWalletService();
        $this->planEntitlements = new WorkspacePlanEntitlementService();
        $this->creditLedger = new WorkspaceCreditLedgerService();
        $this->readiness = $readiness ?? new WorkspaceLaunchReadinessService();
        $this->paymentModes = $paymentModes ?? new WorkspaceBillingPaymentModeService();
        $this->paymentMarkets = $paymentMarkets ?? new BillingPaymentMarketService();
        $this->packageExemptions = new DefaultWorkspacePackageExemptionService();
    }

    public function createTrialSubscription(int $workspaceId, ?int $createdBy = null, int $trialDays = 14): int
    {
        $result = $this->activateCompassFreeSubscription($workspaceId, $createdBy);
        return (int) ($result['subscription_id'] ?? 0);
    }

    public function activateCompassFreeSubscription(int $workspaceId, ?int $createdBy = null): array
    {
        return $this->executeWithReadiness('billing_snapshot', function () use ($workspaceId, $createdBy): array {
            $existing = Database::queryOne(
                "SELECT ws.*, bpp.price_code, bp.code AS plan_code
                 FROM workspace_subscriptions ws
                 JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 WHERE ws.workspace_id = ?
                   AND subscription_status IN ('active', 'trialing', 'past_due')
                 ORDER BY ws.id DESC
                 LIMIT 1",
                [$workspaceId]
            );
            if ($existing && (string) ($existing['plan_code'] ?? '') !== WorkspacePlanEntitlementService::PLAN_COMPASS_FREE) {
                return [
                    'subscription_id' => (int) $existing['id'],
                    'changed' => false,
                    'message' => 'Existing workspace package remains active.',
                ];
            }

            $price = $this->planEntitlements->compassFreePrice() ?? $this->getDefaultSubscriptionPrice();
            $now = date('Y-m-d H:i:s');
            $reference = 'compass_free_' . $workspaceId;
            $startedTransaction = !Database::getInstance()->inTransaction();

            if ($startedTransaction) {
                Database::beginTransaction();
            }
            try {
                if ($existing) {
                    Database::execute(
                        "UPDATE workspace_subscriptions
                         SET billing_plan_price_id = ?,
                             provider = 'internal',
                             provider_reference = ?,
                             provider_subscription_status = 'active',
                             renewal_status = 'free',
                             provider_metadata_json = ?,
                             subscription_status = 'active',
                             current_period_start = COALESCE(current_period_start, ?),
                             current_period_end = NULL,
                             trial_starts_at = NULL,
                             trial_ends_at = NULL,
                             next_billing_at = NULL,
                             scheduled_billing_plan_price_id = NULL,
                             scheduled_change_type = NULL,
                             scheduled_change_at = NULL,
                             scheduled_change_metadata_json = NULL,
                             updated_at = NOW()
                         WHERE id = ?",
                        [
                            (int) $price['id'],
                            $reference,
                            json_encode(['source' => 'compass_free_activation', 'activated_at' => date('c')], JSON_UNESCAPED_SLASHES),
                            $now,
                            (int) $existing['id'],
                        ]
                    );
                    $subscriptionId = (int) $existing['id'];
                } else {
                    Database::execute(
                        "INSERT INTO workspace_subscriptions
                         (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_status,
                          renewal_status, provider_metadata_json, subscription_status, current_period_start, current_period_end,
                          trial_starts_at, trial_ends_at, next_billing_at, created_by)
                         VALUES (?, ?, 'internal', ?, 'active', 'free', ?, 'active', ?, NULL, NULL, NULL, NULL, ?)",
                        [
                            $workspaceId,
                            (int) $price['id'],
                            $reference,
                            json_encode(['source' => 'compass_free_activation', 'activated_at' => date('c')], JSON_UNESCAPED_SLASHES),
                            $now,
                            $createdBy,
                        ]
                    );
                    $subscriptionId = (int) Database::lastInsertId();
                }

                Database::execute(
                    "UPDATE workspaces
                     SET plan_status = 'active',
                         trial_starts_at = NULL,
                         trial_ends_at = NULL,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$workspaceId]
                );

                $creditResult = $this->grantCompassFreeOnboardingCredits($workspaceId, $subscriptionId, $price, $createdBy);
                $this->recordSubscriptionCycle($workspaceId, $subscriptionId, $price, $now, null, null, (int) ($creditResult['credit_lot_id'] ?? 0), 'active', [
                    'source' => 'compass_free_activation',
                ]);
                if ($startedTransaction) {
                    Database::commit();
                }
            } catch (\Throwable $e) {
                if ($startedTransaction && Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
                throw $e;
            }

            return [
                'subscription_id' => $subscriptionId,
                'changed' => true,
                'credited' => $creditResult,
                'snapshot' => $this->getWorkspaceSnapshot($workspaceId),
            ];
        });
    }

    public function syncExpiredSubscriptionPeriods(?int $workspaceId = null): array
    {
        return $this->executeWithReadiness('billing_snapshot', function () use ($workspaceId): array {
            return $this->syncExpiredSubscriptionPeriodsNow($workspaceId);
        });
    }

    public function getWorkspaceSnapshot(int $workspaceId, ?array $actorUser = null): array
    {
        return $this->executeWithReadiness('billing_snapshot', function () use ($workspaceId, $actorUser): array {
            if (!Database::getInstance()->inTransaction()) {
                $this->syncExpiredSubscriptionPeriodsNow($workspaceId);
            }

            $workspace = Database::queryOne(
                "SELECT id, uuid, name, slug, status, plan_status, trial_starts_at, trial_ends_at
                 FROM workspaces
                 WHERE id = ?
                 LIMIT 1",
                [$workspaceId]
            ) ?? [];

            $subscription = Database::queryOne(
                "SELECT ws.*, bpp.price_code, bpp.currency, bpp.amount, bpp.included_tokens, bpp.interval_unit, bpp.interval_count,
                        bp.name AS plan_name, bp.code AS plan_code
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
            ) ?? [];

            $entitlements = $this->planEntitlements->entitlementsForWorkspace($workspaceId);
            $wallet = $this->wallets->getSummary($workspaceId);
            $rawSubscriptionStatus = (string) ($subscription['subscription_status'] ?? ($workspace['plan_status'] ?? 'inactive'));
            $subscriptionStatus = $rawSubscriptionStatus === 'trialing' ? 'active' : $rawSubscriptionStatus;
            if ($subscription !== [] && (string) ($subscription['subscription_status'] ?? '') === 'trialing') {
                $subscription['subscription_status'] = 'active';
            }
            $workspaceStatus = (string) ($workspace['status'] ?? 'active');
            $packageExempt = $this->packageExemptions->isExempt($workspaceId, $workspace);
            $restricted = !$packageExempt
                && (in_array($workspaceStatus, ['suspended', 'archived'], true)
                    || in_array($subscriptionStatus, ['past_due', 'cancelled', 'expired'], true));
            $aiBillingExempt = $packageExempt
                || ($actorUser !== null && Authorization::isSuperAdmin($actorUser));

            return [
                'workspace' => $workspace,
                'subscription' => $subscription,
                'entitlements' => $entitlements,
                'wallet' => $wallet,
                'status' => $subscriptionStatus,
                'subscription_status' => $subscriptionStatus,
                'token_balance' => (int) ($wallet['token_balance'] ?? 0),
                'credit_balance' => (int) ($wallet['credit_balance'] ?? $wallet['token_balance'] ?? 0),
                'available_tokens' => (int) ($wallet['available_tokens'] ?? 0),
                'available_credits' => (int) ($wallet['available_credits'] ?? $wallet['available_tokens'] ?? 0),
                'is_trial_active' => false,
                'trial_starts_at' => null,
                'trial_ends_at' => null,
                'restricted' => $restricted,
                'billing_blocked' => $restricted,
                'show_prompt' => $restricted,
                'is_ai_billing_exempt' => $aiBillingExempt,
                'package_exempt' => $packageExempt,
                'package_exemption_reason' => $packageExempt ? $this->packageExemptions->reason() : '',
                'ai_blocked_reason' => $restricted
                    ? 'subscription_restricted'
                    : ((!$aiBillingExempt && ($wallet['is_depleted'] ?? false)) ? 'wallet_depleted' : null),
            ];
        });
    }

    public function getWorkspaceBillingPortalData(int $workspaceId, int $ledgerLimit = 20, ?array $actorUser = null): array
    {
        return $this->executeWithReadiness('billing_portal', function () use ($workspaceId, $ledgerLimit, $actorUser): array {
            $currency = $this->resolveWorkspaceBillingCurrency($workspaceId);
            $gatewayReady = $this->isPaystackConfigured();
            $mpesaReady = $this->isMpesaConfigured();
            $paymentPolicy = $this->paymentMethodPolicy($workspaceId, $currency);
            $paymentAvailability = (array) ($paymentPolicy['availability'] ?? []);
            $paymentReasons = (array) ($paymentPolicy['reasons'] ?? []);
            $plans = $this->attachPaymentModesToItems($this->listSubscriptionPrices($workspaceId), $gatewayReady, $mpesaReady, $paymentAvailability, $paymentReasons);
            $snapshot = $this->getWorkspaceSnapshot($workspaceId, $actorUser);
            $entitlements = (array) ($snapshot['entitlements'] ?? []);
            $seatUsage = $this->workspaceSeatUsage($workspaceId);
            $packageCards = $this->decoratePackageCardsForWorkspace(
                (new LaunchPackageCatalogService())->cardsFromSubscriptionPrices($plans),
                $snapshot,
                $seatUsage
            );
            $tokenPacks = $this->attachPaymentModesToItems($this->listTokenPacks(), $gatewayReady, $mpesaReady, $paymentAvailability, $paymentReasons);
            if (empty($entitlements['can_top_up'])) {
                $tokenPacks = array_map(static function (array $pack): array {
                    $pack['checkout_available'] = false;
                    $pack['locked_reason'] = 'AI Credit top-ups unlock on Solo Launch and higher plans.';
                    return $pack;
                }, $tokenPacks);
            }

            return [
                'snapshot' => $snapshot,
                'entitlements' => $entitlements,
                'seat_usage' => $seatUsage,
                'plans' => $plans,
                'package_cards' => $packageCards,
                'negotiated_offers' => Database::tableExists('workspace_negotiated_package_offers')
                    ? (new WorkspaceNegotiatedPackageService())->listWorkspaceOffers($workspaceId, 10)
                    : [],
                'token_packs' => $tokenPacks,
                'can_top_up' => !empty($entitlements['can_top_up']),
                'payment_modes' => $this->paymentModes->listAvailableModes($currency, $gatewayReady, $mpesaReady, $paymentAvailability, $paymentReasons),
                'payment_market' => $paymentPolicy['market'] ?? [],
                'payment_market_rule' => $paymentPolicy['applied_rule'] ?? null,
                'ledger' => $this->listWalletLedger($workspaceId, $ledgerLimit),
                'credit_lots' => $this->creditLedger->activeLots($workspaceId, $ledgerLimit),
                'expiring_credits' => $this->creditLedger->expiringLots($workspaceId, 30, $ledgerLimit),
                'credit_ledger' => $this->creditLedger->lotLedger($workspaceId, $ledgerLimit),
                'transactions' => $this->listBillingTransactions($workspaceId, $ledgerLimit),
                'billing_invoices' => (new WorkspacePackageBillingInvoiceService())->listForWorkspace($workspaceId, $ledgerLimit),
                'checkout_sessions' => $this->listCheckoutSessions($workspaceId, $ledgerLimit),
                'ai_usage' => (new AITokenRateLimiterService())->getWorkspaceUsageRollup($workspaceId, 30),
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function catalogPaymentReadiness(string $currency = 'KES'): array
    {
        $paystackReady = $this->isPaystackConfigured();
        $mpesaReady = $this->isMpesaConfigured();
        $globalAvailability = $this->paymentMethodAvailability();
        $policy = $this->paymentMarkets->effectivePolicy(null, $currency, $globalAvailability);
        $availability = (array) ($policy['availability'] ?? []);

        return [
            'paystack_ready' => $paystackReady,
            'mpesa_ready' => $mpesaReady,
            'global_availability' => $globalAvailability,
            'effective_availability' => $availability,
            'market' => $policy['market'] ?? [],
            'modes' => $this->paymentModes->listAvailableModes($currency, $paystackReady, $mpesaReady, $availability, (array) ($policy['reasons'] ?? [])),
        ];
    }

    /**
     * @param list<string> $currencies
     * @return array<string,mixed>
     */
    public function donationPaymentOptions(array $currencies = ['KES', 'USD'], bool $includeBankTransfer = false): array
    {
        $paystackReady = $this->isPaystackConfigured();
        $mpesaReady = $this->isMpesaConfigured();
        $settings = $this->legacyBillingSettings();
        $defaultCurrency = strtoupper(trim((string) ($settings['default_currency'] ?? 'KES')));
        if ($defaultCurrency === '') {
            $defaultCurrency = 'KES';
        }
        $allowedModes = [
            WorkspaceBillingPaymentModeService::MODE_CARD,
            WorkspaceBillingPaymentModeService::MODE_MPESA,
        ];

        if ($includeBankTransfer) {
            $allowedModes[] = WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER;
        }

        $currencyOptions = [];
        $modesByCurrency = [];
        $availabilityByCurrency = [];
        $seenCurrencies = [];

        $candidateCurrencies = array_merge([$defaultCurrency], $currencies, ['KES', 'USD', 'NGN']);
        foreach ($candidateCurrencies as $currency) {
            $currency = strtoupper(trim((string) $currency));
            if ($currency === '' || isset($seenCurrencies[$currency])) {
                continue;
            }
            $seenCurrencies[$currency] = true;

            $availableModes = [];
            $policy = $this->paymentMethodPolicy(null, $currency);
            $availabilityByCurrency[$currency] = (array) ($policy['availability'] ?? []);
            foreach ($this->paymentModes->listAvailableModes(
                $currency,
                $paystackReady,
                $mpesaReady,
                $availabilityByCurrency[$currency],
                (array) ($policy['reasons'] ?? [])
            ) as $mode) {
                $key = (string) ($mode['key'] ?? '');
                if (!in_array($key, $allowedModes, true) || empty($mode['available'])) {
                    continue;
                }

                $availableModes[] = [
                    'key' => $key,
                    'label' => (string) ($mode['label'] ?? $key),
                    'flow_type' => (string) ($mode['flow_type'] ?? ''),
                    'requires_phone' => !empty($mode['requires_phone']),
                ];
            }

            if ($availableModes === []) {
                continue;
            }

            $currencyOptions[] = [
                'value' => $currency,
                'label' => $currency,
            ];
            $modesByCurrency[$currency] = $availableModes;
        }

        $availableDefaultCurrency = isset($modesByCurrency[$defaultCurrency])
            ? $defaultCurrency
            : (string) ($currencyOptions[0]['value'] ?? '');

        return [
            'currencies' => $currencyOptions,
            'modes_by_currency' => $modesByCurrency,
            'default_currency' => $availableDefaultCurrency,
            'has_available_methods' => $availableDefaultCurrency !== '',
            'readiness' => [
                'configured_default_currency' => $defaultCurrency,
                'selected_default_currency' => $availableDefaultCurrency,
                'paystack_ready' => $paystackReady,
                'mpesa_ready' => $mpesaReady,
                'payment_modes' => (array) (
                    $availabilityByCurrency[$availableDefaultCurrency]
                    ?? $this->paymentMethodAvailability()
                ),
                'available_modes_by_currency' => array_map(
                    static fn(array $modes): array => array_values(array_map(
                        static fn(array $mode): string => (string) ($mode['key'] ?? ''),
                        $modes
                    )),
                    $modesByCurrency
                ),
            ],
        ];
    }

    public function previewPackageChange(int $workspaceId, int $billingPlanPriceId): array
    {
        return $this->executeWithReadiness('billing_portal', function () use ($workspaceId, $billingPlanPriceId): array {
            if ($this->packageExemptions->isExempt($workspaceId)) {
                throw new \RuntimeException('Default workspace is package exempt; package changes are disabled.');
            }

            $subscription = $this->loadActiveSubscriptionForPackageChange($workspaceId);
            if (!$subscription) {
                throw new \RuntimeException('This workspace does not have an active package to change.');
            }

            $targetPrice = $this->loadSubscriptionPrice($billingPlanPriceId);
            if ($targetPrice === null) {
                throw new \RuntimeException('Selected package is not available.');
            }
            $this->assertSubscriptionPriceBelongsToWorkspace($targetPrice, $workspaceId);

            $currentPrice = $this->loadSubscriptionPrice((int) ($subscription['billing_plan_price_id'] ?? 0));
            if ($currentPrice === null) {
                throw new \RuntimeException('Current package is not available.');
            }

            $currentRank = $this->planTierRank((string) ($currentPrice['plan_code'] ?? ''));
            $targetRank = $this->planTierRank((string) ($targetPrice['plan_code'] ?? ''));
            $seatUsage = $this->workspaceSeatUsage($workspaceId);
            $targetEntitlements = $this->planEntitlements->entitlementsForPriceRow($targetPrice);
            $seatLimit = (int) ($targetEntitlements['seat_limit'] ?? 0);
            $seatTotal = (int) ($seatUsage['total_usage'] ?? 0);
            $overage = $seatLimit > 0 ? max(0, $seatTotal - $seatLimit) : 0;
            $direction = $targetRank > $currentRank ? 'upgrade' : ($targetRank < $currentRank ? 'downgrade' : 'same');

            return [
                'workspace_id' => $workspaceId,
                'direction' => $direction,
                'change_available' => $direction === 'downgrade' && $overage === 0,
                'current_price' => $currentPrice,
                'target_price' => $targetPrice,
                'current_rank' => $currentRank,
                'target_rank' => $targetRank,
                'effective_at' => (string) (($subscription['current_period_end'] ?? '') ?: $this->calculatePeriodEnd(date('Y-m-d H:i:s'), (string) ($targetPrice['interval_unit'] ?? 'monthly'), (int) ($targetPrice['interval_count'] ?? 1))),
                'seat_usage' => $seatUsage,
                'seat_overage' => [
                    'active_members' => (int) ($seatUsage['active_members'] ?? 0),
                    'pending_invites' => (int) ($seatUsage['pending_invites'] ?? 0),
                    'total_usage' => $seatTotal,
                    'seat_limit' => $seatLimit,
                    'overage' => $overage,
                ],
            ];
        });
    }

    public function scheduleWorkspacePackageDowngrade(int $workspaceId, int $billingPlanPriceId, int $actorUserId): array
    {
        return $this->executeWithReadiness('billing_checkout', function () use ($workspaceId, $billingPlanPriceId, $actorUserId): array {
            if ($workspaceId <= 0) {
                throw new \RuntimeException('No active workspace selected.');
            }
            if ($this->packageExemptions->isExempt($workspaceId)) {
                throw new \RuntimeException('Default workspace is package exempt; package changes are disabled.');
            }

            Database::beginTransaction();
            try {
                $subscription = $this->loadActiveSubscriptionForPackageChange($workspaceId, true);
                if (!$subscription) {
                    throw new \RuntimeException('This workspace does not have an active package to change.');
                }

                $targetPrice = $this->loadSubscriptionPrice($billingPlanPriceId);
                $currentPrice = $this->loadSubscriptionPrice((int) ($subscription['billing_plan_price_id'] ?? 0));
                if ($targetPrice === null || $currentPrice === null) {
                    throw new \RuntimeException('Package pricing is not available.');
                }
                $this->assertSubscriptionPriceBelongsToWorkspace($targetPrice, $workspaceId);

                $currentRank = $this->planTierRank((string) ($currentPrice['plan_code'] ?? ''));
                $targetRank = $this->planTierRank((string) ($targetPrice['plan_code'] ?? ''));
                if ($targetRank >= $currentRank) {
                    throw new \RuntimeException('Only lower-tier packages can be scheduled as downgrades.');
                }

                $seatUsage = $this->workspaceSeatUsage($workspaceId);
                $targetEntitlements = $this->planEntitlements->entitlementsForPriceRow($targetPrice);
                $seatLimit = (int) ($targetEntitlements['seat_limit'] ?? 0);
                $seatTotal = (int) ($seatUsage['total_usage'] ?? 0);
                $overage = $seatLimit > 0 ? max(0, $seatTotal - $seatLimit) : 0;
                if ($overage > 0) {
                    throw new \RuntimeException(sprintf(
                        'Seat cleanup is required before scheduling this downgrade. Remove or revoke %d seat%s first.',
                        $overage,
                        $overage === 1 ? '' : 's'
                    ));
                }

                $scheduledAt = (string) ($subscription['current_period_end'] ?? '');
                if (trim($scheduledAt) === '') {
                    $scheduledAt = $this->calculatePeriodEnd(date('Y-m-d H:i:s'), (string) ($currentPrice['interval_unit'] ?? 'monthly'), (int) ($currentPrice['interval_count'] ?? 1));
                }

                $metadata = [
                    'source' => 'self_service',
                    'actor_user_id' => $actorUserId,
                    'scheduled_at' => $scheduledAt,
                    'created_at' => date('c'),
                    'target_billing_plan_price_id' => (int) $targetPrice['id'],
                    'target_plan_code' => (string) ($targetPrice['plan_code'] ?? ''),
                    'target_price_code' => (string) ($targetPrice['price_code'] ?? ''),
                    'target_seat_limit' => $seatLimit,
                    'seat_usage' => $seatUsage,
                    'current_provider_subscription_code' => (string) ($subscription['provider_subscription_code'] ?? ''),
                    'current_provider_email_token' => (string) ($subscription['provider_email_token'] ?? ''),
                ];

                $existingScheduleMetadata = $this->decodeJson((string) ($subscription['scheduled_change_metadata_json'] ?? ''));
                $oldScheduledDisable = $this->disablePaystackSubscriptionIfPresent(
                    (string) ($existingScheduleMetadata['target_provider_subscription_code'] ?? ''),
                    (string) ($existingScheduleMetadata['target_provider_email_token'] ?? ''),
                    'scheduled'
                );
                if ($oldScheduledDisable !== null) {
                    $metadata['replaced_scheduled_provider_disable_response'] = $oldScheduledDisable;
                }

                $createdProviderSubscription = null;
                if ((float) ($targetPrice['amount'] ?? 0) > 0) {
                    $providerPlanCode = trim((string) ($targetPrice['provider_plan_code'] ?? ''));
                    if ($providerPlanCode === '') {
                        throw new \RuntimeException('The target package is missing a Paystack recurring plan code.');
                    }

                    $createdProviderSubscription = $this->createScheduledProviderSubscription(
                        $workspaceId,
                        $subscription,
                        $targetPrice,
                        $scheduledAt,
                        $actorUserId
                    );
                    $providerDetails = $this->extractSubscriptionProviderDetails((array) ($createdProviderSubscription['data'] ?? []));
                    if ($providerDetails['subscription_code'] === '') {
                        throw new \RuntimeException('Paystack did not return a subscription code for the scheduled downgrade.');
                    }

                    $metadata['target_provider_plan_code'] = $providerPlanCode;
                    $metadata['target_provider_subscription_code'] = $providerDetails['subscription_code'];
                    $metadata['target_provider_email_token'] = $providerDetails['email_token'];
                    $metadata['target_provider_customer_code'] = $providerDetails['customer_code'];
                    $metadata['target_provider_response'] = $createdProviderSubscription;
                }

                $disableResult = $this->disablePaystackSubscriptionIfPresent(
                    (string) ($subscription['provider_subscription_code'] ?? ''),
                    (string) ($subscription['provider_email_token'] ?? ''),
                    'current'
                );
                if ($disableResult !== null) {
                    $metadata['current_provider_disable_response'] = $disableResult;
                }

                Database::execute(
                    "UPDATE workspace_subscriptions
                     SET scheduled_billing_plan_price_id = ?,
                         scheduled_change_type = 'downgrade',
                         scheduled_change_at = ?,
                         scheduled_change_metadata_json = ?,
                         provider_subscription_status = CASE WHEN provider_subscription_code IS NOT NULL THEN 'disabled' ELSE provider_subscription_status END,
                         renewal_status = CASE WHEN provider_subscription_code IS NOT NULL THEN 'non_renewing' ELSE renewal_status END,
                         updated_at = NOW()
                     WHERE id = ?",
                    [
                        (int) $targetPrice['id'],
                        $scheduledAt,
                        json_encode($metadata, JSON_UNESCAPED_SLASHES),
                        (int) $subscription['id'],
                    ]
                );

                Database::commit();

                return [
                    'success' => true,
                    'message' => 'Workspace downgrade scheduled for renewal.',
                    'subscription' => Database::queryOne("SELECT * FROM workspace_subscriptions WHERE id = ? LIMIT 1", [(int) $subscription['id']]) ?? [],
                    'snapshot' => $this->getWorkspaceSnapshot($workspaceId),
                ];
            } catch (\Throwable $e) {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
                throw $e;
            }
        });
    }

    public function cancelScheduledPackageChange(int $workspaceId, int $actorUserId): array
    {
        return $this->executeWithReadiness('billing_checkout', function () use ($workspaceId, $actorUserId): array {
            if ($workspaceId <= 0) {
                throw new \RuntimeException('No active workspace selected.');
            }

            Database::beginTransaction();
            try {
                $subscription = $this->loadActiveSubscriptionForPackageChange($workspaceId, true);
                if (!$subscription || (int) ($subscription['scheduled_billing_plan_price_id'] ?? 0) <= 0) {
                    throw new \RuntimeException('No scheduled package change was found for this workspace.');
                }

                $metadata = $this->decodeJson((string) ($subscription['scheduled_change_metadata_json'] ?? ''));
                $targetDisable = $this->disablePaystackSubscriptionIfPresent(
                    (string) ($metadata['target_provider_subscription_code'] ?? ''),
                    (string) ($metadata['target_provider_email_token'] ?? ''),
                    'scheduled'
                );
                $currentEnable = $this->enablePaystackSubscriptionIfPresent(
                    (string) (($metadata['current_provider_subscription_code'] ?? '') ?: ($subscription['provider_subscription_code'] ?? '')),
                    (string) (($metadata['current_provider_email_token'] ?? '') ?: ($subscription['provider_email_token'] ?? '')),
                    'current'
                );

                $cancelMetadata = $metadata;
                $cancelMetadata['cancelled_by_user_id'] = $actorUserId;
                $cancelMetadata['cancelled_at'] = date('c');
                if ($targetDisable !== null) {
                    $cancelMetadata['target_provider_disable_response'] = $targetDisable;
                }
                if ($currentEnable !== null) {
                    $cancelMetadata['current_provider_enable_response'] = $currentEnable;
                }

                Database::execute(
                    "UPDATE workspace_subscriptions
                     SET scheduled_billing_plan_price_id = NULL,
                         scheduled_change_type = NULL,
                         scheduled_change_at = NULL,
                         scheduled_change_metadata_json = NULL,
                         provider_subscription_status = CASE WHEN provider_subscription_code IS NOT NULL THEN 'active' ELSE provider_subscription_status END,
                         renewal_status = CASE WHEN provider_subscription_code IS NOT NULL THEN 'renewing' ELSE renewal_status END,
                         provider_metadata_json = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [
                        json_encode(array_merge(
                            $this->decodeJson((string) ($subscription['provider_metadata_json'] ?? '')),
                            ['last_cancelled_scheduled_change' => $cancelMetadata]
                        ), JSON_UNESCAPED_SLASHES),
                        (int) $subscription['id'],
                    ]
                );

                Database::commit();

                return [
                    'success' => true,
                    'message' => 'Scheduled package change cancelled.',
                    'subscription' => Database::queryOne("SELECT * FROM workspace_subscriptions WHERE id = ? LIMIT 1", [(int) $subscription['id']]) ?? [],
                    'snapshot' => $this->getWorkspaceSnapshot($workspaceId),
                ];
            } catch (\Throwable $e) {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
                throw $e;
            }
        });
    }

    /**
     * @param list<array<string,mixed>> $cards
     * @param array<string,mixed> $snapshot
     * @param array<string,int> $seatUsage
     * @return list<array<string,mixed>>
     */
    private function decoratePackageCardsForWorkspace(array $cards, array $snapshot, array $seatUsage): array
    {
        $activeSubscription = is_array($snapshot['subscription'] ?? null) ? (array) $snapshot['subscription'] : [];
        $activePlanCode = (string) ($activeSubscription['plan_code'] ?? '');
        $activePriceId = (int) ($activeSubscription['billing_plan_price_id'] ?? 0);
        $activeTierRank = $this->planTierRank((string) (($snapshot['entitlements']['maturity_tier'] ?? '') ?: $activePlanCode));
        $activeIntervalUnit = (string) ($activeSubscription['interval_unit'] ?? '');
        $activeIntervalCount = (int) ($activeSubscription['interval_count'] ?? 0);
        $scheduledPriceId = (int) ($activeSubscription['scheduled_billing_plan_price_id'] ?? 0);
        $scheduledChangeType = (string) ($activeSubscription['scheduled_change_type'] ?? '');
        $scheduledChangeAt = (string) ($activeSubscription['scheduled_change_at'] ?? '');
        $packageExempt = !empty($snapshot['package_exempt'])
            || !empty($snapshot['entitlements']['package_exempt']);

        return array_map(function (array $card) use ($activePlanCode, $activePriceId, $activeTierRank, $activeIntervalUnit, $activeIntervalCount, $scheduledPriceId, $scheduledChangeType, $scheduledChangeAt, $seatUsage, $packageExempt): array {
            $planCode = (string) ($card['plan_code'] ?? $card['code'] ?? '');
            $entitlements = is_array($card['entitlements'] ?? null) ? (array) $card['entitlements'] : [];
            $tierRank = $this->planTierRank((string) (($entitlements['maturity_tier'] ?? '') ?: $planCode));
            $seatLimit = (int) ($entitlements['seat_limit'] ?? 0);
            $totalSeats = (int) ($seatUsage['total_usage'] ?? 0);
            $seatOverageCount = $seatLimit > 0 ? max(0, $totalSeats - $seatLimit) : 0;
            $includedCredits = (int) ($entitlements['included_credits'] ?? $card['billing_price']['included_tokens'] ?? 0);
            $expiryDays = (int) ($entitlements['credit_expiry_days'] ?? 180);
            $isCurrent = $planCode !== '' && $planCode === $activePlanCode;
            $checkoutOptions = array_values((array) ($card['checkout_options'] ?? []));
            $primaryPriceId = (int) ($card['billing_plan_price_id'] ?? 0);
            foreach ($checkoutOptions as $index => $option) {
                $optionPriceId = (int) ($option['billing_plan_price_id'] ?? 0);
                if ($activePriceId > 0 && $optionPriceId === $activePriceId) {
                    $isCurrent = true;
                    $primaryPriceId = $optionPriceId;
                }
                if ($primaryPriceId <= 0 && $optionPriceId > 0) {
                    $primaryPriceId = $optionPriceId;
                }
                if (
                    $optionPriceId > 0
                    && $activeIntervalUnit !== ''
                    && (string) ($option['interval_unit'] ?? '') === $activeIntervalUnit
                    && (int) ($option['interval_count'] ?? 0) === $activeIntervalCount
                ) {
                    $primaryPriceId = $optionPriceId;
                    break;
                }
                if ($index === 0 && $optionPriceId > 0) {
                    $primaryPriceId = $optionPriceId;
                }
            }
            $scheduledForCard = false;
            foreach ($checkoutOptions as $option) {
                if ((int) ($option['billing_plan_price_id'] ?? 0) === $scheduledPriceId) {
                    $scheduledForCard = true;
                    break;
                }
            }
            $direction = $isCurrent
                ? 'current'
                : ($scheduledForCard ? 'scheduled' : ($tierRank > $activeTierRank ? 'upgrade' : ($tierRank < $activeTierRank ? 'downgrade' : 'available')));
            $canCheckout = !empty($card['checkout_available']) || !empty($card['is_free']);
            $changeAvailable = !$packageExempt
                && $primaryPriceId > 0
                && !$isCurrent
                && !$scheduledForCard
                && $canCheckout
                && ($direction !== 'downgrade' || $seatOverageCount === 0);
            $actionLabel = match ($direction) {
                'current' => 'Current package',
                'scheduled' => 'Scheduled for renewal',
                'upgrade' => 'Upgrade now',
                'downgrade' => $seatOverageCount > 0 ? 'Reduce seats to downgrade' : 'Schedule downgrade',
                default => 'Start package',
            };
            $blockedReason = '';
            if ($seatOverageCount > 0 && $direction === 'downgrade') {
                $blockedReason = sprintf(
                    'Remove or revoke %d seat%s before scheduling this downgrade.',
                    $seatOverageCount,
                    $seatOverageCount === 1 ? '' : 's'
                );
            } elseif (!$canCheckout && !$isCurrent && !$scheduledForCard) {
                $blockedReason = (string) ($card['locked_reason'] ?? 'This package is not available for self-service checkout.');
            }

            $card['is_current_package'] = $isCurrent;
            $card['is_scheduled_package'] = $scheduledForCard;
            $card['change_direction'] = $direction;
            $card['change_available'] = $changeAvailable;
            $card['primary_billing_plan_price_id'] = $primaryPriceId;
            $card['action_label'] = $actionLabel;
            $card['effective_at'] = $scheduledForCard ? $scheduledChangeAt : '';
            $card['blocked_reason'] = $blockedReason;
            $card['seat_overage'] = [
                'active_members' => (int) ($seatUsage['active_members'] ?? 0),
                'pending_invites' => (int) ($seatUsage['pending_invites'] ?? 0),
                'total_usage' => $totalSeats,
                'seat_limit' => $seatLimit,
                'overage' => $seatOverageCount,
            ];
            $card['seat_summary'] = $seatLimit > 0
                ? number_format($totalSeats) . ' of ' . number_format($seatLimit) . ' seats in use'
                : number_format($totalSeats) . ' seats in use, unlimited on this package';
            $card['credit_summary'] = $includedCredits > 0
                ? number_format($includedCredits) . ($planCode === WorkspacePlanEntitlementService::PLAN_COMPASS_FREE ? ' one-time onboarding AI Credits' : ' AI Credits per billing cycle')
                : 'No included AI Credits';
            $card['plugin_summary'] = $this->packagePluginSummary($entitlements);
            $card['capability_summary'] = [
                $card['seat_summary'],
                $card['credit_summary'],
                !empty($entitlements['can_top_up']) ? 'AI Credit top-ups available' : 'No AI Credit top-ups',
                !empty($entitlements['business_intelligence_enabled']) ? 'Business Intelligence included' : 'Business Intelligence locked',
                !empty($entitlements['personal_api_key_enabled']) ? 'Personal API key included' : 'Personal API key locked',
                'Credits expire after ' . number_format($expiryDays) . ' days',
            ];
            $card['status_label'] = $isCurrent
                ? 'Current package'
                : ($scheduledForCard ? 'Scheduled ' . ($scheduledChangeType !== '' ? str_replace('_', ' ', $scheduledChangeType) : 'change') : ($tierRank > $activeTierRank ? 'Upgrade' : ($tierRank < $activeTierRank ? 'Downgrade path' : 'Available')));
            $card['status_message'] = $isCurrent
                ? 'This workspace is already on this package. Choose another package to change access.'
                : ($scheduledForCard
                    ? 'Scheduled for ' . ($scheduledChangeAt !== '' ? $scheduledChangeAt : 'next renewal') . '.'
                    : ($blockedReason !== '' ? $blockedReason : ($tierRank > $activeTierRank ? 'Unlock more seats, credits, or plugin access.' : 'Review how this package changes workspace capability.')));

            if ($direction === 'downgrade') {
                $card['checkout_available'] = false;
            }

            if ($packageExempt) {
                $card['is_current_package'] = false;
                $card['is_scheduled_package'] = false;
                $card['checkout_available'] = false;
                $card['checkout_options'] = [];
                $card['locked_reason'] = DefaultWorkspacePackageExemptionService::REASON;
                $card['status_label'] = 'Package exempt';
                $card['status_message'] = 'Default workspace package exempt. Package changes are not required for this workspace.';
                $card['change_direction'] = 'exempt';
                $card['change_available'] = false;
                $card['action_label'] = 'Package exempt';
                $card['blocked_reason'] = DefaultWorkspacePackageExemptionService::REASON;
            }

            return $card;
        }, $cards);
    }

    /**
     * @return array<string,int>
     */
    private function workspaceSeatUsage(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return ['active_members' => 0, 'pending_invites' => 0, 'total_usage' => 0];
        }

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

        return [
            'active_members' => $activeMembers,
            'pending_invites' => $pendingInvites,
            'total_usage' => $activeMembers + $pendingInvites,
        ];
    }

    private function loadActiveSubscriptionForPackageChange(int $workspaceId, bool $forUpdate = false): ?array
    {
        $forUpdateSql = $forUpdate ? ' FOR UPDATE' : '';

        return Database::queryOne(
            "SELECT ws.*, bpp.price_code, bpp.currency, bpp.amount, bpp.included_tokens, bpp.interval_unit, bpp.interval_count,
                    bp.name AS plan_name, bp.code AS plan_code
             FROM workspace_subscriptions ws
             JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE ws.workspace_id = ?
               AND ws.subscription_status IN ('active', 'trialing', 'past_due')
             ORDER BY FIELD(ws.subscription_status, 'active', 'trialing', 'past_due'), ws.id DESC
             LIMIT 1{$forUpdateSql}",
            [$workspaceId]
        ) ?: null;
    }

    /**
     * @param array<string,mixed> $subscription
     * @param array<string,mixed> $targetPrice
     * @return array<string,mixed>
     */
    private function createScheduledProviderSubscription(
        int $workspaceId,
        array $subscription,
        array $targetPrice,
        string $scheduledAt,
        int $actorUserId
    ): array {
        $providerPlanCode = trim((string) ($targetPrice['provider_plan_code'] ?? ''));
        if ($providerPlanCode === '') {
            throw new \RuntimeException('The target package is missing a Paystack recurring plan code.');
        }

        $customer = trim((string) ($subscription['provider_customer_code'] ?? ''));
        if ($customer === '') {
            $customer = $this->resolveCustomerEmail($workspaceId, $actorUserId);
        }
        if ($customer === '') {
            throw new \RuntimeException('A Paystack customer is required before scheduling this downgrade.');
        }

        $payload = [
            'customer' => $customer,
            'plan' => $providerPlanCode,
            'start_date' => (new \DateTimeImmutable($scheduledAt))->format(\DateTimeInterface::ATOM),
        ];
        $authorization = $this->providerAuthorizationCodeFromSubscription($subscription);
        if ($authorization !== '') {
            $payload['authorization'] = $authorization;
        }

        return $this->resolveGateway()->createSubscription($payload);
    }

    private function providerAuthorizationCodeFromSubscription(array $subscription): string
    {
        $metadata = $this->decodeJson((string) ($subscription['provider_metadata_json'] ?? ''));
        $authorization = is_array($metadata['authorization'] ?? null) ? (array) $metadata['authorization'] : [];

        return trim((string) (
            $authorization['authorization_code']
            ?? $authorization['authorizationCode']
            ?? $metadata['authorization_code']
            ?? ''
        ));
    }

    private function disablePaystackSubscriptionIfPresent(string $code, string $token, string $label): ?array
    {
        $code = trim($code);
        $token = trim($token);
        if ($code === '') {
            return null;
        }
        if ($token === '') {
            throw new \RuntimeException(ucfirst($label) . ' Paystack subscription is missing the email token needed to update renewal.');
        }

        return $this->resolveGateway()->disableSubscription($code, $token);
    }

    private function enablePaystackSubscriptionIfPresent(string $code, string $token, string $label): ?array
    {
        $code = trim($code);
        $token = trim($token);
        if ($code === '') {
            return null;
        }
        if ($token === '') {
            throw new \RuntimeException(ucfirst($label) . ' Paystack subscription is missing the email token needed to update renewal.');
        }

        return $this->resolveGateway()->enableSubscription($code, $token);
    }

    private function planTierRank(string $tier): int
    {
        $normalized = strtolower(str_replace('_', '-', trim($tier)));
        return match ($normalized) {
            'free', WorkspacePlanEntitlementService::PLAN_COMPASS_FREE => 0,
            'solo', WorkspacePlanEntitlementService::PLAN_SOLO_LAUNCH => 10,
            'founder', WorkspacePlanEntitlementService::PLAN_FOUNDER_PLUS => 20,
            'growth', WorkspacePlanEntitlementService::PLAN_GROWTH_STUDIO => 30,
            'scale', WorkspacePlanEntitlementService::PLAN_SCALE_CUSTOM => 40,
            default => 0,
        };
    }

    /**
     * @param array<string,mixed> $entitlements
     */
    private function packagePluginSummary(array $entitlements): string
    {
        $plugins = [];
        if (!empty($entitlements['business_intelligence_enabled'])) {
            $plugins[] = 'Business Intelligence';
        }
        if (!empty($entitlements['personal_api_key_enabled'])) {
            $plugins[] = 'personal API key';
        }

        return $plugins === [] ? 'Core plugins only' : implode(' + ', $plugins);
    }

    public function listSubscriptionPrices(?int $workspaceId = null): array
    {
        $hasPrivatePrices = Database::columnExists('billing_plan_prices', 'workspace_id');
        $hasNegotiatedOffers = Database::tableExists('workspace_negotiated_package_offers');
        $workspaceSelect = $hasPrivatePrices ? 'bpp.workspace_id,' : 'NULL AS workspace_id,';
        $privateFilter = '';
        $params = [];
        if ($hasPrivatePrices && $hasNegotiatedOffers && $workspaceId !== null && $workspaceId > 0) {
            $privateFilter = "AND (
                    bpp.workspace_id IS NULL
                    OR (
                        bpp.workspace_id = ?
                        AND EXISTS (
                            SELECT 1
                            FROM workspace_negotiated_package_offers wno
                            WHERE wno.negotiated_billing_plan_price_id = bpp.id
                              AND wno.workspace_id = ?
                              AND wno.status IN ('offered', 'accepted', 'active')
                              AND (wno.starts_at IS NULL OR wno.starts_at <= NOW())
                              AND (wno.expires_at IS NULL OR wno.expires_at >= NOW())
                        )
                    )
                )";
            $params[] = $workspaceId;
            $params[] = $workspaceId;
        } elseif ($hasPrivatePrices) {
            $privateFilter = 'AND bpp.workspace_id IS NULL';
        }

        return array_map([$this, 'hydrateSubscriptionPriceRow'], Database::query(
            "SELECT bpp.id, {$workspaceSelect} bpp.price_code, bpp.currency, bpp.interval_unit, bpp.interval_count, bpp.amount,
                    bpp.included_tokens, bpp.is_default, bpp.metadata_json, bpp.provider, bpp.provider_plan_code,
                    bpp.provider_plan_id, bpp.provider_plan_status, bpp.provider_plan_synced_at,
                    bp.id AS plan_id, bp.code AS plan_code, bp.name AS plan_name, bp.description
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bp.billing_type = 'subscription'
               AND bp.is_active = 1
               AND bpp.is_active = 1
               {$privateFilter}
             ORDER BY COALESCE(bpp.workspace_id, 0) ASC, bpp.is_default DESC, bpp.amount ASC, bpp.id ASC",
            $params
        ));
    }

    public function listTokenPacks(): array
    {
        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
            $row['credit_quantity'] = (int) ($row['token_quantity'] ?? 0);
            $row['display_name'] = (string) (($row['metadata']['display_name'] ?? '') ?: ($row['plan_name'] ?? 'AI Credit Pack'));
            $row['checkout_available'] = true;
            return $row;
        }, Database::query(
            "SELECT tpp.id, tpp.token_quantity, tpp.sort_order, bpp.id AS billing_plan_price_id,
                    bpp.price_code, bpp.currency, bpp.amount,
                    bpp.metadata_json, bp.id AS plan_id, bp.code AS plan_code, bp.name AS plan_name, bp.description
             FROM token_pack_prices tpp
             JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE tpp.is_active = 1
               AND bpp.is_active = 1
               AND bp.is_active = 1
             ORDER BY tpp.sort_order ASC, tpp.id ASC"
        ));
    }

    private function syncExpiredSubscriptionPeriodsNow(?int $workspaceId = null): array
    {
        $params = [];
        $workspaceClause = '';
        if ($workspaceId !== null && $workspaceId > 0) {
            $workspaceClause = ' AND ws.workspace_id = ?';
            $params[] = $workspaceId;
        }

        Database::beginTransaction();
        try {
            $expiredRows = Database::query(
                "SELECT ws.id, ws.workspace_id, ws.subscription_status, ws.current_period_end,
                        ws.scheduled_billing_plan_price_id, ws.scheduled_change_type, ws.scheduled_change_at,
                        ws.scheduled_change_metadata_json, w.status AS workspace_status
                 FROM workspace_subscriptions ws
                 JOIN workspaces w ON w.id = ws.workspace_id
                 WHERE ws.subscription_status = 'active'
                   AND ws.current_period_end IS NOT NULL
                   AND ws.current_period_end < NOW()
                   {$workspaceClause}
                 FOR UPDATE",
                $params
            );

            $workspaceIds = [];
            $updatedSubscriptions = 0;
            foreach ($expiredRows as $row) {
                $subscriptionId = (int) ($row['id'] ?? 0);
                $rowWorkspaceId = (int) ($row['workspace_id'] ?? 0);
                if ($subscriptionId <= 0 || $rowWorkspaceId <= 0) {
                    continue;
                }

                if ($this->applyDueScheduledFreeDowngrade($row)) {
                    $updatedSubscriptions++;
                    continue;
                }

                Database::execute(
                    "UPDATE workspace_subscriptions
                     SET subscription_status = 'expired',
                         next_billing_at = NULL,
                         updated_at = NOW()
                     WHERE id = ?
                       AND subscription_status = 'active'",
                    [$subscriptionId]
                );
                $updatedSubscriptions++;
                $workspaceIds[$rowWorkspaceId] = (string) ($row['workspace_status'] ?? 'active');
            }

            $updatedWorkspaces = 0;
            foreach ($workspaceIds as $rowWorkspaceId => $workspaceStatus) {
                if (in_array((string) $workspaceStatus, ['suspended', 'archived'], true)) {
                    continue;
                }

                Database::execute(
                    "UPDATE workspaces
                     SET plan_status = 'past_due',
                         updated_at = NOW()
                     WHERE id = ?
                       AND status NOT IN ('suspended', 'archived')",
                    [(int) $rowWorkspaceId]
                );
                $updatedWorkspaces++;
            }

            Database::commit();

            return [
                'expired_subscriptions' => $updatedSubscriptions,
                'workspaces_marked_past_due' => $updatedWorkspaces,
                'workspace_ids' => array_map('intval', array_keys($workspaceIds)),
                'synced_at' => date('c'),
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $subscriptionRow
     */
    private function applyDueScheduledFreeDowngrade(array $subscriptionRow): bool
    {
        if ((string) ($subscriptionRow['scheduled_change_type'] ?? '') !== 'downgrade') {
            return false;
        }

        $targetPriceId = (int) ($subscriptionRow['scheduled_billing_plan_price_id'] ?? 0);
        if ($targetPriceId <= 0) {
            return false;
        }

        $targetPrice = $this->loadSubscriptionPrice($targetPriceId);
        if ($targetPrice === null || (float) ($targetPrice['amount'] ?? 0) > 0) {
            return false;
        }

        $workspaceId = (int) ($subscriptionRow['workspace_id'] ?? 0);
        $subscriptionId = (int) ($subscriptionRow['id'] ?? 0);
        if ($workspaceId <= 0 || $subscriptionId <= 0) {
            return false;
        }

        $periodStart = (string) (($subscriptionRow['current_period_end'] ?? '') ?: date('Y-m-d H:i:s'));
        $metadata = $this->decodeJson((string) ($subscriptionRow['scheduled_change_metadata_json'] ?? ''));
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 provider = 'internal',
                 provider_reference = ?,
                 provider_subscription_code = NULL,
                 provider_customer_code = NULL,
                 provider_email_token = NULL,
                 provider_subscription_status = 'active',
                 renewal_status = 'free',
                 provider_metadata_json = ?,
                 subscription_status = 'active',
                 current_period_start = ?,
                 current_period_end = NULL,
                 next_billing_at = NULL,
                 scheduled_billing_plan_price_id = NULL,
                 scheduled_change_type = NULL,
                 scheduled_change_at = NULL,
                 scheduled_change_metadata_json = NULL,
                 updated_at = NOW()
             WHERE id = ?",
            [
                (int) $targetPrice['id'],
                'scheduled_free_' . $workspaceId . '_' . date('YmdHis'),
                json_encode([
                    'source' => 'scheduled_free_downgrade',
                    'scheduled_metadata' => $metadata,
                    'applied_at' => date('c'),
                ], JSON_UNESCAPED_SLASHES),
                $periodStart,
                $subscriptionId,
            ]
        );

        Database::execute(
            "UPDATE workspaces
             SET plan_status = 'active',
                 status = CASE WHEN status IN ('suspended', 'archived') THEN status ELSE 'active' END,
                 updated_at = NOW()
             WHERE id = ?",
            [$workspaceId]
        );

        $creditResult = $this->grantCompassFreeOnboardingCredits(
            $workspaceId,
            $subscriptionId,
            $targetPrice,
            isset($metadata['actor_user_id']) ? (int) $metadata['actor_user_id'] : null
        );
        $this->recordSubscriptionCycle($workspaceId, $subscriptionId, $targetPrice, $periodStart, null, null, (int) ($creditResult['credit_lot_id'] ?? 0), 'active', [
            'source' => 'scheduled_free_downgrade',
        ]);

        return true;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function attachPaymentModesToItems(
        array $items,
        bool $gatewayReady,
        ?bool $mpesaReady = null,
        ?array $paymentAvailability = null,
        ?array $paymentAvailabilityReasons = null
    ): array
    {
        foreach ($items as &$item) {
            if (!array_key_exists('token_quantity', $item)) {
                $item = $this->hydrateSubscriptionPriceRow($item);
                $item['payment_modes'] = $this->subscriptionPaymentModes($item, $gatewayReady, $mpesaReady, $paymentAvailability, $paymentAvailabilityReasons);
                $item['card_recurring_ready'] = $this->paymentModeAvailable($item['payment_modes'], WorkspaceBillingPaymentModeService::MODE_CARD);
                $item['manual_payment_available'] = $this->paymentModeAvailable($item['payment_modes'], WorkspaceBillingPaymentModeService::MODE_MPESA)
                    || $this->paymentModeAvailable($item['payment_modes'], WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER);
                if ((float) ($item['amount'] ?? 0) > 0 && !empty($item['catalog_checkout_enabled']) && $this->hasAvailablePaymentMode($item['payment_modes'])) {
                    $item['checkout_available'] = true;
                    unset($item['locked_reason'], $item['checkout_blocked_reason']);
                } elseif ((float) ($item['amount'] ?? 0) > 0) {
                    $item['checkout_available'] = false;
                    $item['locked_reason'] = empty($item['catalog_checkout_enabled'])
                        ? 'Checkout is disabled for this package.'
                        : (string) ($item['payment_modes'][0]['help'] ?? 'Payment methods are temporarily unavailable.');
                }
                continue;
            }

            $item['payment_modes'] = $this->filterPaymentModesByAllowlist(
                $this->paymentModes->listAvailableModes(
                    (string) ($item['currency'] ?? 'KES'),
                    $gatewayReady,
                    $mpesaReady,
                    $paymentAvailability,
                    $paymentAvailabilityReasons
                ),
                $this->allowedPaymentModesForPrice((int) ($item['billing_plan_price_id'] ?? 0))
            );
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateSubscriptionPriceRow(array $row): array
    {
        $metadata = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
        $amount = (float) ($row['amount'] ?? 0);
        $interval = (string) ($row['interval_unit'] ?? 'monthly');
        $providerPlanCode = trim((string) ($row['provider_plan_code'] ?? ''));
        $catalogCheckoutEnabled = (bool) ($metadata['checkout_available'] ?? true);
        $requiresProviderPlan = (bool) ($metadata['requires_provider_plan'] ?? ($amount > 0));

        $row['metadata'] = $metadata;
        $row['entitlements'] = $this->planEntitlements->entitlementsForPriceRow($row);
        $row['workspace_id'] = (int) ($row['workspace_id'] ?? $metadata['workspace_id'] ?? 0);
        $row['workspace_negotiated'] = !empty($metadata['workspace_negotiated']);
        $row['is_workspace_private'] = $row['workspace_id'] > 0 || !empty($metadata['workspace_private']);
        $row['is_launch_package'] = !empty($metadata['launch_package']);
        $row['is_free'] = $amount <= 0;
        $row['is_recurring'] = $amount > 0 && in_array($interval, ['monthly', 'yearly'], true);
        $row['catalog_checkout_enabled'] = $catalogCheckoutEnabled || $amount <= 0;
        $row['checkout_available'] = $amount <= 0 || $catalogCheckoutEnabled;
        $row['requires_provider_plan'] = $requiresProviderPlan;
        $row['provider_plan_configured'] = $providerPlanCode !== '';
        $row['card_recurring_ready'] = $amount > 0 && (!$requiresProviderPlan || $providerPlanCode !== '');
        $row['manual_payment_available'] = false;
        $row['billing_cadence_label'] = $interval === 'yearly' ? 'Annual' : 'Monthly';
        $row['display_price'] = strtoupper((string) ($row['currency'] ?? 'KES')) . ' ' . number_format($amount, 0);

        if ($amount > 0 && $requiresProviderPlan && $providerPlanCode === '') {
            $row['provider_plan_warning'] = sprintf(
                'Paystack recurring plan is not configured for %s %s.',
                (string) ($row['plan_name'] ?? 'this plan'),
                strtolower((string) $row['billing_cadence_label'])
            );
        }

        return (new LaunchPackageCatalogService())->decorateBillingPlanRow($row);
    }

    /**
     * @param array<string,mixed> $price
     * @return list<array<string,mixed>>
     */
    private function subscriptionPaymentModes(
        array $price,
        bool $gatewayReady,
        ?bool $mpesaReady = null,
        ?array $paymentAvailability = null,
        ?array $paymentAvailabilityReasons = null
    ): array
    {
        if ((float) ($price['amount'] ?? 0) <= 0) {
            return [[
                'key' => 'free',
                'label' => 'Auto-activated',
                'flow_type' => 'free',
                'requires_phone' => false,
                'available' => true,
                'help' => 'Compass Free activates without checkout.',
            ]];
        }

        $configured = trim((string) ($price['provider_plan_code'] ?? '')) !== '';
        $modes = [];
        foreach ($this->paymentModes->listAvailableModes(
            (string) ($price['currency'] ?? 'KES'),
            $gatewayReady,
            $mpesaReady,
            $paymentAvailability,
            $paymentAvailabilityReasons
        ) as $mode) {
            $key = (string) ($mode['key'] ?? '');
            if (empty($price['catalog_checkout_enabled'])) {
                $mode['available'] = false;
                $mode['help'] = 'Checkout is disabled for this package.';
                $modes[] = $mode;
                continue;
            }
            if ($key === WorkspaceBillingPaymentModeService::MODE_CARD) {
                $mode['label'] = 'Card autopay';
                $mode['available'] = !empty($mode['available']) && $configured;
                $mode['help'] = !empty($mode['available'])
                    ? 'Recurring card checkout through Paystack.'
                    : ((string) ($mode['reason'] ?? '') !== ''
                        ? (string) $mode['reason']
                        : 'Run the Paystack launch plan sync before accepting card autopay for this package.');
            } elseif ($key === WorkspaceBillingPaymentModeService::MODE_MPESA) {
                $mode['label'] = 'M-Pesa manual period';
                $mode['help'] = !empty($mode['available'])
                    ? 'Manual one-period package payment. Renewal is not automatic.'
                    : (string) ($mode['reason'] ?? 'M-Pesa is not available for this package.');
            } elseif ($key === WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER) {
                $mode['label'] = 'Bank transfer manual period';
                $mode['help'] = !empty($mode['available'])
                    ? 'Manual one-period package payment. Renewal is not automatic.'
                    : (string) ($mode['reason'] ?? 'Bank transfer is not available for this package.');
            }
            $modes[] = $mode;
        }

        return $this->filterPaymentModesByAllowlist($modes, $this->allowedPaymentModesForPrice((int) ($price['id'] ?? 0)));
    }

    /**
     * @param list<array<string,mixed>> $paymentModes
     */
    private function hasAvailablePaymentMode(array $paymentModes): bool
    {
        foreach ($paymentModes as $mode) {
            if (!empty($mode['available'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $paymentModes
     */
    private function paymentModeAvailable(array $paymentModes, string $modeKey): bool
    {
        foreach ($paymentModes as $mode) {
            if ((string) ($mode['key'] ?? '') === $modeKey && !empty($mode['available'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedPaymentModesForPrice(int $billingPlanPriceId): array
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

    /**
     * @param list<array<string,mixed>> $modes
     * @param list<string> $allowlist
     * @return list<array<string,mixed>>
     */
    private function filterPaymentModesByAllowlist(array $modes, array $allowlist): array
    {
        if ($allowlist === []) {
            return $modes;
        }

        return array_values(array_filter($modes, static function (array $mode) use ($allowlist): bool {
            return in_array((string) ($mode['key'] ?? ''), $allowlist, true);
        }));
    }

    public function listWalletLedger(int $workspaceId, int $limit = 20): array
    {
        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
            return $row;
        }, Database::query(
            "SELECT id, entry_type, reference_type, reference_id, external_reference, token_delta, balance_after, reserved_after,
                    status, description, metadata_json, created_at
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    public function listBillingTransactions(int $workspaceId, int $limit = 20): array
    {
        $rows = Database::query(
            "SELECT id, checkout_session_id, subscription_id, wallet_ledger_id, provider, provider_reference,
                    provider_subscription_code, provider_customer_code,
                    transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json, created_at
             FROM billing_transactions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        );
        $invoiceSummaries = (new WorkspacePackageBillingInvoiceService())->summariesForTransactionIds(array_column($rows, 'id'));

        return array_map(function (array $row) use ($invoiceSummaries): array {
            $row['metadata'] = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
            $row['billing_invoice'] = $invoiceSummaries[(int) ($row['id'] ?? 0)] ?? null;
            return $row;
        }, $rows);
    }

    public function listCheckoutSessions(int $workspaceId, int $limit = 20): array
    {
        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
            $row['instructions'] = $this->decodeJson((string) ($row['instructions_json'] ?? ''));
            return $row;
        }, Database::query(
            "SELECT id, user_id, checkout_type, provider_reference, provider_plan_code, provider_subscription_code,
                    provider_customer_code, status, payment_mode, flow_type, currency, amount,
                    billing_plan_price_id, token_pack_price_id, authorization_url, callback_url, customer_phone,
                    display_text, instructions_json, metadata_json, paid_at, expires_at, created_at, updated_at
             FROM billing_checkout_sessions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    public function listProviderEvents(int $workspaceId, int $limit = 20): array
    {
        return array_map(function (array $row): array {
            $row['payload'] = $this->decodeJson((string) ($row['payload_json'] ?? ''));
            return $row;
        }, Database::query(
            "SELECT id, provider, workspace_id, event_name, event_reference, processing_status, processing_message,
                    signature, payload_json, processed_at, created_at
             FROM billing_provider_events
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    public function getProviderEventById(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }

        $event = Database::queryOne(
            "SELECT id, provider, workspace_id, event_name, event_reference, processing_status, processing_message,
                    signature, payload_json, processed_at, created_at
             FROM billing_provider_events
             WHERE id = ?
             LIMIT 1",
            [$eventId]
        );

        if ($event === null) {
            return null;
        }

        $event['payload'] = $this->decodeJson((string) ($event['payload_json'] ?? ''));
        return $event;
    }

    public function createSubscriptionCheckout(
        int $workspaceId,
        int $billingPlanPriceId,
        ?int $userId = null,
        ?string $callbackUrl = null
    ): array {
        return $this->createCheckout($workspaceId, ['billing_plan_price_id' => $billingPlanPriceId], $userId, $callbackUrl);
    }

    public function createTokenTopUpCheckout(
        int $workspaceId,
        int $tokenPackPriceId,
        ?int $userId = null,
        ?string $callbackUrl = null
    ): array {
        return $this->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackPriceId], $userId, $callbackUrl);
    }

    public function createDonationCheckout(
        int $workspaceId,
        float $amount,
        string $currency = 'KES',
        ?int $userId = null,
        ?string $callbackUrl = null,
        array $selection = []
    ): array {
        return $this->executeWithReadiness('billing_checkout', function () use ($workspaceId, $amount, $currency, $userId, $callbackUrl, $selection): array {
            $amount = round($amount, 2);
            if ($workspaceId <= 0 || $amount <= 0) {
                throw new \RuntimeException('A workspace and donation amount are required.');
            }

            $currency = strtoupper(trim($currency));
            if ($currency === '') {
                $currency = $this->resolveWorkspaceBillingCurrency($workspaceId);
            }

            $paymentMode = $this->paymentModes->normalize((string) ($selection['payment_mode'] ?? WorkspaceBillingPaymentModeService::MODE_CARD));
            $paystackReady = $this->isPaystackConfigured();
            $mpesaReady = $this->isMpesaConfigured();
            $paymentPolicy = $this->paymentMethodPolicy($workspaceId, $currency);
            $modeConfig = $this->paymentModes->assertAvailable(
                $paymentMode,
                $currency,
                $paystackReady,
                $mpesaReady,
                (array) ($paymentPolicy['availability'] ?? []),
                (array) ($paymentPolicy['reasons'] ?? [])
            );
            $customerPhone = trim((string) ($selection['customer_phone'] ?? ''));
            if (!empty($modeConfig['requires_phone']) && $customerPhone === '') {
                throw new \RuntimeException('A customer phone number is required for the selected payment mode.');
            }

            $reference = 'don_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
            $providedCustomerEmail = trim((string) ($selection['customer_email'] ?? ''));
            $validProvidedCustomerEmail = filter_var($providedCustomerEmail, FILTER_VALIDATE_EMAIL)
                ? $providedCustomerEmail
                : null;
            $customerEmail = $validProvidedCustomerEmail
                ?? ($paymentMode === WorkspaceBillingPaymentModeService::MODE_MPESA
                    ? ''
                    : $this->resolveCustomerEmail($workspaceId, $userId));
            $returnUrls = $this->resolveCheckoutReturnUrls($callbackUrl, $selection);
            $callbackUrl = $returnUrls['callback_url'];
            $returnTo = $returnUrls['return_to'];
            $source = trim((string) ($selection['source'] ?? 'workspace_donation'));
            if ($source === '') {
                $source = 'workspace_donation';
            }
            $message = trim((string) ($selection['message'] ?? ''));

            $providerMetadata = [
                'source' => $source,
                'workspace_id' => $workspaceId,
                'checkout_type' => 'donation',
                'payment_mode' => $paymentMode,
                'return_to' => $returnTo,
                'message' => $message,
                'internal_reference' => $reference,
            ];
            if ($validProvidedCustomerEmail !== null) {
                $providerMetadata['customer_email'] = $validProvidedCustomerEmail;
            }
            if ($customerPhone !== '') {
                $providerMetadata['customer_phone'] = $customerPhone;
            }
            $providerCheckout = $this->initiateProviderCheckout(
                $paymentMode,
                $amount,
                $currency,
                $reference,
                $customerEmail,
                $customerPhone !== '' ? $customerPhone : null,
                $callbackUrl,
                $providerMetadata,
                'CRM donation'
            );
            $gatewayData = (array) ($providerCheckout['gateway_data'] ?? []);
            $response = (array) ($providerCheckout['gateway_response'] ?? []);
            $flowType = (string) ($providerCheckout['flow_type'] ?? ($modeConfig['flow_type'] ?? 'redirect'));
            $displayText = $this->extractProviderDisplayText($gatewayData);
            $instructions = $this->extractProviderInstructions($gatewayData);
            $expiresAt = $this->normalizeProviderDateTime($gatewayData['expires_at'] ?? null)
                ?? date('Y-m-d H:i:s', strtotime('+1 hour'));

            Database::execute(
                "INSERT INTO billing_checkout_sessions
                 (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount,
                  billing_plan_price_id, token_pack_price_id, payment_mode, flow_type, authorization_url, callback_url,
                  customer_phone, display_text, instructions_json, metadata_json, expires_at)
                 VALUES (?, ?, ?, 'donation', ?, 'pending', ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    (string) ($providerCheckout['provider'] ?? 'paystack'),
                    (string) ($providerCheckout['provider_reference'] ?? $reference),
                    $currency,
                    $amount,
                    $paymentMode,
                    $flowType,
                    (string) ($providerCheckout['authorization_url'] ?? ''),
                    (string) ($providerCheckout['callback_url'] ?? $callbackUrl),
                    ($providerCheckout['customer_phone'] ?? $customerPhone) ?: null,
                    $displayText !== '' ? $displayText : null,
                    $instructions === [] ? null : json_encode($instructions, JSON_UNESCAPED_SLASHES),
                    json_encode([
                        'gateway_response' => $response,
                        'selection' => [
                            'checkout_type' => 'donation',
                            'payment_mode' => $paymentMode,
                            'internal_reference' => $reference,
                            'return_to' => $returnTo,
                            'message' => $message,
                            'source' => $source,
                            'customer_email' => $validProvidedCustomerEmail,
                            'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                    $expiresAt,
                ]
            );

            return [
                'reference' => (string) ($providerCheckout['provider_reference'] ?? $reference),
                'internal_reference' => $reference,
                'checkout_session_id' => (int) Database::lastInsertId(),
                'payment_mode' => $paymentMode,
                'flow_type' => $flowType,
                'authorization_url' => (string) ($providerCheckout['authorization_url'] ?? ''),
                'access_code' => (string) ($providerCheckout['access_code'] ?? ''),
                'display_text' => $displayText !== '' ? $displayText : null,
                'instructions' => $this->userFacingCheckoutInstructions($paymentMode, $instructions),
                'expires_at' => $expiresAt,
                'amount' => $amount,
                'currency' => $currency,
                'return_to' => $returnTo,
                'checkout_type' => 'donation',
            ];
        });
    }

    public function donationCheckoutStatus(int $checkoutSessionId, string $reference): array
    {
        return $this->executeWithReadiness('billing_finalize', function () use ($checkoutSessionId, $reference): array {
            $checkoutSessionId = max(0, $checkoutSessionId);
            $reference = trim($reference);
            if ($checkoutSessionId <= 0 || $reference === '') {
                throw new \RuntimeException('Donation checkout reference is required.');
            }

            $checkout = Database::queryOne(
                "SELECT id, checkout_type, provider_reference, status, payment_mode, amount, currency, paid_at, expires_at, updated_at
                 FROM billing_checkout_sessions
                 WHERE id = ?
                   AND provider_reference = ?
                 LIMIT 1",
                [$checkoutSessionId, $reference]
            );

            if (!$checkout || (string) ($checkout['checkout_type'] ?? '') !== 'donation') {
                throw new \RuntimeException('Donation checkout was not found.');
            }

            return $this->formatDonationCheckoutStatus($checkout);
        });
    }

    public function createCheckout(
        int $workspaceId,
        array $selection,
        ?int $userId = null,
        ?string $callbackUrl = null
    ): array {
        return $this->executeWithReadiness('billing_checkout', function () use ($workspaceId, $selection, $userId, $callbackUrl): array {
            $subscriptionPriceId = (int) ($selection['billing_plan_price_id'] ?? 0);
            $tokenPackPriceId = (int) ($selection['token_pack_price_id'] ?? 0);

            if ($workspaceId <= 0 || ($subscriptionPriceId <= 0 && $tokenPackPriceId <= 0)) {
                throw new \RuntimeException('A workspace and at least one billing item are required.');
            }

            if ($subscriptionPriceId > 0 && $this->packageExemptions->isExempt($workspaceId)) {
                throw new \RuntimeException('Default workspace is package exempt; package checkout is disabled.');
            }

            $subscriptionPrice = $subscriptionPriceId > 0 ? $this->loadSubscriptionPrice($subscriptionPriceId) : null;
            $tokenPack = $tokenPackPriceId > 0 ? $this->loadTokenPack($tokenPackPriceId) : null;

            if ($subscriptionPrice === null && $tokenPack === null) {
                throw new \RuntimeException('No active billing item is available for checkout.');
            }
            if ($subscriptionPrice !== null) {
                $this->assertSubscriptionPriceBelongsToWorkspace($subscriptionPrice, $workspaceId);
            }

            if ($tokenPack !== null && !$this->planEntitlements->canTopUp($workspaceId)) {
                throw new \RuntimeException('AI Credit top-ups unlock on Solo Launch and higher plans.');
            }

            if ($subscriptionPrice !== null && $tokenPack !== null) {
                throw new \RuntimeException('Recurring workspace packages cannot be purchased together with token packs. Choose the package first, then refill tokens separately.');
            }

            $currency = (string) ($subscriptionPrice['currency'] ?? $tokenPack['currency'] ?? 'KES');

            $amount = (float) ($subscriptionPrice['amount'] ?? 0) + (float) ($tokenPack['amount'] ?? 0);
            $checkoutType = $subscriptionPrice !== null && $tokenPack !== null
                ? 'mixed'
                : ($subscriptionPrice !== null ? 'subscription' : 'token_pack');
            $paymentMode = $this->paymentModes->normalize((string) ($selection['payment_mode'] ?? WorkspaceBillingPaymentModeService::MODE_CARD));
            $allowlistPriceId = $subscriptionPrice !== null
                ? (int) ($subscriptionPrice['id'] ?? 0)
                : (int) ($tokenPack['billing_plan_price_id'] ?? 0);
            $allowedModes = $this->allowedPaymentModesForPrice($allowlistPriceId);
            if ($allowedModes !== [] && !in_array($paymentMode, $allowedModes, true)) {
                throw new \RuntimeException('The selected payment method is not enabled for this package.');
            }

            if ($subscriptionPrice !== null && (float) ($subscriptionPrice['amount'] ?? 0) > 0 && empty($subscriptionPrice['catalog_checkout_enabled'])) {
                throw new \RuntimeException('Checkout is disabled for this package.');
            }

            if ($subscriptionPrice !== null && (float) ($subscriptionPrice['amount'] ?? 0) <= 0 && $tokenPack === null) {
                return $this->activateFreeSubscription($workspaceId, $subscriptionPrice, $userId, $selection, $callbackUrl);
            }

            $providerPlanCode = '';
            if ($subscriptionPrice !== null && (float) ($subscriptionPrice['amount'] ?? 0) > 0) {
                if ($paymentMode === WorkspaceBillingPaymentModeService::MODE_CARD) {
                    $providerPlanCode = trim((string) ($subscriptionPrice['provider_plan_code'] ?? ''));
                }
                if ($paymentMode === WorkspaceBillingPaymentModeService::MODE_CARD && $providerPlanCode === '') {
                    throw new \RuntimeException(sprintf(
                        'Paystack recurring plan is not configured for %s %s. Run `php cli/sync_paystack_launch_plans.php` before accepting this package.',
                        (string) ($subscriptionPrice['plan_name'] ?? 'this plan'),
                        strtolower((string) (($subscriptionPrice['interval_unit'] ?? '') === 'yearly' ? 'annual' : 'monthly'))
                    ));
                }
            }

            $paystackReady = $this->isPaystackConfigured();
            $mpesaReady = $this->isMpesaConfigured();
            $paymentPolicy = $this->paymentMethodPolicy($workspaceId, $currency);
            $modeConfig = $this->paymentModes->assertAvailable(
                $paymentMode,
                $currency,
                $paystackReady,
                $mpesaReady,
                (array) ($paymentPolicy['availability'] ?? []),
                (array) ($paymentPolicy['reasons'] ?? [])
            );
            $customerPhone = trim((string) ($selection['customer_phone'] ?? ''));
            if (!empty($modeConfig['requires_phone']) && $customerPhone === '') {
                throw new \RuntimeException('A customer phone number is required for the selected payment mode.');
            }

            $reference = 'saas_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
            $customerEmail = $this->resolveCustomerEmail($workspaceId, $userId);
            $returnUrls = $this->resolveCheckoutReturnUrls($callbackUrl, $selection);
            $callbackUrl = $returnUrls['callback_url'];
            $returnTo = $returnUrls['return_to'];

            $providerMetadata = [
                'source' => 'workspace_saas_billing',
                'workspace_id' => $workspaceId,
                'checkout_type' => $checkoutType,
                'payment_mode' => $paymentMode,
                'billing_plan_price_id' => $subscriptionPriceId > 0 ? $subscriptionPriceId : null,
                'token_pack_price_id' => $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
                'price_code' => $subscriptionPrice['price_code'] ?? $tokenPack['price_code'] ?? null,
                'provider_plan_code' => $providerPlanCode !== '' ? $providerPlanCode : null,
                'is_recurring' => $providerPlanCode !== '',
                'return_to' => $returnTo,
                'internal_reference' => $reference,
            ];
            $providerCheckout = $this->initiateProviderCheckout(
                $paymentMode,
                $amount,
                $currency,
                $reference,
                $customerEmail,
                $customerPhone !== '' ? $customerPhone : null,
                $callbackUrl,
                $providerMetadata,
                $checkoutType === 'token_pack' ? 'CRM token pack' : 'CRM workspace package',
                $providerPlanCode
            );
            $gatewayData = (array) ($providerCheckout['gateway_data'] ?? []);
            $response = (array) ($providerCheckout['gateway_response'] ?? []);
            $flowType = (string) ($providerCheckout['flow_type'] ?? ($modeConfig['flow_type'] ?? 'redirect'));
            $displayText = $this->extractProviderDisplayText($gatewayData);
            $instructions = $this->extractProviderInstructions($gatewayData);
            $expiresAt = $this->normalizeProviderDateTime($gatewayData['expires_at'] ?? null)
                ?? date('Y-m-d H:i:s', strtotime('+1 hour'));

            Database::execute(
                "INSERT INTO billing_checkout_sessions
                 (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount,
                  provider_plan_code, billing_plan_price_id, token_pack_price_id, payment_mode, flow_type, authorization_url, callback_url,
                  customer_phone, display_text, instructions_json, metadata_json, expires_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    (string) ($providerCheckout['provider'] ?? 'paystack'),
                    $checkoutType,
                    (string) ($providerCheckout['provider_reference'] ?? $reference),
                    $currency,
                    $amount,
                    $providerPlanCode !== '' ? $providerPlanCode : null,
                    $subscriptionPriceId > 0 ? $subscriptionPriceId : null,
                    $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
                    $paymentMode,
                    $flowType,
                    (string) ($providerCheckout['authorization_url'] ?? ''),
                    (string) ($providerCheckout['callback_url'] ?? $callbackUrl),
                    ($providerCheckout['customer_phone'] ?? $customerPhone) ?: null,
                    $displayText !== '' ? $displayText : null,
                    $instructions === [] ? null : json_encode($instructions, JSON_UNESCAPED_SLASHES),
                    json_encode([
                        'gateway_response' => $response,
                        'selection' => [
                            'billing_plan_price_id' => $subscriptionPriceId > 0 ? $subscriptionPriceId : null,
                            'token_pack_price_id' => $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
                            'payment_mode' => $paymentMode,
                            'provider_plan_code' => $providerPlanCode !== '' ? $providerPlanCode : null,
                            'internal_reference' => $reference,
                            'return_to' => $returnTo,
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                    $expiresAt,
                ]
            );
            $checkoutSessionId = (int) Database::lastInsertId();
            if ($subscriptionPriceId > 0) {
                $this->refreshDefaultWorkspaceOwnerContactAfterPayment($workspaceId, $userId);
            }

            return [
                'reference' => (string) ($providerCheckout['provider_reference'] ?? $reference),
                'internal_reference' => $reference,
                'checkout_session_id' => $checkoutSessionId,
                'payment_mode' => $paymentMode,
                'flow_type' => $flowType,
                'authorization_url' => (string) ($providerCheckout['authorization_url'] ?? ''),
                'access_code' => (string) ($providerCheckout['access_code'] ?? ''),
                'display_text' => $displayText !== '' ? $displayText : null,
                'instructions' => $this->userFacingCheckoutInstructions($paymentMode, $instructions),
                'expires_at' => $expiresAt,
                'amount' => $amount,
                'currency' => $currency,
                'return_to' => $returnTo,
                'checkout_type' => $checkoutType,
                'billing_plan_price_id' => $subscriptionPriceId > 0 ? $subscriptionPriceId : null,
                'token_pack_price_id' => $tokenPackPriceId > 0 ? $tokenPackPriceId : null,
                'provider_plan_code' => $providerPlanCode !== '' ? $providerPlanCode : null,
                'subscription' => $subscriptionPrice,
                'token_pack' => $tokenPack,
            ];
        });
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function initiateProviderCheckout(
        string $paymentMode,
        float $amount,
        string $currency,
        string $reference,
        string $customerEmail,
        ?string $customerPhone,
        string $callbackUrl,
        array $metadata,
        string $description,
        string $providerPlanCode = ''
    ): array {
        if ($paymentMode === WorkspaceBillingPaymentModeService::MODE_MPESA) {
            $mpesa = $this->resolveMpesaGateway();
            $mpesaCallbackUrl = $this->resolveMpesaCallbackUrl();
            $normalizedPhone = $mpesa->normalizePhoneNumber((string) $customerPhone);
            $response = $mpesa->stkPush($normalizedPhone, $amount, $reference, $description, $mpesaCallbackUrl);
            $responseCode = (string) ($response['ResponseCode'] ?? '');
            if ($responseCode !== '0') {
                throw new \RuntimeException('Failed to initiate M-Pesa payment: ' . (string) ($response['ResponseDescription'] ?? 'Unknown error'));
            }

            $checkoutRequestId = trim((string) ($response['CheckoutRequestID'] ?? ''));
            if ($checkoutRequestId === '') {
                throw new \RuntimeException('M-Pesa did not return a checkout request id.');
            }
            $merchantRequestId = trim((string) ($response['MerchantRequestID'] ?? ''));
            $displayText = 'M-Pesa prompt sent. Approve it on your phone to complete payment.';

            $gatewayData = [
                'reference' => $checkoutRequestId,
                'checkout_request_id' => $checkoutRequestId,
                'merchant_request_id' => $merchantRequestId,
                'status' => 'pending',
                'display_text' => $displayText,
                'gateway_response' => (string) ($response['ResponseDescription'] ?? $displayText),
                'instructions' => array_filter([
                    'checkout_request_id' => $checkoutRequestId,
                    'merchant_request_id' => $merchantRequestId,
                    'phone_number' => $normalizedPhone,
                ]),
                'expires_at' => date('c', strtotime('+15 minutes')),
            ];

            return [
                'provider' => 'mpesa',
                'provider_reference' => $checkoutRequestId,
                'flow_type' => 'offline_charge',
                'authorization_url' => '',
                'access_code' => '',
                'callback_url' => $mpesaCallbackUrl,
                'customer_phone' => $normalizedPhone,
                'gateway_data' => $gatewayData,
                'gateway_response' => $response,
            ];
        }

        $payload = [
            'email' => $customerEmail,
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];

        $gateway = $this->resolveGateway();
        if ($paymentMode === WorkspaceBillingPaymentModeService::MODE_CARD) {
            $payload['channels'] = ['card'];
            if ($providerPlanCode !== '') {
                $payload['plan'] = $providerPlanCode;
            }
            $response = $gateway->initializeTransaction($payload);
            $flowType = 'redirect';
        } else {
            $payload['bank_transfer'] = [
                'type' => 'nuban',
            ];
            $response = $gateway->createCharge($payload);
            $flowType = 'offline_charge';
        }

        $gatewayData = (array) ($response['data'] ?? []);

        return [
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'flow_type' => $flowType,
            'authorization_url' => (string) ($gatewayData['authorization_url'] ?? ''),
            'access_code' => (string) ($gatewayData['access_code'] ?? ''),
            'callback_url' => $callbackUrl,
            'customer_phone' => $customerPhone,
            'gateway_data' => $gatewayData,
            'gateway_response' => $response,
        ];
    }

    public function verifyCheckoutReference(string $reference): array
    {
        return $this->executeWithReadiness('billing_finalize', function () use ($reference): array {
            $reference = trim($reference);
            if ($reference === '') {
                throw new \RuntimeException('A payment reference is required.');
            }

            $checkout = $this->findCheckoutSessionByReference($reference);
            if (!$checkout) {
                return [
                    'success' => false,
                    'handled' => false,
                    'message' => 'No SaaS checkout session matched this reference.',
                ];
            }

            if ((string) ($checkout['status'] ?? '') === 'paid') {
                return [
                    'success' => true,
                    'handled' => true,
                    'message' => 'Checkout already applied.',
                    'checkout_type' => (string) ($checkout['checkout_type'] ?? ''),
                    'checkout_session_id' => (int) ($checkout['id'] ?? 0),
                    'reference' => (string) ($checkout['provider_reference'] ?? ''),
                    'amount' => (float) ($checkout['amount'] ?? 0),
                    'currency' => strtoupper((string) ($checkout['currency'] ?? 'KES')),
                    'paid_at' => (string) ($checkout['paid_at'] ?? ''),
                    'snapshot' => $this->getWorkspaceSnapshot((int) $checkout['workspace_id']),
                ];
            }

            try {
                if ((string) ($checkout['provider'] ?? 'paystack') === 'mpesa') {
                    $verification = $this->resolveMpesaGateway()->queryStkPush($reference);
                    $data = $this->normalizeMpesaResultData($verification, 'verification');
                    $status = strtolower((string) ($data['status'] ?? ''));

                    if ($this->isSuccessfulProviderStatus($status)) {
                        return $this->applySuccessfulCheckout($checkout, $data, 'verification');
                    }

                    if ($this->isPendingProviderStatus($status)) {
                        $updatedCheckout = $this->markCheckoutProcessing($checkout, $data, 'verification');
                        return [
                            'success' => false,
                            'handled' => true,
                            'message' => $this->extractProviderDisplayText($data) ?: 'M-Pesa payment is still awaiting completion.',
                            'snapshot' => $this->getWorkspaceSnapshot((int) $updatedCheckout['workspace_id']),
                        ];
                    }

                    $this->markCheckoutFailed($checkout, (string) ($data['gateway_response'] ?? 'mpesa_verification_failed'), $data);
                    return [
                        'success' => false,
                        'handled' => true,
                        'message' => 'M-Pesa payment has not completed successfully.',
                        'snapshot' => $this->getWorkspaceSnapshot((int) $checkout['workspace_id']),
                    ];
                }

                $gateway = $this->resolveGateway();
                $verification = $gateway->verifyTransaction($reference);
                $data = (array) ($verification['data'] ?? []);
                $status = strtolower((string) ($data['status'] ?? ''));

                if ($this->isSuccessfulProviderStatus($status)) {
                    return $this->applySuccessfulCheckout($checkout, $data, 'verification');
                }

                if ($this->isPendingProviderStatus($status)) {
                    $updatedCheckout = $this->markCheckoutProcessing($checkout, $data, 'verification');
                    return [
                        'success' => false,
                        'handled' => true,
                        'message' => $this->extractProviderDisplayText($data) ?: 'Checkout is still awaiting payment completion.',
                        'snapshot' => $this->getWorkspaceSnapshot((int) $updatedCheckout['workspace_id']),
                    ];
                }

                if ($status !== '') {
                    $this->markCheckoutFailed($checkout, (string) ($data['gateway_response'] ?? $status ?: 'verification_failed'), $data);
                    return [
                        'success' => false,
                        'handled' => true,
                        'message' => 'Checkout has not completed successfully.',
                        'snapshot' => $this->getWorkspaceSnapshot((int) $checkout['workspace_id']),
                    ];
                }

                return [
                    'success' => false,
                    'handled' => false,
                    'message' => 'Payment verification did not return a usable provider status.',
                    'snapshot' => $this->getWorkspaceSnapshot((int) $checkout['workspace_id']),
                ];
            } catch (\Throwable $e) {
                $this->recordCheckoutFinalizationFailure($checkout, $e->getMessage(), 'verification_exception');
                throw $e;
            }
        });
    }

    /**
     * @param array<string,mixed> $price
     * @param array<string,mixed> $selection
     * @return array<string,mixed>
     */
    private function activateFreeSubscription(
        int $workspaceId,
        array $price,
        ?int $userId,
        array $selection,
        ?string $callbackUrl = null
    ): array {
        $returnUrls = $this->resolveCheckoutReturnUrls($callbackUrl, $selection);
        $reference = 'free_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');

        $startedTransaction = !Database::getInstance()->inTransaction();
        if ($startedTransaction) {
            Database::beginTransaction();
        }
        try {
            $existing = Database::queryOne(
                "SELECT ws.*, bpp.price_code, bp.code AS plan_code
                 FROM workspace_subscriptions ws
                 JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
                 JOIN billing_plans bp ON bp.id = bpp.plan_id
                 WHERE ws.workspace_id = ?
                 ORDER BY ws.id DESC
                 LIMIT 1
                 FOR UPDATE",
                [$workspaceId]
            );

            $protectedExisting = $existing
                && in_array((string) ($existing['subscription_status'] ?? ''), ['active', 'trialing'], true)
                && (string) ($existing['price_code'] ?? '') !== (string) ($price['price_code'] ?? '');

            if ($protectedExisting) {
                Database::commit();
                return [
                    'reference' => '',
                    'checkout_session_id' => 0,
                    'payment_mode' => 'free',
                    'flow_type' => 'free',
                    'authorization_url' => '',
                    'access_code' => '',
                    'display_text' => 'Your existing workspace subscription remains active.',
                    'instructions' => [],
                    'expires_at' => null,
                    'amount' => 0.0,
                    'currency' => (string) ($price['currency'] ?? 'KES'),
                    'return_to' => $returnUrls['return_to'],
                    'checkout_type' => 'subscription',
                    'billing_plan_price_id' => (int) ($price['id'] ?? 0),
                    'token_pack_price_id' => null,
                    'subscription' => $price,
                    'token_pack' => null,
                ];
            }

            if ($existing) {
                Database::execute(
                    "UPDATE workspace_subscriptions
                     SET billing_plan_price_id = ?,
                         provider = 'internal',
                         provider_reference = ?,
                         provider_subscription_code = NULL,
                         provider_customer_code = NULL,
                         provider_email_token = NULL,
                         provider_subscription_status = 'active',
                         renewal_status = 'free',
                         provider_metadata_json = ?,
                         subscription_status = 'active',
                         current_period_start = ?,
                         current_period_end = NULL,
                         next_billing_at = NULL,
                         scheduled_billing_plan_price_id = NULL,
                         scheduled_change_type = NULL,
                         scheduled_change_at = NULL,
                         scheduled_change_metadata_json = NULL,
                         updated_at = NOW()
                     WHERE id = ?",
                    [
                        (int) $price['id'],
                        $reference,
                        json_encode(['source' => 'free_checkout_activation', 'activated_at' => date('c')], JSON_UNESCAPED_SLASHES),
                        $now,
                        (int) $existing['id'],
                    ]
                );
                $subscriptionId = (int) $existing['id'];
            } else {
                Database::execute(
                    "INSERT INTO workspace_subscriptions
                     (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_status,
                      renewal_status, provider_metadata_json, subscription_status, current_period_start, current_period_end,
                      next_billing_at, created_by)
                     VALUES (?, ?, 'internal', ?, 'active', 'free', ?, 'active', ?, NULL, NULL, ?)",
                    [
                        $workspaceId,
                        (int) $price['id'],
                        $reference,
                        json_encode(['source' => 'free_checkout_activation', 'activated_at' => date('c')], JSON_UNESCAPED_SLASHES),
                        $now,
                        $userId,
                    ]
                );
                $subscriptionId = (int) Database::lastInsertId();
            }

            Database::execute(
                "INSERT INTO billing_checkout_sessions
                 (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount,
                  billing_plan_price_id, subscription_id, payment_mode, flow_type, authorization_url, callback_url,
                  display_text, metadata_json, paid_at, expires_at)
                 VALUES (?, ?, 'internal', 'subscription', ?, 'paid', ?, 0, ?, ?, 'card', 'redirect', '', ?, ?, ?, ?, NULL)",
                [
                    $workspaceId,
                    $userId,
                    $reference,
                    (string) ($price['currency'] ?? 'KES'),
                    (int) $price['id'],
                    $subscriptionId,
                    $returnUrls['callback_url'],
                    'Compass Free is active for this workspace.',
                    json_encode([
                        'selection' => [
                            'billing_plan_price_id' => (int) $price['id'],
                            'payment_mode' => 'free',
                            'return_to' => $returnUrls['return_to'],
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                    $now,
                ]
            );
            $checkoutSessionId = (int) Database::lastInsertId();

            Database::execute(
                "UPDATE workspaces
                 SET plan_status = 'active',
                     status = CASE WHEN status IN ('suspended', 'archived') THEN status ELSE 'active' END,
                     updated_at = NOW()
                 WHERE id = ?",
                [$workspaceId]
            );
            $creditResult = $this->grantCompassFreeOnboardingCredits($workspaceId, $subscriptionId, $price, $userId);
            $this->recordSubscriptionCycle($workspaceId, $subscriptionId, $price, $now, null, null, (int) ($creditResult['credit_lot_id'] ?? 0), 'active', [
                'source' => 'free_checkout_activation',
            ]);

            if ($startedTransaction) {
                Database::commit();
            }

            return [
                'reference' => $reference,
                'checkout_session_id' => $checkoutSessionId,
                'payment_mode' => 'free',
                'flow_type' => 'free',
                'authorization_url' => '',
                'access_code' => '',
                'display_text' => 'Compass Free is active for this workspace.',
                'instructions' => [],
                'expires_at' => null,
                'amount' => 0.0,
                'currency' => (string) ($price['currency'] ?? 'KES'),
                'return_to' => $returnUrls['return_to'],
                'checkout_type' => 'subscription',
                'billing_plan_price_id' => (int) $price['id'],
                'token_pack_price_id' => null,
                'subscription_id' => $subscriptionId,
                'credited' => $creditResult,
                'subscription' => $price,
                'token_pack' => null,
            ];
        } catch (\Throwable $e) {
            if ($startedTransaction && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    public function processWebhook(string $rawPayload, ?string $signature): array
    {
        return $this->executeWithReadiness('billing_finalize', function () use ($rawPayload, $signature): array {
            $decoded = json_decode($rawPayload, true);
            if (!is_array($decoded)) {
                return [
                    'accepted' => false,
                    'handled' => false,
                    'message' => 'Invalid JSON payload.',
                ];
            }

            $gateway = $this->resolveGateway();
            if (!$gateway->verifyWebhookSignature($rawPayload, $signature)) {
                $this->logProviderEvent((string) ($decoded['event'] ?? ''), (string) (($decoded['data']['reference'] ?? '') ?: ''), null, $decoded, 'failed', 'Invalid webhook signature', $signature);
                return [
                    'accepted' => false,
                    'handled' => false,
                    'message' => 'Invalid webhook signature.',
                ];
            }

            $event = (string) ($decoded['event'] ?? '');
            $providerData = is_array($decoded['data'] ?? null) ? (array) $decoded['data'] : [];
            $reference = $this->extractProviderReference($decoded);
            $providerDetails = $this->extractSubscriptionProviderDetails($providerData);
            $subscriptionCode = $providerDetails['subscription_code'];
            $eventReference = $reference !== ''
                ? $reference
                : $this->extractProviderEventReference($providerData, $event);
            $checkout = $reference !== '' ? $this->findCheckoutSessionByReference($reference, 'paystack') : null;
            $subscription = (!$checkout && $subscriptionCode !== '') ? $this->findSubscriptionByProviderCode($subscriptionCode) : null;

            if (!$checkout && !$subscription) {
                $this->logProviderEvent($event, $eventReference, null, $decoded, 'ignored', 'No SaaS checkout or subscription matched this event.', $signature);
                return [
                    'accepted' => true,
                    'handled' => false,
                    'message' => 'No SaaS checkout or subscription matched this event.',
                ];
            }

            $workspaceId = $checkout ? (int) $checkout['workspace_id'] : (int) ($subscription['workspace_id'] ?? 0);

            $existingEvent = Database::queryOne(
                "SELECT id
                 FROM billing_provider_events
                 WHERE provider = 'paystack'
                   AND event_name = ?
                   AND event_reference = ?
                   AND workspace_id = ?
                   AND processing_status = 'processed'
                 ORDER BY id DESC
                 LIMIT 1",
                [$event, $eventReference, $workspaceId]
            );
            if ($existingEvent) {
                return [
                    'accepted' => true,
                    'handled' => true,
                    'message' => 'Duplicate SaaS billing event ignored.',
                ];
            }

            $eventId = $this->logProviderEvent($event, $eventReference, $workspaceId, $decoded, 'pending', 'Received webhook', $signature);

            try {
                $result = $checkout
                    ? $this->handleProviderEvent($checkout, $decoded, $event, $reference)
                    : $this->handleSubscriptionProviderEvent((array) $subscription, $decoded, $event, $eventReference);

                $this->finalizeProviderEvent(
                    $eventId,
                    !empty($result['success']) ? 'processed' : 'ignored',
                    (string) ($result['message'] ?? 'Webhook processed')
                );

                return ['accepted' => true] + $result;
            } catch (\Throwable $e) {
                if ($checkout) {
                    $this->recordCheckoutFinalizationFailure($checkout, $e->getMessage(), 'webhook_exception');
                }
                $this->finalizeProviderEvent($eventId, 'failed', substr($e->getMessage(), 0, 255));
                throw $e;
            }
        });
    }

    public function processMpesaCallback(string $rawPayload): array
    {
        return $this->executeWithReadiness('billing_finalize', function () use ($rawPayload): array {
            $decoded = json_decode($rawPayload, true);
            if (!is_array($decoded)) {
                return [
                    'accepted' => true,
                    'handled' => false,
                    'message' => 'Invalid M-Pesa callback JSON ignored.',
                ];
            }

            $stkCallback = $decoded['Body']['stkCallback'] ?? null;
            if (!is_array($stkCallback)) {
                $this->logProviderEvent('stk_callback', '', null, $decoded, 'ignored', 'Missing stkCallback payload.', null, 'mpesa');
                return [
                    'accepted' => true,
                    'handled' => false,
                    'message' => 'Missing M-Pesa stkCallback payload.',
                ];
            }

            $providerData = $this->normalizeMpesaResultData($stkCallback, 'callback');
            $checkoutRequestId = trim((string) ($providerData['checkout_request_id'] ?? ''));
            $merchantRequestId = trim((string) ($providerData['merchant_request_id'] ?? ''));
            $eventReference = $checkoutRequestId !== '' ? $checkoutRequestId : $merchantRequestId;
            $checkout = $eventReference !== '' ? $this->findCheckoutSessionByReference($eventReference, 'mpesa') : null;

            if (!$checkout && $checkoutRequestId !== '' && $merchantRequestId !== '') {
                $checkout = $this->findCheckoutSessionByReference($merchantRequestId, 'mpesa');
            }

            if (!$checkout) {
                $this->logProviderEvent('stk_callback', $eventReference, null, $decoded, 'ignored', 'No M-Pesa checkout matched this callback.', null, 'mpesa');
                return [
                    'accepted' => true,
                    'handled' => false,
                    'message' => 'No M-Pesa checkout matched this callback.',
                ];
            }

            $workspaceId = (int) $checkout['workspace_id'];
            $existingEvent = Database::queryOne(
                "SELECT id
                 FROM billing_provider_events
                 WHERE provider = 'mpesa'
                   AND event_name = 'stk_callback'
                   AND event_reference = ?
                   AND workspace_id = ?
                   AND processing_status = 'processed'
                 ORDER BY id DESC
                 LIMIT 1",
                [$eventReference, $workspaceId]
            );

            if ($existingEvent) {
                return [
                    'accepted' => true,
                    'handled' => true,
                    'message' => 'Duplicate M-Pesa callback ignored.',
                    'snapshot' => $this->getWorkspaceSnapshot($workspaceId),
                ];
            }

            $eventId = $this->logProviderEvent('stk_callback', $eventReference, $workspaceId, $decoded, 'pending', 'Received M-Pesa callback.', null, 'mpesa');

            try {
                $status = strtolower((string) ($providerData['status'] ?? ''));
                if ($this->isSuccessfulProviderStatus($status)) {
                    $result = $this->applySuccessfulCheckout($checkout, $providerData, 'mpesa_callback');
                } else {
                    $this->markCheckoutFailed($checkout, (string) ($providerData['gateway_response'] ?? 'mpesa_callback_failed'), $providerData);
                    $result = [
                        'success' => false,
                        'handled' => true,
                        'message' => 'M-Pesa checkout marked as failed.',
                        'snapshot' => $this->getWorkspaceSnapshot($workspaceId),
                    ];
                }

                $this->finalizeProviderEvent(
                    $eventId,
                    !empty($result['handled']) ? 'processed' : 'ignored',
                    (string) ($result['message'] ?? 'M-Pesa callback processed')
                );

                return ['accepted' => true] + $result;
            } catch (\Throwable $e) {
                $this->recordCheckoutFinalizationFailure($checkout, $e->getMessage(), 'mpesa_callback_exception');
                $this->finalizeProviderEvent($eventId, 'failed', substr($e->getMessage(), 0, 255));
                throw $e;
            }
        });
    }

    public function replayProviderEvent(int $eventId): array
    {
        return $this->executeWithReadiness('billing_finalize', function () use ($eventId): array {
            $event = $this->getProviderEventById($eventId);
            if ($event === null) {
                throw new \RuntimeException('Provider event not found.');
            }

            $reference = trim((string) ($event['event_reference'] ?? ''));
            if ($reference === '') {
                throw new \RuntimeException('Stored provider event does not contain a billing reference.');
            }

            $checkout = $this->findCheckoutSessionByReference($reference);
            $payload = (array) ($event['payload'] ?? []);
            $providerData = is_array($payload['data'] ?? null) ? (array) $payload['data'] : [];
            $subscriptionCode = $this->extractSubscriptionProviderDetails($providerData)['subscription_code'];
            $subscription = $checkout === null && $subscriptionCode !== ''
                ? $this->findSubscriptionByProviderCode($subscriptionCode)
                : null;

            if ($checkout === null && $subscription === null) {
                return [
                    'success' => false,
                    'handled' => false,
                    'message' => 'No SaaS checkout or subscription matched this provider event.',
                    'event' => $event,
                ];
            }

            Database::execute(
                "UPDATE billing_provider_events
                 SET processing_status = 'pending',
                     processing_message = ?,
                     processed_at = NULL
                 WHERE id = ?",
                ['Replay requested', $eventId]
            );

            try {
                $result = $checkout
                    ? $this->handleProviderEvent(
                        $checkout,
                        $payload,
                        (string) ($event['event_name'] ?? ''),
                        $reference
                    )
                    : $this->handleSubscriptionProviderEvent(
                        (array) $subscription,
                        $payload,
                        (string) ($event['event_name'] ?? ''),
                        $reference
                    );

                $this->finalizeProviderEvent(
                    $eventId,
                    !empty($result['success']) ? 'processed' : 'ignored',
                    (string) ($result['message'] ?? 'Provider event replayed')
                );

                return $result + [
                    'event' => $this->getProviderEventById($eventId),
                ];
            } catch (\Throwable $e) {
                if ($checkout) {
                    $this->recordCheckoutFinalizationFailure($checkout, $e->getMessage(), 'provider_event_replay_exception');
                }
                $this->finalizeProviderEvent($eventId, 'failed', substr($e->getMessage(), 0, 255));
                throw $e;
            }
        });
    }

    private function applySuccessfulCheckout(array $checkout, array $providerData, string $source): array
    {
        Database::beginTransaction();
        try {
            $lockedCheckout = Database::queryOne(
                "SELECT *
                 FROM billing_checkout_sessions
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE",
                [(int) $checkout['id']]
            );

            if (!$lockedCheckout) {
                throw new \RuntimeException('Checkout session no longer exists.');
            }

            if ((string) ($lockedCheckout['status'] ?? '') === 'paid') {
                Database::commit();
                return [
                    'success' => true,
                    'handled' => true,
                    'message' => 'Checkout already applied.',
                    'snapshot' => $this->getWorkspaceSnapshot((int) $lockedCheckout['workspace_id']),
                ];
            }

            $paidAt = $this->normalizeProviderDateTime($providerData['paid_at'] ?? null) ?? date('Y-m-d H:i:s');
            $workspaceId = (int) $lockedCheckout['workspace_id'];

            Database::execute(
                "UPDATE billing_checkout_sessions
                 SET status = 'paid',
                     paid_at = ?,
                     display_text = ?,
                     instructions_json = ?,
                     metadata_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $paidAt,
                    $this->extractProviderDisplayText($providerData) ?: ($lockedCheckout['display_text'] ?? null),
                    ($instructions = $this->extractProviderInstructions($providerData)) === []
                        ? ($lockedCheckout['instructions_json'] ?? null)
                        : json_encode($instructions, JSON_UNESCAPED_SLASHES),
                    json_encode($this->mergeCheckoutFinalizationMetadata(
                        $this->decodeJson((string) ($lockedCheckout['metadata_json'] ?? '')),
                        $providerData,
                        [
                            'status' => 'paid',
                            'source' => $source,
                            'provider_status' => (string) ($providerData['status'] ?? 'success'),
                            'provider_paid_at' => $providerData['paid_at'] ?? null,
                            'finalized_at' => date('c'),
                        ]
                    ), JSON_UNESCAPED_SLASHES),
                    (int) $lockedCheckout['id'],
                ]
            );

            if ((string) ($lockedCheckout['checkout_type'] ?? '') === 'donation') {
                $donationResult = $this->applyDonationCheckout($lockedCheckout, $providerData);
                $subscriptionResult = [];
                $tokenPackResult = [];
            } elseif (!empty($lockedCheckout['billing_plan_price_id'])) {
                $subscriptionResult = $this->applySubscriptionPurchase($lockedCheckout, $paidAt, $providerData);
                $donationResult = [];
            } else {
                $subscriptionResult = [];
                $donationResult = [];
            }

            if ((string) ($lockedCheckout['checkout_type'] ?? '') !== 'donation' && !empty($lockedCheckout['token_pack_price_id'])) {
                $tokenPackResult = $this->applyTokenPackPurchase($lockedCheckout, $providerData);
            } else {
                $tokenPackResult = [];
            }

            Database::commit();
            if (!empty($subscriptionResult)) {
                $this->refreshDefaultWorkspaceOwnerContactAfterPayment($workspaceId, !empty($lockedCheckout['user_id']) ? (int) $lockedCheckout['user_id'] : null);
            }

            return [
                'success' => true,
                'handled' => true,
                'message' => 'Checkout applied successfully.',
                'checkout_type' => (string) ($lockedCheckout['checkout_type'] ?? ''),
                'checkout_session_id' => (int) ($lockedCheckout['id'] ?? 0),
                'reference' => (string) ($lockedCheckout['provider_reference'] ?? ''),
                'amount' => (float) ($lockedCheckout['amount'] ?? 0),
                'currency' => strtoupper((string) ($lockedCheckout['currency'] ?? 'KES')),
                'paid_at' => $paidAt,
                'subscription' => $subscriptionResult,
                'token_pack' => $tokenPackResult,
                'donation' => $donationResult,
                'snapshot' => $this->getWorkspaceSnapshot($workspaceId),
            ];
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function refreshDefaultWorkspaceOwnerContactAfterPayment(int $workspaceId, ?int $userId): void
    {
        if ($workspaceId <= 0 || WorkspaceContext::isDefaultWorkspace($workspaceId)) {
            return;
        }

        $ownerUserId = $userId;
        if ($ownerUserId === null || $ownerUserId <= 0) {
            $owner = Database::queryOne(
                "SELECT user_id
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND membership_status = 'active'
                 ORDER BY is_owner DESC, FIELD(role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), id ASC
                 LIMIT 1",
                [$workspaceId]
            );
            $ownerUserId = !empty($owner['user_id']) ? (int) $owner['user_id'] : null;
        }

        if ($ownerUserId === null || $ownerUserId <= 0) {
            return;
        }

        try {
            (new DefaultWorkspaceOwnerContactService())->syncOwnerForWorkspace($workspaceId, $ownerUserId, $ownerUserId);
            (new DefaultWorkspaceLifecycleObserverService())->workspaceChanged($workspaceId, $ownerUserId, 'billing_payment_refresh');
        } catch (\Throwable $e) {
            error_log('Default workspace owner contact payment refresh failed: ' . $e->getMessage());
        }
    }

    private function applySubscriptionPurchase(array $checkout, string $paidAt, array $providerData = []): array
    {
        $price = $this->loadSubscriptionPrice((int) $checkout['billing_plan_price_id']);
        if ($price === null) {
            throw new \RuntimeException('Subscription price is not available.');
        }

        $workspaceId = (int) $checkout['workspace_id'];
        $subscription = Database::queryOne(
            "SELECT *
             FROM workspace_subscriptions
             WHERE id = ?
             LIMIT 1",
            [(int) ($checkout['subscription_id'] ?? 0)]
        );

        if (!$subscription) {
            $subscription = Database::queryOne(
                "SELECT *
                 FROM workspace_subscriptions
                 WHERE workspace_id = ?
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE",
                [$workspaceId]
            );
        }

        $providerDetails = $this->extractSubscriptionProviderDetails($providerData, $checkout);
        $provider = (string) ($checkout['provider'] ?? 'paystack');
        $periodStart = $this->normalizeProviderDateTime($providerData['period_start'] ?? null) ?? $paidAt;
        $periodEnd = $this->normalizeProviderDateTime($providerData['period_end'] ?? null)
            ?? $providerDetails['next_payment_date']
            ?? $this->calculatePeriodEnd($periodStart, (string) ($price['interval_unit'] ?? 'monthly'), (int) ($price['interval_count'] ?? 1));
        $nextBillingAt = $providerDetails['next_payment_date'] ?? $periodEnd;
        $subscriptionCode = $providerDetails['subscription_code'];
        $customerCode = $providerDetails['customer_code'];
        $emailToken = $providerDetails['email_token'];
        $providerSubscriptionStatus = $providerDetails['subscription_status'] ?: 'active';
        $renewalStatus = $subscriptionCode !== '' || trim((string) ($checkout['provider_plan_code'] ?? $price['provider_plan_code'] ?? '')) !== ''
            ? 'renewing'
            : 'manual';
        $supersededProviderResults = [];
        if ($subscription) {
            $oldSubscriptionCode = trim((string) ($subscription['provider_subscription_code'] ?? ''));
            if ($oldSubscriptionCode !== '' && ($subscriptionCode === '' || !hash_equals($oldSubscriptionCode, $subscriptionCode))) {
                $supersededProviderResults['current_disable'] = $this->disablePaystackSubscriptionIfPresent(
                    $oldSubscriptionCode,
                    (string) ($subscription['provider_email_token'] ?? ''),
                    'current'
                );
            }

            $scheduledMetadata = $this->decodeJson((string) ($subscription['scheduled_change_metadata_json'] ?? ''));
            $scheduledCode = trim((string) ($scheduledMetadata['target_provider_subscription_code'] ?? ''));
            if ($scheduledCode !== '' && ($subscriptionCode === '' || !hash_equals($scheduledCode, $subscriptionCode))) {
                $supersededProviderResults['scheduled_disable'] = $this->disablePaystackSubscriptionIfPresent(
                    $scheduledCode,
                    (string) ($scheduledMetadata['target_provider_email_token'] ?? ''),
                    'scheduled'
                );
            }
        }
        $providerMetadata = [
            'provider_plan_code' => trim((string) ($checkout['provider_plan_code'] ?? $price['provider_plan_code'] ?? '')),
            'subscription' => $providerDetails['subscription_payload'],
            'customer' => $providerDetails['customer_payload'],
            'authorization' => is_array($providerData['authorization'] ?? null) ? $providerData['authorization'] : null,
            'last_provider_event' => $providerData,
            'superseded_provider_subscriptions' => array_filter($supersededProviderResults),
        ];

        if ($subscription) {
            Database::execute(
                "UPDATE workspace_subscriptions
                 SET billing_plan_price_id = ?,
                     provider = ?,
                     provider_reference = ?,
                     provider_subscription_code = ?,
                     provider_customer_code = ?,
                     provider_email_token = ?,
                     provider_subscription_status = ?,
                     renewal_status = ?,
                     provider_metadata_json = ?,
                     subscription_status = 'active',
                     current_period_start = ?,
                     current_period_end = ?,
                     next_billing_at = ?,
                     scheduled_billing_plan_price_id = NULL,
                     scheduled_change_type = NULL,
                     scheduled_change_at = NULL,
                     scheduled_change_metadata_json = NULL,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    (int) $price['id'],
                    $provider,
                    (string) $checkout['provider_reference'],
                    $subscriptionCode !== '' ? $subscriptionCode : null,
                    $customerCode !== '' ? $customerCode : null,
                    $emailToken !== '' ? $emailToken : null,
                    $providerSubscriptionStatus,
                    $renewalStatus,
                    json_encode($providerMetadata, JSON_UNESCAPED_SLASHES),
                    $periodStart,
                    $periodEnd,
                    $nextBillingAt,
                    (int) $subscription['id'],
                ]
            );
            $subscriptionId = (int) $subscription['id'];
        } else {
            Database::execute(
                "INSERT INTO workspace_subscriptions
                 (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_code, provider_customer_code,
                  provider_email_token, provider_subscription_status, renewal_status, provider_metadata_json, subscription_status,
                  current_period_start, current_period_end, next_billing_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)",
                [
                    $workspaceId,
                    (int) $price['id'],
                    $provider,
                    (string) $checkout['provider_reference'],
                    $subscriptionCode !== '' ? $subscriptionCode : null,
                    $customerCode !== '' ? $customerCode : null,
                    $emailToken !== '' ? $emailToken : null,
                    $providerSubscriptionStatus,
                    $renewalStatus,
                    json_encode($providerMetadata, JSON_UNESCAPED_SLASHES),
                    $periodStart,
                    $periodEnd,
                    $nextBillingAt,
                    $checkout['user_id'] ?: null,
                ]
            );
            $subscriptionId = (int) Database::lastInsertId();
        }

        Database::execute(
            "UPDATE billing_checkout_sessions
             SET subscription_id = ?,
                 provider_subscription_code = COALESCE(?, provider_subscription_code),
                 provider_customer_code = COALESCE(?, provider_customer_code)
             WHERE id = ?",
            [
                $subscriptionId,
                $subscriptionCode !== '' ? $subscriptionCode : null,
                $customerCode !== '' ? $customerCode : null,
                (int) $checkout['id'],
            ]
        );

        Database::execute(
            "UPDATE workspaces
             SET plan_status = 'active',
                 status = CASE WHEN status IN ('suspended', 'archived') THEN status ELSE 'active' END,
                 updated_at = NOW()
             WHERE id = ?",
            [$workspaceId]
        );

        $transaction = $this->findExistingTransaction((int) $checkout['id'], 'subscription_charge');
        if (!$transaction) {
            Database::execute(
                "INSERT INTO billing_transactions
                 (workspace_id, checkout_session_id, subscription_id, provider, provider_reference, provider_subscription_code,
                  provider_customer_code, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'subscription_charge', 'succeeded', ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    (int) $checkout['id'],
                    $subscriptionId,
                    $provider,
                    (string) $checkout['provider_reference'],
                    $subscriptionCode !== '' ? $subscriptionCode : null,
                    $customerCode !== '' ? $customerCode : null,
                    (string) ($checkout['payment_mode'] ?? WorkspaceBillingPaymentModeService::MODE_CARD),
                    (string) ($checkout['flow_type'] ?? 'redirect'),
                    (float) ($price['amount'] ?? 0),
                    (string) ($price['currency'] ?? 'KES'),
                    json_encode([
                        'included_tokens' => (int) ($price['included_tokens'] ?? 0),
                        'provider_plan_code' => trim((string) ($checkout['provider_plan_code'] ?? $price['provider_plan_code'] ?? '')),
                        'provider_subscription_code' => $subscriptionCode !== '' ? $subscriptionCode : null,
                        'provider_customer_code' => $customerCode !== '' ? $customerCode : null,
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
            $transactionId = (int) Database::lastInsertId();
        } else {
            $transactionId = (int) $transaction['id'];
        }

        $creditResult = $this->grantIncludedCreditsForSubscriptionPeriod(
            $workspaceId,
            $subscriptionId,
            $price,
            $periodStart,
            $periodEnd,
            !empty($checkout['user_id']) ? (int) $checkout['user_id'] : null,
            [
                'subscription_id' => $subscriptionId,
                'checkout_session_id' => (int) $checkout['id'],
                'billing_transaction_id' => $transactionId,
            ]
        );
        $includedTokens = (int) ($price['included_tokens'] ?? 0);
        $this->recordSubscriptionCycle(
            $workspaceId,
            $subscriptionId,
            $price,
            $periodStart,
            $periodEnd,
            $transactionId,
            (int) ($creditResult['credit_lot_id'] ?? 0),
            'active',
            ['source' => 'subscription_purchase']
        );
        $billingInvoice = (new WorkspacePackageBillingInvoiceService())->issueForSubscriptionTransaction($transactionId);

        return [
            'subscription_id' => $subscriptionId,
            'transaction_id' => $transactionId,
            'billing_invoice_id' => (int) ($billingInvoice['id'] ?? 0),
            'billing_invoice' => (array) ($billingInvoice['summary'] ?? []),
            'included_tokens_credited' => (int) ($creditResult['ledger_entry_id'] ?? 0) > 0 ? $includedTokens : 0,
            'included_credits_credited' => (int) ($creditResult['ledger_entry_id'] ?? 0) > 0 ? $includedTokens : 0,
        ];
    }

    private function applyTokenPackPurchase(array $checkout, array $providerData = []): array
    {
        $tokenPack = $this->loadTokenPack((int) $checkout['token_pack_price_id']);
        if ($tokenPack === null) {
            throw new \RuntimeException('Token pack is not available.');
        }

        $workspaceId = (int) $checkout['workspace_id'];
        $provider = (string) ($checkout['provider'] ?? 'paystack');
        $transaction = $this->findExistingTransaction((int) $checkout['id'], 'token_pack_purchase');
        $creditResult = null;

        if (!$transaction) {
            $creditResult = $this->wallets->creditTokens(
                $workspaceId,
                (int) ($tokenPack['token_quantity'] ?? 0),
                'token_pack_purchase',
                (string) $checkout['provider_reference'],
                !empty($checkout['user_id']) ? (int) $checkout['user_id'] : null,
                [
                    'checkout_session_id' => (int) $checkout['id'],
                    'token_pack_price_id' => (int) $tokenPack['id'],
                    'credit_expiry_days' => (int) (($tokenPack['metadata']['credit_expiry_days'] ?? 180) ?: 180),
                ]
            );

            Database::execute(
                "INSERT INTO billing_transactions
                 (workspace_id, checkout_session_id, wallet_ledger_id, provider, provider_reference, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
                 VALUES (?, ?, ?, ?, ?, 'token_pack_purchase', 'succeeded', ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    (int) $checkout['id'],
                    (int) ($creditResult['ledger_entry_id'] ?? 0) ?: null,
                    $provider,
                    (string) $checkout['provider_reference'],
                    (string) ($checkout['payment_mode'] ?? WorkspaceBillingPaymentModeService::MODE_CARD),
                    (string) ($checkout['flow_type'] ?? 'redirect'),
                    (float) ($tokenPack['amount'] ?? 0),
                    (string) ($tokenPack['currency'] ?? 'KES'),
                    json_encode(array_filter([
                        'token_quantity' => (int) ($tokenPack['token_quantity'] ?? 0),
                        'receipt_number' => $providerData['receipt_number'] ?? null,
                        'provider_data' => $providerData !== [] ? $providerData : null,
                    ], static fn($value) => $value !== null && $value !== ''), JSON_UNESCAPED_SLASHES),
                ]
            );
            $transactionId = (int) Database::lastInsertId();
        } else {
            $transactionId = (int) $transaction['id'];
        }

        return [
            'transaction_id' => $transactionId,
            'token_quantity' => (int) ($tokenPack['token_quantity'] ?? 0),
            'ledger_entry_id' => (int) ($creditResult['ledger_entry_id'] ?? ($transaction['wallet_ledger_id'] ?? 0)),
        ];
    }

    private function applyDonationCheckout(array $checkout, array $providerData = []): array
    {
        $provider = (string) ($checkout['provider'] ?? 'paystack');
        $transaction = $this->findExistingTransaction((int) $checkout['id'], 'donation');
        if (!$transaction) {
            Database::execute(
                "INSERT INTO billing_transactions
                 (workspace_id, checkout_session_id, provider, provider_reference, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
                 VALUES (?, ?, ?, ?, 'donation', 'succeeded', ?, ?, ?, ?, ?)",
                [
                    (int) $checkout['workspace_id'],
                    (int) $checkout['id'],
                    $provider,
                    (string) $checkout['provider_reference'],
                    (string) ($checkout['payment_mode'] ?? WorkspaceBillingPaymentModeService::MODE_CARD),
                    (string) ($checkout['flow_type'] ?? 'redirect'),
                    (float) ($checkout['amount'] ?? 0),
                    (string) ($checkout['currency'] ?? 'KES'),
                    json_encode(array_filter([
                        'checkout_type' => 'donation',
                        'receipt_number' => $providerData['receipt_number'] ?? null,
                        'provider_data' => $providerData !== [] ? $providerData : null,
                    ], static fn($value) => $value !== null && $value !== ''), JSON_UNESCAPED_SLASHES),
                ]
            );
            $transactionId = (int) Database::lastInsertId();
        } else {
            $transactionId = (int) $transaction['id'];
        }

        return [
            'transaction_id' => $transactionId,
            'amount' => (float) ($checkout['amount'] ?? 0),
            'currency' => (string) ($checkout['currency'] ?? 'KES'),
        ];
    }

    /**
     * @param array<string,mixed> $checkout
     * @return array<string,mixed>
     */
    private function formatDonationCheckoutStatus(array $checkout): array
    {
        $status = strtolower(trim((string) ($checkout['status'] ?? 'pending')));
        if ($status === '') {
            $status = 'pending';
        }

        $expiresAt = trim((string) ($checkout['expires_at'] ?? ''));
        $expiresAtTime = $expiresAt !== '' ? strtotime($expiresAt) : false;
        if (!in_array($status, ['paid', 'failed', 'cancelled', 'expired'], true)
            && $expiresAtTime !== false
            && $expiresAtTime < time()) {
            $status = 'expired';
        }

        $state = 'waiting';
        $title = 'Waiting for confirmation';
        $message = 'We are checking for your payment confirmation.';
        $terminal = false;

        if ($status === 'paid') {
            $state = 'success';
            $title = 'Thank you for supporting startup AI access';
            $message = 'Your contribution has been received. You helped keep AI access more reachable for small teams.';
            $terminal = true;
        } elseif (in_array($status, ['failed', 'cancelled'], true)) {
            $state = 'failed';
            $title = 'Payment was not completed';
            $message = 'The payment did not complete. You can try again whenever you are ready.';
            $terminal = true;
        } elseif ($status === 'expired') {
            $state = 'expired';
            $title = 'Payment request expired';
            $message = 'This payment request is no longer active. Start a new donation to try again.';
            $terminal = true;
        } elseif ((string) ($checkout['payment_mode'] ?? '') === WorkspaceBillingPaymentModeService::MODE_MPESA) {
            $title = 'Waiting for phone approval';
            $message = 'M-Pesa prompt sent. Approve it on your phone to complete payment.';
        }

        return [
            'status' => $status,
            'state' => $state,
            'title' => $title,
            'message' => $message,
            'amount' => (float) ($checkout['amount'] ?? 0),
            'currency' => strtoupper((string) ($checkout['currency'] ?? 'KES')),
            'paid_at' => $status === 'paid' ? (string) ($checkout['paid_at'] ?? '') : null,
            'terminal' => $terminal,
        ];
    }

    private function markCheckoutProcessing(array $checkout, array $providerData, string $source): array
    {
        $displayText = $this->extractProviderDisplayText($providerData);
        $instructions = $this->extractProviderInstructions($providerData);
        $metadata = $this->mergeCheckoutFinalizationMetadata(
            $this->decodeJson((string) ($checkout['metadata_json'] ?? '')),
            $providerData,
            [
                'status' => 'processing',
                'source' => $source,
                'provider_status' => (string) ($providerData['status'] ?? 'pending'),
                'provider_paid_at' => $providerData['paid_at'] ?? null,
                'updated_at' => date('c'),
            ]
        );

        Database::execute(
            "UPDATE billing_checkout_sessions
             SET status = 'processing',
                 display_text = ?,
                 instructions_json = ?,
                 metadata_json = ?,
                 expires_at = COALESCE(?, expires_at),
                 updated_at = NOW()
             WHERE id = ?",
            [
                $displayText !== '' ? $displayText : ($checkout['display_text'] ?? null),
                $instructions === [] ? ($checkout['instructions_json'] ?? null) : json_encode($instructions, JSON_UNESCAPED_SLASHES),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $this->normalizeProviderDateTime($providerData['expires_at'] ?? null),
                (int) $checkout['id'],
            ]
        );

        return $this->findCheckoutSessionByReference((string) ($checkout['provider_reference'] ?? '')) ?? $checkout;
    }

    private function markCheckoutFailed(array $checkout, string $reason, array $providerData = []): void
    {
        Database::beginTransaction();
        try {
            $lockedCheckout = Database::queryOne(
                "SELECT *
                 FROM billing_checkout_sessions
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE",
                [(int) $checkout['id']]
            );

            if (!$lockedCheckout) {
                throw new \RuntimeException('Checkout session no longer exists.');
            }

            if ((string) ($lockedCheckout['status'] ?? '') !== 'paid') {
                Database::execute(
                    "UPDATE billing_checkout_sessions
                     SET status = 'failed',
                         display_text = ?,
                         metadata_json = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [
                        substr($reason, 0, 255),
                        json_encode($this->mergeCheckoutFinalizationMetadata(
                            $this->decodeJson((string) ($lockedCheckout['metadata_json'] ?? '')),
                            $providerData,
                            [
                                'status' => 'failed',
                                'reason' => substr($reason, 0, 255),
                                'provider_status' => (string) ($providerData['status'] ?? 'failed'),
                                'finalized_at' => date('c'),
                            ]
                        ), JSON_UNESCAPED_SLASHES),
                        (int) $lockedCheckout['id'],
                    ]
                );
            }

            if (!empty($lockedCheckout['billing_plan_price_id'])) {
                $subscription = Database::queryOne(
                    "SELECT id
                     FROM workspace_subscriptions
                     WHERE workspace_id = ?
                     ORDER BY id DESC
                     LIMIT 1",
                    [(int) $lockedCheckout['workspace_id']]
                );
                if ($subscription) {
                    Database::execute(
                        "UPDATE workspace_subscriptions
                         SET subscription_status = 'past_due',
                             updated_at = NOW()
                         WHERE id = ?",
                        [(int) $subscription['id']]
                    );
                }

                Database::execute(
                    "UPDATE workspaces
                     SET plan_status = 'past_due',
                         updated_at = NOW()
                     WHERE id = ?",
                    [(int) $lockedCheckout['workspace_id']]
                );
            }

            $failedTransactionType = match ((string) ($lockedCheckout['checkout_type'] ?? '')) {
                'donation' => 'donation',
                default => !empty($lockedCheckout['billing_plan_price_id']) ? 'subscription_charge' : 'token_pack_purchase',
            };

            if (!$this->findExistingTransaction((int) $lockedCheckout['id'], $failedTransactionType)) {
                $provider = (string) ($lockedCheckout['provider'] ?? 'paystack');
                Database::execute(
                    "INSERT INTO billing_transactions
                     (workspace_id, checkout_session_id, provider, provider_reference, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
                     VALUES (?, ?, ?, ?, ?, 'failed', ?, ?, ?, ?, ?)",
                    [
                        (int) $lockedCheckout['workspace_id'],
                        (int) $lockedCheckout['id'],
                        $provider,
                        (string) $lockedCheckout['provider_reference'],
                        $failedTransactionType,
                        (string) ($lockedCheckout['payment_mode'] ?? WorkspaceBillingPaymentModeService::MODE_CARD),
                        (string) ($lockedCheckout['flow_type'] ?? 'redirect'),
                        (float) ($lockedCheckout['amount'] ?? 0),
                        (string) ($lockedCheckout['currency'] ?? 'KES'),
                        json_encode(array_filter([
                            'reason' => substr($reason, 0, 255),
                            'provider_data' => $providerData !== [] ? $providerData : null,
                        ], static fn($value) => $value !== null), JSON_UNESCAPED_SLASHES),
                    ]
                );
            }

            Database::commit();
            if (!empty($lockedCheckout['billing_plan_price_id'])) {
                $this->refreshDefaultWorkspaceOwnerContactAfterPayment(
                    (int) $lockedCheckout['workspace_id'],
                    !empty($lockedCheckout['user_id']) ? (int) $lockedCheckout['user_id'] : null
                );
            }
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function recordCheckoutFinalizationFailure(array $checkout, string $reason, string $stage): void
    {
        $checkoutId = (int) ($checkout['id'] ?? 0);
        if ($checkoutId <= 0) {
            return;
        }

        $lockedCheckout = Database::queryOne(
            "SELECT id, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [$checkoutId]
        );

        if ($lockedCheckout === null) {
            return;
        }

        $metadata = $this->decodeJson((string) ($lockedCheckout['metadata_json'] ?? ''));
        $history = (array) ($metadata['finalization_errors'] ?? []);
        $history[] = [
            'stage' => $stage,
            'reason' => substr(trim($reason), 0, 255),
            'recorded_at' => date('c'),
        ];
        $history = array_slice($history, -5);
        $metadata['finalization_errors'] = $history;

        Database::execute(
            "UPDATE billing_checkout_sessions
             SET metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [json_encode($metadata, JSON_UNESCAPED_SLASHES), $checkoutId]
        );
    }

    private function grantCompassFreeOnboardingCredits(int $workspaceId, int $subscriptionId, array $price, ?int $userId): array
    {
        $entitlements = $this->planEntitlements->entitlementsForPriceRow($price + [
            'plan_code' => WorkspacePlanEntitlementService::PLAN_COMPASS_FREE,
            'plan_name' => 'Compass Free',
        ]);
        $credits = max(0, (int) ($entitlements['included_credits'] ?? 0));
        if ($workspaceId <= 0 || $subscriptionId <= 0 || $credits <= 0) {
            return [];
        }

        $referenceType = 'compass_free_onboarding_credits';
        $referenceId = 'workspace:' . $workspaceId;
        $existing = Database::queryOne(
            "SELECT id
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = ?
               AND reference_id = ?
               AND entry_type = 'credit'
             LIMIT 1",
            [$workspaceId, $referenceType, $referenceId]
        );
        if ($existing) {
            return [
                'ledger_entry_id' => (int) $existing['id'],
                'credit_lot_id' => $this->creditLotIdForSource($workspaceId, $referenceType, $referenceId),
                'credited_tokens' => 0,
                'credited_credits' => 0,
            ];
        }

        return $this->wallets->creditTokens(
            $workspaceId,
            $credits,
            $referenceType,
            $referenceId,
            $userId,
            [
                'subscription_id' => $subscriptionId,
                'plan_code' => WorkspacePlanEntitlementService::PLAN_COMPASS_FREE,
                'credit_expiry_days' => (int) ($entitlements['credit_expiry_days'] ?? 180),
                'source' => 'compass_free_onboarding',
            ]
        ) + [
            'credited_tokens' => $credits,
            'credited_credits' => $credits,
        ];
    }

    private function grantIncludedCreditsForSubscriptionPeriod(
        int $workspaceId,
        int $subscriptionId,
        array $price,
        string $periodStart,
        ?string $periodEnd,
        ?int $userId,
        array $metadata = []
    ): array {
        $entitlements = $this->planEntitlements->entitlementsForPriceRow($price);
        $credits = max(0, (int) ($price['included_tokens'] ?? $entitlements['included_credits'] ?? 0));
        if ($workspaceId <= 0 || $subscriptionId <= 0 || $credits <= 0) {
            return [];
        }

        $periodReference = sprintf('subscription_period:%d:%s', $subscriptionId, date('YmdHis', strtotime($periodStart) ?: time()));
        $existing = Database::queryOne(
            "SELECT id
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'subscription_included_tokens'
               AND reference_id = ?
               AND entry_type = 'credit'
             LIMIT 1",
            [$workspaceId, $periodReference]
        );
        if ($existing) {
            return [
                'ledger_entry_id' => (int) $existing['id'],
                'credit_lot_id' => $this->creditLotIdForSource($workspaceId, 'subscription_included_tokens', $periodReference),
                'credited_tokens' => 0,
                'credited_credits' => 0,
            ];
        }

        return $this->wallets->creditTokens(
            $workspaceId,
            $credits,
            'subscription_included_tokens',
            $periodReference,
            $userId,
            array_merge($metadata, [
                'subscription_id' => $subscriptionId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'plan_code' => (string) ($price['plan_code'] ?? ''),
                'credit_expiry_days' => (int) ($entitlements['credit_expiry_days'] ?? 180),
                'source' => 'subscription_included_credits',
            ])
        ) + [
            'credited_tokens' => $credits,
            'credited_credits' => $credits,
        ];
    }

    private function recordSubscriptionCycle(
        int $workspaceId,
        int $subscriptionId,
        array $price,
        string $periodStart,
        ?string $periodEnd,
        ?int $transactionId,
        int $creditLotId,
        string $status,
        array $metadata = []
    ): void {
        if (!Database::tableExists('workspace_subscription_cycles') || $workspaceId <= 0 || $subscriptionId <= 0) {
            return;
        }

        $periodKey = sprintf('%d:%s', $subscriptionId, date('YmdHis', strtotime($periodStart) ?: time()));
        Database::execute(
            "INSERT INTO workspace_subscription_cycles
                (workspace_id, subscription_id, billing_plan_price_id, period_key, period_start, period_end,
                 included_credits, credit_lot_id, billing_transaction_id, status, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                billing_plan_price_id = VALUES(billing_plan_price_id),
                period_end = VALUES(period_end),
                included_credits = VALUES(included_credits),
                credit_lot_id = COALESCE(VALUES(credit_lot_id), workspace_subscription_cycles.credit_lot_id),
                billing_transaction_id = COALESCE(VALUES(billing_transaction_id), workspace_subscription_cycles.billing_transaction_id),
                status = VALUES(status),
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
                $transactionId && $transactionId > 0 ? $transactionId : null,
                in_array($status, ['active', 'closed', 'failed', 'voided'], true) ? $status : 'active',
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    private function creditLotIdForSource(int $workspaceId, string $sourceType, string $sourceId): int
    {
        if (!Database::tableExists('workspace_credit_lots')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = ?
               AND source_id = ?
             LIMIT 1",
            [$workspaceId, $sourceType, $sourceId]
        );

        return (int) ($row['id'] ?? 0);
    }

    private function loadSubscriptionPrice(int $billingPlanPriceId): ?array
    {
        $price = Database::queryOne(
            "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.id = ?
               AND bpp.is_active = 1
               AND bp.billing_type = 'subscription'
               AND bp.is_active = 1
             LIMIT 1",
            [$billingPlanPriceId]
        );

        return $price ? $this->hydrateSubscriptionPriceRow($price) : null;
    }

    /**
     * @param array<string,mixed> $price
     */
    private function assertSubscriptionPriceBelongsToWorkspace(array $price, int $workspaceId): void
    {
        $privateWorkspaceId = (int) ($price['workspace_id'] ?? $price['metadata']['workspace_id'] ?? 0);
        $metadata = is_array($price['metadata'] ?? null) ? (array) $price['metadata'] : $this->decodeJson((string) ($price['metadata_json'] ?? ''));
        $isPrivate = $privateWorkspaceId > 0 || !empty($metadata['workspace_private']) || !empty($metadata['workspace_negotiated']);
        if (!$isPrivate) {
            return;
        }

        if ($workspaceId <= 0 || $privateWorkspaceId !== $workspaceId) {
            throw new \RuntimeException('Selected package is not available for this workspace.');
        }

        if (empty($metadata['workspace_negotiated']) || !Database::tableExists('workspace_negotiated_package_offers')) {
            throw new \RuntimeException('Selected private package is not available for checkout.');
        }

        $offer = Database::queryOne(
            "SELECT status, starts_at, expires_at
             FROM workspace_negotiated_package_offers
             WHERE negotiated_billing_plan_price_id = ?
               AND workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) ($price['id'] ?? 0), $workspaceId]
        );
        if (!$offer || !in_array((string) ($offer['status'] ?? ''), ['offered', 'accepted', 'active'], true)) {
            throw new \RuntimeException('Selected negotiated package is not currently open for this workspace.');
        }

        $now = time();
        $startsAt = trim((string) ($offer['starts_at'] ?? ''));
        $expiresAt = trim((string) ($offer['expires_at'] ?? ''));
        if ($startsAt !== '' && ($startTs = strtotime($startsAt)) !== false && $startTs > $now) {
            throw new \RuntimeException('Selected negotiated package is not active yet.');
        }
        if ($expiresAt !== '' && ($expiryTs = strtotime($expiresAt)) !== false && $expiryTs < $now) {
            throw new \RuntimeException('Selected negotiated package has expired.');
        }
    }

    private function loadTokenPack(int $tokenPackPriceId): ?array
    {
        $row = Database::queryOne(
            "SELECT tpp.*, bpp.id AS billing_plan_price_id, bpp.price_code, bpp.currency, bpp.amount, bpp.included_tokens, bpp.metadata_json,
                    bp.code AS plan_code, bp.name AS plan_name, bp.description
             FROM token_pack_prices tpp
             JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE tpp.id = ?
               AND tpp.is_active = 1
               AND bpp.is_active = 1
               AND bp.is_active = 1
             LIMIT 1",
            [$tokenPackPriceId]
        );
        if ($row) {
            $row['metadata'] = $this->decodeJson((string) ($row['metadata_json'] ?? ''));
        }

        return $row;
    }

    private function getDefaultSubscriptionPrice(): array
    {
        $price = Database::queryOne(
            "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.price_code = 'compass-free-monthly'
             LIMIT 1"
        );
        if (!$price) {
            throw new \RuntimeException('Default subscription price is not configured.');
        }

        return $price;
    }

    private function resolveCustomerEmail(int $workspaceId, ?int $userId = null): string
    {
        if ($userId !== null && $userId > 0) {
            $user = Database::queryOne("SELECT email FROM users WHERE id = ? LIMIT 1", [$userId]);
            if (!empty($user['email']) && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
                return (string) $user['email'];
            }
        }

        $owner = Database::queryOne(
            "SELECT u.email
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC, wm.id ASC
             LIMIT 1",
            [$workspaceId]
        );
        if (!empty($owner['email']) && filter_var((string) $owner['email'], FILTER_VALIDATE_EMAIL)) {
            return (string) $owner['email'];
        }

        throw new \RuntimeException('A valid workspace billing contact email is required.');
    }

    private function defaultCallbackUrl(): string
    {
        return (new BillingReturnUrlService())->defaultCallbackUrl();
    }

    /**
     * @return array{callback_url:string,return_to:string}
     */
    private function resolveCheckoutReturnUrls(?string $callbackUrl, array $selection): array
    {
        $returnUrls = new BillingReturnUrlService();
        $returnTo = is_string($selection['return_to'] ?? null)
            ? (string) $selection['return_to']
            : null;

        if (!empty($selection['mobile_return_url'])) {
            return $returnUrls->providerCallbackForMobileReturn(
                $returnTo ?? $callbackUrl,
                is_string($selection['configured_mobile_return_url'] ?? null)
                    ? (string) $selection['configured_mobile_return_url']
                    : null
            );
        }

        return $returnUrls->providerCallbackFromRequest($callbackUrl, $returnTo);
    }

    private function resolveWorkspaceBillingCurrency(int $workspaceId): string
    {
        $subscription = Database::queryOne(
            "SELECT bpp.currency
             FROM workspace_subscriptions ws
             JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
             WHERE ws.workspace_id = ?
             ORDER BY ws.id DESC
             LIMIT 1",
            [$workspaceId]
        );
        if (!empty($subscription['currency'])) {
            return strtoupper((string) $subscription['currency']);
        }

        $defaultPrice = Database::queryOne(
            "SELECT currency
             FROM billing_plan_prices
             WHERE is_default = 1
               AND is_active = 1
             ORDER BY id ASC
             LIMIT 1"
        );

        return strtoupper((string) ($defaultPrice['currency'] ?? 'KES'));
    }

    private function resolveGateway(): PaystackGateway
    {
        if ($this->gateway !== null) {
            return $this->gateway;
        }

        $secretKey = trim((string) ($_ENV['PAYSTACK_SECRET_KEY'] ?? $_ENV['PAYSTACK_SECRET'] ?? ''));
        if ($secretKey === '') {
            $settings = $this->legacyBillingSettings();
            $secretKey = trim((string) ($settings['paystack_secret_key'] ?? ''));
        }
        if ($secretKey === '') {
            throw new \RuntimeException('Paystack secret key is not configured.');
        }

        $this->gateway = new PaystackGateway($secretKey);
        return $this->gateway;
    }

    private function resolveMpesaGateway(): MpesaDarajaGateway
    {
        if ($this->mpesaGateway !== null) {
            return $this->mpesaGateway;
        }

        $this->mpesaGateway = new MpesaDarajaGateway($this->resolveMpesaConfig());
        return $this->mpesaGateway;
    }

    private function isPaystackConfigured(): bool
    {
        if ($this->gateway !== null) {
            return true;
        }

        $secretKey = trim((string) ($_ENV['PAYSTACK_SECRET_KEY'] ?? $_ENV['PAYSTACK_SECRET'] ?? ''));
        if ($secretKey === '') {
            $settings = $this->legacyBillingSettings();
            $secretKey = trim((string) ($settings['paystack_secret_key'] ?? ''));
        }

        return $secretKey !== '';
    }

    private function isMpesaConfigured(): bool
    {
        try {
            return $this->resolveMpesaGateway()->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveMpesaConfig(): array
    {
        $settings = $this->legacyBillingSettings();
        $fakeMode = $this->envString('MPESA_FAKE_MODE', 'false');
        $settingOrEnv = function (string $settingKey, string $envKey, string $default = '') use ($settings): string {
            $value = trim((string) ($settings[$settingKey] ?? ''));
            return $value !== '' ? $value : $this->envString($envKey, $default);
        };

        $businessShortCode = $settingOrEnv('mpesa_shortcode', 'MPESA_BUSINESS_SHORTCODE');
        if ($businessShortCode === '') {
            $businessShortCode = $this->envString('MPESA_SHORTCODE', '');
        }

        return [
            'enabled' => !empty($settings['mpesa_enabled']) || $this->envBool('MPESA_ENABLED', false) || strtolower($fakeMode) === 'true',
            'environment' => $settingOrEnv('mpesa_environment', 'MPESA_ENVIRONMENT', 'sandbox'),
            'consumer_key' => $settingOrEnv('mpesa_consumer_key', 'MPESA_CONSUMER_KEY'),
            'consumer_secret' => $settingOrEnv('mpesa_consumer_secret', 'MPESA_CONSUMER_SECRET'),
            'business_short_code' => $businessShortCode,
            'passkey' => $settingOrEnv('mpesa_passkey', 'MPESA_PASSKEY'),
            'callback_url' => $this->resolveMpesaCallbackUrl($settings),
        ];
    }

    /**
     * @param array<string,mixed>|null $settings
     */
    private function resolveMpesaCallbackUrl(?array $settings = null): string
    {
        $settings = $settings ?? $this->legacyBillingSettings();
        $callbackUrl = $this->envString('MPESA_CALLBACK_URL', (string) ($settings['mpesa_callback_url'] ?? WorkspaceBillingSettings::generatedMpesaCallbackUrl()));
        if ($callbackUrl === '') {
            $callbackUrl = WorkspaceBillingSettings::generatedMpesaCallbackUrl();
        }

        return $this->absoluteProviderUrl($callbackUrl);
    }

    private function absoluteProviderUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || str_contains($url, '\\') || str_starts_with($url, '//')) {
            return $url;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }

        $base = trim($this->envString('APP_URL', ''));
        if ($base === '' || preg_match('/^https?:\/\//i', $base) !== 1) {
            $scheme = 'http';
            $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if (in_array($forwardedProto, ['http', 'https'], true)) {
                $scheme = $forwardedProto;
            } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
                $scheme = 'https';
            } elseif (!empty($_SERVER['REQUEST_SCHEME']) && in_array(strtolower((string) $_SERVER['REQUEST_SCHEME']), ['http', 'https'], true)) {
                $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
            }

            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
            $base = $scheme . '://' . $host;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $default;
        }

        return trim((string) $value);
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $value = $this->envString($key, $default ? 'true' : 'false');
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string,bool>
     */
    private function paymentMethodAvailability(): array
    {
        $settings = $this->legacyBillingSettings();

        return $this->paymentModes->normalizeAvailability([
            WorkspaceBillingPaymentModeService::MODE_CARD => !array_key_exists('payment_card_enabled', $settings)
                || !empty($settings['payment_card_enabled']),
            WorkspaceBillingPaymentModeService::MODE_MPESA => !array_key_exists('payment_mpesa_enabled', $settings)
                || !empty($settings['payment_mpesa_enabled']),
            WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER => !empty($settings['payment_bank_transfer_enabled']),
        ]);
    }

    /** @return array<string,mixed> */
    private function paymentMethodPolicy(?int $workspaceId, string $currency): array
    {
        return $this->paymentMarkets->effectivePolicy($workspaceId, $currency, $this->paymentMethodAvailability());
    }

    private function legacyBillingSettings(): array
    {
        try {
            return (new WorkspaceBillingService())->getSettings();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function findCheckoutSessionByReference(string $reference, ?string $provider = null): ?array
    {
        $conditions = ['provider_reference = ?'];
        $params = [$reference];
        if ($provider !== null && trim($provider) !== '') {
            $conditions[] = 'provider = ?';
            $params[] = trim($provider);
        }

        return Database::queryOne(
            "SELECT *
             FROM billing_checkout_sessions
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY id DESC
             LIMIT 1",
            $params
        );
    }

    private function findExistingTransaction(int $checkoutSessionId, string $transactionType): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = ?
             ORDER BY id DESC
             LIMIT 1",
            [$checkoutSessionId, $transactionType]
        );
    }

    private function handleProviderEvent(array $checkout, array $payload, string $event, string $reference): array
    {
        $providerData = (array) ($payload['data'] ?? []);
        if ($event === 'charge.success') {
            $status = strtolower((string) ($providerData['status'] ?? 'success'));
            if (!empty($checkout['provider_plan_code']) && $this->isSuccessfulProviderStatus($status)) {
                return $this->applySuccessfulCheckout($checkout, $providerData, 'webhook');
            }

            return $this->verifyCheckoutReference($reference);
        }

        if ($event === 'charge.failed') {
            $failureReason = (string) (($payload['data']['gateway_response'] ?? '') ?: 'charge_failed');
            $this->markCheckoutFailed($checkout, $failureReason);

            return [
                'success' => false,
                'handled' => true,
                'message' => 'Checkout marked as failed.',
                'snapshot' => $this->getWorkspaceSnapshot((int) $checkout['workspace_id']),
            ];
        }

        if (in_array($event, ['subscription.create', 'invoice.update', 'invoice.payment_failed', 'subscription.not_renew', 'subscription.disable'], true)) {
            $subscriptionCode = $this->extractSubscriptionProviderDetails($providerData, $checkout)['subscription_code'];
            $subscription = $subscriptionCode !== '' ? $this->findSubscriptionByProviderCode($subscriptionCode) : null;
            if ($subscription) {
                return $this->handleSubscriptionProviderEvent($subscription, $payload, $event, $reference);
            }
        }

        $status = strtolower((string) ($providerData['status'] ?? ''));
        if (str_starts_with($event, 'charge.') && $this->isPendingProviderStatus($status)) {
            $this->markCheckoutProcessing($checkout, $providerData, 'webhook');
            return [
                'success' => false,
                'handled' => true,
                'message' => $this->extractProviderDisplayText($providerData) ?: 'Checkout is awaiting payment completion.',
                'snapshot' => $this->getWorkspaceSnapshot((int) $checkout['workspace_id']),
            ];
        }

        return [
            'success' => false,
            'handled' => true,
            'message' => 'Event logged without billing mutation.',
        ];
    }

    private function handleSubscriptionProviderEvent(array $subscription, array $payload, string $event, string $eventReference): array
    {
        $providerData = (array) ($payload['data'] ?? []);

        if ($event === 'subscription.create') {
            $this->updateSubscriptionProviderState($subscription, $providerData, 'renewing', 'active');
            return [
                'success' => true,
                'handled' => true,
                'message' => 'Subscription provider identifiers updated.',
                'snapshot' => $this->getWorkspaceSnapshot((int) $subscription['workspace_id']),
            ];
        }

        if ($event === 'invoice.update') {
            $paid = (bool) ($providerData['paid'] ?? false);
            $status = strtolower((string) ($providerData['status'] ?? ''));
            if ($paid || $this->isSuccessfulProviderStatus($status)) {
                return $this->applyRecurringSubscriptionPayment($subscription, $providerData, $event, $eventReference);
            }

            if (in_array($status, ['failed', 'attention'], true)) {
                return $this->markSubscriptionPaymentFailed($subscription, $providerData, $eventReference);
            }
        }

        if ($event === 'charge.success') {
            return $this->applyRecurringSubscriptionPayment($subscription, $providerData, $event, $eventReference);
        }

        if ($event === 'invoice.payment_failed') {
            return $this->markSubscriptionPaymentFailed($subscription, $providerData, $eventReference);
        }

        if ($event === 'subscription.not_renew') {
            $this->updateSubscriptionProviderState($subscription, $providerData, 'non_renewing', 'active');
            return [
                'success' => true,
                'handled' => true,
                'message' => 'Subscription marked as not renewing.',
                'snapshot' => $this->getWorkspaceSnapshot((int) $subscription['workspace_id']),
            ];
        }

        if ($event === 'subscription.disable') {
            $details = $this->extractSubscriptionProviderDetails($providerData, $subscription);
            $metadata = $this->decodeJson((string) ($subscription['scheduled_change_metadata_json'] ?? ''));
            $currentScheduledCode = trim((string) ($metadata['current_provider_subscription_code'] ?? ''));
            $incomingCode = $details['subscription_code'] ?: (string) ($subscription['provider_subscription_code'] ?? '');
            if (
                (string) ($subscription['scheduled_change_type'] ?? '') === 'downgrade'
                && $currentScheduledCode !== ''
                && $incomingCode !== ''
                && hash_equals($currentScheduledCode, $incomingCode)
            ) {
                $this->updateSubscriptionProviderState($subscription, $providerData, 'non_renewing', 'active');
                return [
                    'success' => true,
                    'handled' => true,
                    'message' => 'Current subscription disabled for a scheduled package downgrade.',
                    'snapshot' => $this->getWorkspaceSnapshot((int) $subscription['workspace_id']),
                ];
            }

            $providerStatus = strtolower((string) ($providerData['status'] ?? 'cancelled'));
            $localStatus = $providerStatus === 'complete' ? 'expired' : 'cancelled';
            $this->updateSubscriptionProviderState($subscription, $providerData, 'disabled', $localStatus);
            Database::execute(
                "UPDATE workspaces
                 SET plan_status = 'past_due',
                     updated_at = NOW()
                 WHERE id = ?
                   AND status NOT IN ('suspended', 'archived')",
                [(int) $subscription['workspace_id']]
            );

            return [
                'success' => true,
                'handled' => true,
                'message' => 'Subscription disabled by provider.',
                'snapshot' => $this->getWorkspaceSnapshot((int) $subscription['workspace_id']),
            ];
        }

        return [
            'success' => false,
            'handled' => true,
            'message' => 'Subscription event logged without billing mutation.',
        ];
    }

    private function applyRecurringSubscriptionPayment(array $subscription, array $providerData, string $event, string $eventReference): array
    {
        Database::beginTransaction();
        try {
            $locked = Database::queryOne(
                "SELECT *
                 FROM workspace_subscriptions
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE",
                [(int) $subscription['id']]
            );
            if (!$locked) {
                throw new \RuntimeException('Subscription no longer exists.');
            }

            $providerDetails = $this->extractSubscriptionProviderDetails($providerData, $locked);
            $scheduledMetadata = $this->decodeJson((string) ($locked['scheduled_change_metadata_json'] ?? ''));
            $incomingSubscriptionCode = $providerDetails['subscription_code'];
            $scheduledTargetCode = trim((string) ($scheduledMetadata['target_provider_subscription_code'] ?? ''));
            $isScheduledProviderPayment = $scheduledTargetCode !== ''
                && $incomingSubscriptionCode !== ''
                && hash_equals($scheduledTargetCode, $incomingSubscriptionCode);
            $priceId = $isScheduledProviderPayment && (int) ($locked['scheduled_billing_plan_price_id'] ?? 0) > 0
                ? (int) $locked['scheduled_billing_plan_price_id']
                : (int) ($locked['billing_plan_price_id'] ?? 0);
            $price = $this->loadSubscriptionPrice($priceId);
            if ($price === null) {
                throw new \RuntimeException('Subscription price is not available.');
            }

            $paidAt = $this->normalizeProviderDateTime($providerData['paid_at'] ?? ($providerData['transaction']['paid_at'] ?? null)) ?? date('Y-m-d H:i:s');
            $periodStart = $this->normalizeProviderDateTime($providerData['period_start'] ?? null) ?? $paidAt;
            $periodEnd = $this->normalizeProviderDateTime($providerData['period_end'] ?? null)
                ?? $providerDetails['next_payment_date']
                ?? $this->calculatePeriodEnd($periodStart, (string) ($price['interval_unit'] ?? 'monthly'), (int) ($price['interval_count'] ?? 1));
            $nextBillingAt = $providerDetails['next_payment_date'] ?? $periodEnd;
            $reference = $this->extractProviderReference(['data' => $providerData]) ?: $eventReference;
            $subscriptionCode = $providerDetails['subscription_code'] ?: (string) ($locked['provider_subscription_code'] ?? '');
            $customerCode = $providerDetails['customer_code'] ?: (string) ($locked['provider_customer_code'] ?? '');
            $emailToken = $providerDetails['email_token'] ?: (string) ($locked['provider_email_token'] ?? '');
            $amount = isset($providerData['amount'])
                ? ((float) $providerData['amount'] / 100)
                : (float) ($price['amount'] ?? 0);

            Database::execute(
                "UPDATE workspace_subscriptions
                 SET billing_plan_price_id = ?,
                     provider_reference = COALESCE(?, provider_reference),
                     subscription_status = 'active',
                     provider_subscription_code = COALESCE(?, provider_subscription_code),
                     provider_customer_code = COALESCE(?, provider_customer_code),
                     provider_email_token = COALESCE(?, provider_email_token),
                     provider_subscription_status = ?,
                     renewal_status = 'renewing',
                     provider_metadata_json = ?,
                     current_period_start = ?,
                     current_period_end = ?,
                     next_billing_at = ?,
                     scheduled_billing_plan_price_id = CASE WHEN ? THEN NULL ELSE scheduled_billing_plan_price_id END,
                     scheduled_change_type = CASE WHEN ? THEN NULL ELSE scheduled_change_type END,
                     scheduled_change_at = CASE WHEN ? THEN NULL ELSE scheduled_change_at END,
                     scheduled_change_metadata_json = CASE WHEN ? THEN NULL ELSE scheduled_change_metadata_json END,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    (int) $price['id'],
                    $reference !== '' ? $reference : null,
                    $subscriptionCode !== '' ? $subscriptionCode : null,
                    $customerCode !== '' ? $customerCode : null,
                    $emailToken !== '' ? $emailToken : null,
                    $providerDetails['subscription_status'] ?: 'active',
                    json_encode(array_merge(
                        $this->decodeJson((string) ($locked['provider_metadata_json'] ?? '')),
                        [
                            'last_provider_event' => $providerData,
                            'last_provider_event_name' => $event,
                            'last_provider_event_reference' => $eventReference,
                            'scheduled_change_applied' => $isScheduledProviderPayment ? [
                                'source' => 'scheduled_provider_subscription_payment',
                                'target_billing_plan_price_id' => (int) $price['id'],
                                'target_plan_code' => (string) ($price['plan_code'] ?? ''),
                                'scheduled_metadata' => $scheduledMetadata,
                                'applied_at' => date('c'),
                            ] : null,
                        ]
                    ), JSON_UNESCAPED_SLASHES),
                    $periodStart,
                    $periodEnd,
                    $nextBillingAt,
                    $isScheduledProviderPayment ? 1 : 0,
                    $isScheduledProviderPayment ? 1 : 0,
                    $isScheduledProviderPayment ? 1 : 0,
                    $isScheduledProviderPayment ? 1 : 0,
                    (int) $locked['id'],
                ]
            );

            Database::execute(
                "UPDATE workspaces
                 SET plan_status = 'active',
                     status = CASE WHEN status IN ('suspended', 'archived') THEN status ELSE 'active' END,
                     updated_at = NOW()
                 WHERE id = ?",
                [(int) $locked['workspace_id']]
            );

            $transaction = $this->findExistingTransactionByProviderReference((int) $locked['workspace_id'], $reference, 'subscription_charge');
            if (!$transaction) {
                Database::execute(
                    "INSERT INTO billing_transactions
                     (workspace_id, checkout_session_id, subscription_id, provider, provider_reference, provider_subscription_code,
                      provider_customer_code, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
                     VALUES (?, NULL, ?, 'paystack', ?, ?, ?, 'subscription_charge', 'succeeded', 'card', 'redirect', ?, ?, ?)",
                    [
                        (int) $locked['workspace_id'],
                        (int) $locked['id'],
                        $reference,
                        $subscriptionCode !== '' ? $subscriptionCode : null,
                        $customerCode !== '' ? $customerCode : null,
                        $amount,
                        (string) ($price['currency'] ?? 'KES'),
                        json_encode([
                            'provider_event' => $event,
                            'provider_event_reference' => $eventReference,
                            'provider_plan_code' => (string) ($price['provider_plan_code'] ?? ''),
                            'included_tokens' => (int) ($price['included_tokens'] ?? 0),
                        ], JSON_UNESCAPED_SLASHES),
                    ]
                );
                $transactionId = (int) Database::lastInsertId();
            } else {
                $transactionId = (int) $transaction['id'];
            }

            $creditResult = $this->grantIncludedCreditsForSubscriptionPeriod(
                (int) $locked['workspace_id'],
                (int) $locked['id'],
                $price,
                $periodStart,
                $periodEnd,
                null,
                [
                    'subscription_id' => (int) $locked['id'],
                    'provider_event' => $event,
                    'provider_reference' => $reference,
                    'billing_transaction_id' => $transactionId,
                ]
            );
            $includedTokens = (int) ($price['included_tokens'] ?? 0);
            $this->recordSubscriptionCycle(
                (int) $locked['workspace_id'],
                (int) $locked['id'],
                $price,
                $periodStart,
                $periodEnd,
                $transactionId,
                (int) ($creditResult['credit_lot_id'] ?? 0),
                'active',
                ['source' => $isScheduledProviderPayment ? 'scheduled_downgrade_subscription_payment' : 'recurring_subscription_payment', 'provider_event' => $event]
            );
            $billingInvoice = (new WorkspacePackageBillingInvoiceService())->issueForSubscriptionTransaction($transactionId);

            Database::commit();

            return [
                'success' => true,
                'handled' => true,
                'message' => 'Recurring subscription payment applied.',
                'subscription' => [
                    'subscription_id' => (int) $locked['id'],
                    'transaction_id' => $transactionId,
                    'billing_invoice_id' => (int) ($billingInvoice['id'] ?? 0),
                    'billing_invoice' => (array) ($billingInvoice['summary'] ?? []),
                    'included_tokens_credited' => (int) ($creditResult['ledger_entry_id'] ?? 0) > 0 ? $includedTokens : 0,
                    'included_credits_credited' => (int) ($creditResult['ledger_entry_id'] ?? 0) > 0 ? $includedTokens : 0,
                ],
                'snapshot' => $this->getWorkspaceSnapshot((int) $locked['workspace_id']),
            ];
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function markSubscriptionPaymentFailed(array $subscription, array $providerData, string $eventReference): array
    {
        Database::beginTransaction();
        try {
            $reference = $this->extractProviderReference(['data' => $providerData]) ?: $eventReference;
            $details = $this->extractSubscriptionProviderDetails($providerData, $subscription);
            Database::execute(
                "UPDATE workspace_subscriptions
                 SET subscription_status = 'past_due',
                     provider_subscription_status = ?,
                     renewal_status = 'attention',
                     provider_metadata_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $details['subscription_status'] ?: 'attention',
                    json_encode(array_merge(
                        $this->decodeJson((string) ($subscription['provider_metadata_json'] ?? '')),
                        ['last_payment_failure' => $providerData, 'last_payment_failure_reference' => $eventReference]
                    ), JSON_UNESCAPED_SLASHES),
                    (int) $subscription['id'],
                ]
            );
            Database::execute(
                "UPDATE workspaces
                 SET plan_status = 'past_due',
                     updated_at = NOW()
                 WHERE id = ?
                   AND status NOT IN ('suspended', 'archived')",
                [(int) $subscription['workspace_id']]
            );

            if (!$this->findExistingTransactionByProviderReference((int) $subscription['workspace_id'], $reference, 'subscription_charge')) {
                $price = $this->loadSubscriptionPrice((int) ($subscription['billing_plan_price_id'] ?? 0));
                $amount = isset($providerData['amount'])
                    ? ((float) $providerData['amount'] / 100)
                    : (float) ($price['amount'] ?? 0);
                Database::execute(
                    "INSERT INTO billing_transactions
                     (workspace_id, checkout_session_id, subscription_id, provider, provider_reference, provider_subscription_code,
                      provider_customer_code, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
                     VALUES (?, NULL, ?, 'paystack', ?, ?, ?, 'subscription_charge', 'failed', 'card', 'redirect', ?, ?, ?)",
                    [
                        (int) $subscription['workspace_id'],
                        (int) $subscription['id'],
                        $reference,
                        (string) ($subscription['provider_subscription_code'] ?? '') ?: null,
                        (string) ($subscription['provider_customer_code'] ?? '') ?: null,
                        $amount,
                        (string) ($price['currency'] ?? 'KES'),
                        json_encode(['reason' => (string) (($providerData['gateway_response'] ?? '') ?: 'invoice.payment_failed')], JSON_UNESCAPED_SLASHES),
                    ]
                );
            }

            Database::commit();

            return [
                'success' => true,
                'handled' => true,
                'message' => 'Subscription payment failure recorded.',
                'snapshot' => $this->getWorkspaceSnapshot((int) $subscription['workspace_id']),
            ];
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function updateSubscriptionProviderState(array $subscription, array $providerData, string $renewalStatus, string $localStatus): void
    {
        $details = $this->extractSubscriptionProviderDetails($providerData, $subscription);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET provider_subscription_code = COALESCE(?, provider_subscription_code),
                 provider_customer_code = COALESCE(?, provider_customer_code),
                 provider_email_token = COALESCE(?, provider_email_token),
                 provider_subscription_status = ?,
                 renewal_status = ?,
                 provider_metadata_json = ?,
                 subscription_status = ?,
                 next_billing_at = COALESCE(?, next_billing_at),
                 updated_at = NOW()
             WHERE id = ?",
            [
                $details['subscription_code'] !== '' ? $details['subscription_code'] : null,
                $details['customer_code'] !== '' ? $details['customer_code'] : null,
                $details['email_token'] !== '' ? $details['email_token'] : null,
                $details['subscription_status'] ?: $localStatus,
                $renewalStatus,
                json_encode(array_merge(
                    $this->decodeJson((string) ($subscription['provider_metadata_json'] ?? '')),
                    ['last_provider_state_event' => $providerData]
                ), JSON_UNESCAPED_SLASHES),
                $localStatus,
                $details['next_payment_date'],
                (int) $subscription['id'],
            ]
        );
    }

    private function extractProviderReference(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? (array) $payload['data'] : [];
        $transaction = is_array($data['transaction'] ?? null) ? (array) $data['transaction'] : [];

        return trim((string) (
            $data['reference']
            ?? $transaction['reference']
            ?? $data['transaction_reference']
            ?? ''
        ));
    }

    private function extractProviderEventReference(array $providerData, string $event): string
    {
        foreach (['invoice_code', 'invoiceCode', 'subscription_code', 'subscriptionCode', 'reference'] as $key) {
            if (is_string($providerData[$key] ?? null) && trim((string) $providerData[$key]) !== '') {
                return trim((string) $providerData[$key]);
            }
        }

        return $event . '_' . substr(hash('sha256', json_encode($providerData, JSON_UNESCAPED_SLASHES) ?: $event), 0, 20);
    }

    private function findSubscriptionByProviderCode(string $subscriptionCode): ?array
    {
        $subscriptionCode = trim($subscriptionCode);
        if ($subscriptionCode === '') {
            return null;
        }

        $direct = Database::queryOne(
            "SELECT *
             FROM workspace_subscriptions
             WHERE provider = 'paystack'
               AND provider_subscription_code = ?
             ORDER BY id DESC
             LIMIT 1",
            [$subscriptionCode]
        );

        if ($direct) {
            return $direct;
        }

        $scheduledRows = Database::query(
            "SELECT *
             FROM workspace_subscriptions
             WHERE scheduled_change_type = 'downgrade'
               AND scheduled_change_metadata_json IS NOT NULL
             ORDER BY id DESC
             LIMIT 250"
        );

        foreach ($scheduledRows as $row) {
            $metadata = $this->decodeJson((string) ($row['scheduled_change_metadata_json'] ?? ''));
            $targetCode = trim((string) ($metadata['target_provider_subscription_code'] ?? ''));
            if ($targetCode !== '' && hash_equals($targetCode, $subscriptionCode)) {
                $row['matched_scheduled_provider_subscription'] = true;
                return $row;
            }
        }

        return null;
    }

    private function findExistingTransactionByProviderReference(int $workspaceId, string $providerReference, string $transactionType): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM billing_transactions
             WHERE workspace_id = ?
               AND provider = 'paystack'
               AND provider_reference = ?
               AND transaction_type = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $providerReference, $transactionType]
        );
    }

    private function logProviderEvent(
        string $eventName,
        string $reference,
        ?int $workspaceId,
        ?array $payload,
        string $processingStatus,
        string $message,
        ?string $signature = null,
        string $provider = 'paystack'
    ): int {
        Database::execute(
            "INSERT INTO billing_provider_events
             (provider, workspace_id, event_name, event_reference, signature, payload_json, processing_status, processing_message, processed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $provider,
                $workspaceId,
                $eventName,
                $reference !== '' ? $reference : null,
                $signature !== null ? trim($signature) : null,
                $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
                $processingStatus,
                substr($message, 0, 255),
                $processingStatus === 'pending' ? null : date('Y-m-d H:i:s'),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function finalizeProviderEvent(int $eventId, string $status, string $message): void
    {
        if ($eventId <= 0) {
            return;
        }

        Database::execute(
            "UPDATE billing_provider_events
             SET processing_status = ?,
                 processing_message = ?,
                 processed_at = NOW()
             WHERE id = ?",
            [$status, substr($message, 0, 255), $eventId]
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $providerData
     * @param array<string,mixed> $finalization
     * @return array<string,mixed>
     */
    private function mergeCheckoutFinalizationMetadata(array $metadata, array $providerData, array $finalization): array
    {
        $metadata['finalization'] = $finalization;
        if ($providerData !== []) {
            $metadata['last_provider_data'] = $providerData;
        }

        foreach (['checkout_request_id', 'merchant_request_id', 'receipt_number', 'phone_number', 'paid_at'] as $key) {
            if (isset($providerData[$key]) && $providerData[$key] !== '') {
                $metadata[$key] = $providerData[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeMpesaResultData(array $payload, string $source): array
    {
        $metadata = $this->extractMpesaCallbackMetadata($payload);
        $resultCodeValue = $payload['ResultCode'] ?? $payload['result_code'] ?? null;
        $hasResultCode = is_scalar($resultCodeValue) && trim((string) $resultCodeValue) !== '';
        $resultCode = $hasResultCode ? trim((string) $resultCodeValue) : '';
        $responseCode = trim((string) ($payload['ResponseCode'] ?? ''));
        $resultDesc = trim((string) (
            $payload['ResultDesc']
            ?? $payload['result_desc']
            ?? $payload['ResponseDescription']
            ?? $payload['CustomerMessage']
            ?? ''
        ));

        if ($hasResultCode) {
            $status = (int) $resultCode === 0 ? 'success' : 'failed';
        } elseif (isset($payload['status']) && trim((string) $payload['status']) !== '') {
            $status = strtolower(trim((string) $payload['status']));
        } elseif ($responseCode === '0') {
            $status = 'pending';
        } else {
            $status = 'failed';
        }

        $checkoutRequestId = trim((string) ($payload['CheckoutRequestID'] ?? $payload['checkout_request_id'] ?? ''));
        $merchantRequestId = trim((string) ($payload['MerchantRequestID'] ?? $payload['merchant_request_id'] ?? ''));
        $receiptNumber = trim((string) ($metadata['MpesaReceiptNumber'] ?? $payload['MpesaReceiptNumber'] ?? $payload['receipt_number'] ?? ''));
        $transactionDate = $metadata['TransactionDate'] ?? $payload['TransactionDate'] ?? $payload['paid_at'] ?? null;
        $phoneNumber = trim((string) ($metadata['PhoneNumber'] ?? $payload['PhoneNumber'] ?? $payload['phone_number'] ?? ''));
        $amount = $metadata['Amount'] ?? $payload['Amount'] ?? $payload['amount'] ?? null;
        $paidAt = $this->normalizeMpesaTransactionDate($transactionDate) ?? $this->normalizeProviderDateTime($transactionDate);

        return array_filter([
            'reference' => $checkoutRequestId,
            'status' => $status,
            'gateway_response' => $resultDesc !== '' ? $resultDesc : ($status === 'success' ? 'M-Pesa payment completed.' : 'M-Pesa payment is pending.'),
            'result_code' => $hasResultCode ? $resultCode : null,
            'response_code' => $responseCode !== '' ? $responseCode : null,
            'result_desc' => $resultDesc !== '' ? $resultDesc : null,
            'checkout_request_id' => $checkoutRequestId !== '' ? $checkoutRequestId : null,
            'merchant_request_id' => $merchantRequestId !== '' ? $merchantRequestId : null,
            'receipt_number' => $receiptNumber !== '' ? $receiptNumber : null,
            'mpesa_receipt_number' => $receiptNumber !== '' ? $receiptNumber : null,
            'paid_at' => $paidAt,
            'paid_at_raw' => $transactionDate,
            'phone_number' => $phoneNumber !== '' ? $phoneNumber : null,
            'amount' => is_numeric($amount) ? (float) $amount : null,
            'instructions' => array_filter([
                'checkout_request_id' => $checkoutRequestId !== '' ? $checkoutRequestId : null,
                'merchant_request_id' => $merchantRequestId !== '' ? $merchantRequestId : null,
                'receipt_number' => $receiptNumber !== '' ? $receiptNumber : null,
            ], static fn($value) => $value !== null && $value !== ''),
            'mpesa_metadata' => $metadata !== [] ? $metadata : null,
            'source' => $source,
            'raw' => $payload,
        ], static fn($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractMpesaCallbackMetadata(array $payload): array
    {
        $items = $payload['CallbackMetadata']['Item'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $metadata = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['Name'])) {
                continue;
            }
            $name = trim((string) $item['Name']);
            if ($name === '') {
                continue;
            }
            $metadata[$name] = $item['Value'] ?? null;
        }

        return $metadata;
    }

    private function normalizeMpesaTransactionDate($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($digits) || strlen($digits) !== 14) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('YmdHis', $digits);
        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-d H:i:s') : null;
    }

    private function normalizeProviderDateTime($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function calculatePeriodEnd(string $periodStart, string $intervalUnit, int $intervalCount): string
    {
        $intervalCount = max(1, $intervalCount);
        $period = match ($intervalUnit) {
            'weekly' => 'P' . $intervalCount . 'W',
            'quarterly' => 'P' . ($intervalCount * 3) . 'M',
            'yearly' => 'P' . $intervalCount . 'Y',
            default => 'P' . $intervalCount . 'M',
        };

        return (new \DateTimeImmutable($periodStart))->add(new \DateInterval($period))->format('Y-m-d H:i:s');
    }

    private function isSuccessfulProviderStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['success', 'succeeded'], true);
    }

    private function isPendingProviderStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['pending', 'send_otp', 'send_phone', 'pay_offline', 'processing', 'ongoing'], true);
    }

    private function extractProviderDisplayText(array $providerData): string
    {
        $candidates = [
            $providerData['display_text'] ?? null,
            $providerData['gateway_response'] ?? null,
            $providerData['message'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function extractProviderInstructions(array $providerData): array
    {
        $instructions = $providerData['instructions'] ?? $providerData['bank'] ?? $providerData['bank_transfer'] ?? [];
        if (!is_array($instructions)) {
            $instructions = [];
        }

        if (!isset($instructions['expires_at']) && !empty($providerData['expires_at'])) {
            $instructions['expires_at'] = $providerData['expires_at'];
        }

        return $instructions;
    }

    /**
     * @param array<string,mixed> $instructions
     * @return array<string,mixed>
     */
    private function userFacingCheckoutInstructions(string $paymentMode, array $instructions): array
    {
        if ($paymentMode === WorkspaceBillingPaymentModeService::MODE_MPESA) {
            return [];
        }

        return $instructions;
    }

    /**
     * @param array<string,mixed> $providerData
     * @param array<string,mixed> $checkout
     * @return array{subscription_code:string,customer_code:string,email_token:string,subscription_status:string,next_payment_date:?string,subscription_payload:mixed,customer_payload:mixed}
     */
    private function extractSubscriptionProviderDetails(array $providerData, array $checkout = []): array
    {
        $subscription = $providerData['subscription'] ?? [];
        $customer = $providerData['customer'] ?? [];
        $subscriptionPayload = $subscription;
        $customerPayload = $customer;
        $subscription = is_array($subscription) ? $subscription : [];
        $customer = is_array($customer) ? $customer : [];

        $subscriptionCode = trim((string) (
            $providerData['subscription_code']
            ?? $providerData['subscriptionCode']
            ?? $subscription['subscription_code']
            ?? $subscription['code']
            ?? $subscription['subscriptionCode']
            ?? $checkout['provider_subscription_code']
            ?? ''
        ));
        if ($subscriptionCode === '' && is_string($subscriptionPayload) && str_starts_with($subscriptionPayload, 'SUB_')) {
            $subscriptionCode = $subscriptionPayload;
        }

        $customerCode = trim((string) (
            $providerData['customer_code']
            ?? $providerData['customerCode']
            ?? $customer['customer_code']
            ?? $customer['code']
            ?? $checkout['provider_customer_code']
            ?? ''
        ));
        if ($customerCode === '' && is_string($customerPayload) && str_starts_with($customerPayload, 'CUS_')) {
            $customerCode = $customerPayload;
        }

        $emailToken = trim((string) (
            $providerData['email_token']
            ?? $providerData['emailToken']
            ?? $subscription['email_token']
            ?? $subscription['emailToken']
            ?? ''
        ));

        $nextPaymentDate = $this->normalizeProviderDateTime(
            $providerData['next_payment_date']
            ?? $providerData['nextPaymentDate']
            ?? $subscription['next_payment_date']
            ?? $subscription['nextPaymentDate']
            ?? null
        );

        $subscriptionStatus = trim((string) (
            $providerData['subscription_status']
            ?? $providerData['subscriptionStatus']
            ?? $subscription['status']
            ?? ''
        ));

        return [
            'subscription_code' => $subscriptionCode,
            'customer_code' => $customerCode,
            'email_token' => $emailToken,
            'subscription_status' => $subscriptionStatus,
            'next_payment_date' => $nextPaymentDate,
            'subscription_payload' => $subscriptionPayload,
            'customer_payload' => $customerPayload,
        ];
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function executeWithReadiness(string $surface, callable $callback)
    {
        $this->readiness->assertWorkspaceSaasReadiness($surface);

        try {
            return $callback();
        } catch (\PDOException $e) {
            if ($this->isSchemaMismatchException($e)) {
                throw new WorkspaceLaunchReadinessException(
                    $surface,
                    $this->readiness->checkWorkspaceSaasReadiness($surface),
                    $e
                );
            }

            throw $e;
        }
    }

    private function isSchemaMismatchException(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unknown column')
            || str_contains($message, 'base table or view not found')
            || str_contains($message, "doesn't exist");
    }
}
