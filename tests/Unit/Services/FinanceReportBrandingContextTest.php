<?php

namespace CRM\Tests\Unit\Services;

use CRM\Modules\CompanyProfile;
use CRM\Services\FinanceReportDocumentService;
use CRM\Tests\DatabaseTestCase;

class FinanceReportBrandingContextTest extends DatabaseTestCase
{
    public function testBrandingContextUsesCompanyProfileIdentity(): void
    {
        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9s6fP5wAAAAASUVORK5CYII=';
        (new CompanyProfile())->update([
            'company_name' => 'Profile Finance',
            'company_legal_name' => 'Profile Finance Ltd',
            'company_tax_id' => 'FIN-TAX',
            'company_address' => "1 Finance Way\nNairobi",
            'company_email' => 'finance-profile@example.test',
            'company_phone' => '+254700111222',
            'company_logo_url' => $logo,
        ]);

        $context = (new FinanceReportDocumentService())->brandingContext(
            1,
            ['name' => 'Workspace Fallback'],
            '2026-05-01',
            '2026-05-31',
            'KES'
        );

        $this->assertSame('Profile Finance Ltd', $context['business_name']);
        $this->assertSame("1 Finance Way\nNairobi", $context['company_address']);
        $this->assertSame('finance-profile@example.test', $context['company_email']);
        $this->assertSame('+254700111222', $context['company_phone']);
        $this->assertSame('FIN-TAX', $context['company_tax_id']);
        $this->assertSame($logo, $context['logo_src']);
    }
}
