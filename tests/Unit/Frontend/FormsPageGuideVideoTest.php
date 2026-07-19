<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class FormsPageGuideVideoTest extends TestCase
{
    public function testFormsPageGuideButtonIsRenderedBesideNewFormAction(): void
    {
        $forms = file_get_contents(__DIR__ . '/../../../public/forms.php');

        $this->assertNotFalse($forms);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_FORMS', (string) $forms);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $forms);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $forms);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_FORMS', (string) $forms);

        $actionsPosition = strpos((string) $forms, '<div class="forms-hero-actions">');
        $guidePosition = strpos((string) $forms, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_FORMS");
        $newFormPosition = strpos((string) $forms, '/form_edit.php" class="btn-premium-primary"');

        $this->assertIsInt($actionsPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($newFormPosition);
        $this->assertGreaterThan($actionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $newFormPosition);
    }

    public function testFormsPageKeepsCompactListLayout(): void
    {
        $forms = file_get_contents(__DIR__ . '/../../../public/forms.php');

        $this->assertNotFalse($forms);
        $this->assertStringContainsString('Build lead capture forms and review submissions.', (string) $forms);
        $this->assertStringNotContainsString('forms-stats-grid', (string) $forms);
        $this->assertStringNotContainsString('Form Library', (string) $forms);
        $this->assertStringNotContainsString('Add another', (string) $forms);
        $this->assertStringNotContainsString('premium-list-meta', (string) $forms);
        $this->assertStringContainsString('class="forms-icon-actions"', (string) $forms);
        $this->assertStringContainsString('aria-label="Preview <?php echo htmlspecialchars', (string) $forms);
        $this->assertStringContainsString('aria-label="Edit <?php echo htmlspecialchars', (string) $forms);
        $this->assertStringContainsString('aria-label="Delete <?php echo htmlspecialchars', (string) $forms);
    }
}
