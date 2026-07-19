<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Services\MarketingPageGuideUi;
use CRM\Services\MarketplacePageExplainerService;
use PHPUnit\Framework\TestCase;

class MarketingPageGuideVideoTest extends TestCase
{
    public function testMarketingGuideDefinitionsCoverInternalPagesAndExcludePublicLandingPages(): void
    {
        $definitions = MarketingPageGuideUi::pageDefinitions();

        $expectedPages = [
            'marketing.php',
            'marketing_onboarding.php',
            'marketing_system_map.php',
            'marketing_action_router.php',
            'marketing_relationships.php',
            'marketing_execution.php',
            'marketing_decisions.php',
            'marketing_content.php',
            'marketing_context.php',
            'marketing_admin.php',
            'marketing_content_edit.php',
            'marketing_content_view.php',
            'marketing_briefs.php',
            'marketing_brief_edit.php',
            'marketing_brief_view.php',
            'marketing_segments.php',
            'marketing_segment_edit.php',
            'marketing_segment_view.php',
            'marketing_journeys.php',
            'marketing_journey_edit.php',
            'marketing_journey_view.php',
            'marketing_calendar.php',
            'marketing_reviews.php',
            'marketing_launch_checklists.php',
            'marketing_roadmap.php',
            'marketing_persona_offer_matrix.php',
            'marketing_brand.php',
            'marketing_personas.php',
            'marketing_seo.php',
            'marketing_landing_pages.php',
            'marketing_landing_page_edit.php',
            'marketing_landing_page_view.php',
            'marketing_assets.php',
            'marketing_distribution.php',
            'marketing_distribution_bundle.php',
            'marketing_channel_exports.php',
            'marketing_utm_links.php',
            'marketing_email_runs.php',
            'marketing_performance.php',
            'marketing_handoffs.php',
            'marketing_weekly_report.php',
            'marketing_monthly_report.php',
            'marketing_assistants.php',
            'marketing_quality.php',
            'marketing_creative.php',
            'marketing_operations.php',
        ];

        foreach ($expectedPages as $page) {
            $this->assertArrayHasKey($page, $definitions);
            $this->assertFileExists(__DIR__ . '/../../../public/' . $page);
            $pageSource = (string) file_get_contents(__DIR__ . '/../../../public/' . $page);
            $this->assertStringContainsString('page-header-actions', $pageSource, $page);
        }

        $this->assertArrayNotHasKey('marketing_landing_page_preview.php', $definitions);
        $this->assertArrayNotHasKey('marketing_landing_public.php', $definitions);
    }

    public function testMarketingGuideDefinitionsAreAvailableInPageVideoSettings(): void
    {
        $settingsDefinitions = MarketplacePageExplainerService::settingsPageDefinitions();

        foreach (MarketingPageGuideUi::pageDefinitions() as $definition) {
            $this->assertArrayHasKey($definition['key'], $settingsDefinitions);
            $this->assertSame($definition['label'], $settingsDefinitions[$definition['key']]['label']);
        }
    }

    public function testMarketingGuideHelperIsWiredThroughBaseLayoutOnly(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../../views/layouts/base.php');
        $preview = file_get_contents(__DIR__ . '/../../../public/marketing_landing_page_preview.php');
        $public = file_get_contents(__DIR__ . '/../../../public/marketing_landing_public.php');

        $this->assertNotFalse($layout);
        $this->assertStringContainsString('MarketingPageGuideUi::decorateContent', (string) $layout);
        $this->assertStringContainsString('$layoutCurrentPage', (string) $layout);

        $this->assertNotFalse($preview);
        $this->assertNotFalse($public);
        $this->assertStringNotContainsString('MarketingPageGuideUi', (string) $preview);
        $this->assertStringNotContainsString('MarketingPageGuideUi', (string) $public);
    }
}
