<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class SecondWavePageGuideVideoTest extends TestCase
{
    /**
     * @dataProvider secondWavePageGuideProvider
     */
    public function testSecondWavePageGuideButtonsUseSharedConditionalModal(
        string $file,
        string $constant,
        string $videoVariable,
        string $beforeMarker,
        string $afterMarker
    ): void {
        $page = file_get_contents(__DIR__ . '/../../../public/' . $file);

        $this->assertNotFalse($page);
        $page = (string) $page;

        $this->assertStringContainsString('MarketplacePageExplainerService::' . $constant, $page);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::' . $constant, $page);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', $page);
        $this->assertStringContainsString('if ($' . $videoVariable . " !== ''):", $page);
        $this->assertStringContainsString('PageGuideVideoUi::button(MarketplacePageExplainerService::' . $constant, $page);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::' . $constant, $page);

        $beforePosition = strpos($page, $beforeMarker);
        $guidePosition = strpos($page, 'PageGuideVideoUi::button(MarketplacePageExplainerService::' . $constant);
        $afterPosition = strpos($page, $afterMarker, (int) $guidePosition);

        $this->assertIsInt($beforePosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($afterPosition);
        $this->assertGreaterThan($beforePosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $afterPosition);
    }

    public static function secondWavePageGuideProvider(): array
    {
        return [
            'lead_scoring' => ['lead_scoring.php', 'PAGE_LEAD_SCORING', 'leadScoringGuideVideoUrl', 'Rule Scoring</h1>', 'ML Models'],
            'enrichment_dashboard' => ['enrichment_dashboard.php', 'PAGE_ENRICHMENT_DASHBOARD', 'enrichmentDashboardGuideVideoUrl', 'Data Enrichment Dashboard</h1>', 'Back to Contacts'],
            'predictive_analytics' => ['predictive_analytics.php', 'PAGE_PREDICTIVE_ANALYTICS', 'predictiveAnalyticsGuideVideoUrl', 'Predictive Analytics Dashboard</h1>', '<!-- Summary Cards -->'],
            'workflow_analytics' => ['workflow_analytics.php', 'PAGE_WORKFLOW_ANALYTICS', 'workflowAnalyticsGuideVideoUrl', 'Apply', 'function applyDateFilter()'],
            'attribution_reports' => ['attribution_reports.php', 'PAGE_ATTRIBUTION_REPORTS', 'attributionReportsGuideVideoUrl', 'Apply', '</form>'],
            'draft_reviews' => ['draft_reviews.php', 'PAGE_DRAFT_REVIEWS', 'draftReviewsGuideVideoUrl', 'Draft Reviews</h1>', 'Manage Templates'],
            'email_assistant_runs' => ['email_assistant_runs.php', 'PAGE_EMAIL_ASSISTANT_RUNS', 'emailAssistantRunsGuideVideoUrl', '<div class="page-header-actions">', '<div class="content-card"'],
            'commercial_approvals' => ['commercial_approvals.php', 'PAGE_COMMERCIAL_APPROVALS', 'commercialApprovalsGuideVideoUrl', '<div class="page-header-actions">', 'AI learning review'],
        ];
    }
}
