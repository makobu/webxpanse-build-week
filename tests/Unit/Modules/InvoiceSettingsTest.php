<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Tests\DatabaseTestCase;

class InvoiceSettingsTest extends DatabaseTestCase
{
    public function testLoadsDefaultTemplateKeyFromExistingSettings(): void
    {
        $settings = (new InvoiceSettings())->get();

        $this->assertSame('classic', $settings['default_template_key']);
        $this->assertSame($settings['default_template_key'], $settings['visual_theme']);
    }

    public function testSavesAndLoadsDefaultTemplateKey(): void
    {
        $module = new InvoiceSettings();
        $module->save([
            'default_template_key' => 'bold',
            'preview_document_type' => 'proforma',
        ]);

        $settings = $module->get();

        $this->assertSame('bold', $settings['default_template_key']);
        $this->assertSame('bold', $settings['visual_theme']);
        $this->assertSame('proforma', $settings['preview_document_type']);
    }

    public function testCompanyProfileIdentityOverridesLegacyInvoiceIdentity(): void
    {
        Database::execute(
            "UPDATE invoice_settings
             SET company_legal_name = 'Legacy Invoice Co',
                 company_tax_id = 'LEGACY-TAX',
                 company_address = 'Legacy Address',
                 company_email = 'legacy@example.test',
                 company_phone = '+1 555 LEGACY',
                 logo_asset_path = 'uploads/invoices/legacy-logo.png'
             WHERE id = 1"
        );

        (new CompanyProfile())->update([
            'company_name' => 'Profile Trading',
            'company_legal_name' => 'Profile Trading Ltd',
            'company_tax_id' => 'PROFILE-TAX',
            'company_address' => "Profile House\nNairobi",
            'company_email' => 'billing@profile.example.test',
            'company_phone' => '+254700123456',
            'company_logo_url' => 'uploads/company/profile-logo.png',
        ]);

        $settings = (new InvoiceSettings())->get();

        $this->assertSame('Profile Trading Ltd', $settings['company_legal_name']);
        $this->assertSame('PROFILE-TAX', $settings['company_tax_id']);
        $this->assertSame("Profile House\nNairobi", $settings['company_address']);
        $this->assertSame('billing@profile.example.test', $settings['company_email']);
        $this->assertSame('+254700123456', $settings['company_phone']);
        $this->assertSame('uploads/company/profile-logo.png', $settings['logo_asset_path']);
    }

    public function testCompanyProfileContactAndTaxDetailsPopulateDefaultInvoiceSettings(): void
    {
        (new CompanyProfile())->update([
            'company_name' => 'Complete Profile Ltd',
            'company_legal_name' => 'Complete Profile Ltd',
            'company_tax_id' => 'VAT-123456',
            'company_address' => '14 Market Street',
            'company_email' => 'accounts@complete.example.test',
            'company_phone' => '+254 700 555 010',
        ]);

        $settings = (new InvoiceSettings())->get();

        $this->assertSame('Complete Profile Ltd', $settings['company_legal_name']);
        $this->assertSame('VAT-123456', $settings['company_tax_id']);
        $this->assertSame('14 Market Street', $settings['company_address']);
        $this->assertSame('accounts@complete.example.test', $settings['company_email']);
        $this->assertSame('+254 700 555 010', $settings['company_phone']);
    }

    public function testSaveDoesNotPersistProfileOwnedIdentityFields(): void
    {
        Database::execute(
            "UPDATE invoice_settings
             SET company_legal_name = 'Legacy Invoice Co',
                 company_tax_id = 'LEGACY-TAX',
                 company_address = 'Legacy Address',
                 company_email = 'legacy@example.test',
                 company_phone = '+1 555 LEGACY',
                 logo_asset_path = 'uploads/invoices/legacy-logo.png'
             WHERE id = 1"
        );

        (new InvoiceSettings())->save([
            'company_legal_name' => 'Attempted Override Ltd',
            'company_tax_id' => 'ATTEMPT-TAX',
            'company_address' => 'Attempted Address',
            'company_email' => 'attempt@example.test',
            'company_phone' => '+1 555 ATTEMPT',
            'logo_asset_path' => 'uploads/company/attempt-logo.png',
            'default_currency' => 'KES',
        ]);

        $row = Database::queryOne(
            "SELECT company_legal_name, company_tax_id, company_address, company_email, company_phone, logo_asset_path, default_currency
             FROM invoice_settings
             WHERE id = 1"
        );

        $this->assertSame('Legacy Invoice Co', $row['company_legal_name'] ?? '');
        $this->assertSame('LEGACY-TAX', $row['company_tax_id'] ?? '');
        $this->assertSame('Legacy Address', $row['company_address'] ?? '');
        $this->assertSame('legacy@example.test', $row['company_email'] ?? '');
        $this->assertSame('+1 555 LEGACY', $row['company_phone'] ?? '');
        $this->assertSame('uploads/invoices/legacy-logo.png', $row['logo_asset_path'] ?? '');
        $this->assertSame('KES', $row['default_currency'] ?? '');
    }
}
