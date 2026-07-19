<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class UnifiedCatalogAndReceiptsUiTest extends TestCase
{
    public function testSettingsPresentsProductsAndServicesWithoutWorkspacePackages(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');
        $this->assertStringContainsString("'products' => 'Products & Services'", $source);
        $this->assertStringContainsString('Manage the products and services your business sells.', $source);
        $this->assertStringContainsString('placeholder="Product or service"', $source);
        $this->assertStringNotContainsString('Clarity packages your workspace can use', $source);
        $this->assertStringNotContainsString('data-package-code=', $source);
        $this->assertStringNotContainsString('offers + packages', $source);
        $this->assertStringNotContainsString('UnifiedCommercialCatalogService', $source);
    }

    public function testInvoicesProvidesASeparateImmutablePackageReceiptView(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/invoices.php');
        $this->assertStringContainsString("['sales', 'receipts']", $source);
        $this->assertStringContainsString('Package Receipts', $source);
        $this->assertStringContainsString('Immutable receipts issued by Clarity', $source);
        $this->assertStringContainsString('billing_invoice.php?id=', $source);
    }
}
