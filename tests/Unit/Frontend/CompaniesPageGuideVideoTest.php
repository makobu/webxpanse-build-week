<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class CompaniesPageGuideVideoTest extends TestCase
{
    public function testCompaniesPageGuideButtonIsRenderedAfterCompanyFilters(): void
    {
        $companies = file_get_contents(__DIR__ . '/../../../public/companies.php');

        $this->assertNotFalse($companies);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_COMPANIES', (string) $companies);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $companies);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $companies);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_COMPANIES', (string) $companies);

        $filtersPosition = strpos((string) $companies, '<div class="filters-card">');
        $filterActionsPosition = strpos((string) $companies, '<div class="filter-actions">');
        $searchButtonPosition = strpos((string) $companies, '<button type="submit" class="btn-premium-primary">');
        $guidePosition = strpos((string) $companies, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_COMPANIES");

        $this->assertIsInt($filtersPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertIsInt($searchButtonPosition);
        $this->assertIsInt($guidePosition);
        $this->assertGreaterThan($filtersPosition, $filterActionsPosition);
        $this->assertGreaterThan($filterActionsPosition, $searchButtonPosition);
        $this->assertGreaterThan($searchButtonPosition, $guidePosition);
    }
}
