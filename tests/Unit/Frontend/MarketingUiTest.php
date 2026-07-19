<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Services\MarketingUi;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\MarketingPageQualityMatrix;
use PHPUnit\Framework\TestCase;

class MarketingUiTest extends TestCase
{
    public function testMarketingUiCoversInternalMarketingPagesAndExcludesPublicLandingPage(): void
    {
        $definitions = MarketingUi::internalPageFilenames();
        $publicDir = realpath(__DIR__ . '/../../../public');
        $this->assertIsString($publicDir);

        $marketingPages = array_map(
            'basename',
            glob($publicDir . '/marketing*.php') ?: []
        );
        sort($marketingPages);
        $publicOnlyPages = ['marketing_landing_public.php', 'marketing_track.php', 'marketing_unsubscribe.php'];

        foreach ($marketingPages as $page) {
            if (in_array($page, $publicOnlyPages, true)) {
                $this->assertFalse(MarketingUi::isInternalPage($page));
                continue;
            }

            $this->assertContains($page, $definitions, $page);
            $this->assertTrue(MarketingUi::isInternalPage($page), $page);
        }
    }

    public function testMarketingUiIsWiredThroughBaseLayout(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../../views/layouts/base.php');
        $this->assertNotFalse($layout);

        $this->assertStringContainsString('MarketingUi::isRefinementPage', (string) $layout);
        $this->assertStringContainsString('MarketingUi::stylesheetTag', (string) $layout);
        $this->assertStringContainsString('MarketingUi::decorateContent', (string) $layout);
    }

    public function testDecorateContentAddsRefinementClassesAndKeepsOperatorChromeInternal(): void
    {
        $content = '<link rel="stylesheet" href="assets/css/premium-pages.css"><div class="page-premium"><div class="container"><div class="page-header"><div><h1>Marketing</h1></div><div class="page-header-actions"><a href="#">Action</a></div></div></div></div>';
        $decorated = MarketingUi::decorateContent('marketing.php', $content);

        $this->assertStringContainsString('page-premium marketing-ui-page marketing-ui-refined', $decorated);
        $this->assertStringContainsString('marketing-operator-strip', $decorated);
        $this->assertStringContainsString('Marketing Workspace', $decorated);
        $this->assertStringContainsString('marketing-section-link active', $decorated);
        $this->assertStringContainsString('page-header marketing-page-header', $decorated);
        $this->assertStringContainsString('page-header-actions marketing-page-actions', $decorated);

        $design = MarketingUi::decorateContent('marketing_landing_pages.php', $content);
        $this->assertStringContainsString('Design Workspace', $design);
        $this->assertStringContainsString('aria-label="Design workspace navigation"', $design);
        $this->assertStringContainsString('href="design.php"', $design);
        $this->assertStringNotContainsString('Marketing Workspace / Design', $design);

        $legacy = MarketingUi::decorateContent('campaigns.php', $content);
        $this->assertStringContainsString('page-premium marketing-ui-page marketing-ui-refined', $legacy);
        $this->assertStringContainsString('page-header marketing-page-header', $legacy);
        $this->assertStringContainsString('page-header-actions marketing-page-actions', $legacy);
        $this->assertStringNotContainsString('marketing-operator-strip', $legacy);

        $public = MarketingUi::decorateContent('marketing_landing_public.php', $content);
        $this->assertStringNotContainsString('marketing-ui-page', $public);
        $this->assertStringNotContainsString('marketing-page-header', $public);
        $this->assertStringNotContainsString('marketing-operator-strip', $public);
    }

    public function testEveryMarketingMenuRouteUsesTheRefinedOrSpecializedDesignSurface(): void
    {
        $specializedDesignRoutes = [
            'design.php',
            'social_media.php',
            'forms.php',
            'form_edit.php',
            'form_submissions.php',
            'email_signatures.php',
            'email_signature_create.php',
            'email_signature_edit.php',
        ];
        $coveredRoutes = array_values(array_unique(array_merge(
            MarketingUi::refinementPageFilenames(),
            $specializedDesignRoutes
        )));

        $this->assertSame(
            [],
            array_values(array_diff(MarketingUi::navigationPageFilenames(), $coveredRoutes))
        );
        $this->assertSame(
            ['campaigns.php', 'campaign_view.php', 'attribution_reports.php'],
            MarketingUi::legacyRefinementPageFilenames()
        );
        foreach (MarketingUi::legacyRefinementPageFilenames() as $page) {
            $this->assertTrue(MarketingUi::isRefinementPage($page), $page);
            $this->assertFalse(MarketingUi::isInternalPage($page), $page);
        }
    }

    public function testMarketingUiSectionsMapMajorWorkflowsToPhaseOnePath(): void
    {
        $sections = MarketingUi::sectionDefinitions();

        $this->assertArrayHasKey('home', $sections);
        $this->assertArrayHasKey('setup', $sections);
        $this->assertArrayHasKey('audiences', $sections);
        $this->assertArrayHasKey('campaigns', $sections);
        $this->assertArrayHasKey('content', $sections);
        $this->assertArrayHasKey('landing', $sections);
        $this->assertArrayHasKey('send', $sections);
        $this->assertArrayHasKey('results', $sections);
        $this->assertArrayHasKey('advanced', $sections);
        $this->assertSame('home', MarketingUi::pageSection('marketing.php'));
        $this->assertSame('home', MarketingUi::pageSection('marketing_launch_packet.php'));
        $this->assertSame('setup', MarketingUi::pageSection('marketing_onboarding.php'));
        $this->assertSame('audiences', MarketingUi::pageSection('marketing_segments.php'));
        $this->assertSame('campaigns', MarketingUi::pageSection('marketing_briefs.php'));
        $this->assertSame('content', MarketingUi::pageSection('marketing_content.php'));
        $this->assertSame('content', MarketingUi::pageSection('marketing_campaign_kit.php'));
        $this->assertSame('landing', MarketingUi::pageSection('marketing_landing_page_edit.php'));
        $this->assertSame('send', MarketingUi::pageSection('marketing_distribution.php'));
        $this->assertSame('results', MarketingUi::pageSection('marketing_performance.php'));
        $this->assertSame('results', MarketingUi::pageSection('marketing_launch_proof.php'));
        $this->assertSame('advanced', MarketingUi::pageSection('marketing_system_map.php'));
        $this->assertSame('advanced', MarketingUi::pageSection('marketing_execution.php'));
        $this->assertSame('advanced', MarketingUi::pageSection('marketing_launch_checklists.php'));
        $this->assertSame('advanced', MarketingUi::pageSection('marketing_assets.php'));
        $this->assertSame('advanced', MarketingUi::pageSection('marketing_email_runs.php'));
        $this->assertSame('advanced', MarketingUi::pageSection('marketing_admin.php'));
    }

    public function testCampaignKitExposesGuidedReviewFirstGenerationWithoutFalseVisualClaims(): void
    {
        $page = file_get_contents(__DIR__ . '/../../../public/marketing_campaign_kit.php');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/marketing-campaign-kit.css');
        $contentPage = file_get_contents(__DIR__ . '/../../../public/marketing_content.php');

        $this->assertNotFalse($page);
        $this->assertNotFalse($css);
        $this->assertNotFalse($contentPage);
        $this->assertStringContainsString('MarketingCampaignKitService', (string) $page);
        $this->assertStringContainsString('Build your first campaign kit', (string) $page);
        $this->assertStringContainsString('Accept into Content Studio', (string) $page);
        $this->assertStringContainsString('Send brief to Design', (string) $page);
        $this->assertStringContainsString('The current Design bridge does not generate pixels yet.', (string) $page);
        $this->assertStringContainsString('Nothing was published.', (string) $page);
        $this->assertStringContainsString('Content-to-revenue evidence', (string) $page);
        $this->assertStringContainsString('Prepare tracked launch', (string) $page);
        $this->assertStringContainsString('Review in Social Media', (string) $page);
        $this->assertStringContainsString('Nothing was scheduled or published', (string) $page);
        $this->assertStringNotContainsString('<style>', (string) $page);
        $this->assertStringContainsString('.campaign-kit-artifacts', (string) $css);
        $this->assertStringContainsString('.campaign-kit-outcomes', (string) $css);
        $this->assertStringContainsString('@media (max-width: 520px)', (string) $css);
        $this->assertStringContainsString('marketing_campaign_kit.php', (string) $contentPage);
    }

    public function testMarketingNavigationDefinitionsExposePluginFamiliesAndSetupCtas(): void
    {
        $primary = MarketingUi::primaryNavigationItems();
        $this->assertSame(
            ['Campaign Home', 'Setup', 'Audiences', 'Campaigns', 'Messages & Content', 'Send / Export', 'Results'],
            array_column($primary, 'label')
        );
        $groups = MarketingUi::pluginNavigationGroups();
        $this->assertSame(
            [
                MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
                MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA,
                MarketingMarketplaceGateService::FEATURE_DESIGN,
            ],
            array_keys($groups)
        );

        $desktop = MarketingUi::renderDesktopNavigation(true, true, true, true);
        $limitedDesktop = MarketingUi::renderDesktopNavigation(false, false, false, false);
        $designLockedDesktop = MarketingUi::renderDesktopNavigation(true, true, true, true, false, [
            MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => ['can_run' => true],
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => ['can_run' => true],
            MarketingMarketplaceGateService::FEATURE_DESIGN => [
                'can_run' => false,
                'setup_url' => 'workspace_skills.php?module=design#setup',
                'setup_label' => 'Set up Design',
            ],
        ]);

        $this->assertStringNotContainsString('<details class="marketing-nav-advanced">', $desktop);
        foreach (['Campaign Manager', 'Social Media', 'Design'] as $heading) {
            $this->assertStringContainsString($heading, $desktop);
        }
        $this->assertStringContainsString('href="marketing_system_map.php"', $desktop);
        $this->assertStringContainsString('href="marketing_campaign_kit.php"', $desktop);
        $this->assertStringContainsString('href="marketing_admin.php"', $desktop);
        $this->assertStringContainsString('href="campaigns.php"', $desktop);
        $this->assertStringContainsString('href="forms.php"', $desktop);
        $this->assertStringContainsString('href="email_signatures.php"', $desktop);
        $this->assertStringContainsString('href="design.php"', $desktop);
        $this->assertStringNotContainsString('href="nurture.php"', $desktop);
        $this->assertStringNotContainsString('Customer Nurture', $desktop);
        $this->assertStringNotContainsString('href="email_templates.php"', $desktop);
        $this->assertStringNotContainsString('href="marketing_admin.php"', $limitedDesktop);
        $this->assertStringNotContainsString('href="campaigns.php"', $limitedDesktop);
        $this->assertStringNotContainsString('href="marketing_content_edit.php"', $limitedDesktop);
        $this->assertStringContainsString('href="workspace_skills.php?module=design#setup"', $designLockedDesktop);
        $this->assertStringContainsString('Set up Design', $designLockedDesktop);
        $this->assertStringNotContainsString('href="design.php"', $designLockedDesktop);
        $this->assertStringNotContainsString('href="marketing_landing_pages.php"', $designLockedDesktop);
    }

    public function testMarketingPluginNavigationCoversAdvancedLinksOnceExceptCommunicationTemplates(): void
    {
        $pluginHrefs = [];
        foreach (MarketingUi::pluginNavigationGroups() as $group) {
            foreach ($group['items'] as $item) {
                $pluginHrefs[] = $item['href'];
            }
        }

        foreach (MarketingUi::advancedNavigationGroups() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['href'] === 'email_templates.php') {
                    $this->assertNotContains('email_templates.php', $pluginHrefs);
                    continue;
                }

                $this->assertSame(1, count(array_keys($pluginHrefs, $item['href'], true)), $item['href']);
            }
        }

        $this->assertContains('forms.php', $pluginHrefs);
        $this->assertContains('form_edit.php', MarketingUi::navigationPageFilenames());
        $this->assertTrue(MarketingUi::isNavigationPage('form_submissions.php'));
    }

    public function testMarketingStylesheetAssetExistsAndPublicLandingDoesNotLoadAuthenticatedUi(): void
    {
        $this->assertFileExists(__DIR__ . '/../../../public/assets/css/marketing-ui.css');
        $this->assertFileExists(__DIR__ . '/../../../public/assets/css/marketing-public.css');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/marketing-ui.css');
        $this->assertNotFalse($css);
        $this->assertStringContainsString('marketing-operator-strip', (string) $css);
        $this->assertStringContainsString('marketing-section-nav', (string) $css);
        $this->assertStringContainsString('marketing-founder-shell', (string) $css);
        $this->assertStringContainsString('marketing-stage-card', (string) $css);
        $this->assertStringContainsString('marketing-advanced-tools', (string) $css);
        $this->assertStringContainsString('marketing-advanced-tools-group', (string) $css);
        $this->assertStringContainsString('marketing-launch-packet-card', (string) $css);
        $this->assertStringContainsString('marketing-launch-completion-card', (string) $css);
        $this->assertStringContainsString('marketing-launch-proof-card', (string) $css);
        $this->assertStringContainsString('Next best action', (string) $css);
        $this->assertStringContainsString('--marketing-refine-primary: #0f67ea', (string) $css);
        $this->assertStringContainsString('.page-premium.marketing-ui-refined', (string) $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', (string) $css);

        $tag = MarketingUi::stylesheetTag();
        $this->assertStringContainsString('marketing-ui.css', $tag);

        $publicLanding = file_get_contents(__DIR__ . '/../../../public/marketing_landing_public.php');
        $this->assertNotFalse($publicLanding);
        $this->assertStringNotContainsString('marketing-ui.css', (string) $publicLanding);
        $this->assertStringNotContainsString('MarketingUi', (string) $publicLanding);
        $this->assertStringContainsString('marketing-public.css', (string) $publicLanding);
        $this->assertStringContainsString('marketing-public-landing', (string) $publicLanding);
        $this->assertStringNotContainsString('<style>', (string) $publicLanding);
    }

    public function testLegacyMarketingMenuPagesUseSharedRefinementHooksWithoutInlineStyles(): void
    {
        $expectedHooks = [
            'campaigns.php' => 'marketing-campaigns-page',
            'campaign_view.php' => 'marketing-campaign-view-page',
            'attribution_reports.php' => 'marketing-attribution-page',
        ];

        foreach ($expectedHooks as $page => $hook) {
            $source = file_get_contents(__DIR__ . '/../../../public/' . $page);
            $this->assertNotFalse($source, $page);
            $this->assertStringContainsString($hook, (string) $source, $page);
            $this->assertStringNotContainsString('<style>', (string) $source, $page);
            $this->assertStringNotContainsString('style=', (string) $source, $page);
        }
    }

    public function testMarketingCommandCenterUsesFounderFirstVisualMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-founder-shell', $source);
        $this->assertStringContainsString('<h1>Campaign Manager</h1>', $source);
        $this->assertStringContainsString('Plan, launch and improve.', $source);
        $this->assertStringContainsString('marketing-founder-summary', $source);
        $this->assertStringContainsString('MarketingLaunchPacketService', $source);
        $this->assertStringContainsString('<h2>Launch packet</h2>', $source);
        $this->assertStringContainsString('marketing-launch-packet-card', $source);
        $this->assertStringContainsString("launchPacket['needs_manual_proof']", $source);
        $this->assertStringContainsString('Record proof', $source);
        $this->assertStringContainsString('No external send, publish, or channel API call.', $source);
        $this->assertStringContainsString('marketing-lifecycle-graph', $source);
        $this->assertStringContainsString('marketing-stage-grid', $source);
        $this->assertStringContainsString('marketing-tooltip-trigger', $source);
        $this->assertStringContainsString('marketing-today-panel', $source);
        $this->assertStringContainsString('Advanced Marketing Operations', $source);
        $this->assertStringContainsString('Detailed system signals', $source);
        $this->assertStringContainsString('marketing-system-signal-board', $source);
        $this->assertStringContainsString('marketing-system-signal-card', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('marketing-legacy-command-sections', $source);
        $this->assertStringNotContainsString('Marketing Operating Rhythm', $source);
        $this->assertStringNotContainsString('marketing_launch_control.php?id=', $source);

        foreach (['Setup', 'Audiences', 'Campaigns', 'Content', 'Landing Pages', 'Send / Export', 'Results'] as $label) {
            $this->assertStringContainsString($label, file_get_contents(__DIR__ . '/../../../services/MarketingStageContextService.php') ?: '');
        }

        $this->assertStringNotContainsString('Unified Next Best Actions', $source);
        $this->assertStringNotContainsString('Operator Command Flow', $source);
    }

    public function testMarketingCommandCenterKeepsExplanatoryCopyInTooltipsAndDrawers(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('marketing-command-center-page', $source);
        $this->assertStringContainsString('<details class="marketing-detailed-systems">', $source);
        $this->assertStringContainsString('<details class="marketing-advanced-tools" id="advanced-marketing-operations">', $source);
        $this->assertSame(1, substr_count($source, 'class="btn-premium-secondary marketing-stage-action"'));
        $this->assertStringContainsString("stage['tooltip']", $source);
        $this->assertStringNotContainsString('<small><?php echo htmlspecialchars((string) $stage[\'locked_reason\']); ?></small>', $source);
        $this->assertStringNotContainsString('Guided Marketing Recommendations', $source);
        $this->assertStringNotContainsString('Review recommendation', $source);
        $this->assertStringNotContainsString('integration-recommendations', $source);
        $this->assertStringNotContainsString('ai-brain-recommendations', $source);
    }

    public function testDistributionBundleReadsAsManualLaunchPacket(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_distribution_bundle.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('Manual Launch Packet', $source);
        $this->assertStringContainsString('Founder-ready publishing package. No external publishing happens here.', $source);
        $this->assertStringContainsString('Open Packet', $source);
        $this->assertStringContainsString('marketing_launch_proof.php?id=', $source);
        $this->assertStringContainsString('Record proof', $source);
        $this->assertStringContainsString('More packet tools', $source);
        $this->assertStringContainsString('Advanced Marketing Tools', $source);
    }

    public function testGuidedLaunchPacketPageUsesFocusedCompletionFlow(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_launch_packet.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('Guided Launch Packet', $source);
        $this->assertStringContainsString('MarketingLaunchPacketCompletionService', $source);
        $this->assertStringContainsString('marketing-launch-completion-shell', $source);
        $this->assertStringContainsString('marketing-launch-completion-form', $source);
        $this->assertStringContainsString('marketing-launch-completion-readonly', $source);
        $this->assertStringContainsString('No live send, publish, or channel API execution.', $source);
        $this->assertStringContainsString("Authorization::can('marketing.write'", $source);
        $this->assertStringContainsString('Security::validateCSRF', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);

        $service = file_get_contents(__DIR__ . '/../../../services/MarketingLaunchPacketCompletionService.php');
        $this->assertNotFalse($service);
        foreach ([
            'create_foundation',
            'create_audience',
            'create_campaign',
            'create_content',
            'create_landing',
            'create_distribution',
        ] as $action) {
            $this->assertStringContainsString($action, (string) $service);
        }
    }

    public function testGuidedLaunchProofPageUsesManualProofFlow(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_launch_proof.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('Manual Launch Proof', $source);
        $this->assertStringContainsString('MarketingLaunchProofService', $source);
        $this->assertStringContainsString('record_manual_publish_proof', $source);
        $this->assertStringContainsString('Security::validateCSRF', $source);
        $this->assertStringContainsString("Authorization::can('marketing.write'", $source);
        $this->assertStringContainsString('marketing-launch-proof-card', $source);
        $this->assertStringContainsString('Read-only view', $source);
        $this->assertStringContainsString('No live send, publish, or channel API execution.', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
    }

    public function testDetailedSystemSignalsDefaultToCompactSignalBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('Compact health view for the advanced marketing system.', $source);
        $this->assertSame(1, substr_count($source, 'class="marketing-system-signal-grid"'));
        $this->assertStringNotContainsString('marketing-legacy-command-sections', $source);
        $this->assertStringNotContainsString('class="marketing-flow"', $source);
        $this->assertStringNotContainsString('class="workflow-readiness"', $source);
    }

    public function testMarketingOnboardingUsesFounderFirstSetupBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_onboarding.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-onboarding-page', $source);
        $this->assertStringContainsString('<h1>Know Your Customer</h1>', $source);
        $this->assertStringContainsString('marketing-setup-summary', $source);
        $this->assertStringContainsString('marketing-setup-step-grid', $source);
        $this->assertStringContainsString('marketing-setup-today', $source);
        $this->assertStringContainsString('More setup tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('Clarity guidance queue', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringContainsString('data-score', $source);
        $this->assertStringNotContainsString('<h2>Recommended Next Steps</h2>', $source);
        $this->assertStringNotContainsString('<div class="setup-step-grid"', $source);
        $this->assertSame(1, substr_count($source, 'MarketingWorkflowNextStepsUi::render($workflowNextSteps)'));
    }

    public function testMarketingContextUsesFounderFirstContextLibrary(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_context.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-context-page', $source);
        $this->assertStringContainsString('<h1>Context Library</h1>', $source);
        $this->assertStringContainsString('marketing-page-header', $source);
        $this->assertStringContainsString('marketing-page-actions', $source);
        $this->assertStringContainsString('marketing-context-summary', $source);
        $this->assertStringContainsString('marketing-context-type-grid', $source);
        $this->assertStringContainsString('marketing-context-card-visual', $source);
        $this->assertStringContainsString('marketing-context-card-action', $source);
        $this->assertStringContainsString('marketing-context-layout', $source);
        $this->assertStringContainsString('marketing-context-saved-card', $source);
        $this->assertStringContainsString('More context tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('<h1>Marketing Context Hub</h1>', $source);
        $this->assertStringNotContainsString('Context Completeness</h2>', $source);
        $this->assertStringNotContainsString('href="marketing_brand.php">Brand</a><a', $source);
    }

    public function testMarketingBrandUsesFounderFirstBrandLibrary(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_brand.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-brand-page', $source);
        $this->assertStringContainsString('<h1>Brand Library</h1>', $source);
        $this->assertStringContainsString('marketing-page-header', $source);
        $this->assertStringContainsString('marketing-page-actions', $source);
        $this->assertStringContainsString('marketing-brand-summary', $source);
        $this->assertStringContainsString('marketing-brand-type-grid', $source);
        $this->assertStringContainsString('marketing-brand-card-visual', $source);
        $this->assertStringContainsString('marketing-brand-card-action', $source);
        $this->assertStringContainsString('marketing-brand-layout', $source);
        $this->assertStringContainsString('marketing-brand-profile-card', $source);
        $this->assertStringContainsString('More brand tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('Template And Brand System</h2>', $source);
        $this->assertStringNotContainsString('template-score-grid', $source);
    }

    public function testMarketingPersonasUsesFounderFirstPersonaLibrary(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_personas.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-personas-page', $source);
        $this->assertStringContainsString('<h1>Customer Personas</h1>', $source);
        $this->assertStringContainsString('marketing-page-header', $source);
        $this->assertStringContainsString('marketing-page-actions', $source);
        $this->assertStringContainsString('marketing-personas-summary', $source);
        $this->assertStringContainsString('marketing-persona-signal-grid', $source);
        $this->assertStringContainsString('marketing-persona-signal-visual', $source);
        $this->assertStringContainsString('marketing-persona-signal-action', $source);
        $this->assertStringContainsString('marketing-personas-layout', $source);
        $this->assertStringContainsString('marketing-persona-profile-card', $source);
        $this->assertStringContainsString('More persona tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('<h1>Personas</h1>', $source);
        $this->assertStringNotContainsString('marketing-grid', $source);
        $this->assertStringNotContainsString('Define customer segments, pains, goals, objections, and preferred channels.', $source);
    }

    public function testMarketingSegmentsUsesFounderFirstAudienceBuilder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_segments.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-segments-page', $source);
        $this->assertStringContainsString('<h1>Audience Builder</h1>', $source);
        $this->assertStringContainsString('page-header marketing-page-header', $source);
        $this->assertStringContainsString('page-header-actions marketing-page-actions', $source);
        $this->assertStringContainsString('marketing-segments-summary', $source);
        $this->assertStringContainsString('marketing-segment-signal-grid', $source);
        $this->assertStringContainsString('marketing-segment-signal-visual', $source);
        $this->assertStringContainsString('marketing-segment-signal-count', $source);
        $this->assertStringContainsString('marketing-segment-signal-action', $source);
        $this->assertStringContainsString('marketing-segments-layout', $source);
        $this->assertStringContainsString('marketing-segment-profile-card', $source);
        $this->assertStringContainsString('More audience tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('<h1>Audience Segments</h1>', $source);
        $this->assertStringNotContainsString('audience-cockpit', $source);
        $this->assertStringNotContainsString('Build CRM-derived audiences from contacts, companies, deals, forms, tags, activities, lifecycle stage, and engagement.', $source);
    }

    public function testMarketingSegmentEditUsesFounderFirstRuleBuilder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_segment_edit.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-segment-edit-page', $source);
        $this->assertStringContainsString('Audience Rule Builder', $source);
        $this->assertStringContainsString('page-header marketing-page-header', $source);
        $this->assertStringContainsString('page-header-actions marketing-page-actions', $source);
        $this->assertStringContainsString('marketing-segment-edit-summary', $source);
        $this->assertStringContainsString('marketing-segment-builder-grid', $source);
        $this->assertStringContainsString('marketing-segment-builder-visual', $source);
        $this->assertStringContainsString('marketing-segment-builder-value', $source);
        $this->assertStringContainsString('marketing-segment-builder-action', $source);
        $this->assertStringContainsString('marketing-segment-edit-layout', $source);
        $this->assertStringContainsString('marketing-segment-rule-card', $source);
        $this->assertStringContainsString('More rule tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('Create reusable CRM-derived audiences for briefs, landing pages, email runs, and channel export planning.', $source);
        $this->assertStringNotContainsString('hint-text', $source);
    }

    public function testMarketingSegmentViewUsesFounderFirstAudiencePreview(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_segment_view.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-segment-view-page', $source);
        $this->assertStringContainsString('<h1>Audience Preview</h1>', $source);
        $this->assertStringContainsString('marketing-segment-view-summary', $source);
        $this->assertStringContainsString('marketing-segment-preview-grid', $source);
        $this->assertStringContainsString('marketing-segment-view-layout', $source);
        $this->assertStringContainsString('marketing-segment-contact-preview', $source);
        $this->assertStringContainsString('More preview tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('Preview CRM-derived contacts, snapshot a stable manual execution list, and see where this audience is used.', $source);
        $this->assertStringNotContainsString('preview-table', $source);
    }

    public function testMarketingBriefsUsesFounderFirstPlanBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_briefs.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-briefs-page', $source);
        $this->assertStringContainsString('<h1>Campaign Plan Board</h1>', $source);
        $this->assertStringContainsString('marketing-briefs-summary', $source);
        $this->assertStringContainsString('marketing-brief-signal-grid', $source);
        $this->assertStringContainsString('marketing-briefs-layout', $source);
        $this->assertStringContainsString('marketing-brief-card-list', $source);
        $this->assertStringContainsString('marketing-briefs-today', $source);
        $this->assertStringContainsString('More brief tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('MarketingWorkflowNextStepsUi::render($workflowNextSteps)', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('<h1>Campaign Briefs</h1>', $source);
        $this->assertStringNotContainsString('Define the objective, audience, offer, message, and campaign window before content moves into production.', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
    }

    public function testMarketingBriefEditUsesFounderFirstPlanBuilder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_brief_edit.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-brief-edit-page', $source);
        $this->assertStringContainsString('New Campaign Brief', $source);
        $this->assertStringContainsString('marketing-brief-edit-summary', $source);
        $this->assertStringContainsString('marketing-brief-builder-grid', $source);
        $this->assertStringContainsString('marketing-brief-edit-layout', $source);
        $this->assertStringContainsString('marketing-brief-core-fields', $source);
        $this->assertStringContainsString('marketing-brief-builder-today', $source);
        $this->assertStringContainsString('More brief fields', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('data-score', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('marketing-form-grid', $source);
        $this->assertStringNotContainsString('Lock the strategy before content production starts.', $source);
    }

    public function testMarketingBriefViewUsesFounderFirstReviewBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_brief_view.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-brief-view-page', $source);
        $this->assertStringContainsString('Campaign Brief Review', $source);
        $this->assertStringContainsString('marketing-brief-view-summary', $source);
        $this->assertStringContainsString('marketing-brief-review-grid', $source);
        $this->assertStringContainsString('marketing-brief-view-layout', $source);
        $this->assertStringContainsString('marketing-brief-message-card', $source);
        $this->assertStringContainsString('marketing-brief-view-today', $source);
        $this->assertStringContainsString('More brief evidence', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('data-score', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('marketing-detail-grid', $source);
        $this->assertStringNotContainsString('marketing-cardlet', $source);
    }

    public function testMarketingCampaignWorkspaceUsesFounderFirstOperatingMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_campaign_workspace.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-campaign-workspace-page', $source);
        $this->assertStringContainsString('<h1>Campaign Workspace</h1>', $source);
        $this->assertStringContainsString('Marketing Campaign Workspace', $source);
        $this->assertStringContainsString('marketing-campaign-workspace-summary', $source);
        $this->assertStringContainsString('marketing-campaign-stage-map', $source);
        $this->assertStringContainsString('marketing-campaign-stage-card', $source);
        $this->assertStringContainsString('marketing-campaign-workspace-layout', $source);
        $this->assertStringContainsString('marketing-campaign-today', $source);
        $this->assertStringContainsString('Advanced campaign tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('<progress class="marketing-campaign-meter"', $source);
        $this->assertStringContainsString('data-score', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('campaign-workspace-row', $source);
        $this->assertStringNotContainsString('Unify each campaign\'s strategy, audience, content, landing page, distribution, tracking, launch control, and reporting readiness', $source);
    }

    public function testMarketingAudienceActivationUsesFounderFirstReadinessBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_audience_activation.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-audience-activation-page', $source);
        $this->assertStringContainsString('<h1>Audience Activation</h1>', $source);
        $this->assertStringContainsString('Make the audience usable before launch.', $source);
        $this->assertStringContainsString('marketing-audience-summary', $source);
        $this->assertStringContainsString('marketing-audience-stage-grid', $source);
        $this->assertStringContainsString('marketing-audience-stage-card', $source);
        $this->assertStringContainsString('marketing-audience-layout', $source);
        $this->assertStringContainsString('marketing-audience-today', $source);
        $this->assertStringContainsString('More audience tools', $source);
        $this->assertStringContainsString('marketing-audience-form-more', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('<progress class="marketing-audience-fit-meter"', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('activation-row', $source);
        $this->assertStringNotContainsString('Connect CRM audience segments to campaigns, briefs, landing pages, content, email runs, and manual distribution before launch.', $source);
    }

    public function testMarketingJourneysUsesFounderFirstJourneyMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_journeys.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-journeys-page', $source);
        $this->assertStringContainsString('<h1>Journey Map</h1>', $source);
        $this->assertStringContainsString('Marketing Journeys', $source);
        $this->assertStringContainsString('Audience Journey Cockpit', $source);
        $this->assertStringContainsString('marketing-journey-summary', $source);
        $this->assertStringContainsString('marketing-journey-stage-grid', $source);
        $this->assertStringContainsString('marketing-journey-stage-card', $source);
        $this->assertStringContainsString('marketing-journey-layout', $source);
        $this->assertStringContainsString('marketing-journey-today', $source);
        $this->assertStringContainsString('More journey tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('data-score', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('journey-row', $source);
        $this->assertStringNotContainsString('Plan manual-first campaign paths across email, WhatsApp, SMS, tasks, waits, conditions, and branches.', $source);
    }

    public function testMarketingJourneyEditUsesFounderFirstJourneyBuilder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_journey_edit.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-journey-edit-page', $source);
        $this->assertStringContainsString('<h1>Journey Builder</h1>', $source);
        $this->assertStringContainsString('Marketing Journey', $source);
        $this->assertStringContainsString('marketing-journey-edit-summary', $source);
        $this->assertStringContainsString('marketing-journey-builder-grid', $source);
        $this->assertStringContainsString('marketing-journey-builder-card', $source);
        $this->assertStringContainsString('marketing-journey-edit-layout', $source);
        $this->assertStringContainsString('marketing-journey-core-fields', $source);
        $this->assertStringContainsString('marketing-journey-step-card', $source);
        $this->assertStringContainsString('marketing-journey-builder-today', $source);
        $this->assertStringContainsString('More step fields', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('journey-step-row', $source);
        $this->assertStringNotContainsString('Map planned manual journey steps. This planner does not publish, send, or execute externally.', $source);
    }

    public function testMarketingJourneyViewUsesFounderFirstJourneyReview(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_journey_view.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-journey-view-page', $source);
        $this->assertStringContainsString('Journey Readiness', $source);
        $this->assertStringContainsString('Marketing Journey', $source);
        $this->assertStringContainsString('marketing-journey-view-summary', $source);
        $this->assertStringContainsString('marketing-journey-review-grid', $source);
        $this->assertStringContainsString('marketing-journey-review-card', $source);
        $this->assertStringContainsString('marketing-journey-view-layout', $source);
        $this->assertStringContainsString('marketing-journey-timeline-step', $source);
        $this->assertStringContainsString('marketing-journey-view-today', $source);
        $this->assertStringContainsString('More journey evidence', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('journey-detail-grid', $source);
        $this->assertStringNotContainsString('class="timeline-step"', $source);
        $this->assertStringNotContainsString('Manual-first journey plan for campaign and audience sequencing. No external send or publish happens here.', $source);
    }

    public function testMarketingPlaybooksUsesFounderFirstLibrary(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_playbooks.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-playbooks-page', $source);
        $this->assertStringContainsString('<h1>Playbook Library</h1>', $source);
        $this->assertStringContainsString('Campaign Playbooks', $source);
        $this->assertStringContainsString('marketing-playbooks-summary', $source);
        $this->assertStringContainsString('marketing-playbook-stage-grid', $source);
        $this->assertStringContainsString('marketing-playbook-stage-card', $source);
        $this->assertStringContainsString('marketing-playbooks-layout', $source);
        $this->assertStringContainsString('marketing-playbook-board', $source);
        $this->assertStringContainsString('marketing-playbooks-today', $source);
        $this->assertStringContainsString('More playbook tools', $source);
        $this->assertStringContainsString('marketing-playbook-template-card', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('data-score', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('playbook-row', $source);
        $this->assertStringNotContainsString('Package repeatable strategy, content, conversion, distribution, and sales handoff steps into reusable campaign plans.', $source);
    }

    public function testMarketingPlaybookEditUsesFounderFirstBuilder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_playbook_edit.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-playbook-edit-page', $source);
        $this->assertStringContainsString('<h1>Playbook Builder</h1>', $source);
        $this->assertStringContainsString('Campaign Playbook', $source);
        $this->assertStringContainsString('marketing-playbook-edit-summary', $source);
        $this->assertStringContainsString('marketing-playbook-builder-grid', $source);
        $this->assertStringContainsString('marketing-playbook-builder-card', $source);
        $this->assertStringContainsString('marketing-playbook-edit-layout', $source);
        $this->assertStringContainsString('marketing-playbook-core-fields', $source);
        $this->assertStringContainsString('marketing-playbook-foundation-fields', $source);
        $this->assertStringContainsString('marketing-playbook-structure-fields', $source);
        $this->assertStringContainsString('marketing-playbook-builder-today', $source);
        $this->assertStringContainsString('More playbook structure', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('marketing-form-grid', $source);
        $this->assertStringNotContainsString('Create reusable campaign operating plans that can become draft campaign briefs.', $source);
    }

    public function testMarketingPlaybookViewUsesFounderFirstReviewBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_playbook_view.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-playbook-view-page', $source);
        $this->assertStringContainsString('Campaign Playbook: review the campaign pattern before turning it into work.', $source);
        $this->assertStringContainsString('marketing-playbook-view-summary', $source);
        $this->assertStringContainsString('marketing-playbook-view-layout', $source);
        $this->assertStringContainsString('marketing-playbook-view-board', $source);
        $this->assertStringContainsString('Playbook Review Board', $source);
        $this->assertStringContainsString('marketing-playbook-view-card', $source);
        $this->assertStringContainsString('marketing-playbook-view-card-action', $source);
        $this->assertStringContainsString('marketing-playbook-view-today', $source);
        $this->assertStringContainsString('More playbook evidence', $source);
        $this->assertStringContainsString('Strategy and plan', $source);
        $this->assertStringContainsString('Playbook actions', $source);
        $this->assertStringContainsString('Operating Stages', $source);
        $this->assertStringContainsString('Application Runs', $source);
        $this->assertStringContainsString('Create Brief', $source);
        $this->assertStringContainsString('Apply Campaign Kit', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertSame(1, substr_count($source, 'marketing-playbook-view-card-action" href'));
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('playbook-detail', $source);
        $this->assertStringNotContainsString('Manual-first steps for running this campaign pattern.', $source);
    }

    public function testMarketingRoadmapUsesFounderFirstLaunchMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_roadmap.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-roadmap-page', $source);
        $this->assertStringContainsString('<h1>Campaign Roadmap</h1>', $source);
        $this->assertStringContainsString('Launch Map', $source);
        $this->assertStringContainsString('marketing-roadmap-summary', $source);
        $this->assertStringContainsString('marketing-roadmap-stage-grid', $source);
        $this->assertStringContainsString('marketing-roadmap-stage-card', $source);
        $this->assertStringContainsString('marketing-roadmap-layout', $source);
        $this->assertStringContainsString('marketing-roadmap-board', $source);
        $this->assertStringContainsString('marketing-roadmap-today', $source);
        $this->assertStringContainsString('More roadmap tools', $source);
        $this->assertStringContainsString('marketing-roadmap-filter-card', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('<progress class="marketing-roadmap-meter"', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('roadmap-item', $source);
        $this->assertStringNotContainsString('See launch windows, strategy readiness, and missing planning inputs before work reaches production.', $source);
    }

    public function testMarketingPersonaOfferMatrixUsesFounderFirstFitMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_persona_offer_matrix.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-persona-offer-page', $source);
        $this->assertStringContainsString('<h1>Persona Offer Matrix</h1>', $source);
        $this->assertStringContainsString('Offer Fit', $source);
        $this->assertStringContainsString('marketing-persona-offer-summary', $source);
        $this->assertStringContainsString('marketing-persona-offer-stage-grid', $source);
        $this->assertStringContainsString('marketing-persona-offer-stage-card', $source);
        $this->assertStringContainsString('marketing-persona-offer-layout', $source);
        $this->assertStringContainsString('marketing-persona-offer-board', $source);
        $this->assertStringContainsString('marketing-persona-offer-today', $source);
        $this->assertStringContainsString('More fit tools', $source);
        $this->assertStringContainsString('marketing-persona-offer-tool-card', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('matrix-table', $source);
        $this->assertStringNotContainsString('Map which personas have active offers and how many briefs already connect them.', $source);
    }

    public function testMarketingCalendarUsesFounderFirstScheduleBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_calendar.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-calendar-page', $source);
        $this->assertStringContainsString('<h1>Marketing Calendar</h1>', $source);
        $this->assertStringContainsString('Schedule Board', $source);
        $this->assertStringContainsString('marketing-calendar-summary', $source);
        $this->assertStringContainsString('marketing-calendar-stage-grid', $source);
        $this->assertStringContainsString('marketing-calendar-stage-card', $source);
        $this->assertStringContainsString('marketing-calendar-layout', $source);
        $this->assertStringContainsString('marketing-calendar-board', $source);
        $this->assertStringContainsString('Editorial Calendar Plan', $source);
        $this->assertStringContainsString('marketing-calendar-today', $source);
        $this->assertStringContainsString('More calendar tools', $source);
        $this->assertStringContainsString('Calendar Templates', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('marketing-calendar-grid', $source);
        $this->assertStringNotContainsString('Track campaign launches, review deadlines, publishing dates, and marketing events.', $source);
    }

    public function testMarketingReviewsUseFounderFirstApprovalBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_reviews.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-reviews-page', $source);
        $this->assertStringContainsString('<h1>Marketing Approval Workbench</h1>', $source);
        $this->assertStringContainsString('Review Board', $source);
        $this->assertStringContainsString('marketing-reviews-summary', $source);
        $this->assertStringContainsString('marketing-reviews-stage-grid', $source);
        $this->assertStringContainsString('marketing-reviews-stage-card', $source);
        $this->assertStringContainsString('marketing-reviews-layout', $source);
        $this->assertStringContainsString('marketing-reviews-board', $source);
        $this->assertStringContainsString('marketing-reviews-today', $source);
        $this->assertStringContainsString('More review tools', $source);
        $this->assertStringContainsString('Workflow Closure Board', $source);
        $this->assertStringContainsString('Review next steps', $source);
        $this->assertStringContainsString('MarketingWorkflowNextStepsUi', $source);
        $this->assertStringContainsString('Manual-first', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="queue-tabs"', $source);
        $this->assertStringNotContainsString('Move content through pending, overdue, blocked, approved, and rejected review queues.', $source);
    }

    public function testMarketingLaunchReadinessUsesFounderFirstLaunchBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_launch_readiness.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-launch-readiness-page', $source);
        $this->assertStringContainsString('<h1>Launch Readiness</h1>', $source);
        $this->assertStringContainsString('Launch Board', $source);
        $this->assertStringContainsString('marketing-launch-readiness-summary', $source);
        $this->assertStringContainsString('marketing-launch-readiness-stage-grid', $source);
        $this->assertStringContainsString('marketing-launch-readiness-stage-card', $source);
        $this->assertStringContainsString('marketing-launch-readiness-layout', $source);
        $this->assertStringContainsString('marketing-launch-readiness-board', $source);
        $this->assertStringContainsString('marketing-launch-readiness-today', $source);
        $this->assertStringContainsString('More launch tools', $source);
        $this->assertStringContainsString('Evaluate Launch', $source);
        $this->assertStringContainsString('Templates', $source);
        $this->assertStringContainsString('Manual-first', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="launch-grid"', $source);
        $this->assertStringNotContainsString('Score campaign launches before manual execution by checking audience, offer, CTA, approvals, media, UTMs, and distribution packages.', $source);
    }

    public function testMarketingLaunchControlUsesFounderFirstControlBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_launch_control.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-launch-control-page', $source);
        $this->assertStringContainsString('<h1>Launch Control Room</h1>', $source);
        $this->assertStringContainsString('Control Board', $source);
        $this->assertStringContainsString('marketing-launch-control-summary', $source);
        $this->assertStringContainsString('marketing-launch-control-stage-grid', $source);
        $this->assertStringContainsString('marketing-launch-control-stage-card', $source);
        $this->assertStringContainsString('marketing-launch-control-layout', $source);
        $this->assertStringContainsString('marketing-launch-control-board', $source);
        $this->assertStringContainsString('marketing-launch-control-today', $source);
        $this->assertStringContainsString('More launch control tools', $source);
        $this->assertStringContainsString('Manual-first', $source);
        $this->assertStringContainsString('Manual-first Safeguard', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="control-grid"', $source);
        $this->assertStringNotContainsString('Prepare manual launches from readiness reviews, block risky launches, and record launch execution without external publishing or sending.', $source);
    }

    public function testMarketingLaunchChecklistsUseFounderFirstChecklistBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_launch_checklists.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-launch-checklists-page', $source);
        $this->assertStringContainsString('<h1>Campaign Launch Checklists</h1>', $source);
        $this->assertStringContainsString('Checklist Board', $source);
        $this->assertStringContainsString('marketing-launch-checklists-summary', $source);
        $this->assertStringContainsString('marketing-launch-checklists-stage-grid', $source);
        $this->assertStringContainsString('marketing-launch-checklists-stage-card', $source);
        $this->assertStringContainsString('marketing-launch-checklists-layout', $source);
        $this->assertStringContainsString('marketing-launch-checklists-board', $source);
        $this->assertStringContainsString('marketing-launch-checklists-today', $source);
        $this->assertStringContainsString('More checklist tools', $source);
        $this->assertStringContainsString('Manual-first Safeguard', $source);
        $this->assertStringContainsString('Manual publishing and sending still require operator action.', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="launch-checklist-grid"', $source);
        $this->assertStringNotContainsString('Run the last manual-first launch gate across strategy, audience, content, media, tracking, exports, and connector readiness before execution.', $source);
    }

    public function testMarketingGuidedWorkflowsUseFounderFirstWorkflowBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_guided_workflows.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-guided-workflows-page', $source);
        $this->assertStringContainsString('<h1>Marketing Guided Workflows</h1>', $source);
        $this->assertStringContainsString('Workflow Board', $source);
        $this->assertStringContainsString('marketing-guided-workflows-summary', $source);
        $this->assertStringContainsString('marketing-guided-workflows-stage-grid', $source);
        $this->assertStringContainsString('marketing-guided-workflows-stage-card', $source);
        $this->assertStringContainsString('marketing-guided-workflows-layout', $source);
        $this->assertStringContainsString('marketing-guided-workflows-board', $source);
        $this->assertStringContainsString('marketing-guided-workflows-today', $source);
        $this->assertStringContainsString('More workflow tools', $source);
        $this->assertStringContainsString('Workflow Wizards', $source);
        $this->assertStringContainsString('Manual-first Safeguard', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('<progress class="marketing-guided-workflows-progress"', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('class="workflow-grid"', $source);
        $this->assertStringNotContainsString('class="workflow-kpis"', $source);
        $this->assertStringNotContainsString('Use real CRM and Marketing records to see the next safest action for launch, content, landing pages, distribution, and performance review.', $source);
    }

    public function testMarketingTaskHubUsesFounderFirstTaskBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_task_hub.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-task-hub-page', $source);
        $this->assertStringContainsString('<h1>Marketing Task Hub</h1>', $source);
        $this->assertStringContainsString('Task Board', $source);
        $this->assertStringContainsString('marketing-task-hub-summary', $source);
        $this->assertStringContainsString('marketing-task-hub-stage-grid', $source);
        $this->assertStringContainsString('marketing-task-hub-stage-card', $source);
        $this->assertStringContainsString('marketing-task-hub-layout', $source);
        $this->assertStringContainsString('marketing-task-hub-board', $source);
        $this->assertStringContainsString('marketing-task-hub-today', $source);
        $this->assertStringContainsString('More task tools', $source);
        $this->assertStringContainsString('Queue Filter', $source);
        $this->assertStringContainsString('Recent Task Actions', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="task-kpis"', $source);
        $this->assertStringNotContainsString('class="task-layout"', $source);
        $this->assertStringNotContainsString('class="task-row"', $source);
        $this->assertStringNotContainsString('One operator queue for assigned content, reviews, due work, calendar items, blocked launches, and guided next actions.', $source);
    }

    public function testMarketingAssetsUseAssetFirstLibraryWithCompactReporting(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_assets.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-assets-page', $source);
        $this->assertStringContainsString('<h1>Marketing Assets</h1>', $source);
        $this->assertStringContainsString('Asset Library', $source);
        $this->assertStringContainsString('Browse and manage reusable campaign files.', $source);
        $this->assertStringContainsString('marketing-assets-summary', $source);
        $this->assertStringContainsString('marketing-assets-stage-grid', $source);
        $this->assertStringContainsString('marketing-assets-stage-card', $source);
        $this->assertStringContainsString('marketing-assets-layout', $source);
        $this->assertStringContainsString('marketing-assets-board', $source);
        $this->assertStringContainsString('marketing-assets-library', $source);
        $this->assertStringContainsString('marketing-assets-today', $source);
        $this->assertStringContainsString('marketing-assets-reporting', $source);
        $this->assertStringContainsString('Reporting &amp; readiness', $source);
        $this->assertStringContainsString('Asset tools', $source);
        $this->assertStringContainsString('marketing-assets-tool-hub', $source);
        $this->assertStringContainsString('data-asset-library-view', $source);
        $this->assertStringContainsString('data-asset-workspace-toggle', $source);
        $this->assertStringContainsString('data-asset-library-link', $source);
        $this->assertStringContainsString('data-asset-tool-hub hidden', $source);
        $this->assertStringContainsString('data-asset-tool-tab', $source);
        $this->assertStringContainsString('data-asset-tool-panel', $source);
        $this->assertStringContainsString('One tool at a time', $source);
        $this->assertStringContainsString('Back to library', $source);
        $this->assertStringContainsString('setWorkspace', $source);
        $this->assertStringContainsString('Media Operations', $source);
        $this->assertStringContainsString('Media Usage Map', $source);
        $this->assertStringContainsString('AI Media Bridge', $source);
        $this->assertStringContainsString('Add Media', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('MarketingMediaReadinessUi::render($mediaReadiness)', $source);
        $this->assertTrue(strpos($source, 'marketing-assets-library') < strpos($source, 'marketing-assets-summary'));
        $this->assertTrue(strpos($source, 'marketing-assets-library') < strpos($source, 'marketing-assets-board'));
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="marketing-grid"', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
        $this->assertStringNotContainsString('class="media-card"', $source);
        $this->assertStringNotContainsString('class="media-library"', $source);
        $this->assertStringNotContainsString('<details class="content-card marketing-assets-tools"', $source);
        $this->assertStringNotContainsString('Reference export-ready creative, documents, links, and usage rights for marketing work.', $source);
    }

    public function testMarketingCreativeUsesFounderFirstCreativeBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_creative.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-creative-page', $source);
        $this->assertStringContainsString('<h1>Creative Production</h1>', $source);
        $this->assertStringContainsString('Turn visual gaps into one clear creative action.', $source);
        $this->assertStringContainsString('marketing-creative-summary', $source);
        $this->assertStringContainsString('marketing-creative-layout', $source);
        $this->assertStringContainsString('marketing-creative-board', $source);
        $this->assertStringContainsString('Creative Board', $source);
        $this->assertStringContainsString('marketing-creative-stage-card', $source);
        $this->assertStringContainsString('marketing-creative-card-action', $source);
        $this->assertStringContainsString('marketing-creative-today', $source);
        $this->assertStringContainsString('Creative evidence and queues', $source);
        $this->assertStringContainsString('Create and manage creative work', $source);
        $this->assertStringContainsString('AI Creative Workspace', $source);
        $this->assertStringContainsString('Media Production Workflow', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertSame(1, substr_count($source, 'class="btn-premium-secondary marketing-creative-card-action"'));

        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('href="marketing.php"', $source);
        $this->assertStringNotContainsString('Plan creative briefs, request assets, and track asset readiness for marketing content.', $source);
    }

    public function testMarketingDistributionUsesFounderFirstDistributionBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_distribution.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-distribution-page', $source);
        $this->assertStringContainsString('<h1>Distribution Queue</h1>', $source);
        $this->assertStringContainsString('Distribution Board', $source);
        $this->assertStringContainsString('marketing-distribution-summary', $source);
        $this->assertStringContainsString('marketing-distribution-stage-grid', $source);
        $this->assertStringContainsString('marketing-distribution-stage-card', $source);
        $this->assertStringContainsString('marketing-distribution-layout', $source);
        $this->assertStringContainsString('marketing-distribution-board', $source);
        $this->assertStringContainsString('marketing-distribution-today', $source);
        $this->assertStringContainsString('More distribution tools', $source);
        $this->assertStringContainsString('Create Variant', $source);
        $this->assertStringContainsString('MarketingWorkflowNextStepsUi::render($workflowNextSteps)', $source);
        $this->assertStringContainsString('MarketingMediaReadinessUi::render($mediaReadiness)', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="marketing-grid"', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
        $this->assertStringNotContainsString('style="display:flex', $source);
        $this->assertStringNotContainsString('Command Center', $source);
        $this->assertStringNotContainsString('Create channel-specific variants from Content Studio items. Export marks internal readiness only.', $source);
    }

    public function testMarketingDistributionBundleUsesFounderFirstPackageReview(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_distribution_bundle.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-distribution-bundle-page', $source);
        $this->assertStringContainsString('<h1>Manual Launch Packet</h1>', $source);
        $this->assertStringContainsString('Founder-ready publishing package. No external publishing happens here.', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-summary', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-stage-grid', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-stage-card', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-layout', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-copy', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-media', $source);
        $this->assertStringContainsString('marketing-distribution-bundle-today', $source);
        $this->assertStringContainsString('More packet tools', $source);
        $this->assertStringContainsString('Advanced Marketing Tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="bundle-grid"', $source);
        $this->assertStringNotContainsString('class="bundle-pre"', $source);
        $this->assertStringNotContainsString('class="media-pack"', $source);
        $this->assertStringNotContainsString('class="media-preview"', $source);
    }

    public function testMarketingOperatorExportPacksUseFounderFirstHandoffBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_operator_export_packs.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-operator-pack-page', $source);
        $this->assertStringContainsString('<h1>Operator Export Packs</h1>', $source);
        $this->assertStringContainsString('Safe campaign handoffs for manual publishing.', $source);
        $this->assertStringContainsString('marketing-operator-pack-summary', $source);
        $this->assertStringContainsString('marketing-operator-pack-stage-grid', $source);
        $this->assertStringContainsString('marketing-operator-pack-stage-card', $source);
        $this->assertStringContainsString('marketing-operator-pack-layout', $source);
        $this->assertStringContainsString('marketing-operator-pack-copy', $source);
        $this->assertStringContainsString('marketing-operator-pack-risks', $source);
        $this->assertStringContainsString('marketing-operator-pack-today', $source);
        $this->assertStringContainsString('More pack tools', $source);
        $this->assertStringContainsString('Advanced Marketing Tools', $source);
        $this->assertStringContainsString('No sending or publishing happens here.', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="export-pack-layout"', $source);
        $this->assertStringNotContainsString('class="export-pack-grid"', $source);
        $this->assertStringNotContainsString('class="export-pack-row"', $source);
        $this->assertStringNotContainsString('class="export-pack-pre"', $source);
        $this->assertStringNotContainsString('style="display:flex', $source);
        $this->assertStringNotContainsString('Persist manual campaign handoff snapshots for copy, links, approvals, risks, and operator next steps.', $source);
    }

    public function testMarketingChannelExportsUseFounderFirstChannelBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_channel_exports.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-channel-export-page', $source);
        $this->assertStringContainsString('<h1>Channel Export Bundles</h1>', $source);
        $this->assertStringContainsString('Package posts for manual publishing by channel.', $source);
        $this->assertStringContainsString('marketing-channel-export-summary', $source);
        $this->assertStringContainsString('marketing-channel-export-stage-grid', $source);
        $this->assertStringContainsString('marketing-channel-export-stage-card', $source);
        $this->assertStringContainsString('marketing-channel-export-layout', $source);
        $this->assertStringContainsString('marketing-channel-export-board', $source);
        $this->assertStringContainsString('marketing-channel-export-media', $source);
        $this->assertStringContainsString('marketing-channel-export-today', $source);
        $this->assertStringContainsString('More channel tools', $source);
        $this->assertStringContainsString('MarketingMediaReadinessUi::render($mediaReadiness)', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('style="display:flex', $source);
        $this->assertStringNotContainsString('style="margin-bottom', $source);
        $this->assertStringNotContainsString('style="margin-top', $source);
        $this->assertStringNotContainsString('Package distribution posts for manual publishing by channel. No external social, SMS, WhatsApp, ad, or email API is called.', $source);
    }

    public function testMarketingUtmLinksUseFounderFirstTrackingBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_utm_links.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-utm-page', $source);
        $this->assertStringContainsString('<h1>UTM Links</h1>', $source);
        $this->assertStringContainsString('Make manual campaign links trackable.', $source);
        $this->assertStringContainsString('marketing-utm-summary', $source);
        $this->assertStringContainsString('marketing-utm-stage-grid', $source);
        $this->assertStringContainsString('marketing-utm-stage-card', $source);
        $this->assertStringContainsString('marketing-utm-layout', $source);
        $this->assertStringContainsString('marketing-utm-board', $source);
        $this->assertStringContainsString('marketing-utm-create', $source);
        $this->assertStringContainsString('marketing-utm-today', $source);
        $this->assertStringContainsString('More tracking tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="marketing-grid"', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
        $this->assertStringNotContainsString('Generate campaign/content tracking links without changing publishing systems.', $source);
    }

    public function testMarketingEmailRunsUseFounderFirstManualSendBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_email_runs.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-email-runs-page', $source);
        $this->assertStringContainsString('<h1>Email Runs</h1>', $source);
        $this->assertStringContainsString('Prepare manual email sends without sending externally.', $source);
        $this->assertStringContainsString('marketing-email-run-summary', $source);
        $this->assertStringContainsString('marketing-email-run-stage-grid', $source);
        $this->assertStringContainsString('marketing-email-run-stage-card', $source);
        $this->assertStringContainsString('marketing-email-run-layout', $source);
        $this->assertStringContainsString('marketing-email-run-board', $source);
        $this->assertStringContainsString('marketing-email-run-create', $source);
        $this->assertStringContainsString('marketing-email-run-today', $source);
        $this->assertStringContainsString('More email tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="email-run-grid"', $source);
        $this->assertStringNotContainsString('class="email-run-row"', $source);
        $this->assertStringNotContainsString('style="display:flex', $source);
        $this->assertStringNotContainsString('Prepare email campaign runs, export manual send bundles, and keep execution tracked without sending externally.', $source);
    }

    public function testMarketingPerformanceUsesFounderFirstLearningBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_performance.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-performance-page', $source);
        $this->assertStringContainsString('<h1>Marketing Performance</h1>', $source);
        $this->assertStringContainsString('Learn what happened and choose the next test.', $source);
        $this->assertStringContainsString('marketing-performance-summary', $source);
        $this->assertStringContainsString('marketing-performance-stage-grid', $source);
        $this->assertStringContainsString('marketing-performance-stage-card', $source);
        $this->assertStringContainsString('marketing-performance-layout', $source);
        $this->assertStringContainsString('marketing-performance-loop', $source);
        $this->assertStringContainsString('Campaign-To-Revenue Loop', $source);
        $this->assertStringContainsString('Campaign Readiness', $source);
        $this->assertStringContainsString('Conversion Goals', $source);
        $this->assertStringContainsString('Add Conversion Goal', $source);
        $this->assertStringContainsString('Attribution Pipeline', $source);
        $this->assertStringContainsString('Influenced Leads', $source);
        $this->assertStringContainsString('marketing-performance-today', $source);
        $this->assertStringContainsString('More performance tools', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('MarketingWorkflowNextStepsUi::render($workflowNextSteps)', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="marketing-grid"', $source);
        $this->assertStringNotContainsString('class="performance-columns"', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
        $this->assertStringNotContainsString('Track manual distribution readiness, attribution, ROI, content influence, forms, landing pages, and UTM performance.', $source);
    }

    public function testMarketingHandoffsUseFounderFirstFollowUpBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_handoffs.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-handoffs-page', $source);
        $this->assertStringContainsString('<h1>Lead Handoffs</h1>', $source);
        $this->assertStringContainsString('Move interested leads into clear sales follow-up.', $source);
        $this->assertStringContainsString('marketing-handoff-summary', $source);
        $this->assertStringContainsString('marketing-handoff-stage-grid', $source);
        $this->assertStringContainsString('marketing-handoff-stage-card', $source);
        $this->assertStringContainsString('marketing-handoff-layout', $source);
        $this->assertStringContainsString('marketing-handoff-board', $source);
        $this->assertStringContainsString('Follow-Up Queue', $source);
        $this->assertStringContainsString('marketing-handoff-today', $source);
        $this->assertStringContainsString('More handoff tools', $source);
        $this->assertStringContainsString('Queue Filter', $source);
        $this->assertStringContainsString('Routing Rules', $source);
        $this->assertStringContainsString('Add Rule', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="handoff-grid"', $source);
        $this->assertStringNotContainsString('class="handoff-columns"', $source);
        $this->assertStringNotContainsString('class="handoff-row"', $source);
        $this->assertStringNotContainsString('style="display:flex', $source);
        $this->assertStringNotContainsString('Turn captured marketing intent into sales follow-up tasks with ownership, SLA tracking, and CRM record links.', $source);
    }

    public function testMarketingWeeklyReportUsesFounderFirstLearningBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_weekly_report.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-weekly-report-page', $source);
        $this->assertStringContainsString('<h1>Weekly Marketing Report</h1>', $source);
        $this->assertStringContainsString('Review the week and choose one lesson.', $source);
        $this->assertStringContainsString('marketing-weekly-period', $source);
        $this->assertStringContainsString('marketing-weekly-report-summary', $source);
        $this->assertStringContainsString('marketing-weekly-report-stage-grid', $source);
        $this->assertStringContainsString('marketing-weekly-report-stage-card', $source);
        $this->assertStringContainsString('marketing-weekly-report-layout', $source);
        $this->assertStringContainsString('marketing-weekly-report-board', $source);
        $this->assertStringContainsString('This Week', $source);
        $this->assertStringContainsString('marketing-weekly-report-today', $source);
        $this->assertStringContainsString('More weekly tools', $source);
        $this->assertStringContainsString('Execution Evidence', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="report-grid"', $source);
        $this->assertStringNotContainsString('class="report-card"', $source);
        $this->assertStringNotContainsString('class="report-list"', $source);
    }

    public function testMarketingMonthlyReportUsesFounderFirstStrategyBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_monthly_report.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-monthly-report-page', $source);
        $this->assertStringContainsString('<h1>Monthly Marketing Report</h1>', $source);
        $this->assertStringContainsString('Review the month and choose the next bet.', $source);
        $this->assertStringContainsString('marketing-monthly-period', $source);
        $this->assertStringContainsString('marketing-monthly-report-summary', $source);
        $this->assertStringContainsString('marketing-monthly-report-stage-grid', $source);
        $this->assertStringContainsString('marketing-monthly-report-stage-card', $source);
        $this->assertStringContainsString('marketing-monthly-report-layout', $source);
        $this->assertStringContainsString('marketing-monthly-report-board', $source);
        $this->assertStringContainsString('This Month', $source);
        $this->assertStringContainsString('marketing-monthly-report-today', $source);
        $this->assertStringContainsString('More monthly tools', $source);
        $this->assertStringContainsString('Execution Evidence', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="report-grid"', $source);
        $this->assertStringNotContainsString('class="report-card"', $source);
        $this->assertStringNotContainsString('class="report-list"', $source);
    }

    public function testMarketingOperationsUsesFounderFirstWeeklyWorkBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_operations.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-operations-page', $source);
        $this->assertStringContainsString('<h1>Marketing Operations</h1>', $source);
        $this->assertStringContainsString('Plan the week and keep campaign work moving.', $source);
        $this->assertStringContainsString('marketing-operations-week', $source);
        $this->assertStringContainsString('marketing-operations-summary', $source);
        $this->assertStringContainsString('marketing-operations-stage-grid', $source);
        $this->assertStringContainsString('marketing-operations-stage-card', $source);
        $this->assertStringContainsString('marketing-operations-layout', $source);
        $this->assertStringContainsString('marketing-operations-board', $source);
        $this->assertStringContainsString('Weekly Queue', $source);
        $this->assertStringContainsString('marketing-operations-today', $source);
        $this->assertStringContainsString('More operations tools', $source);
        $this->assertStringContainsString('AI Queue Suggestions', $source);
        $this->assertStringContainsString('Manual Automation Readiness', $source);
        $this->assertStringContainsString('No external send or publish happens here.', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="operations-grid"', $source);
        $this->assertStringNotContainsString('class="operations-stats"', $source);
        $this->assertStringNotContainsString('class="operations-row"', $source);
        $this->assertStringNotContainsString('class="automation-loop-grid"', $source);
        $this->assertStringNotContainsString('Run recurring planning rhythms, weekly queues, cadence checklists, and operating reports without publishing externally.', $source);
    }

    public function testMarketingQualityUsesFounderFirstReviewBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_quality.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-quality-page', $source);
        $this->assertStringContainsString('<h1>Content Quality Review</h1>', $source);
        $this->assertStringContainsString('Check copy before it goes into the campaign.', $source);
        $this->assertStringContainsString('marketing-quality-summary', $source);
        $this->assertStringContainsString('marketing-quality-stage-grid', $source);
        $this->assertStringContainsString('marketing-quality-stage-card', $source);
        $this->assertStringContainsString('marketing-quality-layout', $source);
        $this->assertStringContainsString('marketing-quality-board', $source);
        $this->assertStringContainsString('Review Queue', $source);
        $this->assertStringContainsString('marketing-quality-today', $source);
        $this->assertStringContainsString('More quality tools', $source);
        $this->assertStringContainsString('Review Dimensions', $source);
        $this->assertStringContainsString('Run Check', $source);
        $this->assertStringContainsString('Advisory checks do not publish externally, send messages, or overwrite draft copy.', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="quality-grid"', $source);
        $this->assertStringNotContainsString('class="quality-command"', $source);
        $this->assertStringNotContainsString('class="quality-row"', $source);
        $this->assertStringNotContainsString('Quality Review Command Center', $source);
        $this->assertStringNotContainsString('Review brand voice, persona fit, compliance, CTA, SEO readiness, and approval risk without overwriting drafts.', $source);
    }

    public function testMarketingRelationshipsUseFounderFirstConnectionMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_relationships.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-relationships-page', $source);
        $this->assertStringContainsString('<h1>Campaign Connection Map</h1>', $source);
        $this->assertStringContainsString('See what is connected and what needs linking.', $source);
        $this->assertStringContainsString('marketing-relationships-summary', $source);
        $this->assertStringContainsString('marketing-relationships-stage-grid', $source);
        $this->assertStringContainsString('marketing-relationships-stage-card', $source);
        $this->assertStringContainsString('marketing-relationships-layout', $source);
        $this->assertStringContainsString('marketing-relationships-board', $source);
        $this->assertStringContainsString('Connection Map', $source);
        $this->assertStringContainsString('marketing-relationships-today', $source);
        $this->assertStringContainsString('More connection tools', $source);
        $this->assertStringContainsString('Orphaned Records', $source);
        $this->assertStringContainsString('Refresh Connection Map', $source);
        $this->assertStringContainsString('No external sending, publishing, or API execution.', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="relationship-stats"', $source);
        $this->assertStringNotContainsString('class="relationship-layout"', $source);
        $this->assertStringNotContainsString('class="relationship-row"', $source);
        $this->assertStringNotContainsString('class="relationship-panel"', $source);
        $this->assertStringNotContainsString('Map how campaigns, briefs, content, landing pages, media, audiences, distribution, UTMs, and CRM tools connect.', $source);
    }

    public function testMarketingSeoUsesFounderFirstTopicMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_seo.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-seo-page', $source);
        $this->assertStringContainsString('<h1>SEO Topic Map</h1>', $source);
        $this->assertStringContainsString('Choose search topics that support the campaign.', $source);
        $this->assertStringContainsString('marketing-seo-summary', $source);
        $this->assertStringContainsString('marketing-seo-stage-grid', $source);
        $this->assertStringContainsString('marketing-seo-stage-card', $source);
        $this->assertStringContainsString('marketing-seo-layout', $source);
        $this->assertStringContainsString('marketing-seo-board', $source);
        $this->assertStringContainsString('Topic Plan', $source);
        $this->assertStringContainsString('marketing-seo-today', $source);
        $this->assertStringContainsString('More SEO tools', $source);
        $this->assertStringContainsString('Filter Topics', $source);
        $this->assertStringContainsString('Add Topic', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="marketing-grid"', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
        $this->assertStringNotContainsString('Plan keywords, search intent, funnel stage, and the content item that will answer the query.', $source);
    }

    public function testMarketingLandingPagesUseFounderFirstDestinationBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_landing_pages.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-landing-page', $source);
        $this->assertStringContainsString('<h1>Landing Page Board</h1>', $source);
        $this->assertStringContainsString('Build and review the campaign destination before launch.', $source);
        $this->assertStringContainsString("'Open Design home'", $source);
        $this->assertStringContainsString('marketing-landing-summary', $source);
        $this->assertStringContainsString('marketing-landing-stage-grid', $source);
        $this->assertStringContainsString('marketing-landing-stage-card', $source);
        $this->assertStringContainsString('marketing-landing-layout', $source);
        $this->assertStringContainsString('marketing-landing-board', $source);
        $this->assertStringContainsString('Landing Page Plans', $source);
        $this->assertStringContainsString('marketing-landing-today', $source);
        $this->assertStringContainsString('More landing tools', $source);
        $this->assertStringContainsString('Landing Visual Readiness', $source);
        $this->assertStringContainsString('Media Readiness', $source);
        $this->assertStringContainsString('Filter Pages', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="marketing-row"', $source);
        $this->assertStringNotContainsString('landing-visual-grid', $source);
        $this->assertStringNotContainsString('landing-visual-layout', $source);
        $this->assertStringNotContainsString('Plan CRM-native landing page copy, preview it, then publish a tokenized CRM-hosted page when ready.', $source);
    }

    public function testMarketingLandingPageEditUsesProductionDesignStudio(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_landing_page_edit.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-landing-edit-page', $source);
        $this->assertStringContainsString('data-design-studio', $source);
        $this->assertStringContainsString('Design Studio', $source);
        $this->assertStringContainsString('design-studio-toolbar', $source);
        $this->assertStringContainsString('design-library', $source);
        $this->assertStringContainsString('design-canvas', $source);
        $this->assertStringContainsString('design-inspector', $source);
        $this->assertStringContainsString('data-viewport="desktop"', $source);
        $this->assertStringContainsString('data-action="undo"', $source);
        $this->assertStringContainsString('data-action="publish"', $source);
        $this->assertStringContainsString('designStudioInitial', $source);
        $this->assertStringContainsString("apiUrl('marketing/landing_page_design.php')", $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('marketing-form-grid', $source);
        $this->assertStringNotContainsString('name="theme_settings"', $source);
        $this->assertStringNotContainsString('name="form_blocks"', $source);
        $this->assertStringNotContainsString('Publishing is intentionally out of scope', $source);
    }

    public function testMarketingActionRouterUsesFounderFirstRouteBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_action_router.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-action-router-page', $source);
        $this->assertStringContainsString('<h1>Marketing Action Router</h1>', $source);
        $this->assertStringContainsString('Choose the next useful Marketing move.', $source);
        $this->assertStringContainsString('marketing-page-header', $source);
        $this->assertStringContainsString('marketing-page-actions', $source);
        $this->assertStringContainsString('marketing-action-router-summary', $source);
        $this->assertStringContainsString('marketing-action-router-stage-grid', $source);
        $this->assertStringContainsString('marketing-action-router-stage-card', $source);
        $this->assertStringContainsString('marketing-action-router-stage-visual', $source);
        $this->assertStringContainsString('Refresh Next Moves', $source);
        $this->assertStringContainsString('Find Next Move', $source);
        $this->assertStringContainsString('Keep Safe', $source);
        $this->assertStringContainsString('marketing-action-router-layout', $source);
        $this->assertStringContainsString('marketing-action-router-board', $source);
        $this->assertStringContainsString('Route Board', $source);
        $this->assertStringContainsString('marketing-action-router-today', $source);
        $this->assertStringContainsString('More routing tools', $source);
        $this->assertStringContainsString('Filter Queue', $source);
        $this->assertStringContainsString('Connected Systems', $source);
        $this->assertStringContainsString('Router Guardrails', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('class="action-router-grid"', $source);
        $this->assertStringNotContainsString('class="action-router-stats"', $source);
        $this->assertStringNotContainsString('class="action-router-card', $source);
        $this->assertStringNotContainsString('class="action-router-panel"', $source);
        $this->assertStringNotContainsString('class="crm-integration-lane"', $source);
        $this->assertStringNotContainsString('Turn Marketing recommendations into safe CRM-linked actions', $source);
    }

    public function testMarketingExecutionUsesFounderFirstExecutionMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_execution.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-execution-page', $source);
        $this->assertStringContainsString('<h1>Marketing Execution Control Center</h1>', $source);
        $this->assertStringContainsString('Move campaigns from ready to done without losing safety evidence.', $source);
        $this->assertStringContainsString('marketing-execution-summary', $source);
        $this->assertStringContainsString('marketing-execution-layout', $source);
        $this->assertStringContainsString('marketing-execution-board', $source);
        $this->assertStringContainsString('Execution Map', $source);
        $this->assertStringContainsString('marketing-execution-card', $source);
        $this->assertStringContainsString('marketing-execution-today', $source);
        $this->assertStringContainsString('More execution tools', $source);
        $this->assertStringContainsString('Live Setup Wizard', $source);
        $this->assertStringContainsString('Campaign Execution Console', $source);
        $this->assertStringContainsString('Execution Safety Boundary', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('See what is ready, blocked, awaiting review, or waiting for manual export across launches, connectors, email runs, and channel packages.', $source);
        $this->assertStringNotContainsString('href="marketing_decisions.php">Decision Center</a>', $source);
        $this->assertStringNotContainsString('href="marketing_channel_exports.php">Channel Exports</a>', $source);
        $this->assertStringNotContainsString('href="marketing.php">Command Center</a>', $source);
    }

    public function testMarketingContentUsesFounderFirstContentBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_content.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-content-page', $source);
        $this->assertStringContainsString('<h1>Content Studio</h1>', $source);
        $this->assertStringContainsString('Choose the next piece to draft, review, or clear.', $source);
        $this->assertStringContainsString('marketing-content-summary', $source);
        $this->assertStringContainsString('marketing-content-layout', $source);
        $this->assertStringContainsString('marketing-content-board', $source);
        $this->assertStringContainsString('Content Board', $source);
        $this->assertStringContainsString('marketing-content-stage-card', $source);
        $this->assertStringContainsString('marketing-content-card-action', $source);
        $this->assertStringContainsString('marketing-content-today', $source);
        $this->assertStringContainsString('Guidance and media readiness', $source);
        $this->assertStringContainsString('Open content workbench', $source);
        $this->assertStringContainsString('Production Command Queue', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertSame(1, substr_count($source, 'class="btn-premium-secondary marketing-content-card-action"'));

        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('href="marketing.php"', $source);
        $this->assertStringNotContainsString('href="marketing_calendar.php"', $source);
        $this->assertStringNotContainsString('Plan, draft, schedule, and move marketing work through one practical workbench.', $source);
    }

    public function testMarketingContentEditUsesFounderFirstContentBuilder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_content_edit.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-content-edit-page', $source);
        $this->assertStringContainsString('<h1>Content Builder</h1>', $source);
        $this->assertStringContainsString('Marketing Content: create one clear piece before production.', $source);
        $this->assertStringContainsString('marketing-content-edit-summary', $source);
        $this->assertStringContainsString('marketing-content-edit-stage-grid', $source);
        $this->assertStringContainsString('marketing-content-edit-card', $source);
        $this->assertStringContainsString('marketing-content-edit-layout', $source);
        $this->assertStringContainsString('marketing-content-edit-core', $source);
        $this->assertStringContainsString('marketing-content-edit-draft', $source);
        $this->assertStringContainsString('marketing-content-edit-today', $source);
        $this->assertStringContainsString('More production details', $source);
        $this->assertStringContainsString('More linked records', $source);
        $this->assertStringContainsString('More review checks', $source);
        $this->assertStringContainsString('More media placement', $source);
        $this->assertStringContainsString('Generate Draft', $source);
        $this->assertStringContainsString('Save Content', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        foreach (['Name Content', 'Set Goal', 'Write Draft', 'Add Visuals', 'Plan Review', 'Schedule Work'] as $label) {
            $this->assertStringContainsString($label, $source);
        }
        $this->assertStringContainsString('marketing-content-edit-card-action', $source);

        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('marketing-form-grid', $source);
        $this->assertStringNotContainsString('marketing-editor-actions', $source);
        $this->assertStringNotContainsString('Capture the channel plan, audience, schedule, and draft in one workspace-scoped record.', $source);
    }

    public function testMarketingContentViewUsesFounderFirstReviewBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_content_view.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-content-view-page', $source);
        $this->assertStringContainsString('Review the draft, clear blockers, and move it forward.', $source);
        $this->assertStringContainsString('marketing-content-view-summary', $source);
        $this->assertStringContainsString('marketing-content-view-layout', $source);
        $this->assertStringContainsString('marketing-content-view-board', $source);
        $this->assertStringContainsString('Content Review Board', $source);
        $this->assertStringContainsString('marketing-content-view-card', $source);
        $this->assertStringContainsString('marketing-content-view-card-action', $source);
        $this->assertStringContainsString('marketing-content-view-today', $source);
        $this->assertStringContainsString('More content evidence', $source);
        $this->assertStringContainsString('Tools and review workflow', $source);
        $this->assertStringContainsString('Draft Body', $source);
        $this->assertStringContainsString('Attached Media', $source);
        $this->assertStringContainsString('Content Creation Tools', $source);
        $this->assertStringContainsString('Approvals', $source);
        $this->assertStringContainsString('Comments', $source);
        $this->assertStringContainsString('Versions', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringContainsString('&middot;', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('href="marketing_distribution.php"', $source);
        $this->assertStringNotContainsString('href="marketing_utm_links.php"', $source);
    }

    public function testMarketingLandingPageViewUsesFounderFirstDestinationReviewBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_landing_page_view.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-landing-view-page', $source);
        $this->assertStringContainsString('Review the destination, visuals, form, and publish state.', $source);
        $this->assertStringContainsString('marketing-landing-view-summary', $source);
        $this->assertStringContainsString('marketing-landing-view-layout', $source);
        $this->assertStringContainsString('marketing-landing-view-board', $source);
        $this->assertStringContainsString('Destination Review Board', $source);
        $this->assertStringContainsString('marketing-landing-view-card', $source);
        $this->assertStringContainsString('marketing-landing-view-card-action', $source);
        $this->assertStringContainsString('marketing-landing-view-today', $source);
        $this->assertStringContainsString('More destination evidence', $source);
        $this->assertStringContainsString('Preview and manage page', $source);
        $this->assertStringContainsString('Landing Visual Readiness', $source);
        $this->assertStringContainsString('Landing Page Preview', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertSame(1, substr_count($source, 'class="btn-premium-secondary marketing-landing-view-card-action"'));

        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('href="marketing_utm_links.php"', $source);
        $this->assertStringNotContainsString('href="marketing_distribution.php"', $source);
    }

    public function testMarketingSystemMapUsesFounderFirstHealthMap(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_system_map.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-system-map-page', $source);
        $this->assertStringContainsString('<h1>Marketing System Map</h1>', $source);
        $this->assertStringContainsString('See system health and open the weakest area first.', $source);
        $this->assertStringContainsString('marketing-system-map-summary', $source);
        $this->assertStringContainsString('marketing-system-map-layout', $source);
        $this->assertStringContainsString('marketing-system-map-board', $source);
        $this->assertStringContainsString('System Health Map', $source);
        $this->assertStringContainsString('marketing-system-map-stage-grid', $source);
        $this->assertStringContainsString('marketing-system-map-stage-card', $source);
        $this->assertStringContainsString('marketing-system-map-today', $source);
        $this->assertStringContainsString('More system tools', $source);
        $this->assertStringContainsString('System Evidence', $source);
        $this->assertStringContainsString('Guardrails', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('class="system-map-summary"', $source);
        $this->assertStringNotContainsString('class="system-map-grid"', $source);
        $this->assertStringNotContainsString('class="system-map-stage"', $source);
        $this->assertStringNotContainsString('See how setup, context, strategy, content, media, distribution, revenue, and operations connect across the CRM.', $source);
    }

    public function testMarketingDecisionsUsesFounderFirstDecisionBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_decisions.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-decisions-page', $source);
        $this->assertStringContainsString('<h1>Decision Center</h1>', $source);
        $this->assertStringContainsString('Choose the next safe campaign move.', $source);
        $this->assertStringContainsString('marketing-decisions-summary', $source);
        $this->assertStringContainsString('marketing-decisions-layout', $source);
        $this->assertStringContainsString('marketing-decisions-board', $source);
        $this->assertStringContainsString('Decision Board', $source);
        $this->assertStringContainsString('marketing-decision-card', $source);
        $this->assertStringContainsString('marketing-decisions-today', $source);
        $this->assertStringContainsString('More decision tools', $source);
        $this->assertStringContainsString('Manual-first boundary', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('class="decision-grid"', $source);
        $this->assertStringNotContainsString('class="decision-stats"', $source);
        $this->assertStringNotContainsString('class="decision-row"', $source);
        $this->assertStringNotContainsString('Turn launch blockers, AI recommendations, media gaps, and integration risks into clear human decisions.', $source);
        $this->assertStringContainsString('<summary>More choices</summary>', $source);
    }

    public function testMarketingAssistantsUsesFounderFirstAiHelpBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_assistants.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-assistants-page', $source);
        $this->assertStringContainsString('<h1>Marketing Assistants</h1>', $source);
        $this->assertStringContainsString('Choose a specialist and ask.', $source);
        $this->assertStringContainsString('marketing-assistants-summary', $source);
        $this->assertStringContainsString('marketing-assistants-layout', $source);
        $this->assertStringContainsString('marketing-assistants-board', $source);
        $this->assertStringContainsString('<h2>Specialists</h2>', $source);
        $this->assertStringContainsString('marketing-assistant-card', $source);
        $this->assertStringContainsString('data-assistant-type', $source);
        $this->assertStringContainsString('Use specialist', $source);
        $this->assertStringContainsString('marketing-assistants-today', $source);
        $this->assertStringContainsString('More AI context tools', $source);
        $this->assertStringContainsString('Strategy and campaign planning tools', $source);
        $this->assertStringContainsString('AI Workspace Brain', $source);
        $this->assertStringContainsString('AI Context Quality Gate', $source);
        $this->assertStringContainsString('AI Context Evidence', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('assistant-grid', $source);
        $this->assertStringNotContainsString('assistant-command-grid', $source);
        $this->assertStringNotContainsString('ai-brain-panel', $source);
        $this->assertStringNotContainsString('ai-context-gate', $source);
        $this->assertStringNotContainsString('Use CRM context to create draft-side recommendations, content plans, comments, and tool runs.', $source);
    }

    public function testTopLevelMarketingProductsShareCompactWorkspaceSystem(): void
    {
        foreach (['design.php', 'social_media.php', 'marketing.php', 'marketing_assistants.php'] as $page) {
            $source = file_get_contents(__DIR__ . '/../../../public/' . $page);
            $this->assertNotFalse($source, $page);
            $this->assertStringContainsString('plugin-workspaces.css', (string) $source, $page);
            $this->assertStringContainsString('plugin-workspace-shell', (string) $source, $page);
        }

        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/plugin-workspaces.css');
        $this->assertNotFalse($css);
        $css = (string) $css;
        $this->assertStringContainsString('Flat metric rails', $css);
        $this->assertStringContainsString('Underline workspace tabs', $css);
        $this->assertStringContainsString('.marketing-stage-grid { display: none; }', $css);
        $this->assertStringContainsString('@media (max-width: 760px)', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function testMarketingAdminUsesFounderFirstAdminHealthBoard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../public/marketing_admin.php');
        $this->assertNotFalse($source);
        $source = (string) $source;

        $this->assertStringContainsString('marketing-admin-page', $source);
        $this->assertStringContainsString('<h1>Marketing Admin Diagnostics</h1>', $source);
        $this->assertStringContainsString('Keep Marketing safe, ready, and clean.', $source);
        $this->assertStringContainsString('marketing-admin-summary', $source);
        $this->assertStringContainsString('marketing-admin-layout', $source);
        $this->assertStringContainsString('marketing-admin-board', $source);
        $this->assertStringContainsString('Admin Health Board', $source);
        $this->assertStringContainsString('marketing-admin-health-card', $source);
        $this->assertStringContainsString('marketing-admin-today', $source);
        $this->assertStringContainsString('More admin diagnostics', $source);
        $this->assertStringContainsString('Admin actions and reports', $source);
        $this->assertStringContainsString('Marketing QA Console', $source);
        $this->assertStringContainsString('No-secret diagnostics', $source);
        $this->assertStringContainsString('Creative Prompt Registry', $source);
        $this->assertStringContainsString('Operating Loop Prompts', $source);
        $this->assertStringContainsString('Integration Readiness', $source);
        $this->assertStringContainsString('Manager-only controls for demo/starter data', $source);
        $this->assertStringContainsString('No hard delete', $source);
        $this->assertStringContainsString('data-tooltip', $source);
        $this->assertSame(1, substr_count($source, 'class="content-card marketing-admin-board"'));
        $this->assertStringNotContainsString('<style>', $source);
        $this->assertStringNotContainsString('style=', $source);
        $this->assertStringNotContainsString('class="admin-grid"', $source);
        $this->assertStringNotContainsString('class="admin-stats"', $source);
        $this->assertStringNotContainsString('class="qa-check-grid"', $source);
        $this->assertStringNotContainsString('class="workflow-admin-lanes"', $source);
        $this->assertStringNotContainsString('Review production readiness, audit history, starter data cleanup, and hardening checks for this workspace.', $source);
    }

    public function testInternalMarketingPagesKeepOperatorShellHooks(): void
    {
        $publicDir = realpath(__DIR__ . '/../../../public');
        $this->assertIsString($publicDir);

        foreach (MarketingUi::internalPageFilenames() as $page) {
            $source = file_get_contents($publicDir . '/' . $page);
            $this->assertNotFalse($source, $page);
            $source = (string) $source;

            if ($page === 'marketing_landing_page_preview.php') {
                $this->assertStringContainsString('landing-preview', $source, $page . ' should keep authenticated preview chrome.');
                $this->assertStringContainsString('marketing-landing-preview', $source, $page . ' should use shared authenticated preview styling.');
                $this->assertStringContainsString('preview-banner', $source, $page . ' should expose preview controls.');
                $this->assertStringNotContainsString('<style>', $source, $page . ' should keep preview styling in the shared stylesheet.');
                continue;
            }

            $this->assertStringContainsString('page-premium', $source, $page . ' should use the authenticated premium page shell.');
            $this->assertStringContainsString('page-header', $source, $page . ' should expose the shared operator header hook.');
            $this->assertStringContainsString('page-header-actions', $source, $page . ' should keep actions in the shared header action region.');
        }

        foreach (['marketing_landing_public.php', 'marketing_track.php', 'marketing_unsubscribe.php'] as $page) {
            $source = file_get_contents($publicDir . '/' . $page);
            $this->assertNotFalse($source, $page);
            $this->assertStringNotContainsString('marketing-operator-strip', (string) $source, $page . ' should stay outside authenticated operator chrome.');
            if ($page === 'marketing_landing_public.php') {
                $this->assertStringContainsString('marketing-public.css', (string) $source, $page . ' should use the public landing stylesheet.');
                $this->assertStringNotContainsString('<style>', (string) $source, $page . ' should keep visual styling out of the endpoint.');
            }
            if ($page === 'marketing_unsubscribe.php') {
                $this->assertStringContainsString('marketing-public.css', (string) $source, $page . ' should use the public preference stylesheet.');
                $this->assertStringContainsString('marketing-public-preferences', (string) $source, $page . ' should expose public preference hooks.');
                $this->assertStringNotContainsString('<style>', (string) $source, $page . ' should keep visual styling out of the endpoint.');
            }
        }
    }

    public function testMarketingPageQualityMatrixCoversInventoryAndSafetyGuardrails(): void
    {
        $publicDir = realpath(__DIR__ . '/../../../public');
        $this->assertIsString($publicDir);

        $matrix = MarketingPageQualityMatrix::build($publicDir);

        $this->assertSame('ready', $matrix['status'], json_encode($matrix['pages'], JSON_PRETTY_PRINT));
        $this->assertSame(count(MarketingUi::internalPageFilenames()), $matrix['counts']['total']);
        $this->assertSame(0, $matrix['counts']['blocked']);
        $this->assertSame([], $matrix['missing_registrations']);
        $this->assertFalse((bool) $matrix['guardrails']['public_landing_chrome']);
        $this->assertFalse((bool) $matrix['guardrails']['public_tracking_chrome']);
        $this->assertFalse((bool) $matrix['guardrails']['secret_values_exposed']);
        $this->assertFalse((bool) $matrix['guardrails']['raw_debug_output_allowed']);

        foreach ($matrix['pages'] as $page) {
            $this->assertSame('ready', $page['status'], (string) ($page['page'] ?? 'unknown'));
            $checkKeys = array_column((array) $page['checks'], 'key');
            $this->assertContains('auth_guard', $checkKeys, (string) $page['page']);
            $this->assertContains('permission_guard', $checkKeys, (string) $page['page']);
            $this->assertContains('debug_output', $checkKeys, (string) $page['page']);
            $this->assertContains('secret_output', $checkKeys, (string) $page['page']);
        }

        foreach ($matrix['public_only_pages'] as $page) {
            $this->assertSame('ready', $page['status'], (string) ($page['page'] ?? 'public'));
        }
    }
}
