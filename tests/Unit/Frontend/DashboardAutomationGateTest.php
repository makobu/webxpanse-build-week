<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class DashboardAutomationGateTest extends TestCase
{
    public function testAutomationReadinessCardDoesNotUseLegacyReadinessFallback(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString(
            "\$showAutomationReadinessCard = \\CRM\\Authorization::hasAccessProfilePermission('feature.automation_readiness_card', \$user);",
            (string) $dashboard
        );
        $this->assertStringNotContainsString(
            "\$showAutomationReadinessCard = \\CRM\\Authorization::can('feature.automation_readiness_card')",
            (string) $dashboard
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$showAutomationReadinessCard\s*=\s*[^;]*\$hasLegacyAutomationReadiness/',
            (string) $dashboard
        );
    }

    public function testAutomationBatteryUsesExplicitIdleAndLoadingStates(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString("'status_label' => 'Ready to check'", (string) $dashboard);
        $this->assertStringContainsString("'headline_label' => 'Not checked yet'", (string) $dashboard);
        $this->assertStringContainsString("'summary' => 'No background calculation is running.", (string) $dashboard);
        $this->assertStringContainsString('<button type="button" class="hero-automation-shell" data-automation-battery-shell data-automation-battery-load', (string) $dashboard);
        $this->assertStringContainsString('Check your automation score. ', (string) $dashboard);
        $this->assertStringContainsString('Not checked yet.', (string) $dashboard);
        $this->assertStringContainsString('Refresh failed. Showing the last saved score.', (string) $dashboard);
        $this->assertStringContainsString('Your workspace is just getting started. A few basics still need attention.', (string) $dashboard);
        $this->assertStringContainsString('Your workspace is making progress, but a few important pieces still need strengthening.', (string) $dashboard);
        $this->assertStringContainsString('Most of your workspace is running well. Only a few gaps remain.', (string) $dashboard);
        $this->assertStringContainsString('Your workspace is in strong shape and ready to support more automated work.', (string) $dashboard);
        $this->assertStringContainsString('data-automation-battery-refresh-tooltip role="tooltip"', (string) $dashboard);
        $this->assertStringContainsString('data-automation-battery-status-tooltip role="tooltip"', (string) $dashboard);
        $this->assertStringContainsString('aria-describedby="automation-battery-refresh-tooltip"', (string) $dashboard);
        $this->assertStringContainsString('aria-describedby="automation-battery-status-tooltip"', (string) $dashboard);
        $this->assertMatchesRegularExpression('/\.hero-automation-shell\s*\{[^}]*overflow:\s*visible;/s', (string) $dashboard);
        $this->assertMatchesRegularExpression('/\.hero-automation-shell\s*\{[^}]*margin-right:\s*0\.35rem;/s', (string) $dashboard);
        $this->assertMatchesRegularExpression('/\.hero-automation-shell::after\s*\{[^}]*pointer-events:\s*none;/s', (string) $dashboard);
        $this->assertStringContainsString('Refresh automation readiness', (string) $dashboard);
        $this->assertStringContainsString('fa-rotate-right', (string) $dashboard);
        $this->assertStringContainsString('Refreshing automation readiness', (string) $dashboard);
        $this->assertStringContainsString('Automation readiness refreshed', (string) $dashboard);
        $this->assertStringContainsString('Retry automation readiness refresh', (string) $dashboard);
        $this->assertStringNotContainsString('Refresh automation level', (string) $dashboard);
        $this->assertStringNotContainsString('dashboard-affordability-button', (string) $dashboard);
        $this->assertStringNotContainsString('Pay what you can', (string) $dashboard);
        $this->assertStringContainsString('automationBatteryShouldIdleRefresh', (string) $dashboard);
        $this->assertStringContainsString('automationBatteryRefreshPolicy', (string) $dashboard);
        $this->assertStringContainsString('$automationBatteryIdleRefreshDue', (string) $dashboard);
        $this->assertStringContainsString("'cadence' => 'six_hours'", (string) $dashboard);
        $this->assertStringContainsString("'automatic_enabled' => true", (string) $dashboard);
        $this->assertStringNotContainsString('&& (!$automationBatteryHasSnapshot || !empty($automationBattery[\'is_stale\']))', (string) $dashboard);
        $this->assertStringNotContainsString('automation layers need stronger evidence', (string) $dashboard);
        $this->assertStringNotContainsString('setup and trust signals are still thin', (string) $dashboard);
        $this->assertStringNotContainsString('title="<?php echo htmlspecialchars($automationBatteryStatusTooltip); ?>"', (string) $dashboard);
        $this->assertStringNotContainsString('hero-automation-level-trigger', (string) $dashboard);
        $this->assertStringNotContainsString('hero-automation-updated', (string) $dashboard);
        $this->assertStringNotContainsString('data-automation-battery-updated', (string) $dashboard);
        $this->assertStringNotContainsString("'status_label' => 'Calculating'", (string) $dashboard);
        $this->assertStringNotContainsString('Check automation level', (string) $dashboard);
        $this->assertStringNotContainsString('Loading automation level</span>', (string) $dashboard);
        $this->assertStringNotContainsString("?? 'Charging'", (string) $dashboard);
        $this->assertStringNotContainsString("|| 'Charging'", (string) $dashboard);
    }

    public function testTtfvMetricHasHelpTooltip(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('class="ttfv-help"', (string) $dashboard);
        $this->assertStringContainsString('Time to first value', (string) $dashboard);
        $this->assertStringContainsString('role="tooltip"', (string) $dashboard);
    }

    public function testFounderCommandCenterKeepsOneConstraintAndThreeQuietLanes(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('Your operating rhythm', (string) $dashboard);
        $this->assertStringContainsString('Constraint to resolve', (string) $dashboard);
        $this->assertStringContainsString('Needs you', (string) $dashboard);
        $this->assertStringContainsString('Handled for you', (string) $dashboard);
        $this->assertStringContainsString('Growth move', (string) $dashboard);
        $this->assertStringContainsString('data-founder-command-growth-learning', (string) $dashboard);
        $this->assertStringContainsString('growthLearning.has_conclusion', (string) $dashboard);
        $this->assertStringContainsString('function founderCommandHumanize(value)', (string) $dashboard);
        $this->assertStringContainsString("contextNotes.push('Missing: ' + label)", (string) $dashboard);
        $this->assertStringContainsString('commandCenter.needs_you) ? commandCenter.needs_you.slice(0, 3)', (string) $dashboard);
        $this->assertStringNotContainsString('Today’s Revenue Focus', (string) $dashboard);
        $this->assertStringNotContainsString('$outcomeDailyFocus', (string) $dashboard);
    }

    public function testFounderCommandCenterHydratesFromDeterministicEndpoint(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('data-founder-command-center', (string) $dashboard);
        $this->assertStringContainsString('founderCommandCenterUrl', (string) $dashboard);
        $this->assertStringContainsString("apiUrl('dashboard/founder_command_center.php')", (string) $dashboard);
        $this->assertStringContainsString('function loadFounderCommandCenter()', (string) $dashboard);
        $this->assertStringContainsString('runWhenIdle(loadFounderCommandCenter, 350)', (string) $dashboard);
        $this->assertStringContainsString('(new FounderCommandCenterAccessService())->canView($user, $workspaceId)', (string) $dashboard);
        $this->assertStringNotContainsString('data-beginner-guidance-card', (string) $dashboard);
        $this->assertStringNotContainsString('beginnerGuidanceUrl', (string) $dashboard);
    }

    public function testFounderCommandCenterUsesVersionedQuietResponsiveStyles(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/dashboard-premium.css');

        $this->assertNotFalse($dashboard);
        $this->assertNotFalse($css);
        $this->assertStringContainsString("filemtime(__DIR__ . '/assets/css/dashboard-premium.css')", (string) $dashboard);
        $this->assertStringContainsString('.founder-command-center {', (string) $css);
        $this->assertStringContainsString('grid-template-columns: 1.12fr 0.94fr 0.94fr;', (string) $css);
        $this->assertStringContainsString('.founder-command-context-panel {', (string) $css);
        $this->assertStringContainsString('box-sizing: border-box;', (string) $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 640px\).*?\.founder-command-context-panel\s*\{[^}]*position:\s*static;/s',
            (string) $css
        );
    }

    public function testFounderCommandCenterEndpointIsAuthenticatedScopedAndCached(): void
    {
        $endpoint = file_get_contents(__DIR__ . '/../../../api/dashboard/founder_command_center.php');

        $this->assertNotFalse($endpoint);
        $this->assertStringContainsString('if (!Auth::check())', (string) $endpoint);
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'GET'", (string) $endpoint);
        $this->assertStringContainsString('requireAnalyticsWorkspaceId()', (string) $endpoint);
        $this->assertStringContainsString('(new FounderCommandCenterAccessService())->canView($user, $workspaceId)', (string) $endpoint);
        $this->assertStringContainsString('http_response_code(403)', (string) $endpoint);
        $this->assertStringContainsString('ensureScopedUserId($requestedSubjectUserId, $workspaceId)', (string) $endpoint);
        $this->assertStringContainsString("Authorization::can('marketing.read', \$user)", (string) $endpoint);
        $this->assertStringContainsString('getWithCache(', (string) $endpoint);
        $this->assertMatchesRegularExpression('/getWithCache\([\s\S]*?,\s*45\s*\)/', (string) $endpoint);
    }

    public function testSetupProgressHydratesPlainReadinessAfterInitialRender(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('data-plain-readiness-card', (string) $dashboard);
        $this->assertStringContainsString('data-plain-readiness-milestones', (string) $dashboard);
        $this->assertStringContainsString('plainReadinessUrl', (string) $dashboard);
        $this->assertStringContainsString("apiUrl('dashboard/plain_readiness.php')", (string) $dashboard);
        $this->assertStringContainsString('runWhenIdle(loadPlainReadiness, 900)', (string) $dashboard);
        $this->assertStringContainsString('dashboard.readiness.viewed', (string) $dashboard);
        $this->assertStringContainsString('dashboard.readiness.clicked', (string) $dashboard);
        $this->assertStringContainsString('PlainLanguageReadinessService', (string) $dashboard);
        $this->assertStringContainsString('$initialPlainReadiness', (string) $dashboard);
        $this->assertStringContainsString('Loading setup progress...', (string) $dashboard);
        $this->assertStringContainsString("readiness.phase !== 'revenue_momentum'", (string) $dashboard);
        $this->assertStringNotContainsString('data-plain-readiness-coach', (string) $dashboard);
        $this->assertStringNotContainsString('$outcomeProgressItems', (string) $dashboard);
        $this->assertStringNotContainsString('$plainActivationStepLabel($step)', (string) $dashboard);
    }

    public function testSetupProgressCtaRequiresActionableNonDashboardHref(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('data-plain-readiness-cta', (string) $dashboard);
        $this->assertStringContainsString('isActionableGuidanceHref(href)', (string) $dashboard);
        $this->assertStringContainsString('isActionableGuidanceHref(targetHref)', (string) $dashboard);
        $this->assertStringNotContainsString('Stay on dashboard', (string) $dashboard);
    }

    public function testAutomationBatteryRendersLinkedActionMetadata(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('$automationBatteryRenderActionChip', (string) $dashboard);
        $this->assertStringContainsString('data-automation-action-link', (string) $dashboard);
        $this->assertStringContainsString('data-automation-action-key', (string) $dashboard);
        $this->assertStringContainsString("'source' => 'automation_battery'", (string) $dashboard);
        $this->assertStringContainsString('status.top_actions', (string) $dashboard);
        $this->assertStringContainsString('status.top_progress_signals', (string) $dashboard);
        $this->assertStringContainsString('status.context_enrichments', (string) $dashboard);
        $this->assertStringContainsString('var actions = Array.isArray(layer.actions) ? layer.actions : [];', (string) $dashboard);
        $this->assertStringContainsString('var progressSignals = Array.isArray(layer.progress_signals) ? layer.progress_signals : [];', (string) $dashboard);
        $this->assertStringContainsString('function renderAutomationProgressChip(signal, scope)', (string) $dashboard);
        $this->assertStringContainsString('data-automation-progress-key', (string) $dashboard);
        $this->assertStringContainsString('return layer.is_visible !== false;', (string) $dashboard);
        $this->assertStringContainsString('readinessCard.hidden = status.is_visible === false;', (string) $dashboard);
        $this->assertStringContainsString('$automationBatteryRenderProgressChip', (string) $dashboard);
        $this->assertStringContainsString("trackReadinessEvent('dashboard.readiness.clicked'", (string) $dashboard);
        $this->assertStringContainsString("window.location.hash === '#ai-coach'", (string) $dashboard);
    }

    public function testAutomationHealthBandRendersOperationalStatusMetadata(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('$showAutomationHealthBand = $showAutomationReadinessCard && !WorkspaceContext::isDefaultWorkspace($workspaceId);', (string) $dashboard);
        $this->assertStringContainsString('My Automation Readiness while viewing All Users', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-band', (string) $dashboard);
        $this->assertStringContainsString('Automation Health', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-label', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-message', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-score', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-required', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-jobs', (string) $dashboard);
        $this->assertStringContainsString('data-automation-health-refresh', (string) $dashboard);
        $this->assertStringContainsString('No setup checks waiting', (string) $dashboard);
        $this->assertStringContainsString('function applyAutomationHealthBand(status)', (string) $dashboard);
        $this->assertStringContainsString('function markAutomationHealthRefreshFailed()', (string) $dashboard);
        $this->assertStringContainsString('Automation readiness already fresh', (string) $dashboard);
        $this->assertStringContainsString('Automatic checks are off', (string) $dashboard);
        $this->assertStringContainsString('Checking automation health...', (string) $dashboard);
    }

    public function testMobileHomeAllowsReadinessCardPermissionForAutomationBattery(): void
    {
        $mobileHome = file_get_contents(__DIR__ . '/../../../api/mobile/home.php');

        $this->assertNotFalse($mobileHome);
        $this->assertStringContainsString("Authorization::hasAccessProfilePermission('feature.automation_readiness_card', \$user)", (string) $mobileHome);
        $this->assertStringContainsString('FounderCommandCenterService', (string) $mobileHome);
        $this->assertStringContainsString("\$payload['founder_command_center']", (string) $mobileHome);
        $this->assertStringContainsString('(new FounderCommandCenterAccessService())->canView($user, $workspaceId)', (string) $mobileHome);
    }

    public function testFounderCommandCenterHidesSamePageActions(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringNotContainsString('Stay on dashboard', (string) $dashboard);
        $this->assertStringContainsString('function isActionableGuidanceHref(href)', (string) $dashboard);
        $this->assertStringContainsString('target.origin === current.origin && targetPath === currentPath', (string) $dashboard);
        $this->assertStringContainsString('/\/dashboard\.php$/i.test(targetPath)', (string) $dashboard);
        $this->assertStringContainsString('cta_present', (string) $dashboard);
    }

    public function testFounderCommandCenterDoesNotUseAiCoachRecommendationsForHydration(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('function loadFounderCommandCenter()', (string) $dashboard);
        $this->assertStringContainsString('fetch(asyncConfig.founderCommandCenterUrl', (string) $dashboard);
        $this->assertStringNotContainsString('fetch(window.AICoachConfig.recommendationsUrl', (string) $dashboard);
    }

    public function testDashboardDoesNotRenderManualPlatformOpsAutomationButton(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringNotContainsString('Run automation now', (string) $dashboard);
    }

    public function testDashboardExplainerVideoIsActiveVideoOnly(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_DASHBOARD', (string) $dashboard);
        $this->assertStringContainsString("\$dashboardExplainerVideoUrl !== ''", (string) $dashboard);
        $this->assertStringContainsString('data-dashboard-video-open', (string) $dashboard);
        $this->assertStringContainsString('data-dashboard-video-modal', (string) $dashboard);
        $this->assertStringContainsString('<video controls preload="metadata" playsinline data-dashboard-video>', (string) $dashboard);

        $controlsPosition = strpos((string) $dashboard, '<div class="hero-minimal-controls">');
        $filterPosition = strpos((string) $dashboard, '<details class="hero-filter-popover">');
        $guidePosition = strpos((string) $dashboard, '<button type="button" class="hero-guide-btn" data-dashboard-video-open');
        $this->assertIsInt($controlsPosition);
        $this->assertIsInt($filterPosition);
        $this->assertIsInt($guidePosition);
        $this->assertGreaterThan($controlsPosition, $filterPosition);
        $this->assertGreaterThan($filterPosition, $guidePosition);
    }
}
