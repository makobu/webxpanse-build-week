<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class ContactsPageGuideVideoTest extends TestCase
{
    public function testContactsPageGuideButtonIsRenderedWithStageFilters(): void
    {
        $contacts = file_get_contents(__DIR__ . '/../../../public/contacts.php');

        $this->assertNotFalse($contacts);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_CONTACTS', (string) $contacts);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $contacts);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $contacts);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_CONTACTS', (string) $contacts);
        $this->assertStringContainsString('contacts-guide-stage-action', (string) $contacts);

        $filterCardPosition = strpos((string) $contacts, '<div class="filters-card">');
        $stageStatsPosition = strpos((string) $contacts, '<div class="stage-stats">');
        $tablePosition = strpos((string) $contacts, '<div class="contacts-table-card">');
        $guidePosition = strpos((string) $contacts, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_CONTACTS");

        $this->assertIsInt($filterCardPosition);
        $this->assertIsInt($stageStatsPosition);
        $this->assertIsInt($tablePosition);
        $this->assertIsInt($guidePosition);
        $this->assertGreaterThan($filterCardPosition, $stageStatsPosition);
        $this->assertGreaterThan($stageStatsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $tablePosition);
    }
}
