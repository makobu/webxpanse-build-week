<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceBillingPaymentModeService;
use PHPUnit\Framework\TestCase;

class WorkspaceBillingPaymentModeServiceTest extends TestCase
{
    public function testAvailabilityOverridesDisableSpecificPaymentModes(): void
    {
        $service = new WorkspaceBillingPaymentModeService();

        $modes = $service->listAvailableModes('KES', true, true, [
            WorkspaceBillingPaymentModeService::MODE_CARD => false,
            WorkspaceBillingPaymentModeService::MODE_MPESA => true,
            WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER => false,
        ]);
        $byKey = [];
        foreach ($modes as $mode) {
            $byKey[(string) ($mode['key'] ?? '')] = $mode;
        }

        $this->assertFalse((bool) ($byKey['card']['available'] ?? true));
        $this->assertSame('Card payments are temporarily unavailable.', (string) ($byKey['card']['reason'] ?? ''));
        $this->assertTrue((bool) ($byKey['mpesa']['available'] ?? false));
        $this->assertFalse((bool) ($byKey['bank_transfer']['available'] ?? true));
        $this->assertSame('Bank transfer payments are temporarily unavailable.', (string) ($byKey['bank_transfer']['reason'] ?? ''));
    }

    public function testAssertAvailableRejectsDisabledMode(): void
    {
        $service = new WorkspaceBillingPaymentModeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('M-Pesa payments are temporarily unavailable.');

        $service->assertAvailable(
            WorkspaceBillingPaymentModeService::MODE_MPESA,
            'KES',
            true,
            true,
            [WorkspaceBillingPaymentModeService::MODE_MPESA => false]
        );
    }

    public function testRegionalPolicyReasonExplainsWhyModeIsDisabled(): void
    {
        $service = new WorkspaceBillingPaymentModeService();
        $modes = $service->listAvailableModes(
            'KES',
            true,
            true,
            [WorkspaceBillingPaymentModeService::MODE_CARD => false],
            [WorkspaceBillingPaymentModeService::MODE_CARD => 'Card payments are disabled for Kenya by regional payment policy.']
        );

        $this->assertFalse((bool) ($modes[0]['available'] ?? true));
        $this->assertSame(
            'Card payments are disabled for Kenya by regional payment policy.',
            (string) ($modes[0]['reason'] ?? '')
        );
    }
}
