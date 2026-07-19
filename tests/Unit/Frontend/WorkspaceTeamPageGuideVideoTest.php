<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class WorkspaceTeamPageGuideVideoTest extends TestCase
{
    public function testWorkspaceTeamPartialUsesConditionalPageGuideVideo(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../views/partials/settings_workspace_governance.php');

        $this->assertNotFalse($partial);
        $partial = (string) $partial;

        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM', $partial);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', $partial);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', $partial);
        $this->assertStringContainsString('PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM', $partial);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM', $partial);
        $this->assertStringContainsString('Workspace Team onboarding guide', $partial);
        $this->assertStringContainsString('<span>Onboarding</span>', $partial);

        $summaryPosition = strpos($partial, 'workspace-governance-summary-card');
        $guidePosition = strpos($partial, 'PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_WORKSPACE_TEAM');
        $ownershipPosition = strpos($partial, '<span>Ownership</span>');

        $this->assertIsInt($summaryPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($ownershipPosition);
        $this->assertGreaterThan($summaryPosition, $guidePosition);
        $this->assertLessThan($ownershipPosition, $guidePosition);
    }
}
