<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class HighPriorityPageGuideVideoTest extends TestCase
{
    /**
     * @dataProvider highPriorityPageGuideProvider
     */
    public function testHighPriorityPageGuideButtonsUseSharedConditionalModal(
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

    public static function highPriorityPageGuideProvider(): array
    {
        return [
            'workflows' => ['workflows.php', 'PAGE_WORKFLOWS', 'workflowsGuideVideoUrl', '<div class="page-header-actions">', 'workflow_approvals.php'],
            'calendar' => ['calendar.php', 'PAGE_CALENDAR', 'calendarGuideVideoUrl', '<div class="page-header-actions">', 'workspace_skills.php?module=calendar_meetings'],
            'campaigns' => ['campaigns.php', 'PAGE_CAMPAIGNS', 'campaignsGuideVideoUrl', '<div class="page-header-actions">', '#create-campaign'],
            'reports' => ['reports.php', 'PAGE_REPORTS', 'reportsGuideVideoUrl', '<div class="page-header-actions">', 'report_create.php'],
            'docs' => ['docs.php', 'PAGE_DOCS', 'docsGuideVideoUrl', 'Feature Documentation</h1>', '<?php foreach ($grouped as $category => $items): ?>'],
            'users' => ['users.php', 'PAGE_USERS', 'usersGuideVideoUrl', '<div class="page-header-actions">', 'departments.php'],
            'roles' => ['roles.php', 'PAGE_ROLES', 'rolesGuideVideoUrl', '<div class="admin-hero-actions">', 'users.php'],
            'ai_control_center' => ['ai_control_center.php', 'PAGE_AI_CONTROL_CENTER', 'aiControlCenterGuideVideoUrl', '<div class="page-header-actions">', 'ai_learning_review.php'],
            'hr_analytics' => ['hr_analytics.php', 'PAGE_HR_ANALYTICS', 'hrAnalyticsGuideVideoUrl', '<div class="hr-instrument-panel">', '<details class="hr-refine-menu">'],
        ];
    }
}
