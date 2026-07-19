<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class TagsPageGuideVideoTest extends TestCase
{
    public function testTagsPageGuideButtonIsRenderedBesideCreateTagAction(): void
    {
        $tags = file_get_contents(__DIR__ . '/../../../public/tags.php');

        $this->assertNotFalse($tags);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_TAGS', (string) $tags);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $tags);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $tags);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_TAGS', (string) $tags);

        $actionsPosition = strpos((string) $tags, '<div class="page-header-actions">');
        $guidePosition = strpos((string) $tags, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_TAGS");
        $createTagPosition = strpos((string) $tags, 'href="tag_create.php"');

        $this->assertIsInt($actionsPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($createTagPosition);
        $this->assertGreaterThan($actionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $createTagPosition);
    }
}
