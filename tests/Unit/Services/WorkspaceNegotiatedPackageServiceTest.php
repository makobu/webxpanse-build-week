<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceNegotiatedPackageService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceNegotiatedPackageServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['MPESA_FAKE_MODE'] = 'true';
        $_ENV['MPESA_ENABLED'] = 'true';
        $_ENV['MPESA_CALLBACK_URL'] = 'https://crm.example/api/webhooks/mpesa.php';
        putenv('MPESA_FAKE_MODE=true');
        putenv('MPESA_ENABLED=true');
        putenv('MPESA_CALLBACK_URL=https://crm.example/api/webhooks/mpesa.php');
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('MPESA_FAKE_MODE');
        putenv('MPESA_ENABLED');
        putenv('MPESA_CALLBACK_URL');
        unset($_ENV['MPESA_FAKE_MODE'], $_ENV['MPESA_ENABLED'], $_ENV['MPESA_CALLBACK_URL']);

        parent::tearDown();
    }

    public function testCreateOfferAddsPrivatePriceAndWorkspaceOnlyPortalCard(): void
    {
        $provisioned = $this->provisionWorkspace('negotiated-private-portal');
        $workspaceId = (int) $provisioned['workspace_id'];
        $actorUserId = (int) $provisioned['user_id'];
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $basePriceId = $this->priceId('growth-studio-monthly');

        $offer = (new WorkspaceNegotiatedPackageService())->createOffer([
            'workspace_id' => $workspaceId,
            'base_billing_plan_price_id' => $basePriceId,
            'status' => 'offered',
            'amount' => 7000,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'interval_count' => 1,
            'included_credits' => 9000000,
            'seat_limit' => 25,
            'credit_expiry_days' => 365,
            'can_top_up' => true,
            'business_intelligence' => true,
            'personal_api_key' => false,
            'display_name' => 'Negotiated Growth Package',
            'display_copy' => 'Private workspace pricing with expanded limits.',
            'payment_modes' => ['mpesa'],
        ], $actorUserId, 'Approved negotiated package test');
        $priceId = (int) ($offer['negotiated_billing_plan_price_id'] ?? 0);

        $price = $this->loadPrice($priceId);
        $entitlements = (new WorkspacePlanEntitlementService())->entitlementsForPriceRow($price);
        $globalPriceIds = array_map('intval', array_column((new SaaSBillingService())->listSubscriptionPrices(), 'id'));
        $targetPriceIds = array_map('intval', array_column((new SaaSBillingService())->listSubscriptionPrices($workspaceId), 'id'));
        $otherWorkspace = $this->provisionWorkspace('negotiated-other-portal');
        $otherPriceIds = array_map('intval', array_column((new SaaSBillingService())->listSubscriptionPrices((int) $otherWorkspace['workspace_id']), 'id'));
        $portal = (new SaaSBillingService())->getWorkspaceBillingPortalData($workspaceId);
        $privateCards = array_values(array_filter((array) ($portal['package_cards'] ?? []), static function (array $card): bool {
            return !empty($card['workspace_negotiated']);
        }));

        $this->assertSame($workspaceId, (int) ($price['workspace_id'] ?? 0));
        $this->assertSame(25, (int) ($entitlements['seat_limit'] ?? 0));
        $this->assertSame(9000000, (int) ($entitlements['included_credits'] ?? 0));
        $this->assertSame(365, (int) ($entitlements['credit_expiry_days'] ?? 0));
        $this->assertTrue((bool) ($entitlements['business_intelligence_enabled'] ?? false));
        $this->assertFalse((bool) ($entitlements['personal_api_key_enabled'] ?? true));
        $this->assertNotContains($priceId, $globalPriceIds);
        $this->assertContains($priceId, $targetPriceIds);
        $this->assertNotContains($priceId, $otherPriceIds);
        $this->assertCount(1, $privateCards);
        $this->assertSame($priceId, (int) ($privateCards[0]['billing_plan_price_id'] ?? 0));
        $this->assertTrue((bool) ($privateCards[0]['checkout_available'] ?? false));
    }

    public function testPrivatePriceCheckoutIsRejectedForAnotherWorkspace(): void
    {
        $provisioned = $this->provisionWorkspace('negotiated-checkout-owner');
        $workspaceId = (int) $provisioned['workspace_id'];
        $actorUserId = (int) $provisioned['user_id'];
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $offer = $this->createMpesaOffer($workspaceId, $actorUserId);
        $otherWorkspace = $this->provisionWorkspace('negotiated-checkout-other');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Selected package is not available for this workspace.');

        (new SaaSBillingService())->createCheckout((int) $otherWorkspace['workspace_id'], [
            'billing_plan_price_id' => (int) ($offer['negotiated_billing_plan_price_id'] ?? 0),
            'payment_mode' => 'mpesa',
            'customer_phone' => '254712345678',
        ], (int) $otherWorkspace['user_id']);
    }

    public function testMpesaCheckoutAndOperatorActivationUseNegotiatedPrice(): void
    {
        $provisioned = $this->provisionWorkspace('negotiated-mpesa-checkout');
        $workspaceId = (int) $provisioned['workspace_id'];
        $actorUserId = (int) $provisioned['user_id'];
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $offer = $this->createMpesaOffer($workspaceId, $actorUserId);
        $priceId = (int) ($offer['negotiated_billing_plan_price_id'] ?? 0);

        $checkout = (new SaaSBillingService())->createCheckout($workspaceId, [
            'billing_plan_price_id' => $priceId,
            'payment_mode' => 'mpesa',
            'customer_phone' => '254712345678',
        ], $actorUserId);
        $service = new WorkspaceNegotiatedPackageService();
        $activated = $service->activateOffer((int) ($offer['id'] ?? 0), $actorUserId, 'Manual activation after M-Pesa confirmation');
        $subscription = Database::queryOne(
            "SELECT id, billing_plan_price_id, provider, renewal_status, subscription_status
             FROM workspace_subscriptions
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        ) ?? [];
        $invoice = Database::queryOne(
            "SELECT id, document_key, billing_plan_price_id, negotiated_offer_id, provider
             FROM billing_invoices
             WHERE subscription_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) ($subscription['id'] ?? 0)]
        ) ?? [];
        $activatedAgain = $service->activateOffer((int) ($offer['id'] ?? 0), $actorUserId, 'Duplicate manual activation after M-Pesa confirmation');
        $invoiceCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_invoices
             WHERE subscription_id = ?
               AND billing_plan_price_id = ?",
            [(int) ($subscription['id'] ?? 0), $priceId]
        )['c'] ?? 0);

        $this->assertSame('mpesa', (string) ($checkout['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($checkout['flow_type'] ?? ''));
        $this->assertSame([], (array) ($checkout['instructions'] ?? ['unexpected']));
        $this->assertSame('active', (string) ($activated['status'] ?? ''));
        $this->assertGreaterThan(0, (int) (($activated['activation']['billing_invoice_id'] ?? 0)));
        $this->assertSame($priceId, (int) ($subscription['billing_plan_price_id'] ?? 0));
        $this->assertSame('operator', (string) ($subscription['provider'] ?? ''));
        $this->assertSame('manual', (string) ($subscription['renewal_status'] ?? ''));
        $this->assertSame('active', (string) ($subscription['subscription_status'] ?? ''));
        $this->assertSame($priceId, (int) ($invoice['billing_plan_price_id'] ?? 0));
        $this->assertSame((int) ($offer['id'] ?? 0), (int) ($invoice['negotiated_offer_id'] ?? 0));
        $this->assertSame('operator', (string) ($invoice['provider'] ?? ''));
        $this->assertStringStartsWith('operator_activation:' . (int) ($subscription['id'] ?? 0) . ':' . $priceId . ':', (string) ($invoice['document_key'] ?? ''));
        $this->assertFalse((bool) (($activatedAgain['activation']['changed'] ?? true)));
        $this->assertSame((int) ($invoice['id'] ?? 0), (int) (($activatedAgain['activation']['billing_invoice_id'] ?? 0)));
        $this->assertSame(1, $invoiceCount);
    }

    public function testCardAutopayIsBlockedUntilPrivateProviderPlanExists(): void
    {
        $provisioned = $this->provisionWorkspace('negotiated-card-blocked');
        $workspaceId = (int) $provisioned['workspace_id'];
        $actorUserId = (int) $provisioned['user_id'];
        $this->assignGlobalRole($actorUserId, 'superadmin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Card autopay for negotiated packages requires a synced private provider plan first.');

        (new WorkspaceNegotiatedPackageService())->createOffer([
            'workspace_id' => $workspaceId,
            'base_billing_plan_price_id' => $this->priceId('growth-studio-monthly'),
            'status' => 'offered',
            'amount' => 7000,
            'currency' => 'KES',
            'payment_modes' => ['card'],
        ], $actorUserId, 'Attempt card before provider sync');
    }

    public function testWorkspaceOfferSummariesExposeMetricsAndFilters(): void
    {
        $service = new WorkspaceNegotiatedPackageService();
        $openWorkspace = $this->provisionWorkspace('negotiated-summary-open');
        $openWorkspaceId = (int) $openWorkspace['workspace_id'];
        $actorUserId = (int) $openWorkspace['user_id'];
        $this->assignGlobalRole($actorUserId, 'superadmin');
        $openOffer = $service->createOffer([
            'workspace_id' => $openWorkspaceId,
            'base_billing_plan_price_id' => $this->priceId('growth-studio-monthly'),
            'status' => 'offered',
            'amount' => 7100,
            'currency' => 'KES',
            'payment_modes' => ['mpesa'],
            'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
        ], $actorUserId, 'Create expiring negotiated summary offer');

        $archivedWorkspace = $this->provisionWorkspace('negotiated-summary-archived');
        $archivedOffer = $service->createOffer([
            'workspace_id' => (int) $archivedWorkspace['workspace_id'],
            'base_billing_plan_price_id' => $this->priceId('growth-studio-monthly'),
            'status' => 'draft',
            'amount' => 6400,
            'currency' => 'KES',
            'payment_modes' => ['mpesa'],
        ], $actorUserId, 'Create archived negotiated summary offer');
        $service->archiveOffer((int) ($archivedOffer['id'] ?? 0), $actorUserId, 'Archive negotiated summary offer');

        $summaries = $service->listWorkspaceOfferSummaries();
        $openSummary = $this->summaryForWorkspace($summaries, $openWorkspaceId);
        $archivedSummary = $this->summaryForWorkspace($summaries, (int) $archivedWorkspace['workspace_id']);
        $summaryWorkspaceIds = array_map('intval', array_column($summaries, 'workspace_id'));
        $metrics = $service->negotiatedOfferMetrics();
        $offeredSummaries = $service->listWorkspaceOfferSummaries(['status' => 'offered']);
        $mpesaSummaries = $service->listWorkspaceOfferSummaries(['payment_mode' => 'mpesa']);
        $searchedSummaries = $service->listWorkspaceOfferSummaries(['q' => 'negotiated-summary-archived']);

        $this->assertSame('offered', (string) ($openSummary['current_status'] ?? ''));
        $this->assertTrue((bool) ($openSummary['is_expiring_soon'] ?? false));
        $this->assertSame(1, (int) (($openSummary['status_counts']['offered'] ?? 0)));
        $this->assertSame('archived', (string) ($archivedSummary['current_status'] ?? ''));
        $this->assertLessThan(
            array_search((int) $archivedWorkspace['workspace_id'], $summaryWorkspaceIds, true),
            array_search($openWorkspaceId, $summaryWorkspaceIds, true)
        );
        $this->assertGreaterThanOrEqual(1, (int) ($metrics['open_offers'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($metrics['expiring_soon'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($metrics['card_sync_blocked'] ?? 0));
        $this->assertContains($openWorkspaceId, array_map('intval', array_column($offeredSummaries, 'workspace_id')));
        $this->assertNotContains((int) $archivedWorkspace['workspace_id'], array_map('intval', array_column($offeredSummaries, 'workspace_id')));
        $this->assertContains($openWorkspaceId, array_map('intval', array_column($mpesaSummaries, 'workspace_id')));
        $this->assertSame([(int) $archivedWorkspace['workspace_id']], array_map('intval', array_column($searchedSummaries, 'workspace_id')));
        $this->assertSame((int) ($openOffer['id'] ?? 0), (int) ($openSummary['current_offer_id'] ?? 0));
    }

    /**
     * @return array{workspace_id:int,user_id:int}
     */
    private function provisionWorkspace(string $slug): array
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => ucwords(str_replace('-', ' ', $slug)),
            'workspace_slug' => $slug,
            'first_name' => 'Negotiated',
            'last_name' => 'Owner',
            'email' => $slug . '@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        return [
            'workspace_id' => (int) ($provisioned['workspace_id'] ?? 0),
            'user_id' => (int) ($provisioned['user_id'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createMpesaOffer(int $workspaceId, int $actorUserId): array
    {
        return (new WorkspaceNegotiatedPackageService())->createOffer([
            'workspace_id' => $workspaceId,
            'base_billing_plan_price_id' => $this->priceId('growth-studio-monthly'),
            'status' => 'offered',
            'amount' => 7000,
            'currency' => 'KES',
            'interval_unit' => 'monthly',
            'interval_count' => 1,
            'included_credits' => 9000000,
            'seat_limit' => 25,
            'credit_expiry_days' => 365,
            'can_top_up' => true,
            'business_intelligence' => true,
            'personal_api_key' => true,
            'payment_modes' => ['mpesa'],
        ], $actorUserId, 'Approved negotiated M-Pesa package');
    }

    private function priceId(string $priceCode): int
    {
        $row = Database::queryOne(
            "SELECT id FROM billing_plan_prices WHERE price_code = ? LIMIT 1",
            [$priceCode]
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPrice(int $priceId): array
    {
        return Database::queryOne(
            "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.id = ?
             LIMIT 1",
            [$priceId]
        ) ?? [];
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $this->assertNotEmpty($role['id'] ?? null);
        Authorization::assignUserRole($userId, (int) $role['id'], $userId);
    }

    /**
     * @param list<array<string,mixed>> $summaries
     * @return array<string,mixed>
     */
    private function summaryForWorkspace(array $summaries, int $workspaceId): array
    {
        foreach ($summaries as $summary) {
            if ((int) ($summary['workspace_id'] ?? 0) === $workspaceId) {
                return $summary;
            }
        }

        $this->fail('Expected negotiated package summary for workspace ' . $workspaceId);
    }
}
