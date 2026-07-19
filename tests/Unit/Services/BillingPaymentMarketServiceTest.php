<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\BillingPaymentMarketService;
use CRM\Services\WorkspaceBillingPaymentModeService;
use CRM\Tests\DatabaseTestCase;

class BillingPaymentMarketServiceTest extends DatabaseTestCase
{
    public function testCountryRuleRestrictsGlobalAvailabilityForAssignedWorkspace(): void
    {
        $service = new BillingPaymentMarketService();
        $service->saveRule([
            'scope_type' => 'country',
            'scope_code' => 'ke',
            'scope_name' => 'Kenya',
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => false,
            'payment_bank_transfer_enabled' => true,
            'is_active' => true,
        ], 1);
        $service->saveWorkspaceAssignment(1, 'ke', 'east africa', 1);

        $policy = $service->effectivePolicy(1, 'KES', [
            WorkspaceBillingPaymentModeService::MODE_CARD => true,
            WorkspaceBillingPaymentModeService::MODE_MPESA => true,
            WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER => true,
        ]);

        $this->assertSame('workspace_assignment', (string) ($policy['market']['source'] ?? ''));
        $this->assertSame('KE', (string) ($policy['market']['country_code'] ?? ''));
        $this->assertTrue((bool) ($policy['availability']['card'] ?? false));
        $this->assertFalse((bool) ($policy['availability']['mpesa'] ?? true));
        $this->assertTrue((bool) ($policy['availability']['bank_transfer'] ?? false));
        $this->assertStringContainsString('Kenya', (string) ($policy['reasons']['mpesa'] ?? ''));

        $this->assertTrue($service->clearWorkspaceAssignment(1));
        $afterClear = $service->resolveMarket(1, 'NGN');
        $this->assertSame('currency_inference', (string) ($afterClear['source'] ?? ''));
        $this->assertSame('NG', (string) ($afterClear['country_code'] ?? ''));
    }
}
