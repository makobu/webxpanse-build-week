<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class CompanyProfileDocumentBrandingSettingsTest extends TestCase
{
    public function testCompanyProfileOwnsDocumentBrandingControls(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertStringContainsString('name="company_legal_name"', $source);
        $this->assertStringContainsString('name="company_tax_id"', $source);
        $this->assertStringContainsString('name="company_logo_file"', $source);
        $this->assertStringContainsString('name="company_logo_remove"', $source);
        $this->assertStringContainsString('Invoices, quotes, proformas, and finance reports use Company Profile', $source);
    }

    public function testInvoicingNoLongerOwnsCompanyIdentityInputs(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertStringNotContainsString('name="invoice_company_legal_name"', $source);
        $this->assertStringNotContainsString('name="invoice_company_tax_id"', $source);
        $this->assertStringNotContainsString('name="invoice_company_email"', $source);
        $this->assertStringNotContainsString('name="invoice_company_phone"', $source);
        $this->assertStringNotContainsString('name="invoice_company_address"', $source);
        $this->assertStringNotContainsString('name="invoice_logo_file"', $source);
        $this->assertStringNotContainsString('name="invoice_logo_remove"', $source);
    }
}
