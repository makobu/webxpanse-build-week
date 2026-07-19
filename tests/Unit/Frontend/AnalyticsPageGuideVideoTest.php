<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class AnalyticsPageGuideVideoTest extends TestCase
{
    public function testAnalyticsPageGuideButtonIsRenderedAfterScopeFilter(): void
    {
        $analytics = file_get_contents(__DIR__ . '/../../../public/analytics.php');

        $this->assertNotFalse($analytics);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_ANALYTICS', (string) $analytics);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $analytics);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $analytics);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_ANALYTICS', (string) $analytics);

        $filterBarPosition = strpos((string) $analytics, '<div class="analytics-filter-bar" aria-label="Analytics filters">');
        $scopePosition = strpos((string) $analytics, '<span>Scope</span>');
        $guidePosition = strpos((string) $analytics, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_ANALYTICS");
        $filterBarEndPosition = strpos((string) $analytics, '</div>', $guidePosition);

        $this->assertIsInt($filterBarPosition);
        $this->assertIsInt($scopePosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($filterBarEndPosition);
        $this->assertGreaterThan($filterBarPosition, $scopePosition);
        $this->assertGreaterThan($scopePosition, $guidePosition);
        $this->assertLessThan($filterBarEndPosition, $guidePosition);
    }
}
