<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceCreditLedgerService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceWalletService;
use CRM\Tests\DatabaseTestCase;

class BillingCreditsCommercialModelTest extends DatabaseTestCase
{
    public function testSeededPlanEntitlementsMatchCommercialDefaults(): void
    {
        $service = new WorkspacePlanEntitlementService();

        $compass = $service->entitlementsForPriceRow($service->priceByCode('compass-free-monthly') ?? []);
        $founder = $service->entitlementsForPriceRow($service->priceByCode('founder-plus-monthly') ?? []);
        $growth = $service->entitlementsForPriceRow($service->priceByCode('growth-studio-monthly') ?? []);
        $scale = $service->entitlementsForPriceRow($service->priceByCode('scale-custom-monthly') ?? []);

        $this->assertSame(1, (int) ($compass['seat_limit'] ?? 0));
        $this->assertSame(50000, (int) ($compass['included_credits'] ?? 0));
        $this->assertFalse((bool) ($compass['can_top_up'] ?? true));
        $this->assertFalse((bool) ($compass['business_intelligence_enabled'] ?? true));
        $this->assertFalse((bool) ($compass['personal_api_key_enabled'] ?? true));

        $this->assertSame(3, (int) ($founder['seat_limit'] ?? 0));
        $this->assertSame(3500000, (int) ($founder['included_credits'] ?? 0));
        $this->assertTrue((bool) ($founder['business_intelligence_enabled'] ?? false));
        $this->assertFalse((bool) ($founder['personal_api_key_enabled'] ?? true));

        $this->assertSame(15, (int) ($growth['seat_limit'] ?? 0));
        $this->assertTrue((bool) ($growth['personal_api_key_enabled'] ?? false));

        $this->assertSame(0, (int) ($scale['seat_limit'] ?? -1));
        $this->assertTrue((bool) ($scale['is_custom'] ?? false));
    }

    public function testLegacyStarterSubscriptionSeedIsRemovedFromCatalog(): void
    {
        $starterPlan = Database::queryOne(
            "SELECT id
             FROM billing_plans
             WHERE code = 'starter-subscription'
             LIMIT 1"
        );
        $starterPrice = Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = 'starter-subscription-monthly'
             LIMIT 1"
        );
        $compassPrice = Database::queryOne(
            "SELECT bpp.id
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bp.code = 'compass-free'
               AND bpp.price_code = 'compass-free-monthly'
             LIMIT 1"
        );

        $this->assertNull($starterPlan);
        $this->assertNull($starterPrice);
        $this->assertNotNull($compassPrice);
    }

    public function testDefaultWorkspaceEntitlementsArePackageExempt(): void
    {
        $service = new WorkspacePlanEntitlementService();
        $entitlements = $service->entitlementsForWorkspace(1);

        $this->assertTrue((bool) ($entitlements['package_exempt'] ?? false));
        $this->assertSame('Default workspace package exempt.', (string) ($entitlements['package_exemption_reason'] ?? ''));
        $this->assertSame(0, (int) ($entitlements['seat_limit'] ?? -1));
        $this->assertSame('Unlimited', (string) ($entitlements['seat_limit_label'] ?? ''));
        $this->assertTrue((bool) ($entitlements['can_top_up'] ?? false));
        $this->assertTrue((bool) ($entitlements['business_intelligence_enabled'] ?? false));
        $this->assertTrue((bool) ($entitlements['personal_api_key_enabled'] ?? false));
        $this->assertTrue($service->canUseFeature(1, WorkspacePlanEntitlementService::FEATURE_BUSINESS_INTELLIGENCE));
        $this->assertTrue($service->canUseFeature(1, WorkspacePlanEntitlementService::FEATURE_PERSONAL_API_KEY));
        $this->assertFalse($service->canUseFeature(1, 'future_unknown_feature'));
    }

    public function testTenantCompassFreeEntitlementsRemainPackageLimited(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Tenant Compass Limited',
            'workspace_slug' => 'tenant-compass-limited',
            'first_name' => 'Tenant',
            'last_name' => 'Limited',
            'email' => 'tenant.compass.limited@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);

        $service = new WorkspacePlanEntitlementService();
        $entitlements = $service->entitlementsForWorkspace($workspaceId);

        $this->assertFalse((bool) ($entitlements['package_exempt'] ?? true));
        $this->assertSame('', (string) ($entitlements['package_exemption_reason'] ?? 'not-empty'));
        $this->assertSame(1, (int) ($entitlements['seat_limit'] ?? 0));
        $this->assertFalse((bool) ($entitlements['can_top_up'] ?? true));
        $this->assertFalse($service->canUseFeature($workspaceId, WorkspacePlanEntitlementService::FEATURE_BUSINESS_INTELLIGENCE));
        $this->assertFalse($service->canUseFeature($workspaceId, WorkspacePlanEntitlementService::FEATURE_PERSONAL_API_KEY));
        $this->assertFalse($service->canUseFeature($workspaceId, 'future_unknown_feature'));
    }

    public function testNewWorkspaceStartsOnCompassFreeWithOnboardingCredits(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Compass Credits Workspace',
            'workspace_slug' => 'compass-credits-workspace',
            'first_name' => 'Compass',
            'last_name' => 'Credits',
            'email' => 'compass.credits@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);

        $snapshot = (new SaaSBillingService())->getWorkspaceSnapshot($workspaceId);
        $lot = Database::queryOne(
            "SELECT *
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = 'compass_free_onboarding_credits'
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame('active', (string) ($snapshot['subscription_status'] ?? ''));
        $this->assertSame(WorkspacePlanEntitlementService::PLAN_COMPASS_FREE, (string) ($snapshot['subscription']['plan_code'] ?? ''));
        $this->assertSame(50000, (int) ($snapshot['credit_balance'] ?? 0));
        $this->assertSame(50000, (int) ($snapshot['available_credits'] ?? 0));
        $this->assertNotEmpty($lot);
        $this->assertSame(50000, (int) ($lot['granted_credits'] ?? 0));
        $this->assertSame(50000, (int) ($lot['remaining_credits'] ?? 0));
    }

    public function testCreditLotsDebitFifoByNearestExpiry(): void
    {
        $workspaceId = 1;
        $wallets = new WorkspaceWalletService();
        $wallets->ensureWallet($workspaceId, 'KES');

        $wallets->creditTokens($workspaceId, 100, 'unit_test_lot', 'soon', null, ['credit_expiry_days' => 10]);
        $wallets->creditTokens($workspaceId, 100, 'unit_test_lot', 'later', null, ['credit_expiry_days' => 180]);

        $result = $wallets->debitTokens($workspaceId, 150, 'unit_test_debit', 'fifo', null, [], 'FIFO debit test');
        $soon = Database::queryOne(
            "SELECT remaining_credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = 'unit_test_lot'
               AND source_id = 'soon'
             LIMIT 1",
            [$workspaceId]
        );
        $later = Database::queryOne(
            "SELECT remaining_credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = 'unit_test_lot'
               AND source_id = 'later'
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame(150, (int) ($result['debited_credits'] ?? 0));
        $this->assertSame(0, (int) ($soon['remaining_credits'] ?? -1));
        $this->assertSame(50, (int) ($later['remaining_credits'] ?? -1));
    }

    public function testSuperAdminSamePlanActivationDoesNotGrantDuplicatePeriodCredits(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Duplicate Credit Guard Workspace',
            'workspace_slug' => 'duplicate-credit-guard-workspace',
            'first_name' => 'Duplicate',
            'last_name' => 'Guard',
            'email' => 'duplicate.credit.guard@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $price = (new WorkspacePlanEntitlementService())->priceByCode('solo-launch-monthly') ?? [];
        $this->assertNotEmpty($price['id'] ?? null);

        $service = new PlatformWorkspaceOperationsService();
        $first = $service->activateWorkspaceSubscriptionPlan($workspaceId, (int) $price['id'], $actorUserId, 'Upgrade to Solo Launch');
        $subscriptionAfterFirst = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]);
        $balanceAfterFirst = (int) (Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId])['token_balance'] ?? 0);

        $second = $service->activateWorkspaceSubscriptionPlan($workspaceId, (int) $price['id'], $actorUserId, 'Retry same Solo Launch activation');
        $subscriptionAfterSecond = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$workspaceId]);
        $balanceAfterSecond = (int) (Database::queryOne("SELECT token_balance FROM workspace_wallets WHERE workspace_id = ?", [$workspaceId])['token_balance'] ?? 0);
        $periodCreditLots = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = 'subscription_included_tokens'",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertTrue((bool) ($first['changed'] ?? false));
        $this->assertSame('granted', (string) ($first['credit_result']['credit_grant_status'] ?? ''));
        $this->assertFalse((bool) ($second['changed'] ?? true));
        $this->assertSame('duplicate_active_period', (string) ($second['credit_result']['credit_grant_status'] ?? ''));
        $this->assertSame($balanceAfterFirst, $balanceAfterSecond);
        $this->assertSame((string) ($subscriptionAfterFirst['current_period_start'] ?? ''), (string) ($subscriptionAfterSecond['current_period_start'] ?? ''));
        $this->assertSame((string) ($subscriptionAfterFirst['current_period_end'] ?? ''), (string) ($subscriptionAfterSecond['current_period_end'] ?? ''));
        $this->assertSame(1, $periodCreditLots);
    }

    public function testDisablingOneSubscriptionPriceDoesNotDeactivateSiblingPrice(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Price Active Workspace',
            'workspace_slug' => 'price-active-workspace',
            'first_name' => 'Price',
            'last_name' => 'Active',
            'email' => 'price.active@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $entitlements = new WorkspacePlanEntitlementService();
        $monthly = $entitlements->priceByCode('solo-launch-monthly') ?? [];
        $annual = $entitlements->priceByCode('solo-launch-annual') ?? [];
        $annualEntitlements = $entitlements->entitlementsForPriceRow($annual);
        $this->assertNotEmpty($monthly['id'] ?? null);
        $this->assertNotEmpty($annual['id'] ?? null);

        (new PlatformWorkspaceOperationsService())->updateSubscriptionPlanCommercials(
            (int) $annual['id'],
            (float) ($annual['amount'] ?? 0),
            (string) ($annual['currency'] ?? 'KES'),
            (int) ($annual['included_tokens'] ?? 0),
            (string) ($annual['interval_unit'] ?? 'yearly'),
            (int) ($annual['interval_count'] ?? 1),
            (int) ($annualEntitlements['seat_limit'] ?? 1),
            (bool) ($annualEntitlements['can_top_up'] ?? false),
            (bool) ($annualEntitlements['business_intelligence_enabled'] ?? false),
            (bool) ($annualEntitlements['personal_api_key_enabled'] ?? false),
            (int) ($annualEntitlements['credit_expiry_days'] ?? 180),
            (bool) ($annualEntitlements['is_custom'] ?? false),
            (string) ($annualEntitlements['maturity_tier'] ?? 'solo'),
            (string) ($annualEntitlements['public_display_name'] ?? 'Solo Launch'),
            (string) ($annualEntitlements['public_display_copy'] ?? ''),
            20,
            false,
            false,
            $actorUserId,
            'Disable annual Solo Launch only'
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

    public function testWalletSummaryDoesNotExpireCreditsOrExposeExpiredLotsAsAvailable(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Summary Expiry Workspace',
            'workspace_slug' => 'summary-expiry-workspace',
            'first_name' => 'Summary',
            'last_name' => 'Expiry',
            'email' => 'summary.expiry@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $wallets = new WorkspaceWalletService();
        $wallets->creditTokens($workspaceId, 77, 'unit_test_expired_lot', 'summary_read_only', null, ['credit_expiry_days' => 180]);
        Database::execute(
            "UPDATE workspace_credit_lots
             SET remaining_credits = 0,
                 status = 'depleted'
             WHERE workspace_id = ?
               AND source_type = 'compass_free_onboarding_credits'",
            [$workspaceId]
        );
        Database::execute(
            "UPDATE workspace_wallets SET token_balance = 77 WHERE workspace_id = ?",
            [$workspaceId]
        );
        Database::execute(
            "UPDATE workspace_credit_lots
             SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
             WHERE workspace_id = ?
               AND source_type = 'unit_test_expired_lot'
               AND source_id = 'summary_read_only'",
            [$workspaceId]
        );

        $beforeExpiryLedgerCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'credit_expiry'",
            [$workspaceId]
        )['c'] ?? 0);

        $summary = $wallets->getSummary($workspaceId);
        $lot = Database::queryOne(
            "SELECT status, remaining_credits
             FROM workspace_credit_lots
             WHERE workspace_id = ?
               AND source_type = 'unit_test_expired_lot'
               AND source_id = 'summary_read_only'
             LIMIT 1",
            [$workspaceId]
        );
        $afterExpiryLedgerCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'credit_expiry'",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertSame($beforeExpiryLedgerCount, $afterExpiryLedgerCount);
        $this->assertSame('active', (string) ($lot['status'] ?? ''));
        $this->assertSame(77, (int) ($lot['remaining_credits'] ?? 0));
        $this->assertSame(0, (int) ($summary['available_credits'] ?? -1));
    }

    public function testExplicitCreditExpiryRecordsOperatorAuditAndLedgerMetadata(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Expiry Audit Workspace',
            'workspace_slug' => 'expiry-audit-workspace',
            'first_name' => 'Expiry',
            'last_name' => 'Audit',
            'email' => 'expiry.audit@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $actorUserId = (int) ($provisioned['user_id'] ?? 0);
        $wallets = new WorkspaceWalletService();
        $wallets->creditTokens($workspaceId, 123, 'unit_test_expiry', 'audit', $actorUserId, ['credit_expiry_days' => 180]);
        Database::execute(
            "UPDATE workspace_credit_lots
             SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
             WHERE workspace_id = ?
               AND source_type = 'unit_test_expiry'
               AND source_id = 'audit'",
            [$workspaceId]
        );

        $summary = (new WorkspaceCreditLedgerService())->expireUnusedCredits(
            $workspaceId,
            null,
            $actorUserId,
            'Monthly expiry sweep',
            ['source' => 'unit_test']
        );
        $audit = Database::queryOne(
            "SELECT action_type, reason, metadata_json
             FROM operator_audit_log
             WHERE target_workspace_id = ?
               AND action_type = 'workspace_credits_expired'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $ledger = Database::queryOne(
            "SELECT metadata_json
             FROM workspace_wallet_ledger
             WHERE workspace_id = ?
               AND reference_type = 'credit_expiry'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );
        $auditMetadata = json_decode((string) ($audit['metadata_json'] ?? ''), true) ?: [];
        $ledgerMetadata = json_decode((string) ($ledger['metadata_json'] ?? ''), true) ?: [];

        $this->assertSame(1, (int) ($summary['expired_lots'] ?? 0));
        $this->assertSame(123, (int) ($summary['expired_credits'] ?? 0));
        $this->assertSame('workspace_credits_expired', (string) ($audit['action_type'] ?? ''));
        $this->assertSame('Monthly expiry sweep', (string) ($audit['reason'] ?? ''));
        $this->assertSame(123, (int) ($auditMetadata['expired_credits'] ?? 0));
        $this->assertSame($actorUserId, (int) ($ledgerMetadata['operator_user_id'] ?? 0));
        $this->assertSame('Monthly expiry sweep', (string) ($ledgerMetadata['operator_reason'] ?? ''));
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }
}
