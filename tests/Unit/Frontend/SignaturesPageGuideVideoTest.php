<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class SignaturesPageGuideVideoTest extends TestCase
{
    public function testSignaturesPageGuideButtonIsRenderedBesideNewSignatureAction(): void
    {
        $signatures = file_get_contents(__DIR__ . '/../../../public/email_signatures.php');

        $this->assertNotFalse($signatures);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_SIGNATURES', (string) $signatures);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $signatures);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $signatures);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_SIGNATURES', (string) $signatures);

        $headingPosition = strpos((string) $signatures, '<h1 class="signature-page-title" id="signature-page-title">Email Signatures</h1>');
        $guidePosition = strpos((string) $signatures, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_SIGNATURES");
        $newSignaturePosition = strpos((string) $signatures, 'href="email_signature_create.php" class="signature-button signature-button-primary"');

        $this->assertIsInt($headingPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($newSignaturePosition);
        $this->assertGreaterThan($headingPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $newSignaturePosition);
    }

    public function testSignaturesPageOffersModernQuickStartsAndLibraryActions(): void
    {
        $signatures = (string) file_get_contents(__DIR__ . '/../../../public/email_signatures.php');

        $this->assertStringContainsString('EmailSignatureTemplateCatalog::all', $signatures);
        $this->assertStringContainsString('Start with a template', $signatures);
        $this->assertStringContainsString('data-signature-search', $signatures);
        $this->assertStringContainsString('data-copy-signature', $signatures);
        $this->assertStringContainsString('name="duplicate"', $signatures);
        $this->assertStringContainsString('email-signatures-library.js', $signatures);
    }
}
