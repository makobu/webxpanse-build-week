<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class BillingProviderPaymentModesSourceTest extends TestCase
{
    public function testBillingSettingsSaveDelegatesFirstSetupDefaultsWithoutOverridingAdminSwitches(): void
    {
        $page = file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertNotFalse($page);
        $source = str_replace(["\r\n", "\r"], "\n", (string) $page);

        $this->assertStringNotContainsString('$billingSettingsPayload[\'payment_card_enabled\'] = true;', $source);
        $this->assertStringNotContainsString('$billingSettingsPayload[\'payment_mpesa_enabled\'] = true;', $source);
        $this->assertStringNotContainsString('$billingSettingsPayload[\'payment_bank_transfer_enabled\'] = true;', $source);

        $settingsModule = file_get_contents(__DIR__ . '/../../../modules/WorkspaceBillingSettings.php');
        $this->assertNotFalse($settingsModule);
        $this->assertStringContainsString('!$paystackWasReady && $paystackIsReady', (string) $settingsModule);
        $this->assertStringContainsString('!$mpesaWasReady && $mpesaIsReady', (string) $settingsModule);
        $this->assertStringContainsString("array_key_exists('payment_bank_transfer_enabled', \$data)", (string) $settingsModule);
    }
}
