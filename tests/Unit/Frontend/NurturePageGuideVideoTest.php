<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class NurturePageGuideVideoTest extends TestCase
{
    public function testNurturePageGuideButtonIsRenderedBesideFollowUpPlansAction(): void
    {
        $nurture = file_get_contents(__DIR__ . '/../../../public/nurture.php');

        $this->assertNotFalse($nurture);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_NURTURE', (string) $nurture);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $nurture);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $nurture);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_NURTURE', (string) $nurture);

        $headerPosition = strpos((string) $nurture, '<div class="page-header nurture-workbench-header">');
        $guidePosition = strpos((string) $nurture, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_NURTURE");
        $followUpPlansPosition = strpos((string) $nurture, '<button class="btn-premium-primary" type="button" data-care-plan-open>');
        $queuePosition = strpos((string) $nurture, 'data-care-queue');
        $filterPosition = strpos((string) $nurture, '<details class="filters-card nurture-filter-panel" data-nurture-filter-toggle>');
        $drawerPosition = strpos((string) $nurture, 'data-care-plan-drawer');

        $this->assertIsInt($headerPosition);
        $this->assertIsInt($queuePosition);
        $this->assertIsInt($filterPosition);
        $this->assertIsInt($drawerPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($followUpPlansPosition);
        $this->assertGreaterThan($headerPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $followUpPlansPosition);
        $this->assertGreaterThan($followUpPlansPosition, $filterPosition);
        $this->assertLessThan($queuePosition, $guidePosition);
        $this->assertLessThan($queuePosition, $filterPosition);
        $this->assertLessThan($drawerPosition, $queuePosition);
    }

    public function testNurturePagesExposeCareWorkbenchLineageAndCleanSeparators(): void
    {
        $nurture = (string) file_get_contents(__DIR__ . '/../../../public/nurture.php');
        $detail = (string) file_get_contents(__DIR__ . '/../../../public/nurture_view.php');
        $programs = (string) file_get_contents(__DIR__ . '/../../../public/nurture_programs.php');
        $handoffs = (string) file_get_contents(__DIR__ . '/../../../public/marketing_handoffs.php');
        $contact = (string) file_get_contents(__DIR__ . '/../../../public/contact_view.php');
        $deal = (string) file_get_contents(__DIR__ . '/../../../public/deal_view.php');
        $marketingModule = (string) file_get_contents(__DIR__ . '/../../../modules/Marketing.php');

        $this->assertStringContainsString('nurture-workbench-header', $nurture);
        $this->assertStringContainsString('page-header nurture-workbench-header', $nurture);
        $this->assertStringContainsString('data-nurture-care-queue', $nurture);
        $this->assertStringContainsString('data-care-queue', $nurture);
        $this->assertStringContainsString('table-card nurture-queue-shell', $nurture);
        $this->assertStringContainsString('stage-stats nurture-tabs', $nurture);
        $this->assertStringContainsString('data-care-automation-status', $nurture);
        $this->assertStringContainsString('Why automation is limited', $nurture);
        $this->assertStringContainsString('data-nurture-care-row', $nurture);
        $this->assertStringContainsString('data-care-row', $nurture);
        $this->assertStringContainsString('data-nurture-filter-toggle', $nurture);
        $this->assertStringContainsString('nurture-filter-panel', $nurture);
        $this->assertStringContainsString('filters-card nurture-filter-panel', $nurture);
        $this->assertStringContainsString('<summary>', $nurture);
        $this->assertStringContainsString('nurture-empty-state', $nurture);
        $this->assertStringContainsString('nurture-card-list', $nurture);
        $this->assertStringContainsString('nurture-selected-action-bar', $nurture);
        $this->assertStringContainsString('data-care-plan-drawer', $nurture);
        $this->assertStringContainsString('data-care-plan-create', $nurture);
        $this->assertStringContainsString('name="plan_first_touch_delay_days"', $nurture);
        $this->assertStringContainsString('name="plan_preferred_channel"', $nurture);
        $this->assertStringContainsString('name="plan_default_touch_type"', $nurture);
        $this->assertStringContainsString('name="plan_touch_guidance"', $nurture);
        $this->assertStringContainsString('data-first-touch-delay', $nurture);
        $this->assertStringContainsString('data-channel', $nurture);
        $this->assertStringContainsString('data-touch-type', $nurture);
        $this->assertStringContainsString('data-care-check-in', $nurture);
        $this->assertStringContainsString('Follow-up Plans', $nurture);
        $this->assertStringContainsString('Use a plan when several customers need the same check-in rhythm.', $nurture);
        $this->assertStringContainsString('data-nurture-selection-count', $nurture);
        $this->assertStringContainsString('data-nurture-program-control', $nurture);
        $this->assertStringNotContainsString('nurture-cockpit', $nurture);
        $this->assertStringNotContainsString('nurture-summary-grid', $nurture);
        $this->assertStringNotContainsString('nurture-insights', $nurture);
        $this->assertStringNotContainsString('nurture-programs-help', $nurture);
        $this->assertStringNotContainsString('Care programs are reusable follow-up paths', $nurture);
        $this->assertStringContainsString('Why this customer is here', $detail);
        $this->assertStringContainsString('Suggested next step', $detail);
        $this->assertStringContainsString('Where this came from', $detail);
        $this->assertStringContainsString('data-care-settings-panel', $detail);
        $this->assertStringNotContainsString('Care Readiness', $detail);
        $this->assertStringNotContainsString('Outreach Handoff Lineage', $detail);
        $this->assertStringContainsString('&middot;', $detail);
        $this->assertStringNotContainsString('Â', $detail);
        $this->assertStringContainsString('program-summary', $programs);
        $this->assertStringContainsString('active_enrollment_count', $programs);
        $this->assertStringContainsString('name="first_touch_delay_days"', $programs);
        $this->assertStringContainsString('name="preferred_channel"', $programs);
        $this->assertStringContainsString('name="default_touch_type"', $programs);
        $this->assertStringContainsString('name="touch_guidance"', $programs);
        $this->assertStringContainsString('$preferenceSummary', $programs);
        $this->assertStringContainsString('data-follow-up-plan-row', $programs);
        $this->assertStringContainsString('data-follow-up-plan-edit', $programs);
        $this->assertStringContainsString('data-follow-up-plan-delete', $programs);
        $this->assertStringContainsString('data-follow-up-plan-archive', $programs);
        $this->assertStringContainsString('Follow-up plan updated.', $programs);
        $this->assertStringContainsString('Follow-up plan deleted.', $programs);
        $this->assertStringContainsString('Follow-up plan archived because it has customer history.', $programs);
        $this->assertStringContainsString('No archived follow-up plans.', $programs);
        $this->assertStringContainsString('marketing-handoff-nurture', $handoffs);
        $this->assertStringContainsString('Open Customer Care', $handoffs);
        $this->assertStringContainsString('Finish sales handoff', $handoffs);
        $this->assertStringContainsString('Customer Care', $contact);
        $this->assertStringContainsString('Customer Care', $deal);
        $this->assertStringContainsString('customer_nurture_profiles', $marketingModule);
        $this->assertStringContainsString('open_customer_nurture', $marketingModule);
        $this->assertStringNotContainsString('nurture_settings.php', $nurture . $programs);
        $this->assertStringNotContainsString('settings.php?tab=customer_care', $nurture . $programs);
    }
}
