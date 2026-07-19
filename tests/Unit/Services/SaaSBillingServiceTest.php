<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Services\DefaultWorkspaceOwnerContactService;
use CRM\Services\MpesaDarajaGateway;
use CRM\Services\PaystackGateway;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class SaaSBillingServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ]);
    }

    public function testWorkspaceSnapshotShowsCompassCreditsForSuperAdminActor(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Super Admin Snapshot Workspace',
            'workspace_slug' => 'super-admin-snapshot-workspace',
            'first_name' => 'Snapshot',
            'last_name' => 'Admin',
            'email' => 'snapshot.superadmin@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($userId, 'superadmin');
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];

        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId, $actor);

        $this->assertSame(50000, (int) ($snapshot['available_credits'] ?? -1));
        $this->assertTrue((bool) ($snapshot['is_ai_billing_exempt'] ?? false));
        $this->assertNull($snapshot['ai_blocked_reason'] ?? null);
    }

    public function testWorkspaceSnapshotShowsCompassCreditsForRegularActor(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Regular Snapshot Workspace',
            'workspace_slug' => 'regular-snapshot-workspace',
            'first_name' => 'Regular',
            'last_name' => 'Snapshot',
            'email' => 'regular.snapshot@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]) ?? [];

        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId, $actor);

        $this->assertSame(50000, (int) ($snapshot['available_credits'] ?? -1));
        $this->assertFalse((bool) ($snapshot['is_ai_billing_exempt'] ?? true));
        $this->assertNull($snapshot['ai_blocked_reason'] ?? null);
    }

    public function testDefaultWorkspaceSnapshotSuppressesWalletDepletedForRegularActor(): void
    {
        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot(1);

        $this->assertSame(0, (int) ($snapshot['available_tokens'] ?? -1));
        $this->assertTrue((bool) ($snapshot['is_ai_billing_exempt'] ?? false));
        $this->assertTrue((bool) ($snapshot['package_exempt'] ?? false));
        $this->assertFalse((bool) ($snapshot['billing_blocked'] ?? true));
        $this->assertNull($snapshot['ai_blocked_reason'] ?? null);
    }

    public function testDefaultWorkspaceSnapshotIgnoresRestrictedPackageStatus(): void
    {
        $price = (new WorkspacePlanEntitlementService())->compassFreePrice() ?? [];
        $this->assertNotEmpty($price['id']);
        Database::execute("DELETE FROM workspace_subscriptions WHERE workspace_id = 1");
        Database::execute(
            "INSERT INTO workspace_subscriptions
             (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_status,
              renewal_status, subscription_status, current_period_start, current_period_end, created_at, updated_at)
             VALUES (1, ?, 'internal', 'default-expired-test', 'expired', 'free', 'expired', DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), NOW(), NOW())",
            [(int) $price['id']]
        );
        Database::execute("UPDATE workspaces SET status = 'active', plan_status = 'past_due' WHERE id = 1");

        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot(1);

        $this->assertSame('expired', (string) ($snapshot['subscription_status'] ?? ''));
        $this->assertTrue((bool) ($snapshot['package_exempt'] ?? false));
        $this->assertFalse((bool) ($snapshot['billing_blocked'] ?? true));
        $this->assertFalse((bool) ($snapshot['show_prompt'] ?? true));
        $this->assertTrue((bool) ($snapshot['is_ai_billing_exempt'] ?? false));
        $this->assertNull($snapshot['ai_blocked_reason'] ?? null);
    }

    public function testDonationPaymentOptionsPreferConfiguredDefaultCurrencyAndKeepPaystackFallback(): void
    {
        (new WorkspaceBillingSettings())->save([
            'default_currency' => 'KES',
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ]);

        $options = (new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway()))
            ->donationPaymentOptions(['USD'], true);
        $currencyValues = array_map(
            static fn(array $currency): string => (string) ($currency['value'] ?? ''),
            (array) ($options['currencies'] ?? [])
        );
        $kesModes = array_column((array) (($options['modes_by_currency']['KES'] ?? [])), 'key');
        $ngnModes = array_column((array) (($options['modes_by_currency']['NGN'] ?? [])), 'key');

        $this->assertSame('KES', (string) ($options['default_currency'] ?? ''));
        $this->assertSame('KES', (string) ($options['readiness']['configured_default_currency'] ?? ''));
        $this->assertSame('KES', (string) ($options['readiness']['selected_default_currency'] ?? ''));
        $this->assertTrue((bool) ($options['readiness']['paystack_ready'] ?? false));
        $this->assertTrue((bool) ($options['readiness']['mpesa_ready'] ?? false));
        $this->assertContains('KES', $currencyValues);
        $this->assertContains('NGN', $currencyValues);
        $this->assertContains('mpesa', $kesModes);
        $this->assertContains('card', $kesModes);
        $this->assertContains('bank_transfer', $ngnModes);
    }

    public function testDonationPaymentOptionsUseSavedMpesaSettingsWhenEnvIsBlankOrFalse(): void
    {
        (new WorkspaceBillingSettings())->save([
            'default_currency' => 'KES',
            'mpesa_enabled' => true,
            'mpesa_environment' => 'live',
            'mpesa_consumer_key' => 'saved-consumer-key',
            'mpesa_consumer_secret' => 'saved-consumer-secret',
            'mpesa_shortcode' => '731996',
            'mpesa_passkey' => 'saved-passkey',
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => false,
        ]);

        $envKeys = [
            'MPESA_FAKE_MODE' => 'false',
            'MPESA_ENABLED' => 'false',
            'MPESA_CONSUMER_KEY' => '',
            'MPESA_CONSUMER_SECRET' => '',
            'MPESA_BUSINESS_SHORTCODE' => '',
            'MPESA_SHORTCODE' => '',
            'MPESA_PASSKEY' => '',
        ];
        $previous = [];
        foreach ($envKeys as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        try {
            $options = (new SaaSBillingService(new FakePaystackGateway()))->donationPaymentOptions(['KES'], true);
        } finally {
            foreach ($envKeys as $key => $_) {
                if ($previous[$key] === false) {
                    putenv($key);
                    unset($_ENV[$key]);
                } else {
                    putenv($key . '=' . $previous[$key]);
                    $_ENV[$key] = $previous[$key];
                }
            }
        }

        $kesModes = array_column((array) (($options['modes_by_currency']['KES'] ?? [])), 'key');

        $this->assertSame('KES', (string) ($options['default_currency'] ?? ''));
        $this->assertContains('mpesa', $kesModes);
    }

    public function testSavedMpesaSettingsOverrideStaleEnvironmentValues(): void
    {
        (new WorkspaceBillingSettings())->save([
            'default_currency' => 'KES',
            'mpesa_enabled' => true,
            'mpesa_environment' => 'live',
            'mpesa_consumer_key' => 'saved-live-consumer-key',
            'mpesa_consumer_secret' => 'saved-live-consumer-secret',
            'mpesa_shortcode' => '731996',
            'mpesa_passkey' => 'saved-live-passkey',
            'payment_mpesa_enabled' => true,
        ]);

        $envKeys = [
            'MPESA_ENVIRONMENT' => 'sandbox',
            'MPESA_CONSUMER_KEY' => 'stale-env-consumer-key',
            'MPESA_CONSUMER_SECRET' => 'stale-env-consumer-secret',
            'MPESA_BUSINESS_SHORTCODE' => '174379',
            'MPESA_SHORTCODE' => '174379',
            'MPESA_PASSKEY' => 'stale-env-passkey',
        ];
        $previous = [];
        foreach ($envKeys as $key => $value) {
            $previous[$key] = getenv($key);
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        try {
            $method = new \ReflectionMethod(SaaSBillingService::class, 'resolveMpesaConfig');
            $method->setAccessible(true);
            $config = $method->invoke(new SaaSBillingService());
        } finally {
            foreach ($envKeys as $key => $_) {
                if ($previous[$key] === false) {
                    putenv($key);
                    unset($_ENV[$key]);
                } else {
                    putenv($key . '=' . $previous[$key]);
                    $_ENV[$key] = $previous[$key];
                }
            }
        }

        $this->assertSame('live', (string) ($config['environment'] ?? ''));
        $this->assertSame('saved-live-consumer-key', (string) ($config['consumer_key'] ?? ''));
        $this->assertSame('saved-live-consumer-secret', (string) ($config['consumer_secret'] ?? ''));
        $this->assertSame('731996', (string) ($config['business_short_code'] ?? ''));
        $this->assertSame('saved-live-passkey', (string) ($config['passkey'] ?? ''));
    }

    public function testCatalogPaymentReadinessHonorsSwitchesWhenPaystackCredentialsRemainSaved(): void
    {
        (new WorkspaceBillingSettings())->save([
            'default_currency' => 'KES',
            'paystack_secret_key' => 'saved-paystack-secret',
            'mpesa_enabled' => true,
            'mpesa_environment' => 'live',
            'mpesa_consumer_key' => 'saved-consumer-key',
            'mpesa_consumer_secret' => 'saved-consumer-secret',
            'mpesa_shortcode' => '731996',
            'mpesa_passkey' => 'saved-passkey',
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => false,
        ]);

        $readiness = (new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway()))
            ->catalogPaymentReadiness('KES');
        $modesByKey = [];
        foreach ((array) ($readiness['modes'] ?? []) as $mode) {
            $modesByKey[(string) ($mode['key'] ?? '')] = $mode;
        }

        $this->assertTrue((bool) ($readiness['paystack_ready'] ?? false));
        $this->assertTrue((bool) ($readiness['mpesa_ready'] ?? false));
        $this->assertFalse((bool) ($readiness['global_availability']['card'] ?? true));
        $this->assertTrue((bool) ($readiness['global_availability']['mpesa'] ?? false));
        $this->assertFalse((bool) ($readiness['global_availability']['bank_transfer'] ?? true));
        $this->assertFalse((bool) ($modesByKey['card']['available'] ?? true));
        $this->assertTrue((bool) ($modesByKey['mpesa']['available'] ?? false));
        $this->assertFalse((bool) ($modesByKey['bank_transfer']['available'] ?? true));
    }

    public function testDonationPaymentOptionsUseMpesaOnlyWhenPaystackIsPaused(): void
    {
        (new WorkspaceBillingSettings())->save([
            'default_currency' => 'KES',
            'paystack_secret_key' => 'saved-paystack-secret',
            'mpesa_enabled' => true,
            'mpesa_environment' => 'live',
            'mpesa_consumer_key' => 'saved-consumer-key',
            'mpesa_consumer_secret' => 'saved-consumer-secret',
            'mpesa_shortcode' => '731996',
            'mpesa_passkey' => 'saved-passkey',
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => false,
        ]);

        $options = (new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway()))
            ->donationPaymentOptions(['KES', 'USD', 'NGN'], true);
        $currencies = array_map(
            static fn(array $currency): string => (string) ($currency['value'] ?? ''),
            (array) ($options['currencies'] ?? [])
        );
        $kesModes = array_column((array) (($options['modes_by_currency']['KES'] ?? [])), 'key');

        $this->assertSame('KES', (string) ($options['default_currency'] ?? ''));
        $this->assertSame(['KES'], $currencies);
        $this->assertSame(['mpesa'], $kesModes);
        $this->assertArrayNotHasKey('NGN', (array) ($options['modes_by_currency'] ?? []));
        $this->assertFalse((bool) ($options['readiness']['payment_modes']['card'] ?? true));
        $this->assertTrue((bool) ($options['readiness']['payment_modes']['mpesa'] ?? false));
        $this->assertFalse((bool) ($options['readiness']['payment_modes']['bank_transfer'] ?? true));
    }

    public function testSubscriptionPricesExposeLaunchPackageFeatureMetadata(): void
    {
        $prices = (new SaaSBillingService())->listSubscriptionPrices();
        $soloMonthly = null;
        foreach ($prices as $price) {
            if ((string) ($price['price_code'] ?? '') === 'solo-launch-monthly') {
                $soloMonthly = $price;
                break;
            }
        }

        $this->assertNotNull($soloMonthly);
        $this->assertSame('compass-free', (string) ($soloMonthly['inherits_from'] ?? ''));
        $this->assertSame('Everything in Compass Free, plus...', (string) ($soloMonthly['tier_intro'] ?? ''));
        $this->assertContains('Founder operating rhythm', (array) ($soloMonthly['feature_highlights'] ?? []));
        $this->assertNotEmpty($soloMonthly['feature_groups'] ?? []);
        $this->assertSame('solo-launch', (string) (($soloMonthly['launch_package_details']['code'] ?? '')));
    }

    public function testBillingPortalGroupsSubscriptionPricesIntoPackageCards(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Grouped Package Portal',
            'workspace_slug' => 'grouped-package-portal',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace.grouped.portal@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);

        $portal = (new SaaSBillingService(new FakePaystackGateway()))->getWorkspaceBillingPortalData($workspaceId);
        $cardsByCode = [];
        foreach ((array) ($portal['package_cards'] ?? []) as $card) {
            $cardsByCode[(string) ($card['code'] ?? '')] = $card;
        }

        $this->assertArrayHasKey('compass-free', $cardsByCode);
        $this->assertArrayHasKey('solo-launch', $cardsByCode);
        $this->assertArrayHasKey('founder-plus', $cardsByCode);
        $this->assertArrayHasKey('growth-studio', $cardsByCode);
        $this->assertArrayHasKey('scale-custom', $cardsByCode);
        $this->assertArrayHasKey('seat_usage', $portal);
        $this->assertGreaterThanOrEqual(1, (int) ($portal['seat_usage']['total_usage'] ?? 0));
        $this->assertTrue((bool) ($cardsByCode['compass-free']['is_current_package'] ?? false));
        $this->assertSame('Current package', (string) ($cardsByCode['compass-free']['status_label'] ?? ''));
        $this->assertStringContainsString('one-time onboarding AI Credits', (string) ($cardsByCode['compass-free']['credit_summary'] ?? ''));
        $this->assertContains('No AI Credit top-ups', (array) ($cardsByCode['compass-free']['capability_summary'] ?? []));
        $this->assertContains('Business Intelligence included', (array) ($cardsByCode['founder-plus']['capability_summary'] ?? []));
        $this->assertContains('Personal API key included', (array) ($cardsByCode['growth-studio']['capability_summary'] ?? []));
        $this->assertContains('Credits expire after 180 days', (array) ($cardsByCode['solo-launch']['capability_summary'] ?? []));
        $this->assertCount(2, (array) ($cardsByCode['solo-launch']['checkout_options'] ?? []));
        $this->assertSame('KES 2,500/mo or KES 25,000/yr', (string) ($cardsByCode['solo-launch']['price_short'] ?? ''));
        $this->assertFalse((bool) ($cardsByCode['compass-free']['checkout_available'] ?? true));
    }

    public function testDefaultWorkspaceBillingPortalSuppressesPackageCheckoutPrompts(): void
    {
        $portal = (new SaaSBillingService(new FakePaystackGateway()))->getWorkspaceBillingPortalData(1);

        $this->assertTrue((bool) ($portal['snapshot']['package_exempt'] ?? false));
        $this->assertTrue((bool) ($portal['entitlements']['package_exempt'] ?? false));
        $this->assertTrue((bool) ($portal['can_top_up'] ?? false));
        foreach ((array) ($portal['package_cards'] ?? []) as $card) {
            $this->assertSame('Package exempt', (string) ($card['status_label'] ?? ''), json_encode($card, JSON_PRETTY_PRINT));
            $this->assertFalse((bool) ($card['checkout_available'] ?? true), json_encode($card, JSON_PRETTY_PRINT));
            $this->assertSame([], (array) ($card['checkout_options'] ?? ['unexpected']), json_encode($card, JSON_PRETTY_PRINT));
        }
    }

    public function testDefaultWorkspacePackageCheckoutIsRejectedAtServiceBoundary(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Default Package Reject Contact',
            'workspace_slug' => 'default-package-reject-contact',
            'first_name' => 'Package',
            'last_name' => 'Reject',
            'email' => 'default.package.reject@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $priceId = $this->paidLaunchPriceId();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Default workspace is package exempt; package checkout is disabled.');

        (new SaaSBillingService(new FakePaystackGateway()))->createCheckout(1, [
            'billing_plan_price_id' => $priceId,
        ], (int) ($provisioned['user_id'] ?? 0));
    }

    public function testDefaultWorkspaceTokenPackCheckoutStillAllowedAtServiceBoundary(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Default Token Pack Contact',
            'workspace_slug' => 'default-token-pack-contact',
            'first_name' => 'Token',
            'last_name' => 'Allowed',
            'email' => 'default.token.allowed@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $service = new SaaSBillingService(new FakePaystackGateway());

        $checkout = $service->createCheckout(1, [
            'token_pack_price_id' => $this->tokenPackPriceId(),
        ], (int) ($provisioned['user_id'] ?? 0));
        $session = Database::queryOne(
            "SELECT workspace_id, checkout_type, callback_url
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );

        $this->assertSame('token_pack', (string) ($checkout['checkout_type'] ?? ''));
        $this->assertSame(1, (int) ($session['workspace_id'] ?? 0));
        $this->assertSame('token_pack', (string) ($session['checkout_type'] ?? ''));
        $this->assertStringContainsString('billing_callback.php', (string) ($session['callback_url'] ?? ''));
    }

    public function testWorkspaceSnapshotPrefersActiveSubscriptionOverNewerCancelledRows(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Active Subscription Priority',
            'workspace_slug' => 'active-subscription-priority',
            'first_name' => 'Active',
            'last_name' => 'Priority',
            'email' => 'active.subscription.priority@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $priceId = $this->freeLaunchPriceId();

        Database::execute(
            "INSERT INTO workspace_subscriptions
             (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_status,
              renewal_status, subscription_status, current_period_start, current_period_end, next_billing_at, created_by)
             VALUES (?, ?, 'test', 'newer-cancelled-subscription', 'cancelled', 'disabled', 'cancelled', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), ?)",
            [$workspaceId, $priceId, (int) ($provisioned['user_id'] ?? 0)]
        );

        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId);

        $this->assertSame('active', (string) ($snapshot['subscription_status'] ?? ''));
        $this->assertNotSame('newer-cancelled-subscription', (string) (($snapshot['subscription']['provider_reference'] ?? '')));
    }

    public function testCustomerCanSchedulePaidDowngradeForRenewal(): void
    {
        $provisioned = $this->provisionBillingWorkspace('Schedule Paid Downgrade');
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $currentPriceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_test_growth_monthly', 11900, 6000000);
        $targetPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_test_solo_monthly', 2500, 1000000);
        $this->setActivePaystackSubscription($workspaceId, $userId, $currentPriceId);
        $gateway = new FakePaystackGateway();

        $result = (new SaaSBillingService($gateway))->scheduleWorkspacePackageDowngrade($workspaceId, $targetPriceId, $userId);
        $subscription = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]) ?? [];
        $metadata = json_decode((string) ($subscription['scheduled_change_metadata_json'] ?? '{}'), true) ?: [];

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame($targetPriceId, (int) ($subscription['scheduled_billing_plan_price_id'] ?? 0));
        $this->assertSame('downgrade', (string) ($subscription['scheduled_change_type'] ?? ''));
        $this->assertSame('non_renewing', (string) ($subscription['renewal_status'] ?? ''));
        $this->assertSame('SUB_current_' . $workspaceId, (string) ($metadata['current_provider_subscription_code'] ?? ''));
        $this->assertStringStartsWith('SUB_', (string) ($metadata['target_provider_subscription_code'] ?? ''));
        $this->assertCount(1, $gateway->subscriptions);
        $this->assertCount(1, $gateway->disabledSubscriptions);
        $this->assertSame('SUB_current_' . $workspaceId, $gateway->disabledSubscriptions[0]['code']);
    }

    public function testCustomerDowngradeReportsSeatOverageBeforeScheduling(): void
    {
        $provisioned = $this->provisionBillingWorkspace('Seat Overage Downgrade');
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $currentPriceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_overage_growth_monthly', 11900, 6000000);
        $targetPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_overage_solo_monthly', 2500, 1000000);
        $this->setActivePaystackSubscription($workspaceId, $userId, $currentPriceId);
        Database::execute(
            "INSERT INTO workspace_invites (workspace_id, email, role_slug, token_hash, invite_status, invited_by, expires_at)
             VALUES (?, ?, 'viewer', ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL 7 DAY))",
            [$workspaceId, 'overage.invite@example.com', hash('sha256', 'overage-token'), $userId]
        );

        $preview = (new SaaSBillingService(new FakePaystackGateway()))->previewPackageChange($workspaceId, $targetPriceId);
        $this->assertSame('downgrade', (string) ($preview['direction'] ?? ''));
        $this->assertSame(1, (int) ($preview['seat_overage']['overage'] ?? 0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Seat cleanup is required before scheduling this downgrade.');
        (new SaaSBillingService(new FakePaystackGateway()))->scheduleWorkspacePackageDowngrade($workspaceId, $targetPriceId, $userId);
    }

    public function testCustomerCanCancelScheduledDowngrade(): void
    {
        $provisioned = $this->provisionBillingWorkspace('Cancel Scheduled Downgrade');
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $currentPriceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_cancel_growth_monthly', 11900, 6000000);
        $targetPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_cancel_solo_monthly', 2500, 1000000);
        $this->setActivePaystackSubscription($workspaceId, $userId, $currentPriceId);
        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);

        $service->scheduleWorkspacePackageDowngrade($workspaceId, $targetPriceId, $userId);
        $result = $service->cancelScheduledPackageChange($workspaceId, $userId);
        $subscription = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]) ?? [];

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertNull($subscription['scheduled_billing_plan_price_id'] ?? null);
        $this->assertNull($subscription['scheduled_change_type'] ?? null);
        $this->assertSame('renewing', (string) ($subscription['renewal_status'] ?? ''));
        $this->assertCount(2, $gateway->disabledSubscriptions);
        $this->assertCount(1, $gateway->enabledSubscriptions);
        $this->assertSame('SUB_current_' . $workspaceId, $gateway->enabledSubscriptions[0]['code']);
    }

    public function testSuccessfulUpgradeClearsScheduledDowngradeAndDisablesSupersededSubscriptions(): void
    {
        $provisioned = $this->provisionBillingWorkspace('Upgrade Clears Scheduled Downgrade');
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $currentPriceId = $this->paidLaunchPriceId('founder-plus-monthly', 'PLN_upgrade_founder_monthly', 6500, 3500000);
        $scheduledPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_upgrade_solo_monthly', 2500, 1000000);
        $upgradePriceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_upgrade_growth_monthly', 11900, 6000000);
        $this->setActivePaystackSubscription($workspaceId, $userId, $currentPriceId);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET scheduled_billing_plan_price_id = ?,
                 scheduled_change_type = 'downgrade',
                 scheduled_change_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 scheduled_change_metadata_json = ?
             WHERE workspace_id = ?",
            [
                $scheduledPriceId,
                json_encode([
                    'target_provider_subscription_code' => 'SUB_scheduled_' . $workspaceId,
                    'target_provider_email_token' => 'tok_scheduled_' . $workspaceId,
                    'current_provider_subscription_code' => 'SUB_current_' . $workspaceId,
                    'current_provider_email_token' => 'tok_current_' . $workspaceId,
                ], JSON_UNESCAPED_SLASHES),
                $workspaceId,
            ]
        );
        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $upgradePriceId], $userId);
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
                'subscription' => [
                    'subscription_code' => 'SUB_upgrade_' . $workspaceId,
                    'email_token' => 'tok_upgrade_' . $workspaceId,
                    'next_payment_date' => date('c', strtotime('+1 month')),
                    'status' => 'active',
                ],
                'customer' => ['customer_code' => 'CUS_current_' . $workspaceId],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $service->processWebhook($payload, hash_hmac('sha512', $payload, 'test-secret'));
        $subscription = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]) ?? [];

        $this->assertSame($upgradePriceId, (int) ($subscription['billing_plan_price_id'] ?? 0));
        $this->assertNull($subscription['scheduled_billing_plan_price_id'] ?? null);
        $this->assertCount(2, $gateway->disabledSubscriptions);
        $this->assertSame('SUB_current_' . $workspaceId, $gateway->disabledSubscriptions[0]['code']);
        $this->assertSame('SUB_scheduled_' . $workspaceId, $gateway->disabledSubscriptions[1]['code']);
    }

    public function testScheduledPaidDowngradeAppliesWhenTargetProviderSubscriptionBills(): void
    {
        $provisioned = $this->provisionBillingWorkspace('Scheduled Paid Downgrade Applies');
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $currentPriceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_apply_growth_monthly', 11900, 6000000);
        $targetPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_apply_solo_monthly', 2500, 1000000);
        $this->setActivePaystackSubscription($workspaceId, $userId, $currentPriceId);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET scheduled_billing_plan_price_id = ?,
                 scheduled_change_type = 'downgrade',
                 scheduled_change_at = NOW(),
                 scheduled_change_metadata_json = ?
             WHERE workspace_id = ?",
            [
                $targetPriceId,
                json_encode([
                    'target_provider_subscription_code' => 'SUB_target_' . $workspaceId,
                    'target_provider_email_token' => 'tok_target_' . $workspaceId,
                ], JSON_UNESCAPED_SLASHES),
                $workspaceId,
            ]
        );
        $payload = json_encode([
            'event' => 'invoice.update',
            'data' => [
                'paid' => true,
                'status' => 'success',
                'reference' => 'INV_target_' . $workspaceId,
                'subscription' => [
                    'subscription_code' => 'SUB_target_' . $workspaceId,
                    'email_token' => 'tok_target_' . $workspaceId,
                    'next_payment_date' => date('c', strtotime('+1 month')),
                    'status' => 'active',
                ],
                'customer' => ['customer_code' => 'CUS_current_' . $workspaceId],
                'amount' => 250000,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $result = (new SaaSBillingService(new FakePaystackGateway()))->processWebhook($payload, hash_hmac('sha512', $payload, 'test-secret'));
        $subscription = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]) ?? [];

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame($targetPriceId, (int) ($subscription['billing_plan_price_id'] ?? 0));
        $this->assertNull($subscription['scheduled_billing_plan_price_id'] ?? null);
        $this->assertSame('SUB_target_' . $workspaceId, (string) ($subscription['provider_subscription_code'] ?? ''));
    }

    public function testScheduledFreeDowngradeAppliesAtPeriodEnd(): void
    {
        $provisioned = $this->provisionBillingWorkspace('Scheduled Free Downgrade Applies');
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $currentPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_free_current_monthly', 2500, 1000000);
        $targetPriceId = $this->freeLaunchPriceId();
        $this->setActivePaystackSubscription($workspaceId, $userId, $currentPriceId);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET current_period_end = DATE_SUB(NOW(), INTERVAL 1 DAY),
                 scheduled_billing_plan_price_id = ?,
                 scheduled_change_type = 'downgrade',
                 scheduled_change_at = DATE_SUB(NOW(), INTERVAL 1 DAY),
                 scheduled_change_metadata_json = ?
             WHERE workspace_id = ?",
            [
                $targetPriceId,
                json_encode(['actor_user_id' => $userId], JSON_UNESCAPED_SLASHES),
                $workspaceId,
            ]
        );

        (new SaaSBillingService(new FakePaystackGateway()))->syncExpiredSubscriptionPeriods($workspaceId);
        $subscription = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]) ?? [];
        $workspace = Database::queryOne("SELECT plan_status FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]) ?? [];

        $this->assertSame($targetPriceId, (int) ($subscription['billing_plan_price_id'] ?? 0));
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame('free', (string) ($subscription['renewal_status'] ?? ''));
        $this->assertNull($subscription['scheduled_billing_plan_price_id'] ?? null);
        $this->assertSame('active', (string) ($workspace['plan_status'] ?? ''));
    }

    public function testExpiredActivePackageBecomesExpiredAndPastDue(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Expired Package Workspace',
            'workspace_slug' => 'expired-package-workspace',
            'first_name' => 'Expired',
            'last_name' => 'Package',
            'email' => 'expired.package@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $priceId = $this->paidLaunchPriceId();
        $service = new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway());
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $priceId], $userId);
        $service->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        Database::execute(
            "UPDATE workspace_subscriptions
             SET current_period_end = DATE_SUB(NOW(), INTERVAL 1 DAY),
                 next_billing_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
             WHERE workspace_id = ?",
            [$workspaceId]
        );

        $snapshot = $service->getWorkspaceSnapshot($workspaceId);
        $workspace = Database::queryOne("SELECT plan_status FROM workspaces WHERE id = ?", [$workspaceId]);
        $subscription = Database::queryOne(
            "SELECT subscription_status
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame('expired', (string) ($snapshot['subscription_status'] ?? ''));
        $this->assertSame('expired', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame('past_due', (string) ($workspace['plan_status'] ?? ''));
        $this->assertTrue((bool) ($snapshot['billing_blocked'] ?? false));
    }

    public function testFutureActivePackageRemainsActive(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Future Package Workspace',
            'workspace_slug' => 'future-package-workspace',
            'first_name' => 'Future',
            'last_name' => 'Package',
            'email' => 'future.package@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $priceId = $this->paidLaunchPriceId();
        $service = new SaaSBillingService(new FakePaystackGateway());
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $priceId], $userId);
        $service->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        Database::execute(
            "UPDATE workspace_subscriptions
             SET current_period_end = DATE_ADD(NOW(), INTERVAL 7 DAY),
                 next_billing_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
             WHERE workspace_id = ?",
            [$workspaceId]
        );

        $sync = $service->syncExpiredSubscriptionPeriods($workspaceId);
        $subscription = Database::queryOne(
            "SELECT subscription_status
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame(0, (int) ($sync['expired_subscriptions'] ?? -1));
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
    }

    public function testLegacyTrialingPackageIsTreatedAsActiveCompatibilityState(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Legacy Trialing Workspace',
            'workspace_slug' => 'legacy-trialing-workspace',
            'first_name' => 'Legacy',
            'last_name' => 'Package',
            'email' => 'legacy.trialing@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);

        Database::execute(
            "UPDATE workspace_subscriptions
             SET subscription_status = 'trialing',
                 trial_ends_at = DATE_SUB(NOW(), INTERVAL 1 DAY),
                 current_period_end = DATE_SUB(NOW(), INTERVAL 1 DAY),
                 next_billing_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
             WHERE workspace_id = ?",
            [$workspaceId]
        );

        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId);
        $workspace = Database::queryOne("SELECT plan_status FROM workspaces WHERE id = ?", [$workspaceId]);

        $this->assertSame('active', (string) ($snapshot['subscription_status'] ?? ''));
        $this->assertSame('active', (string) ($snapshot['subscription']['subscription_status'] ?? ''));
        $this->assertSame('active', (string) ($workspace['plan_status'] ?? ''));
        $this->assertFalse((bool) ($snapshot['is_trial_active'] ?? true));
        $this->assertFalse((bool) ($snapshot['billing_blocked'] ?? true));
        $this->assertNull($snapshot['trial_ends_at'] ?? null);
    }

    public function testCreateCheckoutInitializesWorkspaceAwareSubscriptionSession(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Billing Workspace',
            'workspace_slug' => 'billing-workspace',
            'first_name' => 'Mary',
            'last_name' => 'Poppins',
            'email' => 'mary.billing@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $priceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_test_solo_monthly', 4500, 250);

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $priceId], $userId);

        $session = Database::queryOne(
            "SELECT *
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) $checkout['checkout_session_id']]
        );

        $this->assertNotNull($session);
        $this->assertSame('subscription', (string) ($session['checkout_type'] ?? ''));
        $this->assertSame($workspaceId, (int) ($session['workspace_id'] ?? 0));
        $this->assertSame('pending', (string) ($session['status'] ?? ''));
        $this->assertSame('https://paystack.example/authorize/' . $checkout['reference'], (string) ($checkout['authorization_url'] ?? ''));
        $this->assertSame($checkout['reference'], (string) ($gateway->initialized[0]['reference'] ?? ''));
        $this->assertSame('PLN_test_solo_monthly', (string) ($gateway->initialized[0]['plan'] ?? ''));
        $this->assertSame(['card'], (array) ($gateway->initialized[0]['channels'] ?? []));
    }

    public function testCreateCheckoutRejectsPaidRecurringPackageWithoutProviderPlanCode(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Missing Plan Code Workspace',
            'workspace_slug' => 'missing-plan-code-workspace',
            'first_name' => 'Missing',
            'last_name' => 'Plan',
            'email' => 'missing.plan@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $priceId = $this->paidLaunchPriceId('solo-launch-monthly', '', 1990, 0);
        Database::execute("UPDATE billing_plan_prices SET provider_plan_code = NULL WHERE id = ?", [$priceId]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Run `php cli/sync_paystack_launch_plans.php` before accepting this package.');

        (new SaaSBillingService(new FakePaystackGateway()))->createCheckout(
            (int) ($provisioned['workspace_id'] ?? 0),
            ['billing_plan_price_id' => $priceId],
            (int) ($provisioned['user_id'] ?? 0)
        );
    }

    public function testCreateCheckoutAllowsMpesaManualPeriodForPaidPackage(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Recurring Mpesa Workspace',
            'workspace_slug' => 'recurring-mpesa-workspace',
            'first_name' => 'Recurring',
            'last_name' => 'Mpesa',
            'email' => 'recurring.mpesa@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $priceId = $this->paidLaunchPriceId();
        (new WorkspaceBillingSettings())->save([
            'paystack_secret_key' => 'saved-paystack-secret',
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => false,
        ], $userId);

        $gateway = new FakePaystackGateway();
        $mpesa = new FakeMpesaDarajaGateway();
        $service = new SaaSBillingService($gateway, null, null, null, $mpesa);
        $checkout = $service->createCheckout(
            $workspaceId,
            [
                'billing_plan_price_id' => $priceId,
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            $userId
        );
        $session = Database::queryOne(
            "SELECT provider, payment_mode, flow_type, provider_plan_code
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );

        $this->assertSame([], $gateway->initialized);
        $this->assertCount(1, $mpesa->stkPushes);
        $this->assertSame('mpesa', (string) ($checkout['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($checkout['flow_type'] ?? ''));
        $this->assertSame('mpesa', (string) ($session['provider'] ?? ''));
        $this->assertSame('mpesa', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($session['flow_type'] ?? ''));
        $this->assertNull($session['provider_plan_code'] ?? null);

        $callback = json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant_package_success',
                    'CheckoutRequestID' => (string) ($checkout['reference'] ?? ''),
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1990],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MPESA-PKG-001'],
                            ['Name' => 'TransactionDate', 'Value' => 20260705140102],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $result = $service->processMpesaCallback($callback);
        $transaction = Database::queryOne(
            "SELECT id, provider, transaction_status
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'subscription_charge'
             ORDER BY id DESC
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        ) ?? [];
        $invoice = Database::queryOne(
            "SELECT document_key, billing_plan_price_id, provider, amount
             FROM billing_invoices
             WHERE billing_transaction_id = ?
             LIMIT 1",
            [(int) ($transaction['id'] ?? 0)]
        ) ?? [];

        $this->assertTrue((bool) ($result['accepted'] ?? false));
        $this->assertSame('mpesa', (string) ($transaction['provider'] ?? ''));
        $this->assertSame('succeeded', (string) ($transaction['transaction_status'] ?? ''));
        $this->assertSame('transaction:' . (int) ($transaction['id'] ?? 0), (string) ($invoice['document_key'] ?? ''));
        $this->assertSame($priceId, (int) ($invoice['billing_plan_price_id'] ?? 0));
        $this->assertSame('mpesa', (string) ($invoice['provider'] ?? ''));
    }

    public function testCreateCheckoutRejectsDisabledCardPaymentMode(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Disabled Card Workspace',
            'workspace_slug' => 'disabled-card-workspace',
            'first_name' => 'Disabled',
            'last_name' => 'Card',
            'email' => 'disabled.card@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], $userId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Card payments are temporarily unavailable.');

        (new SaaSBillingService(new FakePaystackGateway()))->createCheckout($workspaceId, [
            'token_pack_price_id' => $this->tokenPackPriceId(),
            'payment_mode' => 'card',
        ], $userId);
    }

    public function testBillingPortalKeepsPaidPackageCheckoutAvailableWhenManualModeIsAvailable(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Disabled Package Card Workspace',
            'workspace_slug' => 'disabled-package-card-workspace',
            'first_name' => 'Disabled',
            'last_name' => 'Package',
            'email' => 'disabled.package.card@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], (int) ($provisioned['user_id'] ?? 0));
        $this->paidLaunchPriceId();

        $portal = (new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway()))
            ->getWorkspaceBillingPortalData($workspaceId);
        $soloPackage = null;
        foreach ((array) ($portal['package_cards'] ?? []) as $card) {
            if ((string) ($card['code'] ?? $card['plan_code'] ?? '') === 'solo-launch') {
                $soloPackage = $card;
                break;
            }
        }
        $modesByKey = [];
        foreach ((array) ($portal['payment_modes'] ?? []) as $mode) {
            $modesByKey[(string) ($mode['key'] ?? '')] = $mode;
        }
        $packageModesByKey = [];
        foreach ((array) (($soloPackage['checkout_options'][0]['payment_modes'] ?? []) ?: []) as $mode) {
            $packageModesByKey[(string) ($mode['key'] ?? '')] = $mode;
        }

        $this->assertNotNull($soloPackage);
        $this->assertTrue((bool) ($soloPackage['checkout_available'] ?? false));
        $this->assertFalse((bool) ($modesByKey['card']['available'] ?? true));
        $this->assertTrue((bool) ($modesByKey['mpesa']['available'] ?? false));
        $this->assertFalse((bool) ($packageModesByKey['card']['available'] ?? true));
        $this->assertTrue((bool) ($packageModesByKey['mpesa']['available'] ?? false));
    }

    public function testBillingPortalUsesActualMpesaReadinessForPackagePaymentModes(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Unavailable Mpesa Package Workspace',
            'workspace_slug' => 'unavailable-mpesa-package-workspace',
            'first_name' => 'Unavailable',
            'last_name' => 'Mpesa',
            'email' => 'unavailable.mpesa.package@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], (int) ($provisioned['user_id'] ?? 0));
        $this->paidLaunchPriceId();

        $portal = (new SaaSBillingService(new FakePaystackGateway(), null, null, null, new UnconfiguredFakeMpesaDarajaGateway()))
            ->getWorkspaceBillingPortalData($workspaceId);
        $soloPackage = null;
        foreach ((array) ($portal['package_cards'] ?? []) as $card) {
            if ((string) ($card['code'] ?? $card['plan_code'] ?? '') === 'solo-launch') {
                $soloPackage = $card;
                break;
            }
        }
        $modesByKey = [];
        foreach ((array) ($portal['payment_modes'] ?? []) as $mode) {
            $modesByKey[(string) ($mode['key'] ?? '')] = $mode;
        }
        $packageModesByKey = [];
        foreach ((array) (($soloPackage['checkout_options'][0]['payment_modes'] ?? []) ?: []) as $mode) {
            $packageModesByKey[(string) ($mode['key'] ?? '')] = $mode;
        }

        $this->assertNotNull($soloPackage);
        $this->assertTrue((bool) ($soloPackage['checkout_available'] ?? false));
        $this->assertTrue((bool) ($modesByKey['card']['available'] ?? false));
        $this->assertFalse((bool) ($modesByKey['mpesa']['available'] ?? true));
        $this->assertTrue((bool) ($packageModesByKey['card']['available'] ?? false));
        $this->assertFalse((bool) ($packageModesByKey['mpesa']['available'] ?? true));
        $this->assertStringContainsString('M-Pesa billing is not ready', (string) ($packageModesByKey['mpesa']['help'] ?? $packageModesByKey['mpesa']['reason'] ?? ''));
    }

    public function testCompassFreeActivatesWithoutPaystackCheckout(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Compass Free Workspace',
            'workspace_slug' => 'compass-free-workspace',
            'first_name' => 'Compass',
            'last_name' => 'Free',
            'email' => 'compass.free@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        Database::execute("DELETE FROM workspace_subscriptions WHERE workspace_id = ?", [$workspaceId]);
        Database::execute("UPDATE workspaces SET plan_status = 'inactive' WHERE id = ?", [$workspaceId]);
        $priceId = $this->freeLaunchPriceId();

        $gateway = new FakePaystackGateway();
        $checkout = (new SaaSBillingService($gateway))->createCheckout($workspaceId, [
            'billing_plan_price_id' => $priceId,
        ], $userId);

        $subscription = Database::queryOne(
            "SELECT provider, subscription_status, renewal_status, billing_plan_price_id
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $session = Database::queryOne(
            "SELECT provider, status, amount
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );

        $this->assertSame([], $gateway->initialized);
        $this->assertSame('free', (string) ($checkout['flow_type'] ?? ''));
        $this->assertSame('internal', (string) ($subscription['provider'] ?? ''));
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame('free', (string) ($subscription['renewal_status'] ?? ''));
        $this->assertSame($priceId, (int) ($subscription['billing_plan_price_id'] ?? 0));
        $this->assertSame('internal', (string) ($session['provider'] ?? ''));
        $this->assertSame('paid', (string) ($session['status'] ?? ''));
        $this->assertSame(0.0, (float) ($session['amount'] ?? -1));
    }

    public function testCreateCheckoutPersistsMpesaModeAndOfflineChargeInstructions(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Mpesa Workspace',
            'workspace_slug' => 'mpesa-workspace',
            'first_name' => 'Mpesa',
            'last_name' => 'Owner',
            'email' => 'mpesa.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $tokenPackId = $this->tokenPackPriceId();

        $gateway = new FakePaystackGateway();
        $mpesa = new FakeMpesaDarajaGateway();
        $service = new SaaSBillingService($gateway, null, null, null, $mpesa);
        $checkout = $service->createCheckout($workspaceId, [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'mpesa',
            'customer_phone' => '0712345678',
        ], $userId);

        $session = Database::queryOne(
            "SELECT provider, provider_reference, payment_mode, flow_type, customer_phone, authorization_url, display_text, instructions_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) $checkout['checkout_session_id']]
        );

        $this->assertSame([], $gateway->charges);
        $this->assertCount(1, $mpesa->stkPushes);
        $this->assertSame('mpesa', (string) ($session['provider'] ?? ''));
        $this->assertSame((string) ($checkout['reference'] ?? ''), (string) ($session['provider_reference'] ?? ''));
        $this->assertSame('mpesa', (string) ($checkout['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($checkout['flow_type'] ?? ''));
        $this->assertSame('', (string) ($checkout['authorization_url'] ?? ''));
        $this->assertSame('M-Pesa prompt sent. Approve it on your phone to complete payment.', (string) ($checkout['display_text'] ?? ''));
        $this->assertSame([], (array) ($checkout['instructions'] ?? []));
        $this->assertSame('mpesa', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($session['flow_type'] ?? ''));
        $this->assertSame('254712345678', (string) ($session['customer_phone'] ?? ''));
        $this->assertSame('', (string) ($session['authorization_url'] ?? ''));
        $this->assertStringContainsString('checkout_request_id', (string) ($session['instructions_json'] ?? ''));
        $this->assertSame('254712345678', (string) ($mpesa->stkPushes[0]['phone'] ?? ''));
    }

    public function testCreateCheckoutRequiresPhoneForMpesaMode(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Mpesa Validation Workspace',
            'workspace_slug' => 'mpesa-validation-workspace',
            'first_name' => 'Mpesa',
            'last_name' => 'Validation',
            'email' => 'mpesa.validation@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $service = new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway());
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible((int) ($provisioned['workspace_id'] ?? 0), (int) ($provisioned['user_id'] ?? 0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A customer phone number is required for the selected payment mode.');

        $service->createCheckout((int) ($provisioned['workspace_id'] ?? 0), [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'mpesa',
        ], (int) ($provisioned['user_id'] ?? 0));
    }

    public function testCreateCheckoutRejectsBankTransferForKesWorkspace(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Bank Kes Workspace',
            'workspace_slug' => 'bank-kes-workspace',
            'first_name' => 'Bank',
            'last_name' => 'Kes',
            'email' => 'bank.kes@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $service = new SaaSBillingService(new FakePaystackGateway());
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible((int) ($provisioned['workspace_id'] ?? 0), (int) ($provisioned['user_id'] ?? 0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bank transfer is only enabled for NGN-supported workspaces in this slice.');

        $service->createCheckout((int) ($provisioned['workspace_id'] ?? 0), [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'bank_transfer',
        ], (int) ($provisioned['user_id'] ?? 0));
    }

    public function testCreateCheckoutAllowsBankTransferForNgnWorkspace(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Bank Ngn Workspace',
            'workspace_slug' => 'bank-ngn-workspace',
            'first_name' => 'Bank',
            'last_name' => 'Ngn',
            'email' => 'bank.ngn@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $this->updateTokenPackBillingCurrency($tokenPackId, 'NGN');

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $checkout = $service->createCheckout($workspaceId, [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'bank_transfer',
        ], $userId);

        $session = Database::queryOne(
            "SELECT payment_mode, flow_type, instructions_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) $checkout['checkout_session_id']]
        );

        $this->assertSame('bank_transfer', (string) ($checkout['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($checkout['flow_type'] ?? ''));
        $this->assertNotEmpty((array) ($checkout['instructions'] ?? []));
        $this->assertSame('bank_transfer', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($session['flow_type'] ?? ''));
        $this->assertStringContainsString('account_number', (string) ($session['instructions_json'] ?? ''));
    }

    public function testProcessWebhookCreditsTokenPackExactlyOnce(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Token Workspace',
            'workspace_slug' => 'token-workspace',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.tokens@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        Database::execute(
            "UPDATE billing_plan_prices
             SET amount = 2500, currency = 'KES'
             WHERE id = 2"
        );

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $payload, 'test-secret');

        $first = $service->processWebhook($payload, $signature);
        $second = $service->processWebhook($payload, $signature);

        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'token_pack_purchase'
               AND transaction_status = 'succeeded'",
            [(int) $checkout['checkout_session_id']]
        )['c'] ?? 0);
        $ledger = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'token_pack_purchase'
               AND reference_id = ?",
            [$workspaceId, $checkout['reference']]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($first['accepted'] ?? false));
        $this->assertTrue((bool) ($first['handled'] ?? false));
        $this->assertTrue((bool) ($second['accepted'] ?? false));
        $this->assertSame($initialBalance + 100000, (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $transactions);
        $this->assertSame(1, $ledger);
        $this->assertSame([(string) ($checkout['reference'] ?? '')], $gateway->verified);
    }

    public function testProcessMpesaCallbackCreditsTokenPackOnceAndStoresReceipt(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Mpesa Callback Workspace',
            'workspace_slug' => 'mpesa-callback-workspace',
            'first_name' => 'Mpesa',
            'last_name' => 'Callback',
            'email' => 'mpesa.callback@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $service = new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway());
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'mpesa',
            'customer_phone' => '0712345678',
        ], $userId);

        $payload = json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant_test_123',
                    'CheckoutRequestID' => (string) ($checkout['reference'] ?? ''),
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 2500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RCP123456'],
                            ['Name' => 'TransactionDate', 'Value' => 20260614123045],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $first = $service->processMpesaCallback($payload);
        $second = $service->processMpesaCallback($payload);

        $session = Database::queryOne(
            "SELECT status, paid_at, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );
        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true) ?: [];
        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND provider = 'mpesa'
               AND transaction_type = 'token_pack_purchase'
               AND transaction_status = 'succeeded'",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        )['c'] ?? 0);
        $transaction = Database::queryOne(
            "SELECT metadata_json
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND provider = 'mpesa'
               AND transaction_type = 'token_pack_purchase'
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );
        $transactionMetadata = json_decode((string) ($transaction['metadata_json'] ?? '{}'), true) ?: [];
        $ledger = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'token_pack_purchase'
               AND reference_id = ?",
            [$workspaceId, (string) ($checkout['reference'] ?? '')]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($first['accepted'] ?? false));
        $this->assertTrue((bool) ($first['handled'] ?? false));
        $this->assertTrue((bool) ($second['accepted'] ?? false));
        $this->assertSame('paid', (string) ($session['status'] ?? ''));
        $this->assertSame('2026-06-14 12:30:45', (string) ($session['paid_at'] ?? ''));
        $this->assertSame('RCP123456', (string) ($metadata['receipt_number'] ?? ''));
        $this->assertSame('RCP123456', (string) ($transactionMetadata['receipt_number'] ?? ''));
        $this->assertSame($initialBalance + 100000, (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $transactions);
        $this->assertSame(1, $ledger);
    }

    public function testProcessMpesaCallbackMarksCheckoutFailed(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Mpesa Failed Callback Workspace',
            'workspace_slug' => 'mpesa-failed-callback-workspace',
            'first_name' => 'Mpesa',
            'last_name' => 'Failed',
            'email' => 'mpesa.failed.callback@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $service = new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway());
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, (int) ($provisioned['user_id'] ?? 0));
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'mpesa',
            'customer_phone' => '254712345678',
        ], (int) ($provisioned['user_id'] ?? 0));

        $payload = json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant_failed_123',
                    'CheckoutRequestID' => (string) ($checkout['reference'] ?? ''),
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user.',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $result = $service->processMpesaCallback($payload);
        $session = Database::queryOne(
            "SELECT status, display_text
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );
        $transaction = Database::queryOne(
            "SELECT provider, transaction_status
             FROM billing_transactions
             WHERE checkout_session_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );
        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);

        $this->assertTrue((bool) ($result['accepted'] ?? false));
        $this->assertTrue((bool) ($result['handled'] ?? false));
        $this->assertSame('failed', (string) ($session['status'] ?? ''));
        $this->assertSame('Request cancelled by user.', (string) ($session['display_text'] ?? ''));
        $this->assertSame('mpesa', (string) ($transaction['provider'] ?? ''));
        $this->assertSame('failed', (string) ($transaction['transaction_status'] ?? ''));
        $this->assertSame($initialBalance, (int) ($wallet['token_balance'] ?? -1));
    }

    public function testWebhookIgnoresNonChargeSuccessLookingEvent(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ignored Webhook Workspace',
            'workspace_slug' => 'ignored-webhook-workspace',
            'first_name' => 'Ignored',
            'last_name' => 'Webhook',
            'email' => 'ignored.webhook@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);

        $payload = json_encode([
            'event' => 'transfer.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha512', $payload, 'test-secret');

        $result = $service->processWebhook($payload, $signature);
        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $session = Database::queryOne(
            "SELECT status
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($result['accepted'] ?? false));
        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertSame('Event logged without billing mutation.', (string) ($result['message'] ?? ''));
        $this->assertSame('pending', (string) ($session['status'] ?? ''));
        $this->assertSame($initialBalance, (int) ($wallet['token_balance'] ?? -1));
        $this->assertSame(0, $transactions);
        $this->assertSame([], $gateway->verified);
    }

    public function testProcessWebhookActivatesSubscriptionAndCreditsIncludedTokensOnce(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Subscription Workspace',
            'workspace_slug' => 'subscription-workspace',
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah.subscription@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $priceId = $this->paidLaunchPriceId('founder-plus-monthly', 'PLN_test_founder_monthly', 6500, 500);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], $userId);

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $priceId], $userId);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
                'subscription' => [
                    'subscription_code' => 'SUB_test_founder_monthly',
                    'email_token' => 'email-token-test',
                    'next_payment_date' => date('c', strtotime('+1 month')),
                    'status' => 'active',
                ],
                'customer' => [
                    'customer_code' => 'CUS_test_founder',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $payload, 'test-secret');

        $service->processWebhook($payload, $signature);
        $service->processWebhook($payload, $signature);

        $subscription = Database::queryOne(
            "SELECT subscription_status, billing_plan_price_id, provider_subscription_code, provider_customer_code, provider_email_token
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $workspace = Database::queryOne("SELECT plan_status FROM workspaces WHERE id = ?", [$workspaceId]);
        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $includedCredits = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'subscription_included_tokens'",
            [$workspaceId]
        )['c'] ?? 0);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'subscription_charge'
               AND transaction_status = 'succeeded'",
            [(int) $checkout['checkout_session_id']]
        )['c'] ?? 0);
        $transaction = Database::queryOne(
            "SELECT id
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'subscription_charge'
               AND transaction_status = 'succeeded'
             LIMIT 1",
            [(int) $checkout['checkout_session_id']]
        ) ?? [];
        $billingInvoice = Database::queryOne(
            "SELECT document_key, billing_plan_price_id, amount, buyer_email
             FROM billing_invoices
             WHERE billing_transaction_id = ?
             LIMIT 1",
            [(int) ($transaction['id'] ?? 0)]
        ) ?? [];
        $ownerContact = (new DefaultWorkspaceOwnerContactService())->statusForOwner($userId) ?? [];
        $ownerMetadata = json_decode((string) ($ownerContact['metadata_json'] ?? '{}'), true) ?: [];

        $this->assertNotNull($subscription);
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame($priceId, (int) ($subscription['billing_plan_price_id'] ?? 0));
        $this->assertSame('SUB_test_founder_monthly', (string) ($subscription['provider_subscription_code'] ?? ''));
        $this->assertSame('CUS_test_founder', (string) ($subscription['provider_customer_code'] ?? ''));
        $this->assertSame('email-token-test', (string) ($subscription['provider_email_token'] ?? ''));
        $this->assertSame('active', (string) ($workspace['plan_status'] ?? ''));
        $this->assertSame($initialBalance + 500, (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $includedCredits);
        $this->assertSame(1, $transactions);
        $this->assertSame('transaction:' . (int) ($transaction['id'] ?? 0), (string) ($billingInvoice['document_key'] ?? ''));
        $this->assertSame($priceId, (int) ($billingInvoice['billing_plan_price_id'] ?? 0));
        $this->assertSame(6500.0, (float) ($billingInvoice['amount'] ?? 0));
        $this->assertSame('sarah.subscription@example.com', (string) ($billingInvoice['buyer_email'] ?? ''));
        $this->assertSame('won', (string) ($ownerContact['stage'] ?? ''));
        $this->assertSame('current_paying_customer', (string) ($ownerMetadata['default_workspace_contact_scope'] ?? ''));
        $this->assertTrue((bool) ($ownerMetadata['current_paying_customer'] ?? false));
    }

    public function testRecurringInvoiceUpdateExtendsSubscriptionBySubscriptionCode(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Recurring Invoice Workspace',
            'workspace_slug' => 'recurring-invoice-workspace',
            'first_name' => 'Recurring',
            'last_name' => 'Invoice',
            'email' => 'recurring.invoice@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $priceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_invoice_monthly', 1990, 125);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 provider = 'paystack',
                 provider_subscription_code = 'SUB_invoice_monthly',
                 provider_customer_code = 'CUS_invoice',
                 subscription_status = 'active',
                 current_period_start = DATE_SUB(NOW(), INTERVAL 30 DAY),
                 current_period_end = DATE_SUB(NOW(), INTERVAL 1 DAY),
                 next_billing_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
             WHERE workspace_id = ?",
            [$priceId, $workspaceId]
        );

        $payload = json_encode([
            'event' => 'invoice.update',
            'data' => [
                'invoice_code' => 'INV_invoice_monthly_001',
                'subscription' => [
                    'subscription_code' => 'SUB_invoice_monthly',
                    'next_payment_date' => date('c', strtotime('+1 month')),
                    'status' => 'active',
                ],
                'customer' => ['customer_code' => 'CUS_invoice'],
                'period_start' => date('c'),
                'period_end' => date('c', strtotime('+1 month')),
                'amount' => 199000,
                'paid' => true,
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha512', $payload, 'test-secret');
        $service = new SaaSBillingService(new FakePaystackGateway(), null, null, null, new FakeMpesaDarajaGateway());

        $first = $service->processWebhook($payload, $signature);
        $second = $service->processWebhook($payload, $signature);

        $subscription = Database::queryOne(
            "SELECT subscription_status, renewal_status, current_period_end
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE workspace_id = ?
               AND provider_reference = 'INV_invoice_monthly_001'
               AND transaction_status = 'succeeded'",
            [$workspaceId]
        )['c'] ?? 0);
        $transaction = Database::queryOne(
            "SELECT id
             FROM billing_transactions
             WHERE workspace_id = ?
               AND provider_reference = 'INV_invoice_monthly_001'
               AND transaction_status = 'succeeded'
             LIMIT 1",
            [$workspaceId]
        ) ?? [];
        $billingInvoice = Database::queryOne(
            "SELECT document_key, billing_plan_price_id, provider, amount
             FROM billing_invoices
             WHERE billing_transaction_id = ?
             LIMIT 1",
            [(int) ($transaction['id'] ?? 0)]
        ) ?? [];
        $credits = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'subscription_included_tokens'",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($first['success'] ?? false));
        $this->assertTrue((bool) ($second['handled'] ?? false));
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame('renewing', (string) ($subscription['renewal_status'] ?? ''));
        $this->assertGreaterThan(time(), strtotime((string) ($subscription['current_period_end'] ?? '')));
        $this->assertSame(1, $transactions);
        $this->assertSame(1, $credits);
        $this->assertSame('transaction:' . (int) ($transaction['id'] ?? 0), (string) ($billingInvoice['document_key'] ?? ''));
        $this->assertSame($priceId, (int) ($billingInvoice['billing_plan_price_id'] ?? 0));
        $this->assertSame('paystack', (string) ($billingInvoice['provider'] ?? ''));
        $this->assertSame(1990.0, (float) ($billingInvoice['amount'] ?? 0));
    }

    public function testRecurringFailureAndDisableEventsUpdateSubscriptionStatus(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Recurring Failure Workspace',
            'workspace_slug' => 'recurring-failure-workspace',
            'first_name' => 'Recurring',
            'last_name' => 'Failure',
            'email' => 'recurring.failure@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $priceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_failure_monthly', 11900, 0);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 provider = 'paystack',
                 provider_subscription_code = 'SUB_failure_monthly',
                 provider_customer_code = 'CUS_failure',
                 subscription_status = 'active',
                 renewal_status = 'renewing'
             WHERE workspace_id = ?",
            [$priceId, $workspaceId]
        );
        $service = new SaaSBillingService(new FakePaystackGateway());

        $failedPayload = json_encode([
            'event' => 'invoice.payment_failed',
            'data' => [
                'invoice_code' => 'INV_failure_monthly_001',
                'subscription' => ['subscription_code' => 'SUB_failure_monthly', 'status' => 'attention'],
                'customer' => ['customer_code' => 'CUS_failure'],
                'amount' => 1190000,
                'gateway_response' => 'Card declined',
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $service->processWebhook($failedPayload, hash_hmac('sha512', $failedPayload, 'test-secret'));

        $pastDue = Database::queryOne(
            "SELECT ws.subscription_status, ws.renewal_status, w.plan_status
             FROM workspace_subscriptions ws
             JOIN workspaces w ON w.id = ws.workspace_id
             WHERE ws.workspace_id = ?
             ORDER BY ws.id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $notRenewPayload = json_encode([
            'event' => 'subscription.not_renew',
            'data' => [
                'subscription_code' => 'SUB_failure_monthly',
                'status' => 'active',
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $service->processWebhook($notRenewPayload, hash_hmac('sha512', $notRenewPayload, 'test-secret'));

        $disablePayload = json_encode([
            'event' => 'subscription.disable',
            'data' => [
                'subscription_code' => 'SUB_failure_monthly',
                'status' => 'cancelled',
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $service->processWebhook($disablePayload, hash_hmac('sha512', $disablePayload, 'test-secret'));

        $disabled = Database::queryOne(
            "SELECT subscription_status, renewal_status
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $invoiceCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_invoices bi
             JOIN billing_transactions bt ON bt.id = bi.billing_transaction_id
             WHERE bt.workspace_id = ?
               AND bt.provider_reference = 'INV_failure_monthly_001'",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertSame('past_due', (string) ($pastDue['subscription_status'] ?? ''));
        $this->assertSame('attention', (string) ($pastDue['renewal_status'] ?? ''));
        $this->assertSame('past_due', (string) ($pastDue['plan_status'] ?? ''));
        $this->assertSame('cancelled', (string) ($disabled['subscription_status'] ?? ''));
        $this->assertSame('disabled', (string) ($disabled['renewal_status'] ?? ''));
        $this->assertSame(0, $invoiceCount);
    }

    public function testVerifyThenWebhookFinalizesTokenPackCheckoutExactlyOnce(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Verify First Workspace',
            'workspace_slug' => 'verify-first-workspace',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.verify@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);

        $verify = $service->verifyCheckoutReference($checkout['reference']);
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $payload, 'test-secret');
        $webhook = $service->processWebhook($payload, $signature);

        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'token_pack_purchase'
               AND transaction_status = 'succeeded'",
            [(int) $checkout['checkout_session_id']]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($verify['success'] ?? false));
        $this->assertTrue((bool) ($webhook['accepted'] ?? false));
        $this->assertSame($initialBalance + 100000, (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $transactions);
        $this->assertSame([$checkout['reference']], $gateway->verified);
    }

    public function testWebhookThenVerifyFinalizesTokenPackCheckoutExactlyOnce(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Webhook First Workspace',
            'workspace_slug' => 'webhook-first-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace.webhook@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $payload, 'test-secret');

        $webhook = $service->processWebhook($payload, $signature);
        $verify = $service->verifyCheckoutReference($checkout['reference']);

        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'token_pack_purchase'
               AND transaction_status = 'succeeded'",
            [(int) $checkout['checkout_session_id']]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($webhook['success'] ?? false));
        $this->assertTrue((bool) ($verify['success'] ?? false));
        $this->assertSame($initialBalance + 100000, (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $transactions);
        $this->assertSame([$checkout['reference']], $gateway->verified);
    }

    public function testVerifyCheckoutMarksSubscriptionFailedOnceWhenProviderRejectsPayment(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Failed Billing Workspace',
            'workspace_slug' => 'failed-billing-workspace',
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'email' => 'katherine.failed@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $gateway = new FakePaystackGateway();
        $gateway->verifyStatus = 'failed';
        $gateway->verifyGatewayResponse = 'Card declined';
        $priceId = $this->paidLaunchPriceId();

        $service = new SaaSBillingService($gateway);
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $priceId], $userId);

        $result = $service->verifyCheckoutReference($checkout['reference']);
        $resultAgain = $service->verifyCheckoutReference($checkout['reference']);

        $session = Database::queryOne(
            "SELECT status, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) $checkout['checkout_session_id']]
        );
        $workspace = Database::queryOne("SELECT plan_status FROM workspaces WHERE id = ?", [$workspaceId]);
        $subscription = Database::queryOne(
            "SELECT subscription_status
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $failedTransactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_status = 'failed'",
            [(int) $checkout['checkout_session_id']]
        )['c'] ?? 0);
        $metadata = json_decode((string) ($session['metadata_json'] ?? ''), true) ?: [];

        $this->assertFalse((bool) ($result['success'] ?? true));
        $this->assertFalse((bool) ($resultAgain['success'] ?? true));
        $this->assertSame('failed', (string) ($session['status'] ?? ''));
        $this->assertSame('past_due', (string) ($workspace['plan_status'] ?? ''));
        $this->assertSame('past_due', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame(1, $failedTransactions);
        $this->assertSame('failed', (string) (($metadata['finalization']['status'] ?? '')));
    }

    public function testWorkspaceBillingPortalDataIncludesTransactionsCheckoutSessionsAndAiUsage(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Portal Workspace',
            'workspace_slug' => 'portal-workspace',
            'first_name' => 'Dorothy',
            'last_name' => 'Vaughan',
            'email' => 'dorothy.portal@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $service = new SaaSBillingService(new FakePaystackGateway());
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);
        $service->verifyCheckoutReference($checkout['reference']);

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (?, ?, 'openai', 'gpt-test', 'email_draft', ?, 45, 75, 120, 0.024, NOW())",
            [$workspaceId, $userId, 'portal-usage-' . bin2hex(random_bytes(4))]
        );

        $portal = $service->getWorkspaceBillingPortalData($workspaceId);

        $this->assertNotEmpty($portal['transactions']);
        $this->assertNotEmpty($portal['checkout_sessions']);
        $this->assertSame($checkout['reference'], (string) ($portal['checkout_sessions'][0]['provider_reference'] ?? ''));
        $this->assertSame(120, (int) ($portal['ai_usage']['overview']['total_billable_tokens'] ?? 0));
        $this->assertSame('email_draft', (string) ($portal['ai_usage']['by_feature'][0]['feature_key'] ?? ''));
        $this->assertSame($userId, (int) ($portal['ai_usage']['by_user'][0]['user_id'] ?? 0));
    }

    public function testVerifyCheckoutRecordsBoundedFailureMetadataWhenVerificationThrows(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Exception Billing Workspace',
            'workspace_slug' => 'exception-billing-workspace',
            'first_name' => 'Mae',
            'last_name' => 'Jemison',
            'email' => 'mae.exception@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $service = new SaaSBillingService(new ThrowingPaystackGateway());
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);

        try {
            $service->verifyCheckoutReference($checkout['reference']);
            $this->fail('Expected verification to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Provider verification unavailable', $e->getMessage());
        }

        $session = Database::queryOne(
            "SELECT metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) $checkout['checkout_session_id']]
        );
        $metadata = json_decode((string) ($session['metadata_json'] ?? ''), true) ?: [];
        $errors = (array) ($metadata['finalization_errors'] ?? []);

        $this->assertNotEmpty($errors);
        $this->assertSame('verification_exception', (string) ($errors[0]['stage'] ?? ''));
        $this->assertSame('Provider verification unavailable', (string) ($errors[0]['reason'] ?? ''));
    }

    public function testReplayProviderEventRemainsIdempotentAfterSuccessfulWebhook(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Replay Billing Workspace',
            'workspace_slug' => 'replay-billing-workspace',
            'first_name' => 'Ellen',
            'last_name' => 'Ochoa',
            'email' => 'ellen.replay@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);

        $gateway = new FakePaystackGateway();
        $service = new SaaSBillingService($gateway);
        $tokenPackId = $this->tokenPackPriceId();
        $this->makeWorkspaceTopUpEligible($workspaceId, $userId);
        $initialBalance = $this->walletTokenBalance($workspaceId);
        $checkout = $service->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackId], $userId);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $signature = hash_hmac('sha512', $payload, 'test-secret');

        $service->processWebhook($payload, $signature);
        $eventId = (int) (Database::queryOne(
            "SELECT id
             FROM billing_provider_events
             WHERE workspace_id = ?
               AND event_reference = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $checkout['reference']]
        )['id'] ?? 0);

        $result = $service->replayProviderEvent($eventId);

        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'token_pack_purchase'
               AND transaction_status = 'succeeded'",
            [(int) $checkout['checkout_session_id']]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame($initialBalance + 100000, (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $transactions);
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionBillingWorkspace(string $name): array
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name) ?? 'billing-workspace');
        $slug = trim($slug, '-') . '-' . substr(hash('sha256', $name . microtime(true)), 0, 8);

        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => $name,
            'workspace_slug' => $slug,
            'first_name' => 'Billing',
            'last_name' => 'Owner',
            'email' => $slug . '@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    private function setActivePaystackSubscription(int $workspaceId, int $userId, int $priceId): void
    {
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 provider = 'paystack',
                 provider_reference = ?,
                 provider_subscription_code = ?,
                 provider_customer_code = ?,
                 provider_email_token = ?,
                 provider_subscription_status = 'active',
                 renewal_status = 'renewing',
                 provider_metadata_json = ?,
                 subscription_status = 'active',
                 current_period_start = NOW(),
                 current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 next_billing_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 scheduled_billing_plan_price_id = NULL,
                 scheduled_change_type = NULL,
                 scheduled_change_at = NULL,
                 scheduled_change_metadata_json = NULL,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [
                $priceId,
                'ref_current_' . $workspaceId,
                'SUB_current_' . $workspaceId,
                'CUS_current_' . $workspaceId,
                'tok_current_' . $workspaceId,
                json_encode([
                    'authorization' => [
                        'authorization_code' => 'AUTH_current_' . $workspaceId,
                    ],
                ], JSON_UNESCAPED_SLASHES),
                $workspaceId,
            ]
        );
        Database::execute("UPDATE workspaces SET plan_status = 'active', status = 'active' WHERE id = ?", [$workspaceId]);
    }

    private function paidLaunchPriceId(string $priceCode = 'solo-launch-monthly', string $providerPlanCode = 'PLN_test_solo_monthly', ?float $amount = null, ?int $includedTokens = null): int
    {
        $price = Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = ?
             LIMIT 1",
            [$priceCode]
        );
        $this->assertNotEmpty($price['id'] ?? null, 'Expected launch price ' . $priceCode . ' to exist.');

        $sets = ['provider = ?', 'provider_plan_code = ?', 'provider_plan_status = ?', 'provider_plan_synced_at = NOW()', 'is_active = 1'];
        $params = ['paystack', $providerPlanCode, 'active'];
        if ($amount !== null) {
            $sets[] = 'amount = ?';
            $params[] = $amount;
        }
        if ($includedTokens !== null) {
            $sets[] = 'included_tokens = ?';
            $params[] = $includedTokens;
        }
        $params[] = (int) $price['id'];

        Database::execute(
            'UPDATE billing_plan_prices SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return (int) $price['id'];
    }

    private function makeWorkspaceTopUpEligible(int $workspaceId, int $userId): void
    {
        $priceId = $this->paidLaunchPriceId();
        Database::execute(
            "INSERT INTO workspace_subscriptions (
                workspace_id,
                billing_plan_price_id,
                provider,
                provider_reference,
                subscription_status,
                current_period_start,
                current_period_end,
                next_billing_at,
                created_by
             ) VALUES (?, ?, 'test', ?, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), ?)",
            [$workspaceId, $priceId, 'topup-eligible-' . $workspaceId, $userId]
        );
    }

    private function walletTokenBalance(int $workspaceId): int
    {
        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        return (int) ($wallet['token_balance'] ?? 0);
    }

    private function tokenPackPriceId(): int
    {
        $pack = Database::queryOne(
            "SELECT tpp.id
             FROM token_pack_prices tpp
             JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE tpp.is_active = 1
               AND bpp.is_active = 1
               AND bp.is_active = 1
             ORDER BY tpp.sort_order ASC, tpp.id ASC
             LIMIT 1"
        );
        $this->assertNotEmpty($pack['id'] ?? null, 'Expected an active token pack price.');
        return (int) $pack['id'];
    }

    private function freeLaunchPriceId(): int
    {
        $price = Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = 'compass-free-monthly'
             LIMIT 1"
        );
        $this->assertNotEmpty($price['id'] ?? null, 'Expected Compass Free monthly price to exist.');
        return (int) $price['id'];
    }

    private function updateTokenPackBillingCurrency(int $tokenPackPriceId, string $currency): void
    {
        Database::execute(
            "UPDATE billing_plan_prices bpp
             JOIN token_pack_prices tpp ON tpp.billing_plan_price_id = bpp.id
             SET bpp.currency = ?
             WHERE tpp.id = ?",
            [$currency, $tokenPackPriceId]
        );
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null, 'Expected role ' . $roleSlug . ' to exist.');

        Database::execute("DELETE FROM user_roles WHERE user_id = ?", [$userId]);
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)",
            [$userId, (int) $role['id'], $userId]
        );
    }
}

class FakeMpesaDarajaGateway extends MpesaDarajaGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $stkPushes = [];

    /** @var array<int,string> */
    public array $queries = [];

    public string $queryResultCode = '0';
    public string $queryResultDesc = 'The service request is processed successfully.';

    public function __construct()
    {
        parent::__construct([
            'enabled' => true,
            'fake_mode' => true,
            'environment' => 'sandbox',
            'business_short_code' => '174379',
            'passkey' => 'test-passkey',
            'callback_url' => 'https://crm.example/api/webhooks/mpesa.php',
        ]);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function stkPush(string $phoneNumber, float $amount, string $accountReference, string $transactionDesc, ?string $callbackUrl = null): array
    {
        $this->stkPushes[] = [
            'phone' => $phoneNumber,
            'amount' => $amount,
            'account_reference' => $accountReference,
            'transaction_desc' => $transactionDesc,
            'callback_url' => $callbackUrl,
        ];

        $suffix = str_pad((string) count($this->stkPushes), 4, '0', STR_PAD_LEFT);

        return [
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Approve the M-Pesa prompt on the selected phone to complete payment.',
            'MerchantRequestID' => 'merchant_' . $suffix,
            'CheckoutRequestID' => 'ws_FAKE_' . $suffix,
        ];
    }

    public function queryStkPush(string $checkoutRequestId): array
    {
        $this->queries[] = $checkoutRequestId;

        return [
            'ResponseCode' => '0',
            'ResponseDescription' => 'The service request has been accepted successfully.',
            'MerchantRequestID' => 'merchant_query_0001',
            'CheckoutRequestID' => $checkoutRequestId,
            'ResultCode' => $this->queryResultCode,
            'ResultDesc' => $this->queryResultDesc,
        ];
    }
}

class UnconfiguredFakeMpesaDarajaGateway extends FakeMpesaDarajaGateway
{
    public function isConfigured(): bool
    {
        return false;
    }
}

class FakePaystackGateway extends PaystackGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $initialized = [];

    /** @var array<int,array<string,mixed>> */
    public array $charges = [];

    /** @var array<int,array<string,mixed>> */
    public array $subscriptions = [];

    /** @var array<int,array<string,string>> */
    public array $disabledSubscriptions = [];

    /** @var array<int,array<string,string>> */
    public array $enabledSubscriptions = [];

    /** @var array<int,string> */
    public array $verified = [];

    public string $verifyStatus = 'success';
    public string $verifyGatewayResponse = 'Approved';

    public function __construct()
    {
        parent::__construct('test-secret');
    }

    public function initializeTransaction(array $payload): array
    {
        $this->initialized[] = $payload;

        return [
            'status' => true,
            'data' => [
                'authorization_url' => 'https://paystack.example/authorize/' . (string) ($payload['reference'] ?? ''),
                'access_code' => 'access_' . (string) ($payload['reference'] ?? ''),
                'reference' => (string) ($payload['reference'] ?? ''),
            ],
        ];
    }

    public function createCharge(array $payload): array
    {
        $this->charges[] = $payload;
        $mode = isset($payload['mobile_money']) ? 'mpesa' : (isset($payload['bank_transfer']) ? 'bank_transfer' : 'card');
        $instructions = $mode === 'bank_transfer'
            ? [
                'bank_name' => 'Paystack Test Bank',
                'account_number' => '1234567890',
                'account_name' => 'Workspace Billing',
                'expires_at' => date('c', strtotime('+30 minutes')),
            ]
            : [];

        return [
            'status' => true,
            'data' => [
                'reference' => (string) ($payload['reference'] ?? ''),
                'status' => 'pending',
                'display_text' => $mode === 'mpesa'
                    ? 'Approve the M-Pesa prompt on the selected phone to complete payment.'
                    : 'Use the returned transfer instructions to complete payment.',
                'gateway_response' => $mode === 'mpesa' ? 'M-Pesa prompt sent' : 'Bank transfer initialized',
                'instructions' => $instructions,
                'expires_at' => $instructions['expires_at'] ?? date('c', strtotime('+30 minutes')),
            ],
        ];
    }

    public function createSubscription(array $payload): array
    {
        $this->subscriptions[] = $payload;
        $hash = substr(hash('sha256', (string) ($payload['customer'] ?? '') . '|' . (string) ($payload['plan'] ?? '') . '|' . (string) ($payload['start_date'] ?? '')), 0, 12);

        return [
            'status' => true,
            'message' => 'Subscription successfully created',
            'data' => [
                'customer_code' => (string) ($payload['customer'] ?? 'CUS_fake'),
                'subscription_code' => 'SUB_' . $hash,
                'email_token' => 'email_' . $hash,
                'next_payment_date' => (string) ($payload['start_date'] ?? date('c', strtotime('+1 month'))),
                'status' => 'active',
            ],
        ];
    }

    public function disableSubscription(string $code, string $token): array
    {
        $this->disabledSubscriptions[] = ['code' => $code, 'token' => $token];

        return ['status' => true, 'message' => 'Subscription disabled successfully'];
    }

    public function enableSubscription(string $code, string $token): array
    {
        $this->enabledSubscriptions[] = ['code' => $code, 'token' => $token];

        return ['status' => true, 'message' => 'Subscription enabled successfully'];
    }

    public function verifyTransaction(string $reference): array
    {
        $this->verified[] = $reference;

        return [
            'status' => true,
            'data' => [
                'reference' => $reference,
                'status' => $this->verifyStatus,
                'paid_at' => date('c'),
                'gateway_response' => $this->verifyGatewayResponse,
            ],
        ];
    }
}

class ThrowingPaystackGateway extends FakePaystackGateway
{
    public function verifyTransaction(string $reference): array
    {
        throw new \RuntimeException('Provider verification unavailable');
    }
}
