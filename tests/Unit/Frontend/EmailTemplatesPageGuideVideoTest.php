<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class EmailTemplatesPageGuideVideoTest extends TestCase
{
    public function testEmailTemplatesPageGuideButtonIsRenderedBesideTemplateHeaderActions(): void
    {
        $templates = file_get_contents(__DIR__ . '/../../../public/email_templates.php');

        $this->assertNotFalse($templates);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_EMAIL_TEMPLATES', (string) $templates);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $templates);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $templates);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_EMAIL_TEMPLATES', (string) $templates);

        $headerActionsPosition = strpos((string) $templates, '<div class="page-header-actions">');
        $guidePosition = strpos((string) $templates, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_EMAIL_TEMPLATES");
        $newTemplatePosition = strpos((string) $templates, 'href="email_template_create.php"');
        $filterActionsPosition = strpos((string) $templates, '<div class="filter-actions">');
        $filterButtonPosition = strpos((string) $templates, '<button type="submit" class="btn-premium-primary">');

        $this->assertIsInt($headerActionsPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertIsInt($filterButtonPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($newTemplatePosition);
        $this->assertStringNotContainsString('href="email_template_library.php"', (string) $templates);
        $this->assertGreaterThan($headerActionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $newTemplatePosition);
        $this->assertLessThan($filterActionsPosition, $guidePosition);
        $this->assertLessThan($filterButtonPosition, $guidePosition);
    }

    public function testRetiredGenericLibraryIsAbsentFromTemplateAndWorkflowSurfaces(): void
    {
        $templates = (string) file_get_contents(__DIR__ . '/../../../public/email_templates.php');
        $templateView = (string) file_get_contents(__DIR__ . '/../../../public/email_template_view.php');
        $workflowCreate = (string) file_get_contents(__DIR__ . '/../../../public/workflow_create.php');

        $this->assertStringNotContainsString('href="email_template_library.php"', $templates);
        $this->assertStringNotContainsString('href="email_template_library.php"', $templateView);
        $this->assertStringNotContainsString("api/email_templates/library.php", $templateView);
        $this->assertStringContainsString('COALESCE(et.is_library, 0) = 0', $workflowCreate);
        $this->assertStringNotContainsString('is_library = 1 AND', $workflowCreate);
    }
}
