<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class InvoicesPageGuideVideoTest extends TestCase
{
    public function testInvoicesPageGuideButtonIsRenderedAfterInvoiceFilters(): void
    {
        $invoices = file_get_contents(__DIR__ . '/../../../public/invoices.php');

        $this->assertNotFalse($invoices);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_INVOICES', (string) $invoices);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $invoices);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $invoices);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_INVOICES', (string) $invoices);

        $filtersPosition = strpos((string) $invoices, '<div class="filters-card">');
        $filterActionsPosition = strpos((string) $invoices, '<div class="filter-actions">');
        $filterButtonPosition = strpos((string) $invoices, '<button type="submit" class="btn-premium-primary">');
        $guidePosition = strpos((string) $invoices, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_INVOICES");

        $this->assertIsInt($filtersPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertIsInt($filterButtonPosition);
        $this->assertIsInt($guidePosition);
        $this->assertGreaterThan($filtersPosition, $filterActionsPosition);
        $this->assertGreaterThan($filterActionsPosition, $filterButtonPosition);
        $this->assertGreaterThan($filterButtonPosition, $guidePosition);
    }
}
