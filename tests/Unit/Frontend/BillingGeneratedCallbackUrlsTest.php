<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class BillingGeneratedCallbackUrlsTest extends TestCase
{
    public function testBillingCallbackFieldsAreGeneratedAndReadOnly(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../views/partials/settings_billing_saas.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('WorkspaceBillingSettings::generatedPaystackCallbackUrl()', $source);
        $this->assertStringContainsString('WorkspaceBillingSettings::generatedPaystackWebhookUrl()', $source);
        $this->assertStringContainsString('WorkspaceBillingSettings::generatedMpesaCallbackUrl()', $source);
        $this->assertStringContainsString('WorkspaceBillingSettings::absoluteCrmUrl(apiUrl(\'workspace_billing/status_sync.php\'))', $source);
        $this->assertStringNotContainsString('name="billing_paystack_callback_url"', $source);
        $this->assertStringNotContainsString('name="billing_paystack_webhook_url"', $source);
        $this->assertStringNotContainsString('name="billing_mpesa_callback_url"', $source);
    }

    public function testBillingSettingsSaveUsesGeneratedCallbackUrls(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'paystack_callback_url' => WorkspaceBillingSettings::generatedPaystackCallbackUrl()", $source);
        $this->assertStringContainsString("'paystack_webhook_url' => WorkspaceBillingSettings::generatedPaystackWebhookUrl()", $source);
        $this->assertStringContainsString("'mpesa_callback_url' => WorkspaceBillingSettings::generatedMpesaCallbackUrl()", $source);
        $this->assertStringNotContainsString("'paystack_callback_url' => trim((string) (\$_POST['billing_paystack_callback_url']", $source);
        $this->assertStringNotContainsString("'paystack_webhook_url' => trim((string) (\$_POST['billing_paystack_webhook_url']", $source);
        $this->assertStringNotContainsString("'mpesa_callback_url' => trim((string) (\$_POST['billing_mpesa_callback_url']", $source);
    }
}
