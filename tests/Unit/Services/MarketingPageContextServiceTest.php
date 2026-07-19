<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\ClarityPageInsightService;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\MarketingPageContextService;
use CRM\Services\MarketingUi;
use PHPUnit\Framework\TestCase;

class MarketingPageContextServiceTest extends TestCase
{
    public function testMarketingPageContextExistsForAllInternalMarketingPages(): void
    {
        foreach (MarketingUi::internalPageFilenames() as $filename) {
            $context = MarketingPageContextService::contextForPage($filename);
            $this->assertIsArray($context, $filename);
            $this->assertNotSame('', (string) ($context['founder_job'] ?? ''), $filename);
            $this->assertNotSame('', (string) ($context['stage_key'] ?? ''), $filename);
            $this->assertNotSame('', (string) ($context['one_next_step'] ?? ''), $filename);
            $this->assertNotSame('', (string) ($context['plugin_feature'] ?? ''), $filename);
            $this->assertNotSame('', (string) ($context['plugin_family'] ?? ''), $filename);
        }
    }

    public function testMarketingPageContextExposesPluginFamily(): void
    {
        $content = MarketingPageContextService::contextForPage('marketing_content.php');
        $landing = MarketingPageContextService::contextForPage('marketing_landing_pages.php');
        $performance = MarketingPageContextService::contextForPage('marketing_performance.php');

        $this->assertSame(MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA, $content['plugin_feature'] ?? null);
        $this->assertSame('Social Media', $content['plugin_family'] ?? null);
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_DESIGN, $landing['plugin_feature'] ?? null);
        $this->assertSame('Design', $landing['plugin_family'] ?? null);
        $this->assertSame(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, $performance['plugin_feature'] ?? null);
        $this->assertSame('Campaign Manager', $performance['plugin_family'] ?? null);
    }

    public function testSegmentsPageUsesFounderLanguage(): void
    {
        $context = MarketingPageContextService::contextForPage('marketing_segments.php');

        $this->assertSame('Choose who this campaign is for', $context['founder_job'] ?? null);
        $this->assertSame('audiences', $context['stage_key'] ?? null);
        $this->assertSame('Audience segment', $context['expert_object_name'] ?? null);
        $this->assertSame('Use this audience in a campaign plan.', $context['one_next_step'] ?? null);
    }

    public function testClarityUsesMarketingContextInsteadOfGenericInsight(): void
    {
        $insight = (new ClarityPageInsightService())->openingInsight(0, 0, 'marketing_segments.php');

        $this->assertSame('marketing_page_context', $insight['kind'] ?? null);
        $this->assertSame('Choose who this campaign is for', $insight['title'] ?? null);
        $this->assertArrayHasKey('marketing_page_context', $insight);
    }

    public function testCommandCenterMovesRecommendationsIntoClarityContext(): void
    {
        $context = MarketingPageContextService::contextForPage('marketing.php');

        $this->assertSame(false, $context['ui_policy']['visible_recommendations'] ?? null);
        $this->assertSame('clarity', $context['ui_policy']['recommendation_surface'] ?? null);
        $this->assertNotEmpty($context['clarity_recommendation_context']['sources'] ?? []);

        $insight = (new ClarityPageInsightService())->openingInsight(0, 0, 'marketing.php');
        $this->assertContains(
            'Clarity recommendation role: Marketing recommendations belong in Clarity, while the command center stays visual and procedural.',
            $insight['bullets'] ?? []
        );
    }
}
