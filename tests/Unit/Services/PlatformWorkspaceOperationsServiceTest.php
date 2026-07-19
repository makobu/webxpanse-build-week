<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\MpesaDarajaGateway;
use CRM\Services\PaystackGateway;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class PlatformWorkspaceOperationsServiceTest extends DatabaseTestCase
{
    public function testUpdateWorkspaceLifecycleWritesAuditAndChangesSnapshot(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Lifecycle Workspace',
            'workspace_slug' => 'ops-lifecycle-workspace',
            'first_name' => 'Operator',
            'last_name' => 'Admin',
            'email' => 'ops.lifecycle@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $service = new PlatformWorkspaceOperationsService();
        $startingBalance = (int) (Database::queryOne(
            "SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?",
            [$workspaceId]
        )['token_balance'] ?? 0);

        $result = $service->updateWorkspaceLifecycle($workspaceId, 'suspend', $actorUserId, 'Support suspension test');
        $audit = Database::queryOne(
            "SELECT action_type, reason
             FROM operator_audit_log
             WHERE target_workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame('suspended', (string) ($result['workspace']['status'] ?? ''));
        $this->assertTrue((bool) ($result['snapshot']['billing_blocked'] ?? false));
        $this->assertSame('workspace_suspend', (string) ($audit['action_type'] ?? ''));
        $this->assertSame('Support suspension test', (string) ($audit['reason'] ?? ''));
    }

    public function testAdjustWorkspaceWalletCreatesAuditAndUsesWalletLedger(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Wallet Workspace',
            'workspace_slug' => 'ops-wallet-workspace',
            'first_name' => 'Wallet',
            'last_name' => 'Operator',
            'email' => 'ops.wallet@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $service = new PlatformWorkspaceOperationsService();
        $startingBalance = (int) (Database::queryOne(
            "SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?",
            [$workspaceId]
        )['token_balance'] ?? 0);

        $service->adjustWorkspaceWallet($workspaceId, 400, $actorUserId, 'Credit for support resolution');
        $result = $service->adjustWorkspaceWallet($workspaceId, -150, $actorUserId, 'Correction after duplicate credit');
        $ledger = Database::query(
            "SELECT entry_type, reference_type, token_delta
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'manual_adjustment'
             ORDER BY id ASC",
            [$workspaceId]
        );
        $auditCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM operator_audit_log
             WHERE target_workspace_id = ?
               AND action_type = 'wallet_manual_adjustment'",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertSame($startingBalance + 250, (int) ($result['after']['token_balance'] ?? 0));
        $this->assertCount(2, $ledger);
        $this->assertSame('credit', (string) ($ledger[0]['entry_type'] ?? ''));
        $this->assertSame('debit', (string) ($ledger[1]['entry_type'] ?? ''));
        $this->assertSame(2, $auditCount);
    }

    public function testSuperAdminActivatesWorkspacePackageWithoutTrialState(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Workspace',
            'workspace_slug' => 'ops-package-workspace',
            'first_name' => 'Package',
            'last_name' => 'Operator',
            'email' => 'ops.package@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $subscriptionPriceId = (int) (Database::queryOne(
            "SELECT billing_plan_price_id
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        )['billing_plan_price_id'] ?? 0);

        Database::execute(
            "UPDATE workspace_subscriptions SET subscription_status = 'expired' WHERE workspace_id = ?",
            [$workspaceId]
        );
        Database::execute(
            "UPDATE workspaces
             SET plan_status = 'inactive', trial_starts_at = NULL, trial_ends_at = NULL
             WHERE id = ?",
            [$workspaceId]
        );

        $service = new PlatformWorkspaceOperationsService();
        $activated = $service->activateWorkspaceSubscriptionPlan($workspaceId, $subscriptionPriceId, $actorUserId, 'Restore Compass Free package');
        $subscription = Database::queryOne(
            "SELECT subscription_status, trial_starts_at, trial_ends_at
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $auditCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM operator_audit_log
             WHERE target_workspace_id = ?
               AND action_type = 'workspace_subscription_changed'",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertNull($subscription['trial_starts_at'] ?? null);
        $this->assertNull($subscription['trial_ends_at'] ?? null);
        $this->assertSame('active', (string) ($activated['snapshot']['subscription_status'] ?? ''));
        $this->assertFalse((bool) ($activated['snapshot']['is_trial_active'] ?? true));
        $this->assertSame(1, $auditCount);
    }

    public function testSuperAdminUpdatesSubscriptionPriceAndTokenPackPrice(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Pricing Workspace',
            'workspace_slug' => 'ops-pricing-workspace',
            'first_name' => 'Pricing',
            'last_name' => 'Operator',
            'email' => 'ops.pricing@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $service = new PlatformWorkspaceOperationsService();
        $subscriptionPriceId = (int) (Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = 'solo-launch-monthly'
             LIMIT 1"
        )['id'] ?? 0);
        $this->assertGreaterThan(0, $subscriptionPriceId);

        $subscriptionPrice = $service->updateSubscriptionPrice(
            $subscriptionPriceId,
            4500.0,
            'KES',
            250,
            'monthly',
            1,
            true,
            true,
            $actorUserId,
            'Align starter subscription pricing'
        );
        $tokenPack = $service->updateTokenPackPrice(
            1,
            1200.0,
            'KES',
            50000,
            3,
            true,
            $actorUserId,
            'Align starter token pricing'
        );

        $auditCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM operator_audit_log
             WHERE action_type IN ('subscription_price_updated', 'token_pack_price_updated')",
        )['c'] ?? 0);

        $this->assertSame(4500.0, (float) ($subscriptionPrice['amount'] ?? 0));
        $this->assertSame(250, (int) ($subscriptionPrice['included_tokens'] ?? 0));
        $this->assertSame(1200.0, (float) ($tokenPack['amount'] ?? 0));
        $this->assertSame(50000, (int) ($tokenPack['token_quantity'] ?? 0));
        $this->assertSame(50000, (int) ($tokenPack['included_tokens'] ?? 0));
        $this->assertSame(2, $auditCount);
    }

    public function testBillingOperatorActionsRejectMissingActivationReason(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Invalid Billing Workspace',
            'workspace_slug' => 'ops-invalid-billing-workspace',
            'first_name' => 'Invalid',
            'last_name' => 'Operator',
            'email' => 'ops.invalid.billing@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $service = new PlatformWorkspaceOperationsService();

        $this->expectException(\RuntimeException::class);
        $service->activateWorkspaceSubscriptionPlan((int) ($provisioned['workspace_id'] ?? 0), 1, $actorUserId, '');
    }

    public function testBillingOperatorActionsRequireReasonAndPositivePrices(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Price Validation Workspace',
            'workspace_slug' => 'ops-price-validation-workspace',
            'first_name' => 'Price',
            'last_name' => 'Validation',
            'email' => 'ops.price.validation@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $service = new PlatformWorkspaceOperationsService();
        $subscriptionPriceId = (int) (Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = 'solo-launch-monthly'
             LIMIT 1"
        )['id'] ?? 0);
        $this->assertGreaterThan(0, $subscriptionPriceId);

        try {
            $service->updateSubscriptionPrice($subscriptionPriceId, 1000.0, 'KES', 0, 'monthly', 1, true, true, $actorUserId, '');
            $this->fail('Missing reason should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason', strtolower($e->getMessage()));
        }

        $this->expectException(\RuntimeException::class);
        $service->updateTokenPackPrice(1, 0.0, 'KES', 50000, 1, true, $actorUserId, 'Invalid token price');
    }

    public function testSuperAdminUpdatesPackageDescriptionMetadataAndCatalogEntitlements(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Copy Workspace',
            'workspace_slug' => 'ops-package-copy-workspace',
            'first_name' => 'Copy',
            'last_name' => 'Operator',
            'email' => 'ops.package.copy@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $entitlements = new WorkspacePlanEntitlementService();
        $price = $entitlements->priceByCode('founder-plus-monthly') ?? [];
        $priceEntitlements = $entitlements->entitlementsForPriceRow($price);
        $this->assertNotEmpty($price['id'] ?? null);

        $service = new PlatformWorkspaceOperationsService();
        $service->updateSubscriptionPlanCommercials(
            (int) $price['id'],
            (float) ($price['amount'] ?? 0),
            (string) ($price['currency'] ?? 'KES'),
            (int) ($priceEntitlements['included_credits'] ?? $price['included_tokens'] ?? 0),
            (string) ($price['interval_unit'] ?? 'monthly'),
            (int) ($price['interval_count'] ?? 1),
            (int) ($priceEntitlements['seat_limit'] ?? 3),
            (bool) ($priceEntitlements['can_top_up'] ?? true),
            (bool) ($priceEntitlements['business_intelligence_enabled'] ?? true),
            (bool) ($priceEntitlements['personal_api_key_enabled'] ?? false),
            (int) ($priceEntitlements['credit_expiry_days'] ?? 180),
            (bool) ($priceEntitlements['is_custom'] ?? false),
            (string) ($priceEntitlements['maturity_tier'] ?? 'founder'),
            'Founder Plus Studio',
            'Updated package description for founder teams.',
            42,
            true,
            false,
            $actorUserId,
            'Update package copy from settings tab'
        );

        $updated = Database::queryOne(
            "SELECT bpp.metadata_json, bp.description
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.id = ?
             LIMIT 1",
            [(int) $price['id']]
        ) ?: [];
        $metadata = json_decode((string) ($updated['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $updatedEntitlements = $entitlements->entitlementsForPriceRow($entitlements->priceByCode('founder-plus-monthly') ?? []);

        $this->assertSame('Updated package description for founder teams.', (string) ($updated['description'] ?? ''));
        $this->assertSame('Founder Plus Studio', (string) ($metadata['display_name'] ?? ''));
        $this->assertSame('Updated package description for founder teams.', (string) ($metadata['display_copy'] ?? ''));
        $this->assertSame('Updated package description for founder teams.', (string) ($metadata['public_summary'] ?? ''));
        $this->assertSame('Founder Plus Studio', (string) (($metadata['entitlements']['display_name'] ?? '')));
        $this->assertSame('Founder Plus Studio', (string) ($updatedEntitlements['public_display_name'] ?? ''));
        $this->assertSame('Updated package description for founder teams.', (string) ($updatedEntitlements['public_display_copy'] ?? ''));
        $this->assertSame('Updated package description for founder teams.', (string) ($updatedEntitlements['public_summary'] ?? ''));
    }

    public function testPackageCommercialUpdatesRequireSuperAdmin(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Permission Workspace',
            'workspace_slug' => 'ops-package-permission-workspace',
            'first_name' => 'Package',
            'last_name' => 'Denied',
            'email' => 'ops.package.denied@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $price = (new WorkspacePlanEntitlementService())->priceByCode('solo-launch-monthly') ?? [];
        $this->assertNotEmpty($price['id'] ?? null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only Super Admin');

        (new PlatformWorkspaceOperationsService())->updateSubscriptionPlanCommercials(
            (int) $price['id'],
            (float) ($price['amount'] ?? 0),
            (string) ($price['currency'] ?? 'KES'),
            (int) ($price['included_tokens'] ?? 0),
            (string) ($price['interval_unit'] ?? 'monthly'),
            (int) ($price['interval_count'] ?? 1),
            1,
            true,
            false,
            false,
            180,
            false,
            'solo',
            'Solo Launch',
            'Denied update.',
            10,
            true,
            false,
            $actorUserId,
            'Non-superadmin should not update package settings'
        );
    }

    public function testSuperAdminDisablesOnePackageCadenceWithoutDisablingParentPlan(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Cadence Workspace',
            'workspace_slug' => 'ops-package-cadence-workspace',
            'first_name' => 'Cadence',
            'last_name' => 'Operator',
            'email' => 'ops.package.cadence@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $entitlements = new WorkspacePlanEntitlementService();
        $annual = $entitlements->priceByCode('solo-launch-annual') ?? [];
        $annualEntitlements = $entitlements->entitlementsForPriceRow($annual);
        $this->assertNotEmpty($annual['id'] ?? null);

        (new PlatformWorkspaceOperationsService())->updateSubscriptionPlanCommercials(
            (int) $annual['id'],
            (float) ($annual['amount'] ?? 0),
            (string) ($annual['currency'] ?? 'KES'),
            (int) ($annualEntitlements['included_credits'] ?? $annual['included_tokens'] ?? 0),
            (string) ($annual['interval_unit'] ?? 'yearly'),
            (int) ($annual['interval_count'] ?? 1),
            (int) ($annualEntitlements['seat_limit'] ?? 1),
            (bool) ($annualEntitlements['can_top_up'] ?? true),
            (bool) ($annualEntitlements['business_intelligence_enabled'] ?? false),
            (bool) ($annualEntitlements['personal_api_key_enabled'] ?? false),
            (int) ($annualEntitlements['credit_expiry_days'] ?? 180),
            (bool) ($annualEntitlements['is_custom'] ?? false),
            (string) ($annualEntitlements['maturity_tier'] ?? 'solo'),
            (string) ($annualEntitlements['public_display_name'] ?? 'Solo Launch'),
            (string) ($annualEntitlements['public_display_copy'] ?? $annualEntitlements['public_summary'] ?? ''),
            20,
            false,
            false,
            $actorUserId,
            'Disable annual Solo Launch from package settings'
        );

        $parentPlan = Database::queryOne("SELECT is_active FROM billing_plans WHERE id = ? LIMIT 1", [(int) ($annual['plan_id'] ?? 0)]);
        $monthlyStillAvailable = Database::queryOne(
            "SELECT bpp.id
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.price_code = 'solo-launch-monthly'
               AND bpp.is_active = 1
               AND bp.is_active = 1
             LIMIT 1"
        );
        $annualActive = (int) (Database::queryOne("SELECT is_active FROM billing_plan_prices WHERE id = ? LIMIT 1", [(int) $annual['id']])['is_active'] ?? 1);

        $this->assertSame(1, (int) ($parentPlan['is_active'] ?? 0));
        $this->assertNotEmpty($monthlyStillAvailable);
        $this->assertSame(0, $annualActive);
    }

    public function testSuperAdminCanCreateUpdateAndHardDeleteUnusedPackage(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Crud Workspace',
            'workspace_slug' => 'ops-package-crud-workspace',
            'first_name' => 'Crud',
            'last_name' => 'Operator',
            'email' => 'ops.package.crud@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $service = new PlatformWorkspaceOperationsService();

        $package = $service->createSubscriptionPackage([
            'code' => 'operator-crud',
            'name' => 'Operator CRUD',
            'description' => 'Draft package for CRUD controls.',
            'amount' => 0,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'interval_count' => 1,
            'included_credits' => 1500,
            'seat_limit' => 2,
            'is_active' => 0,
        ], $actorUserId, 'Create package for CRUD test');

        $planId = (int) ($package['id'] ?? 0);
        $service->updateSubscriptionPackage($planId, [
            'name' => 'Operator CRUD Updated',
            'description' => 'Updated draft package description.',
            'display_order' => 33,
            'is_active' => 0,
        ], $actorUserId, 'Update package for CRUD test');
        $deleteResult = $service->deleteSubscriptionPackage($planId, $actorUserId, 'Delete unused package for CRUD test');

        $deletedPlan = Database::queryOne("SELECT id FROM billing_plans WHERE id = ? LIMIT 1", [$planId]);
        $deletedPriceCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM billing_plan_prices WHERE plan_id = ?",
            [$planId]
        )['c'] ?? 0);

        $this->assertSame(['subscriptions' => 0, 'checkouts' => 0, 'cycles' => 0, 'transactions' => 0], $deleteResult['reference_counts']);
        $this->assertTrue((bool) ($deleteResult['hard_deleted'] ?? false));
        $this->assertFalse((bool) ($deleteResult['archived'] ?? true));
        $this->assertNull($deletedPlan);
        $this->assertSame(0, $deletedPriceCount);
    }

    public function testClonePackageCopiesDraftCadencesFeaturesAndPaymentRules(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Clone Workspace',
            'workspace_slug' => 'ops-package-clone-workspace',
            'first_name' => 'Clone',
            'last_name' => 'Operator',
            'email' => 'ops.package.clone@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $service = new PlatformWorkspaceOperationsService();
        $package = $service->createSubscriptionPackage([
            'code' => 'operator-clone-source',
            'name' => 'Operator Clone Source',
            'description' => 'Source package for clone controls.',
            'amount' => 500,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'included_credits' => 1500,
            'features' => [
                'seat_limit' => 7,
                'can_top_up' => true,
                'credit_expiry_days' => 240,
            ],
            'payment_modes' => ['mpesa'],
            'is_active' => 1,
        ], $actorUserId, 'Create source package for clone test');
        $planId = (int) ($package['id'] ?? 0);
        $annual = $service->createSubscriptionPrice($planId, [
            'currency' => 'KES',
            'amount' => 5000,
            'interval_unit' => 'yearly',
            'included_credits' => 18000,
            'payment_modes' => ['card'],
            'is_active' => 1,
        ], $actorUserId, 'Add annual source cadence');

        $clone = $service->cloneSubscriptionPackage($planId, 'operator-clone-copy', 'Operator Clone Copy', $actorUserId, 'Clone package for production controls');
        $clonePlanId = (int) ($clone['id'] ?? 0);
        $clonePrices = Database::query(
            "SELECT id, interval_unit, is_active, is_default, provider_plan_code, metadata_json
             FROM billing_plan_prices
             WHERE plan_id = ?
             ORDER BY interval_unit ASC",
            [$clonePlanId]
        );
        $featureRows = Database::query(
            "SELECT bpf.feature_key, bpfv.value_json, bpfv.is_enabled
             FROM billing_plan_feature_values bpfv
             JOIN billing_package_features bpf ON bpf.id = bpfv.feature_id
             WHERE bpfv.plan_id = ?",
            [$clonePlanId]
        );
        $allowlistCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_plan_price_payment_methods bpm
             JOIN billing_plan_prices bpp ON bpp.id = bpm.billing_plan_price_id
             WHERE bpp.plan_id = ?",
            [$clonePlanId]
        )['c'] ?? 0);

        $this->assertSame(0, (int) ($clone['is_active'] ?? 1));
        $this->assertCount(2, $clonePrices);
        foreach ($clonePrices as $clonePrice) {
            $metadata = json_decode((string) ($clonePrice['metadata_json'] ?? '{}'), true) ?: [];
            $this->assertSame(0, (int) ($clonePrice['is_active'] ?? 1));
            $this->assertSame(0, (int) ($clonePrice['is_default'] ?? 1));
            $this->assertSame('', (string) ($clonePrice['provider_plan_code'] ?? ''));
            $this->assertSame($planId, (int) ($metadata['lifecycle']['cloned_from_plan_id'] ?? 0));
        }
        $this->assertNotEmpty($featureRows);
        $this->assertGreaterThanOrEqual(2, $allowlistCount);
        $this->assertSame('yearly', (string) ($annual['interval_unit'] ?? ''));
    }

    public function testCheckoutLinkedTransactionsForceArchiveInsteadOfHardDelete(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Checkout Ref Workspace',
            'workspace_slug' => 'ops-package-checkout-ref-workspace',
            'first_name' => 'Checkout',
            'last_name' => 'Reference',
            'email' => 'ops.package.checkout.ref@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $service = new PlatformWorkspaceOperationsService();
        $package = $service->createSubscriptionPackage([
            'code' => 'operator-checkout-ref',
            'name' => 'Operator Checkout Ref',
            'description' => 'Package with checkout-only references.',
            'amount' => 100,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'included_credits' => 1000,
            'is_active' => 0,
        ], $actorUserId, 'Create package for checkout reference test');
        $planId = (int) ($package['id'] ?? 0);
        $priceId = (int) (Database::queryOne("SELECT id FROM billing_plan_prices WHERE plan_id = ? LIMIT 1", [$planId])['id'] ?? 0);

        Database::execute(
            "INSERT INTO billing_checkout_sessions
                (workspace_id, user_id, provider, payment_mode, flow_type, checkout_type, provider_reference, status, currency, amount, billing_plan_price_id, callback_url, metadata_json, expires_at, paid_at)
             VALUES (?, ?, 'paystack', 'card', 'redirect', 'subscription', ?, 'paid', 'KES', 100, ?, 'https://crm.example/callback', '{}', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())",
            [$workspaceId, $actorUserId, 'checkout-ref-' . $workspaceId, $priceId]
        );
        $checkoutId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO billing_transactions
                (workspace_id, checkout_session_id, provider, payment_mode, flow_type, provider_reference, transaction_type, transaction_status, amount, currency, metadata_json)
             VALUES (?, ?, 'paystack', 'card', 'redirect', ?, 'subscription_charge', 'succeeded', 100, 'KES', '{}')",
            [$workspaceId, $checkoutId, 'checkout-ref-' . $workspaceId]
        );

        $deleteResult = $service->deleteSubscriptionPackage($planId, $actorUserId, 'Archive checkout referenced package');
        $plan = Database::queryOne("SELECT id, is_active FROM billing_plans WHERE id = ? LIMIT 1", [$planId]);

        $this->assertFalse((bool) ($deleteResult['hard_deleted'] ?? true));
        $this->assertTrue((bool) ($deleteResult['archived'] ?? false));
        $this->assertSame(1, (int) ($deleteResult['reference_counts']['checkouts'] ?? 0));
        $this->assertSame(1, (int) ($deleteResult['reference_counts']['transactions'] ?? 0));
        $this->assertNotNull($plan);
        $this->assertSame(0, (int) ($plan['is_active'] ?? 1));
    }

    public function testReferencedPackageArchivesInsteadOfHardDelete(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Archive Workspace',
            'workspace_slug' => 'ops-package-archive-workspace',
            'first_name' => 'Archive',
            'last_name' => 'Operator',
            'email' => 'ops.package.archive@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $service = new PlatformWorkspaceOperationsService();
        $package = $service->createSubscriptionPackage([
            'code' => 'operator-archive',
            'name' => 'Operator Archive',
            'description' => 'Referenced package for archive controls.',
            'amount' => 0,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'interval_count' => 1,
            'included_credits' => 750,
            'seat_limit' => 2,
            'is_active' => 1,
        ], $actorUserId, 'Create package for archive test');
        $price = Database::queryOne(
            "SELECT id FROM billing_plan_prices WHERE plan_id = ? LIMIT 1",
            [(int) ($package['id'] ?? 0)]
        );
        $this->assertNotEmpty($price['id'] ?? null);

        $service->activateWorkspaceSubscriptionPlan(
            $workspaceId,
            (int) ($price['id'] ?? 0),
            $actorUserId,
            'Reference package before archive test'
        );
        $deleteResult = $service->deleteSubscriptionPackage((int) ($package['id'] ?? 0), $actorUserId, 'Archive referenced package');

        $plan = Database::queryOne("SELECT is_active FROM billing_plans WHERE id = ? LIMIT 1", [(int) ($package['id'] ?? 0)]);
        $archivedPrice = Database::queryOne("SELECT is_active, metadata_json FROM billing_plan_prices WHERE id = ? LIMIT 1", [(int) ($price['id'] ?? 0)]);
        $metadata = json_decode((string) ($archivedPrice['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];

        $this->assertFalse((bool) ($deleteResult['hard_deleted'] ?? true));
        $this->assertTrue((bool) ($deleteResult['archived'] ?? false));
        $this->assertGreaterThan(0, (int) (($deleteResult['reference_counts']['subscriptions'] ?? 0)));
        $this->assertSame(0, (int) ($plan['is_active'] ?? 1));
        $this->assertSame(0, (int) ($archivedPrice['is_active'] ?? 1));
        $this->assertNotEmpty($metadata['lifecycle']['archived_at'] ?? null);
    }

    public function testDynamicFeatureValuesResolveThroughWorkspaceEntitlements(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Feature Workspace',
            'workspace_slug' => 'ops-package-feature-workspace',
            'first_name' => 'Feature',
            'last_name' => 'Operator',
            'email' => 'ops.package.feature@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $service = new PlatformWorkspaceOperationsService();
        $service->saveFeatureCatalog([
            'feature_key' => 'advanced_reporting',
            'label' => 'Advanced reporting',
            'category' => 'analytics',
            'value_type' => 'boolean',
            'default_value' => false,
            'display_order' => 55,
            'is_active' => 1,
        ], $actorUserId, 'Create custom reporting feature');
        $package = $service->createSubscriptionPackage([
            'code' => 'operator-feature',
            'name' => 'Operator Feature',
            'description' => 'Package with dynamic feature values.',
            'amount' => 0,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'included_credits' => 5000,
            'features' => [
                'seat_limit' => 9,
                'business_intelligence' => true,
                'advanced_reporting' => true,
                'credit_expiry_days' => 365,
            ],
            'is_active' => 1,
        ], $actorUserId, 'Create feature package');
        $price = Database::queryOne("SELECT id FROM billing_plan_prices WHERE plan_id = ? LIMIT 1", [(int) ($package['id'] ?? 0)]);
        $this->assertNotEmpty($price['id'] ?? null);
        $service->activateWorkspaceSubscriptionPlan($workspaceId, (int) ($price['id'] ?? 0), $actorUserId, 'Activate feature package');

        $entitlementService = new WorkspacePlanEntitlementService();
        $entitlements = $entitlementService->entitlementsForWorkspace($workspaceId);

        $this->assertSame(9, (int) ($entitlements['seat_limit'] ?? 0));
        $this->assertSame(365, (int) ($entitlements['credit_expiry_days'] ?? 0));
        $this->assertTrue((bool) ($entitlements['business_intelligence_enabled'] ?? false));
        $this->assertTrue((bool) (($entitlements['features']['advanced_reporting'] ?? false)));
        $this->assertTrue($entitlementService->canUseFeature($workspaceId, 'advanced_reporting'));
        $this->assertNotEmpty($entitlements['feature_details'] ?? []);
    }

    public function testPerPackagePaymentAllowlistsAreHonoredByCheckoutModeListing(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Payment Workspace',
            'workspace_slug' => 'ops-package-payment-workspace',
            'first_name' => 'Payment',
            'last_name' => 'Operator',
            'email' => 'ops.package.payment@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $priceId = (int) (Database::queryOne(
            "SELECT id FROM billing_plan_prices WHERE price_code = 'solo-launch-monthly' LIMIT 1"
        )['id'] ?? 0);
        $this->assertGreaterThan(0, $priceId);

        (new PlatformWorkspaceOperationsService())->savePricePaymentMethods(
            $priceId,
            ['mpesa'],
            $actorUserId,
            'Allow M-Pesa only for Solo package test'
        );
        $portal = (new SaaSBillingService(new PlatformOpsFakePaystackGateway(), null, null, null, new PlatformOpsFakeMpesaDarajaGateway()))
            ->getWorkspaceBillingPortalData($workspaceId);
        $solo = null;
        foreach ((array) ($portal['plans'] ?? []) as $plan) {
            if ((int) ($plan['id'] ?? 0) === $priceId) {
                $solo = $plan;
                break;
            }
        }
        $this->assertNotNull($solo);
        $modes = array_values(array_map(static fn(array $mode): string => (string) ($mode['key'] ?? ''), (array) ($solo['payment_modes'] ?? [])));

        $this->assertSame(['mpesa'], $modes);
        $this->assertTrue((bool) (($solo['payment_modes'][0]['available'] ?? false)));
    }

    public function testPackageAnalyticsAggregatesSubscriptionsCheckoutsTransactionsAndPacks(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Ops Package Analytics Workspace',
            'workspace_slug' => 'ops-package-analytics-workspace',
            'first_name' => 'Analytics',
            'last_name' => 'Operator',
            'email' => 'ops.package.analytics@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $service = new PlatformWorkspaceOperationsService();
        $package = $service->createSubscriptionPackage([
            'code' => 'operator-analytics',
            'name' => 'Operator Analytics',
            'description' => 'Package for analytics aggregation.',
            'amount' => 1000,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'included_credits' => 3000,
            'is_active' => 1,
        ], $actorUserId, 'Create analytics package');
        $priceId = (int) (Database::queryOne("SELECT id FROM billing_plan_prices WHERE plan_id = ? LIMIT 1", [(int) ($package['id'] ?? 0)])['id'] ?? 0);
        $service->activateWorkspaceSubscriptionPlan($workspaceId, $priceId, $actorUserId, 'Activate analytics package');
        $subscriptionId = (int) (Database::queryOne(
            "SELECT id FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);
        Database::execute(
            "INSERT INTO billing_checkout_sessions
                (workspace_id, user_id, provider, payment_mode, flow_type, checkout_type, provider_reference, status, currency, amount, billing_plan_price_id, subscription_id, callback_url, metadata_json, expires_at, paid_at)
             VALUES (?, ?, 'paystack', 'card', 'redirect', 'subscription', ?, 'paid', 'KES', 1000, ?, ?, 'https://crm.example/callback', '{}', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())",
            [$workspaceId, $actorUserId, 'analytics-subscription-' . $workspaceId, $priceId, $subscriptionId]
        );
        $checkoutId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO billing_transactions
                (workspace_id, checkout_session_id, subscription_id, provider, payment_mode, flow_type, provider_reference, transaction_type, transaction_status, amount, currency, metadata_json)
             VALUES (?, ?, ?, 'paystack', 'card', 'redirect', ?, 'subscription_charge', 'succeeded', 1000, 'KES', '{}')",
            [$workspaceId, $checkoutId, $subscriptionId, 'analytics-subscription-' . $workspaceId]
        );
        $pack = $service->createTokenPack([
            'code' => 'analytics-pack',
            'name' => 'Analytics Pack',
            'amount' => 250,
            'currency' => 'KES',
            'token_quantity' => 2500,
            'sort_order' => 2,
            'is_active' => 1,
        ], $actorUserId, 'Create analytics token pack');
        Database::execute(
            "INSERT INTO billing_checkout_sessions
                (workspace_id, user_id, provider, payment_mode, flow_type, checkout_type, provider_reference, status, currency, amount, token_pack_price_id, callback_url, metadata_json, expires_at, paid_at)
             VALUES (?, ?, 'paystack', 'card', 'redirect', 'token_pack', ?, 'paid', 'KES', 250, ?, 'https://crm.example/callback', '{}', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())",
            [$workspaceId, $actorUserId, 'analytics-pack-' . $workspaceId, (int) ($pack['id'] ?? 0)]
        );
        $packCheckoutId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO billing_transactions
                (workspace_id, checkout_session_id, provider, payment_mode, flow_type, provider_reference, transaction_type, transaction_status, amount, currency, metadata_json)
             VALUES (?, ?, 'paystack', 'card', 'redirect', ?, 'token_pack_purchase', 'succeeded', 250, 'KES', '{}')",
            [$workspaceId, $packCheckoutId, 'analytics-pack-' . $workspaceId]
        );

        $analytics = $service->listPackageAnalytics();
        $subscriptionRows = array_filter((array) ($analytics['subscriptions'] ?? []), static fn(array $row): bool => (string) ($row['plan_code'] ?? '') === 'operator-analytics');
        $checkoutRows = array_filter((array) ($analytics['checkouts'] ?? []), static fn(array $row): bool => (string) ($row['package_code'] ?? '') === 'operator-analytics');
        $revenueRows = array_filter((array) ($analytics['revenue'] ?? []), static fn(array $row): bool => (string) ($row['package_code'] ?? '') === 'operator-analytics');
        $packRows = array_filter((array) ($analytics['token_packs'] ?? []), static fn(array $row): bool => (string) ($row['plan_code'] ?? '') === 'analytics-pack');
        $popularityRows = array_filter((array) ($analytics['popularity'] ?? []), static fn(array $row): bool => (string) ($row['plan_code'] ?? '') === 'operator-analytics');

        $this->assertNotEmpty($subscriptionRows);
        $this->assertGreaterThanOrEqual(1, (int) (array_values($subscriptionRows)[0]['active_subscriptions'] ?? 0));
        $this->assertNotEmpty($checkoutRows);
        $this->assertSame(1, (int) (array_values($checkoutRows)[0]['paid_checkouts'] ?? 0));
        $this->assertNotEmpty($revenueRows);
        $this->assertSame(1000.0, (float) (array_values($revenueRows)[0]['revenue'] ?? 0));
        $this->assertNotEmpty($packRows);
        $this->assertSame(1, (int) (array_values($packRows)[0]['paid_purchases'] ?? 0));
        $this->assertNotEmpty($popularityRows);

        $oldWindow = $service->listPackageAnalytics(['from' => '1999-01-01', 'to' => '1999-01-02']);
        $oldCheckoutRows = array_filter((array) ($oldWindow['checkouts'] ?? []), static fn(array $row): bool => (string) ($row['package_code'] ?? '') === 'operator-analytics');
        $oldRevenueRows = array_filter((array) ($oldWindow['revenue'] ?? []), static fn(array $row): bool => (string) ($row['package_code'] ?? '') === 'operator-analytics');
        $this->assertSame('1999-01-01 00:00:00 to 1999-01-02 23:59:59', (string) ($oldWindow['window']['label'] ?? ''));
        $this->assertSame([], array_values($oldCheckoutRows));
        $this->assertSame([], array_values($oldRevenueRows));
    }

    public function testReplayProviderEventUsesStoredContextAndStaysIdempotent(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Replay Ops Workspace',
            'workspace_slug' => 'replay-ops-workspace',
            'first_name' => 'Replay',
            'last_name' => 'Admin',
            'email' => 'ops.replay@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $topUpEnabledPriceId = (int) (Database::queryOne(
            "SELECT bpp.id
             FROM billing_plan_prices bpp
             WHERE bpp.price_code = 'solo-launch-monthly'
             LIMIT 1"
        )['id'] ?? 0);
        (new PlatformWorkspaceOperationsService())->activateWorkspaceSubscriptionPlan(
            $workspaceId,
            $topUpEnabledPriceId,
            $actorUserId,
            'Enable top-up replay test package'
        );

        $billing = new SaaSBillingService(new PlatformOpsFakePaystackGateway());
        $startingBalance = (int) (Database::queryOne(
            "SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?",
            [$workspaceId]
        )['token_balance'] ?? 0);
        $tokenPack = Database::queryOne(
            "SELECT tpp.id, tpp.token_quantity
             FROM token_pack_prices tpp
             JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE tpp.is_active = 1
               AND bpp.is_active = 1
               AND bp.is_active = 1
             ORDER BY tpp.sort_order ASC, tpp.id ASC
             LIMIT 1"
        ) ?: [];
        $tokenPackPriceId = (int) ($tokenPack['id'] ?? 0);
        $checkout = $billing->createCheckout($workspaceId, ['token_pack_price_id' => $tokenPackPriceId], $actorUserId);
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $checkout['reference'],
                'status' => 'success',
                'paid_at' => date('c'),
            ],
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $payload, 'test-secret');

        $billing->processWebhook($payload, $signature);
        $eventId = (int) (Database::queryOne(
            "SELECT id
             FROM billing_provider_events
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);

        $service = new PlatformWorkspaceOperationsService($billing);
        $result = $service->replayProviderEvent($workspaceId, $eventId, $actorUserId, 'Replay after support inspection');
        $wallet = Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId]);
        $transactions = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_transactions
             WHERE checkout_session_id = ?
               AND transaction_type = 'token_pack_purchase'
               AND transaction_status = 'succeeded'",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame($startingBalance + (int) ($tokenPack['token_quantity'] ?? 0), (int) ($wallet['token_balance'] ?? 0));
        $this->assertSame(1, $transactions);
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }
}

class PlatformOpsFakeMpesaDarajaGateway extends MpesaDarajaGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $stkPushes = [];

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

        return [
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Approve the M-Pesa prompt on the selected phone to complete payment.',
            'MerchantRequestID' => 'merchant_ops_0001',
            'CheckoutRequestID' => 'ws_OPS_0001',
        ];
    }
}

class PlatformOpsFakePaystackGateway extends PaystackGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $initialized = [];

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
