<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class MarketplacePageGuideVideoTest extends TestCase
{
    public function testMarketplacePageGuideButtonIsRenderedInDashboardHeroActions(): void
    {
        $marketplace = file_get_contents(__DIR__ . '/../../../public/workspace_skills.php');

        $this->assertNotFalse($marketplace);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_MARKETPLACE', (string) $marketplace);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $marketplace);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $marketplace);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_MARKETPLACE', (string) $marketplace);

        $heroPosition = strpos((string) $marketplace, '<div class="page-header marketplace-hero marketplace-dashboard-hero">');
        $heroActionsPosition = strpos((string) $marketplace, '<div class="marketplace-hero-actions marketplace-dashboard-hero-actions">');
        $guidePosition = strpos((string) $marketplace, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_MARKETPLACE");
        $toolbarPosition = strpos((string) $marketplace, '<div class="marketplace-toolbar">');
        $resetPosition = strpos((string) $marketplace, 'id="marketplace-reset-filters"');

        $this->assertIsInt($heroPosition);
        $this->assertIsInt($heroActionsPosition);
        $this->assertIsInt($toolbarPosition);
        $this->assertIsInt($resetPosition);
        $this->assertIsInt($guidePosition);
        $this->assertGreaterThan($heroPosition, $heroActionsPosition);
        $this->assertGreaterThan($heroActionsPosition, $guidePosition);
        $this->assertLessThan($toolbarPosition, $guidePosition);
        $this->assertLessThan($resetPosition, $guidePosition);
    }
}
