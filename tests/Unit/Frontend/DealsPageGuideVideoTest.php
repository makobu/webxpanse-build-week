<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class DealsPageGuideVideoTest extends TestCase
{
    public function testDealsPageGuideButtonIsRenderedBesideNewDealAction(): void
    {
        $deals = file_get_contents(__DIR__ . '/../../../public/deals.php');

        $this->assertNotFalse($deals);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_DEALS', (string) $deals);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $deals);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $deals);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_DEALS', (string) $deals);

        $headerActionsPosition = strpos((string) $deals, '<div class="page-header-actions">');
        $guidePosition = strpos((string) $deals, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_DEALS");
        $newDealPosition = strpos((string) $deals, 'href="deal_create.php"');
        $filtersPosition = strpos((string) $deals, '<div class="filters-card deals-toolbar-card">');
        $filterActionsPosition = strpos((string) $deals, '<div class="filter-actions">');

        $this->assertIsInt($headerActionsPosition);
        $this->assertIsInt($filtersPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($newDealPosition);
        $this->assertGreaterThan($headerActionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $newDealPosition);
        $this->assertLessThan($filtersPosition, $guidePosition);
        $this->assertLessThan($filterActionsPosition, $guidePosition);
    }

    public function testAssigneeFilterUsesTheActiveWorkspaceMemberDirectory(): void
    {
        $deals = file_get_contents(__DIR__ . '/../../../public/deals.php');

        $this->assertNotFalse($deals);
        $this->assertStringContainsString('use CRM\\Services\\ContactAssignmentAccessService;', (string) $deals);
        $this->assertStringContainsString('(new ContactAssignmentAccessService())->getAssignableUsers($workspaceId)', (string) $deals);
        $this->assertStringContainsString('<option value="">All workspace members</option>', (string) $deals);
        $this->assertStringNotContainsString('SELECT id, email FROM users ORDER BY email ASC', (string) $deals);
    }
}
