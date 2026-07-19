<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Services\MarketingUi;
use CRM\Services\MarketingMarketplaceGateService;
use PHPUnit\Framework\TestCase;

class MainNavigationTest extends TestCase
{
    private function layout(): string
    {
        $layout = file_get_contents(__DIR__ . '/../../../views/layouts/base.php');

        $this->assertNotFalse($layout);

        return str_replace(["\r\n", "\r"], "\n", (string) $layout);
    }

    private function startupJourneyPage(): string
    {
        $page = file_get_contents(__DIR__ . '/../../../public/startup_journey.php');

        $this->assertNotFalse($page);

        return str_replace(["\r\n", "\r"], "\n", (string) $page);
    }

    private function dashboardPage(): string
    {
        $page = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($page);

        return str_replace(["\r\n", "\r"], "\n", (string) $page);
    }

    public function testDashboardBillingSummaryUsesAboveNavNoticeInsteadOfCard(): void
    {
        $layout = $this->layout();
        $dashboard = $this->dashboardPage();

        $this->assertStringNotContainsString('dashboard-billing-card', $dashboard);
        $this->assertStringNotContainsString('dashboard-billing-kpi', $dashboard);
        $this->assertStringContainsString('id="workspace-plan-summary-banner"', $layout);
        $this->assertStringContainsString('class="workspace-system-notice workspace-plan-summary-notice"', $layout);
        $this->assertStringContainsString(
            "\$sessionAutomationPage === 'dashboard.php'\n            && empty(\$workspaceBillingState['billing_blocked'])",
            $layout
        );
        $this->assertStringContainsString('$workspacePlanSummaryEntitlements = (array) ($workspaceBillingState[\'entitlements\'] ?? []);', $layout);
        $this->assertStringContainsString('$workspacePlanSummarySubscription = (array) ($workspaceBillingState[\'subscription\'] ?? []);', $layout);
        $this->assertStringContainsString('\'available_credits\' => (int) ($workspaceBillingState[\'available_credits\'] ?? $workspaceBillingState[\'available_tokens\'] ?? 0),', $layout);
        $this->assertStringContainsString('data-workspace-id="<?php echo (int) ($workspacePlanSummary[\'workspace_id\'] ?? 0); ?>"', $layout);
        $this->assertStringContainsString('crm:workspace-plan-summary-banner-dismissed:', $layout);
        $this->assertStringContainsString('var autoDismissMs = 30000;', $layout);
    }

    public function testAffordabilityModalUsesDonateFooterAndPaidCreditState(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('$workspaceAffordabilityCanTopUp = !empty($workspaceBillingState[\'entitlements\'][\'can_top_up\']);', $layout);
        $this->assertStringContainsString('Keep workspace running', $layout);
        $this->assertStringContainsString('$workspaceDonationsEnabled = \\CRM\\Modules\\WorkspaceBillingSettings::donationsEnabled();', $layout);
        $this->assertStringContainsString('$workspaceDonationsEnabled ? \'Choose access, credits, or support.\' : \'Choose access or credits.\'', $layout);
        $this->assertStringContainsString('Manage CRM access.', $layout);
        $this->assertStringContainsString('$workspaceAffordabilityCanTopUp ? \'Top up AI usage.\' : \'Available on paid access.\'', $layout);
        $this->assertStringContainsString('Donations do not change package or credit balance.', $layout);
        $this->assertStringContainsString('class="workspace-affordability-donate-link" data-workspace-donation-open>Donate</button>', $layout);
        $this->assertStringNotContainsString('Need help with payment?', $layout);
        $this->assertStringContainsString('\'href\' => $workspaceAffordabilityCanTopUp ? $workspaceAffordabilityTokenUrl : null,', $layout);
        $this->assertStringNotContainsString('\'href\' => $workspaceAffordabilityTokenUrl,', $layout);
        $this->assertStringContainsString('<div class="workspace-affordability-option is-token-option is-inactive" aria-disabled="true">', $layout);
        $this->assertStringContainsString(
            "} elseif ((\$workspaceAiBillingState['ai_blocked_reason'] ?? null) === 'wallet_depleted' && \$workspaceAffordabilityCanTopUp) {",
            $layout
        );
        $this->assertStringContainsString("Auth::check() && !\$isProtectedDemoSession && !\$layoutIsDefaultWorkspace", $layout);
    }

    public function testTasksArePromotedToMainNavigation(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            "\$isTasks = in_array(\$currentPage, ['tasks.php', 'task_create.php', 'task_edit.php', 'task_view.php'], true);",
            $layout
        );
        $this->assertMatchesRegularExpression(
            '/<a href="tasks\.php" class="main-nav-tab <\?php echo \$isTasks \? \'active\' : \'\'; \?>" data-tab="tasks">/',
            $layout
        );
        $this->assertStringContainsString('<span class="tab-label">Tasks</span>', $layout);
    }

    public function testMainNavigationCountersUseSharedTabBadgeStyle(): void
    {
        $layout = $this->layout();

        foreach (['navContactsNewCount', 'navInboxUnreadCount', 'navNewTaskCount'] as $counterName) {
            $this->assertMatchesRegularExpression(
                '/<\?php if \(\$' . $counterName . ' > 0\): \?>\s+<span class="tab-badge"><\?php echo htmlspecialchars\(\$formatNavBadge\(\$' . $counterName . '\)\); \?><\/span>/',
                $layout
            );
        }
        $this->assertStringContainsString('getNewUnresolvedSinceCount($navUserId, $lastTasksOpenedAt)', $layout);
        $this->assertStringContainsString(
            "\$formatNavBadge = static fn(int \$count): string => \$count > 99 ? '99+' : (string) \$count;",
            $layout
        );
        $this->assertStringContainsString('.main-nav-tab.active .tab-badge,', $layout);
        $this->assertStringContainsString('.main-nav-tab:hover .tab-badge,', $layout);
    }

    public function testMarketingProductNavigationUsesConcisePluginPaths(): void
    {
        $layout = $this->layout();
        $access = [
            MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => ['can_run' => true],
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => ['can_run' => true],
            MarketingMarketplaceGateService::FEATURE_DESIGN => ['can_run' => true],
        ];

        $this->assertStringContainsString('MarketingUi::renderDesktopPluginNavigation', $layout);
        $this->assertStringContainsString('MarketingUi::renderMobilePluginNavigation', $layout);

        foreach (MarketingUi::pluginNavigationGroups() as $feature => $group) {
            $desktop = MarketingUi::renderDesktopPluginNavigation($feature, true, true, true, true, false, $access);
            $mobile = MarketingUi::renderMobilePluginNavigation($feature, true, true, true, true, false, $access);
            $this->assertStringContainsString(htmlspecialchars($group['advanced_label'], ENT_QUOTES, 'UTF-8'), $desktop);
            $this->assertStringContainsString(htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'), $mobile);
            $this->assertLessThanOrEqual(8, substr_count($desktop, 'class="nav-dropdown-item'), $feature);
        }

        $marketingPro = MarketingUi::renderDesktopPluginNavigation(MarketingMarketplaceGateService::FEATURE_MARKETING_PRO, true, true, true, true, false, $access);
        $this->assertStringContainsString('Campaign Home', $marketingPro);
        $this->assertStringContainsString('Advanced Marketing Operations', $marketingPro);
        $this->assertStringNotContainsString('href="marketing_system_map.php"', $marketingPro);
        $this->assertStringNotContainsString('href="marketing_execution.php"', $marketingPro);
        $this->assertStringNotContainsString('href="marketing_admin.php"', $marketingPro);

        $socialLocked = MarketingUi::renderDesktopPluginNavigation(MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA, true, true, true, true, false, [
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => [
                'can_run' => false,
                'setup_url' => 'workspace_skills.php?module=social_media#setup',
                'setup_label' => 'Set up Social Media',
            ],
        ]);
        $this->assertStringContainsString('Set up Social Media', $socialLocked);
        $this->assertStringNotContainsString('href="social_media.php"', $socialLocked);
    }

    public function testMarketingNavigationIsGroupedBySplitPlugins(): void
    {
        $layout = $this->layout();
        $this->assertStringContainsString('id="design-dropdown"', $layout);
        $this->assertStringContainsString('id="social-media-dropdown"', $layout);
        $this->assertStringContainsString('id="marketing-pro-dropdown"', $layout);
        $this->assertStringNotContainsString('id="marketing-dropdown"', $layout);
        $this->assertStringContainsString('MarketingUi::isNavigationPage($currentPage)', $layout);
        $this->assertStringContainsString('navigationStateForUser($currentUserForNav)', $layout);
        foreach (['>Campaign Manager</span>', '>Social Media</span>', '>Design</span>'] as $heading) {
            $this->assertStringContainsString($heading, $layout);
        }
        $this->assertMatchesRegularExpression(
            '/id="marketing-pro-dropdown".*?<i class="fas fa-bullhorn"><\/i>.*?<span class="tab-label">Campaign Manager<\/span>/s',
            $layout
        );
        $this->assertMatchesRegularExpression(
            '/id="sales-dropdown".*?<i class="fas fa-chart-line"><\/i>.*?<span>Sales<\/span>/s',
            $layout
        );
        $this->assertStringContainsString('$activeMarketingFeature === \\CRM\\Services\\MarketingMarketplaceGateService::FEATURE_DESIGN', $layout);
        $this->assertStringContainsString('$activeMarketingFeature === \\CRM\\Services\\MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA', $layout);
        $this->assertStringContainsString('$activeMarketingFeature === \\CRM\\Services\\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO', $layout);
    }

    public function testBeginnerModeKeepsInstalledProductNavigationAndSoftensLabels(): void
    {
        $layout = $this->layout();
        $access = [
            MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => ['can_run' => true],
            MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => ['can_run' => true],
            MarketingMarketplaceGateService::FEATURE_DESIGN => ['can_run' => true],
        ];
        $beginnerDesktop = '';
        $beginnerMobile = '';
        foreach (array_keys($access) as $feature) {
            $beginnerDesktop .= MarketingUi::renderDesktopPluginNavigation($feature, true, true, true, true, true, $access);
            $beginnerMobile .= MarketingUi::renderMobilePluginNavigation($feature, true, true, true, true, true, $access);
        }

        $this->assertStringContainsString('UIExperienceService', $layout);
        $this->assertStringContainsString('$marketingNavigationState = [];', $layout);
        $this->assertStringContainsString('$marketingMenuPluginInstalled = false;', $layout);
        $this->assertStringContainsString('$marketingMenuPluginInstalled = $marketingGate->hasInstalledVisibleMenuPlugin($navWorkspaceId);', $layout);
        $this->assertStringContainsString('$marketplaceNavLabel = $uiExperience->marketplaceLabel($navExperienceMode);', $layout);
        $this->assertStringContainsString('$showMarketingProductNav = $canMarketingRead && $marketingMenuPluginInstalled;', $layout);

        foreach (['Campaign Manager', 'Posts & Sharing', 'Pages & Forms', 'Campaign Home', 'Posts & Sharing Home', 'Offer Pages'] as $label) {
            $escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString($escapedLabel, $beginnerDesktop . $beginnerMobile);
        }
        $this->assertStringNotContainsString('Marketing Pro', $beginnerDesktop . $beginnerMobile);
    }

    public function testMarketingTopLevelProductsRequireTheirInstalledPlugin(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            '$showMarketingProductNav = $canMarketingRead && $marketingMenuPluginInstalled;',
            $layout
        );
        $this->assertStringContainsString("['menu_visible']", $layout);
        $this->assertStringContainsString('<?php if ($showDesignNav): ?>', $layout);
        $this->assertStringContainsString('<?php if ($showSocialMediaNav): ?>', $layout);
        $this->assertStringContainsString('<?php if ($showMarketingProNav): ?>', $layout);
    }

    public function testOwnerSettingsQuickAccessIsAvailableWithoutAdminSettingsLinks(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('$canOwnerSettingsAllowlist = $navActiveWorkspaceRole === \'owner\';', $layout);
        $this->assertStringContainsString('$canSettingsQuickAccess = $canOwnerSettingsAllowlist', $layout);
        $this->assertStringContainsString('<?php if (!$isProtectedDemoSession && $canSettingsQuickAccess): ?>', $layout);
        $this->assertStringContainsString('<?php if ($canSettingsQuickAccess ?? false): ?><a href="<?php echo htmlspecialchars($basePath . \'/settings.php\'); ?>" class="nav-link">Settings</a><?php endif; ?>', $layout);
        $this->assertStringContainsString('Two-Factor Authentication</a>', $layout);
        $this->assertStringContainsString('Notification Preferences</a>', $layout);
    }

    public function testPluginNewBadgesUseVisibleCounterPlacement(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('.nav-new-badge {', $layout);
        $this->assertStringContainsString('.nav-vip-badge {', $layout);
        $this->assertStringContainsString("position: absolute;\n        top: 2px;\n        right: 0;", $layout);
        $this->assertStringContainsString('box-shadow: 0 2px 6px rgba(4, 120, 87, 0.28);', $layout);
        $this->assertStringContainsString('.mobile-menu .nav-new-badge {', $layout);
        $this->assertStringContainsString('.mobile-menu .nav-vip-badge {', $layout);
        $this->assertStringContainsString('position: static;', $layout);
        $this->assertStringContainsString('$renderNavVipBadge = static function (): string {', $layout);
        $this->assertStringContainsString('return \'<span class="nav-vip-badge">VIP</span>\';', $layout);

        $this->assertStringContainsString(
            "<i class=\"fas fa-compass\"></i>\n                                <?php echo \$renderNavNewBadge(\$showStartupJourneyNewBadge); ?>\n                                <span class=\"tab-label\">Founder Loop</span>",
            $layout
        );
        $this->assertStringContainsString(
            "<i class=\"fas fa-route\"></i>\n                                <?php echo \$renderNavNewBadge(\$showStartupJourneyNewBadge); ?>\n                                <span class=\"tab-label\">Clarity Journey</span>",
            $layout
        );
        $this->assertStringContainsString(
            "<i class=\"fas fa-wallet\"></i>\n                                <?php echo \$renderNavNewBadge(\$showFinanceNewBadge); ?>\n                                <span class=\"tab-label\">Finance</span>",
            $layout
        );

        $this->assertStringNotContainsString('<span class="tab-label">Founder Loop<?php if ($showStartupJourneyNewBadge): ?>', $layout);
        $this->assertStringNotContainsString('<span class="tab-label">Clarity Journey<?php if ($showStartupJourneyNewBadge): ?>', $layout);
    }

    public function testClarityJourneyAndFounderLoopNavAreMutuallyExclusive(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('$startupJourneyCompletedAt = null;', $layout);
        $this->assertStringContainsString('$startupJourneyComplete = $startupJourneyCompletedAt !== null;', $layout);
        $this->assertStringContainsString(
            '$canViewStartupJourneyNav = $canViewMarketplaceNav && $startupJourneyInstalled && !$startupJourneyComplete;',
            $layout
        );
        $this->assertStringContainsString(
            '$canViewFounderLoopNav = $canViewFounderLoopPermission && $startupJourneyInstalled && $startupJourneyComplete;',
            $layout
        );
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$canViewFounderLoopNav\): \?>\s+<a href="founder_operating_loop\.php" class="main-nav-tab/',
            $layout
        );
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$canViewStartupJourneyNav\): \?>\s+<a href="startup_journey\.php" class="main-nav-tab/',
            $layout
        );
        $this->assertStringContainsString(
            '<?php if ($canViewFounderLoopNav ?? false): ?><a href="founder_operating_loop.php" class="nav-link">Founder Loop',
            $layout
        );
        $this->assertStringContainsString(
            '<?php if ($canViewStartupJourneyNav ?? false): ?><a href="startup_journey.php" class="nav-link">Clarity Journey',
            $layout
        );
    }

    public function testClarityJourneyCompletionPanelProvidesFounderLoopHandoff(): void
    {
        $page = $this->startupJourneyPage();

        $this->assertStringContainsString('class="journey-completion-panel"', $page);
        $this->assertStringContainsString('data-journey-completion-panel', $page);
        $this->assertStringContainsString('Foundation complete', $page);
        $this->assertStringContainsString('Clarity Journey is complete', $page);
        $this->assertStringContainsString(
            'Your business foundation is saved. Founder Loop turns it into first deals. AI Coach will use this context for ongoing recommendations.',
            $page
        );
        $this->assertStringContainsString('href="founder_operating_loop.php"', $page);
        $this->assertStringContainsString('Open AI Coach', $page);
        $this->assertStringContainsString('Set up AI Coach', $page);
        $this->assertStringContainsString('workspace_skills.php?module=', $page);
        $this->assertStringContainsString('var completionPanel = document.querySelector(\'[data-journey-completion-panel]\');', $page);
        $this->assertStringContainsString('completionPanel.hidden = !(', $page);
        $this->assertStringNotContainsString('data-journey-completion-cta', $page);
    }

    public function testClarityJourneySaveControlsUseApiUrlEndpoints(): void
    {
        $page = $this->startupJourneyPage();

        $this->assertStringContainsString("apiUrl('startup_journey/save_stage.php')", $page);
        $this->assertStringContainsString("apiUrl('startup_journey/draft_field.php')", $page);
        $this->assertStringContainsString('fetch(journeySaveEndpoint', $page);
        $this->assertStringContainsString('fetch(journeyDraftEndpoint', $page);
        $this->assertStringContainsString('navigator.sendBeacon(journeySaveEndpoint', $page);
        $this->assertStringNotContainsString("fetch('api/startup_journey/", $page);
        $this->assertStringNotContainsString("navigator.sendBeacon('api/startup_journey/", $page);
        $this->assertStringContainsString('parseJsonResponse(response,', $page);
        $this->assertStringContainsString("payload.manual_completion_required", $page);
    }

    public function testClarityJourneyWritingFieldsHaveBreathingRoom(): void
    {
        $page = $this->startupJourneyPage();

        $this->assertStringContainsString(
            '.journey-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1.05rem 1rem;margin-top:.9rem}',
            $page
        );
        $this->assertStringContainsString(
            '.journey-field-label-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:.65rem;line-height:1.42}',
            $page
        );
        $this->assertStringContainsString(
            '.journey-field textarea{width:100%;box-sizing:border-box;min-height:118px;',
            $page
        );
        $this->assertStringContainsString('padding:.78rem .82rem', $page);
        $this->assertStringContainsString('.journey-notes-field textarea{min-height:156px}', $page);
        $this->assertStringContainsString('class="journey-field journey-notes-field"', $page);
        $this->assertStringContainsString('.journey-form-grid{grid-template-columns:1fr;gap:.9rem}', $page);
        $this->assertStringNotContainsString('repeat(auto-fit,minmax(220px,1fr))', $page);
        $this->assertStringNotContainsString('style="margin-top:.65rem;"', $page);
    }

    public function testClarityJourneyQuestionTooltipsAreInteractive(): void
    {
        $page = $this->startupJourneyPage();

        $this->assertStringContainsString('data-journey-tooltip-trigger', $page);
        $this->assertStringContainsString('class="journey-tooltip-bubble"', $page);
        $this->assertStringContainsString('role="tooltip"', $page);
        $this->assertStringContainsString('aria-expanded="false"', $page);
        $this->assertStringContainsString('closeJourneyTooltips', $page);
        $this->assertStringContainsString('event.stopPropagation();', $page);
        $this->assertStringContainsString("event.key === 'Escape') closeJourneyTooltips();", $page);
        $this->assertStringContainsString('Capture the few assumptions needed for this stage.', $page);
        $this->assertStringContainsString('Only the selected stage is expanded. Use Journey Map to open another stage.', $page);
        $this->assertStringNotContainsString('class="journey-tooltip" title=', $page);
    }

    public function testClarityJourneyReliabilityControlsAreVisibleAndWired(): void
    {
        $page = $this->startupJourneyPage();

        foreach ([
            'data-journey-map-open',
            'data-journey-progress-chip',
            'data-focus-toggle',
            'data-complete-stage',
            'data-reopen-stage',
            'data-save-recovery',
            'data-save-retry',
            'data-leave-anyway',
        ] as $needle) {
            $this->assertStringContainsString($needle, $page);
        }

        $this->assertStringNotContainsString('data-save-draft', $page);
        $this->assertStringContainsString('$currentReadyToComplete', $page);
        $this->assertStringContainsString('$showCompleteStage = $currentStatus !== \'completed\' && $currentReadyToComplete;', $page);
        $this->assertStringContainsString('$showReopenStage = $currentStatus === \'completed\';', $page);
        $this->assertStringContainsString('var completionNextStageUrl =', $page);
        $this->assertStringContainsString('function setStageActionVisibility(stageStatus, readiness)', $page);
        $this->assertStringContainsString("readinessStatus === 'ready_to_complete'", $page);
        $this->assertStringContainsString('button.hidden = !canComplete', $page);
        $this->assertStringContainsString('window.location.href = completionNextStageUrl', $page);
        $this->assertStringContainsString('completion_intent: completionIntent', $page);
        $this->assertStringContainsString('save_source: saveSource', $page);
        $this->assertStringContainsString("completion_intent: 'draft'", $page);
        $this->assertStringContainsString("save_source: 'beacon'", $page);
        $this->assertStringContainsString('Draft saved', $page);
        $this->assertStringContainsString('Save failed', $page);
        $this->assertStringContainsString('Completed stages across the full Clarity Journey.', $page);
        $this->assertStringContainsString('Journey complete', $page);
        $this->assertStringContainsString('stages complete', $page);
        $this->assertStringContainsString("document.querySelector('[data-journey-progress-chip]')", $page);
        $this->assertStringContainsString('journeyProgressLabel(progressCounts)', $page);
        $this->assertStringContainsString('data-inspector-status', $page);
        $this->assertStringNotContainsString('data-stage-status-chip', $page);
    }

    public function testClarityJourneyFocusModeHasActiveWorkspaceState(): void
    {
        $page = $this->startupJourneyPage();

        $this->assertStringContainsString('data-focus-toggle aria-pressed="false"', $page);
        $this->assertStringContainsString('data-focus-toggle-label', $page);
        $this->assertStringContainsString('data-focus-strip', $page);
        $this->assertStringContainsString('data-focus-strip-status', $page);
        $this->assertStringContainsString('data-focus-strip-title', $page);
        $this->assertStringContainsString('journey-shell.is-focus-mode', $page);
        $this->assertStringContainsString("journeyShell.classList.toggle('is-focus-mode', on)", $page);
        $this->assertStringContainsString("button.classList.toggle('is-focus-active', on)", $page);
        $this->assertStringContainsString("button.setAttribute('aria-pressed', on ? 'true' : 'false')", $page);
        $this->assertStringContainsString("label.textContent = on ? 'Focus On' : 'Focus Mode';", $page);
        $this->assertStringContainsString('focusStrip.hidden = !on', $page);
        $this->assertStringContainsString('scrollFocusTarget(focusStrip', $page);
    }

    public function testClarityJourneyDraftAssistShowsProvenanceAndSavesMetadata(): void
    {
        $page = $this->startupJourneyPage();

        $this->assertStringContainsString('data-draft-review-note', $page);
        $this->assertStringContainsString('activeDraftMeta', $page);
        $this->assertStringContainsString('payload.confidence', $page);
        $this->assertStringContainsString('payload.draft_source', $page);
        $this->assertStringContainsString('pendingSaveSource = \'ai_draft_assist\';', $page);
        $this->assertStringContainsString('body.ai_assist_metadata = aiAssistMetadata;', $page);
        $this->assertStringContainsString('Use draft inserts editable text only and saves it as a draft.', $page);
    }

    public function testMobilePluginNewBadgesRemainInline(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            'Founder Loop<?php echo $renderNavNewBadge((bool) ($showStartupJourneyNewBadge ?? false)); ?>',
            $layout
        );
        $this->assertStringContainsString(
            'Clarity Journey<?php echo $renderNavNewBadge((bool) ($showStartupJourneyNewBadge ?? false)); ?>',
            $layout
        );
        $this->assertStringContainsString(
            'Finance<?php echo $renderNavNewBadge((bool) ($showFinanceNewBadge ?? false)); ?>',
            $layout
        );
    }

    public function testRetiredEmailTemplateLibraryIsNotLinkedFromMainNavigation(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('href="email_templates.php" class="nav-link">Email Templates</a>', $layout);
        $this->assertStringNotContainsString('email_template_library.php', $layout);
        $this->assertStringNotContainsString('Template Library', $layout);
    }

    public function testCustomerServiceAndOrganizationIntelligenceArePromotedToMainNavigation(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString('data-tab="nurture"', $layout);
        $this->assertStringNotContainsString('data-tab="deals"', $layout);
        $this->assertStringNotContainsString('data-tab="targets"', $layout);

        $this->assertStringContainsString('$canViewCustomerServiceNav = \CRM\Authorization::can(\'nurture.read\', $currentUserForNav);', $layout);
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$canViewCustomerServiceNav\): \?>\s+<a href="nurture\.php" class="main-nav-tab <\?php echo \$isNurture \? \'active\' : \'\'; \?>" data-tab="customer-service">/',
            $layout
        );
        $this->assertStringContainsString('<span class="tab-label">Customer Service</span>', $layout);

        $this->assertStringContainsString('$isOrganizationIntelligence = in_array($currentPage, [\'hr_analytics.php\', \'organization_intelligence_setup.php\'], true);', $layout);
        $this->assertStringContainsString('$canViewOrganizationIntelligenceNav = !$isProtectedDemoSession', $layout);
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$canViewOrganizationIntelligenceNav\): \?>\s+<a href="<\?php echo htmlspecialchars\(\$hrAnalyticsNavUrl\); \?>" class="main-nav-tab <\?php echo \$isOrganizationIntelligence \? \'active\' : \'\'; \?>" data-tab="organization-intelligence">/',
            $layout
        );
        $this->assertStringContainsString(
            "<i class=\"fas fa-user-tie\"></i>\n                                <?php echo \$renderNavVipBadge(); ?>\n                                <span class=\"tab-label\">Organization Intelligence</span>",
            $layout
        );
        $this->assertStringContainsString('<span class="tab-label">Organization Intelligence</span>', $layout);

        $this->assertStringContainsString('<?php if ($canViewCustomerServiceNav ?? \CRM\Authorization::can(\'nurture.read\', \CRM\Auth::user())): ?><a href="nurture.php" class="nav-link">Customer Service</a><?php endif; ?>', $layout);
        $this->assertStringContainsString('<?php if ($canViewOrganizationIntelligenceNav ?? false): ?><a href="<?php echo htmlspecialchars($hrAnalyticsNavUrl ?? \'hr_analytics.php\'); ?>" class="nav-link">Organization Intelligence<?php echo $renderNavVipBadge(); ?></a><?php endif; ?>', $layout);

        $this->assertStringContainsString(
            '<a href="deals.php" class="nav-dropdown-item"><i class="fas fa-handshake"></i>Deals</a>',
            $layout
        );
        $this->assertStringContainsString(
            '<a href="targets.php" class="nav-dropdown-item"><i class="fas fa-bullseye"></i>Targets</a>',
            $layout
        );
        $this->assertStringNotContainsString('<a href="nurture.php" class="nav-dropdown-item"><i class="fas fa-seedling"></i>Nurture</a>', $layout);
        $this->assertStringNotContainsString('Customer Nurture', $layout);
        $this->assertStringNotContainsString('$canHrAnalytics && $hrAnalyticsRuntimeReady', $layout);
    }

    public function testSalesDropdownIsActiveForSalesOwnedPages(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString(
            '$isSalesActive = $isDeals || $isTargets;',
            $layout
        );
        $this->assertStringContainsString(
            '<a href="#" class="nav-dropdown-toggle main-nav-tab <?php echo $isSalesActive ? \'active\' : \'\'; ?>" onclick="event.preventDefault(); toggleDropdown(\'sales-dropdown\');">',
            $layout
        );
    }
}
