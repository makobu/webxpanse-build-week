<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

final class LandingPageMobileLayoutTest extends TestCase
{
    public function testPublishedLandingPagesUseCompactMobileSectionContracts(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/design-studio-public.css');

        $this->assertStringContainsString('@media (max-width: 640px)', $css);
        $this->assertStringContainsString('.ds-space--normal { padding: 44px 0; }', $css);
        $this->assertStringContainsString('font-size: clamp(2rem, 10.5vw, 2.8rem)', $css);
        $this->assertStringContainsString('.ds-split__media { aspect-ratio: 4 / 3; min-height: 0; width: 100%; }', $css);
        $this->assertStringContainsString('.ds-benefits li { min-height: 0; padding: 20px; }', $css);
        $this->assertStringContainsString('.ds-price { margin-top: 26px; max-width: none; padding: 22px; }', $css);
        $this->assertStringContainsString('.ds-button { min-height: 50px; padding: 12px 18px; width: 100%; }', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
    }

    public function testDesignStudioMobilePreviewMirrorsPublishedResponsiveBehavior(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/design-studio.css');
        $mobileScope = ':is(.design-canvas-viewport.is-mobile, .design-draft-preview.is-mobile)';

        $this->assertStringContainsString($mobileScope . ' .ds-space--normal { padding: 44px 0; }', $css);
        $this->assertStringContainsString('.design-draft-preview.is-mobile .design-preview-device { container-type: inline-size; }', $css);
        $this->assertStringContainsString('font-size: clamp(2rem, 10.5cqw, 2.8rem)', $css);
        $this->assertStringContainsString($mobileScope . ' .ds-actions { align-items: stretch; flex-direction: column; gap: 10px; }', $css);
        $this->assertStringContainsString($mobileScope . ' .ds-split__media { aspect-ratio: 4 / 3; min-height: 0; width: 100%; }', $css);
        $this->assertStringContainsString($mobileScope . ' .ds-benefits li { min-height: 0; padding: 20px; }', $css);
        $this->assertStringContainsString($mobileScope . ' .ds-hide-desktop { display: block !important; }', $css);
        $this->assertStringContainsString($mobileScope . ' .ds-hide-mobile { display: none !important; }', $css);
    }
}
