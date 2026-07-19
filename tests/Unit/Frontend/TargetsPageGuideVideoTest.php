<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class TargetsPageGuideVideoTest extends TestCase
{
    public function testTargetsPageGuideButtonIsRenderedBesideNewTargetAction(): void
    {
        $targets = file_get_contents(__DIR__ . '/../../../public/targets.php');

        $this->assertNotFalse($targets);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_TARGETS', (string) $targets);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $targets);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $targets);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_TARGETS', (string) $targets);

        $headerActionsPosition = strpos((string) $targets, '<div class="page-header-actions">');
        $guidePosition = strpos((string) $targets, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_TARGETS");
        $newTargetPosition = strpos((string) $targets, '/target_create.php" class="btn-premium-primary"');
        $filtersPosition = strpos((string) $targets, '<div class="filters-card">');
        $filterActionsPosition = strpos((string) $targets, '<div class="filter-actions">');

        $this->assertIsInt($headerActionsPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($newTargetPosition);
        $this->assertIsInt($filtersPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertGreaterThan($headerActionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $newTargetPosition);
        $this->assertLessThan($filtersPosition, $guidePosition);
        $this->assertLessThan($filterActionsPosition, $guidePosition);
    }
}
