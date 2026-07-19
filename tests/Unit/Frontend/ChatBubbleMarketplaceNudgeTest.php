<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class ChatBubbleMarketplaceNudgeTest extends TestCase
{
    public function testChatBubbleDoesNotRenderMarketplaceNudgeControls(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/chat-bubble.css');

        $this->assertNotFalse($js);
        $this->assertNotFalse($css);
        $this->assertStringNotContainsString('marketplace_nudges', (string) $js);
        $this->assertStringNotContainsString('activation_bundle_nudges', (string) $js);
        $this->assertStringNotContainsString('buildMarketplaceNudgeHtml', (string) $js);
        $this->assertStringNotContainsString('buildActivationBundleNudgeHtml', (string) $js);
        $this->assertStringNotContainsString('data-marketplace-action="ask_why"', (string) $js);
        $this->assertStringNotContainsString('data-marketplace-action="snoozed"', (string) $js);
        $this->assertStringNotContainsString('data-marketplace-action="dismissed"', (string) $js);
        $this->assertStringNotContainsString('/api/chat/marketplace_feedback.php', (string) $js);
        $this->assertStringNotContainsString('/api/workspace/marketplace_recommendation_event.php', (string) $js);
        $this->assertStringNotContainsString('/api/workspace/marketplace_activation_bundle_event.php', (string) $js);
        $this->assertStringNotContainsString('trackMarketplaceRecommendationEvent', (string) $js);
        $this->assertStringNotContainsString('trackActivationBundleEvent', (string) $js);
        $this->assertStringNotContainsString('chat-bubble-marketplace-setup-journey', (string) $js);
        $this->assertStringNotContainsString('chat-bubble-activation-bundle-nudge', (string) $js);
        $this->assertStringNotContainsString('Setup progress', (string) $js);
        $this->assertStringNotContainsString('.chat-bubble-marketplace-nudge', (string) $css);
        $this->assertStringNotContainsString('.chat-bubble-marketplace-primary', (string) $css);
        $this->assertStringNotContainsString('.chat-bubble-marketplace-setup-journey', (string) $css);
    }

    public function testChatBubbleRendersStructuredPageAwareInsightSafely(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/chat-bubble.css');
        $welcomeApi = file_get_contents(__DIR__ . '/../../../api/chat/welcome.php');

        $this->assertNotFalse($js);
        $this->assertNotFalse($css);
        $this->assertNotFalse($welcomeApi);
        $this->assertStringContainsString('current_page=', (string) $js);
        $this->assertStringNotContainsString('chatWelcomeShown:', (string) $js);
        $this->assertStringContainsString('data-clarity-opening', (string) $js);
        $this->assertStringContainsString('removeClarityOpeningMessages', (string) $js);
        $this->assertStringContainsString('Start with the setup or task that feels most blocking', (string) $js);
        $this->assertStringContainsString('addStructuredInsightMessage', (string) $js);
        $this->assertStringContainsString('buildStructuredInsightHtml', (string) $js);
        $this->assertStringContainsString('renderMarkdownLite', (string) $js);
        $this->assertStringContainsString('escapeHtml(item)', (string) $js);
        $this->assertStringContainsString('chat-bubble-insight-thumb', (string) $js);
        $this->assertStringContainsString('chat-bubble-insight-cta', (string) $js);
        $this->assertStringContainsString('var hasCta = ctaLabel !== \'\' && ctaUrl !== \'\';', (string) $js);
        $this->assertStringContainsString('.chat-bubble-insight-thumb', (string) $css);
        $this->assertStringContainsString('.chat-bubble-insight-cta', (string) $css);
        $this->assertStringContainsString('.chat-bubble-insight--no-cta', (string) $css);
        $this->assertStringContainsString('opening_insight', (string) $welcomeApi);
        $this->assertStringContainsString('ClarityPageInsightService', (string) $welcomeApi);
    }

    public function testChatBubbleStructuredInsightSupportsNoCtaAndDefensivePayloads(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/chat-bubble.css');

        $this->assertNotFalse($js);
        $this->assertNotFalse($css);
        $this->assertStringContainsString('Array.isArray(insight)', (string) $js);
        $this->assertStringContainsString('var cleanBullets = bullets.map', (string) $js);
        $this->assertStringContainsString('filter(function (item)', (string) $js);
        $this->assertStringContainsString('if (!title && !body && !cleanBullets.length && !hasThumbnail && !hasCta) return \'\';', (string) $js);
        $this->assertStringContainsString('hasCta ? \'chat-bubble-insight--with-cta\' : \'chat-bubble-insight--no-cta\'', (string) $js);
        $this->assertStringContainsString('hasThumbnail ? \'chat-bubble-insight--with-thumb\' : \'chat-bubble-insight--no-thumb\'', (string) $js);
        $this->assertStringContainsString('if (hasCta)', (string) $js);
        $this->assertStringContainsString('.chat-bubble-insight--no-cta ul:last-child', (string) $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', (string) $css);
        $this->assertStringContainsString('white-space: normal', (string) $css);
    }

    public function testChatBubbleOpeningReplacementPreservesUserMessages(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');

        $this->assertNotFalse($js);
        $this->assertStringContainsString('querySelectorAll(\'[data-clarity-opening="true"]\')', (string) $js);
        $this->assertStringContainsString('msg.setAttribute(\'data-clarity-opening\', \'true\')', (string) $js);
        $this->assertStringContainsString('removeClarityOpeningMessages();', (string) $js);
        $this->assertStringNotContainsString('messagesEl.innerHTML =', (string) $js);
        $this->assertStringNotContainsString('messagesEl.textContent =', (string) $js);
    }

    public function testOrganizationIntelligenceChatUsesExecutiveSuiteContextOnlyOnAnalyticsPage(): void
    {
        $analytics = file_get_contents(__DIR__ . '/../../../public/hr_analytics.php');
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');
        $askApi = file_get_contents(__DIR__ . '/../../../api/chat/ask.php');

        $this->assertNotFalse($analytics);
        $this->assertNotFalse($js);
        $this->assertNotFalse($askApi);
        $this->assertStringContainsString('window.organizationIntelligenceChatContext', (string) $analytics);
        $this->assertStringContainsString("'page_key' => 'organization_intelligence'", (string) $analytics);
        $this->assertStringNotContainsString("'success' => array_slice(\$organizationIntelligenceSuccessSignals", (string) $analytics);
        $this->assertStringNotContainsString("'watch' => array_slice(\$organizationIntelligenceWatchSignals", (string) $analytics);
        $this->assertStringContainsString('function isOrganizationIntelligencePage()', (string) $js);
        $this->assertStringContainsString("getCurrentPage() === 'hr_analytics.php'", (string) $js);
        $this->assertStringContainsString("data-chat-mode', 'executive-suite'", (string) $js);
        $this->assertStringContainsString('Welcome to the executive suite', (string) $js);
        $this->assertStringContainsString('Preparing executive brief', (string) $js);
        $this->assertStringContainsString('payload.page_context = getOrganizationIntelligenceContext();', (string) $js);
        $this->assertStringContainsString('sanitizeOrganizationIntelligencePageContext', (string) $askApi);
        $this->assertStringContainsString("\$currentPage !== 'hr_analytics.php'", (string) $askApi);
        $this->assertStringContainsString("(string) (\$rawContext['page_key'] ?? '') !== 'organization_intelligence'", (string) $askApi);
        $this->assertStringContainsString('organization_intelligence_page_context', (string) $askApi);
        $this->assertStringContainsString('ORGANIZATION INTELLIGENCE EXECUTIVE SUITE MODE', (string) $askApi);
        $this->assertStringContainsString('buildOrganizationIntelligenceFallbackAnswer', (string) $askApi);
    }

    public function testChatBubbleAssetsUseDynamicVersionUrls(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../../views/layouts/base.php');
        $emailView = file_get_contents(__DIR__ . '/../../../public/email_view.php');

        $this->assertNotFalse($layout);
        $this->assertNotFalse($emailView);
        $this->assertStringContainsString('$versionedAssetUrl', (string) $layout);
        $this->assertStringContainsString('$chatBubbleCssUrl = $versionedAssetUrl(\'css/chat-bubble.css\');', (string) $layout);
        $this->assertStringContainsString('$aiUiConsistencyJsUrl = $versionedAssetUrl(\'js/ai-ui-consistency.js\');', (string) $layout);
        $this->assertStringContainsString('$chatBubbleJsUrl = $versionedAssetUrl(\'js/chat-bubble.js\');', (string) $layout);
        $this->assertStringContainsString('filemtime($assetPath)', (string) $layout);
        $this->assertStringContainsString('json_encode($chatBubbleJsUrl)', (string) $layout);
        $this->assertStringContainsString('json_encode($aiUiConsistencyJsUrl)', (string) $layout);
        $this->assertStringContainsString('loadScript(aiUiConsistencySrc, function ()', (string) $layout);
        $this->assertStringContainsString('loadScript(chatBubbleSrc, function () {})', (string) $layout);
        $this->assertStringContainsString('htmlspecialchars($chatBubbleCssUrl)', (string) $layout);
        $this->assertStringNotContainsString('/js/chat-bubble.js\');', (string) $layout);
        $this->assertStringNotContainsString('chat-bubble.css?v=chat-panel-lg', (string) $layout);
        $this->assertStringContainsString("filemtime(__DIR__ . '/assets/js/ai-ui-consistency.js')", (string) $emailView);

        $chat = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');
        $this->assertNotFalse($chat);
        $this->assertStringContainsString("typeof window.AIUiConsistency.renderExecutionStatus === 'function'", (string) $chat);
        $this->assertStringContainsString("stage: 'execution_status'", (string) $chat);
    }

    public function testMarketplaceStructuredInsightKeepsThumbnailBeforeRecommendationText(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');

        $this->assertNotFalse($js);
        $thumbnailPosition = strpos((string) $js, 'chat-bubble-insight-thumb');
        $titlePosition = strpos((string) $js, 'chat-bubble-insight-title');
        $ctaPosition = strpos((string) $js, 'chat-bubble-insight-cta');

        $this->assertIsInt($thumbnailPosition);
        $this->assertIsInt($titlePosition);
        $this->assertIsInt($ctaPosition);
        $this->assertLessThan($titlePosition, $thumbnailPosition);
        $this->assertGreaterThan($titlePosition, $ctaPosition);
    }

    public function testAiCoachRendersMarketplaceActionsAndTaskMetadataHooks(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/ai-coach-modal.js');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/ai-coach.css');

        $this->assertNotFalse($js);
        $this->assertNotFalse($css);
        $this->assertStringContainsString('marketplaceFeedbackUrl', (string) $js);
        $this->assertStringContainsString('marketplaceEventUrl', (string) $js);
        $this->assertStringContainsString('activationBundleEventUrl', (string) $js);
        $this->assertStringContainsString('marketplace_skill_key', (string) $js);
        $this->assertStringContainsString('marketplace_setup_url', (string) $js);
        $this->assertStringContainsString('marketplace_activation_bundle', (string) $js);
        $this->assertStringContainsString('/api/workspace/marketplace_recommendation_event.php', (string) $js);
        $this->assertStringContainsString('/api/workspace/marketplace_activation_bundle_event.php', (string) $js);
        $this->assertStringContainsString('trackMarketplaceRecommendationEvent', (string) $js);
        $this->assertStringContainsString('trackActivationBundleEvent', (string) $js);
        $this->assertStringContainsString('renderActivationBundleGuidance', (string) $js);
        $this->assertStringContainsString('data-marketplace-feedback-type="snoozed"', (string) $js);
        $this->assertStringContainsString('data-marketplace-feedback-type="dismissed"', (string) $js);
        $this->assertStringContainsString('marketplaceSkillKey && marketplaceFeedbackEnabled', (string) $js);
        $this->assertStringContainsString('ai-coach-marketplace-setup-journey', (string) $js);
        $this->assertStringContainsString('data-marketplace-next-setup-step', (string) $js);
        $this->assertStringContainsString('marketplace_next_setup_step', (string) $js);
        $this->assertStringContainsString("source: 'coach'", (string) $js);
        $this->assertStringContainsString('source=coach&marketplace_skill=', (string) $js);
        $this->assertStringContainsString('activation_bundle_key', (string) $js);
        $this->assertStringContainsString('ai-coach-activation-bundle-guidance', (string) $js);
        $this->assertStringContainsString('.ai-coach-btn-marketplace', (string) $css);
        $this->assertStringContainsString('.ai-coach-btn-marketplace-feedback', (string) $css);
        $this->assertStringContainsString('.ai-coach-marketplace-setup-journey', (string) $css);
        $this->assertStringContainsString('.ai-coach-activation-bundle-guidance', (string) $css);

        $taskApi = file_get_contents(__DIR__ . '/../../../api/tasks/create.php');
        $this->assertNotFalse($taskApi);
        $this->assertStringContainsString('marketplace_activation_bundle', (string) $taskApi);
    }

    public function testAiCoachWorkspaceExposesDailyFocusIdeaLabAndOptionalStrategy(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/ai-coach-modal.js');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/ai-coach.css');
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($js);
        $this->assertNotFalse($css);
        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('data-tab-target="recommendations"', (string) $js);
        $this->assertStringContainsString('Daily Focus', (string) $js);
        $this->assertStringContainsString('data-tab-target="idea-validation"', (string) $js);
        $this->assertStringContainsString('Idea Lab', (string) $js);
        $this->assertStringContainsString('ideaValidationUrl', (string) $js);
        $this->assertStringContainsString('renderIdeaValidationForm', (string) $js);
        $this->assertStringContainsString('data-tab-target="personal-strategy"', (string) $js);
        $this->assertStringContainsString('My Strategy', (string) $js);
        $this->assertStringContainsString('showPlanDayLayer', (string) $js);
        $this->assertStringContainsString('ai-coach-workspace', (string) $css);
        $this->assertStringContainsString('data-founder-command-center', (string) $dashboard);
        $this->assertStringContainsString('data-founder-command-coach', (string) $dashboard);
        $this->assertStringContainsString('Your operating rhythm', (string) $dashboard);
        $this->assertStringNotContainsString('data-ai-coach-launcher', (string) $dashboard);
        $this->assertStringNotContainsString('data-tab-target="coach-brief"', (string) $js);
        $this->assertStringNotContainsString('data-tab-target="lean-canvas"', (string) $js);
    }

    public function testMarketplacePageContainsCtaTrackingHooks(): void
    {
        $php = file_get_contents(__DIR__ . '/../../../public/workspace_skills.php');

        $this->assertNotFalse($php);
        $this->assertStringContainsString('marketplace-recommendation-cta', (string) $php);
        $this->assertStringNotContainsString('marketplace-insight-chip', (string) $php);
        $this->assertStringNotContainsString('marketplace-adaptive-note', (string) $php);
        $this->assertStringNotContainsString('marketplace-activation-bundles', (string) $php);
        $this->assertStringNotContainsString('Recommended for this workspace', (string) $php);
        $this->assertStringNotContainsString('<h2 style="font-size:1.1rem;margin:0;color:#0f172a;">Activation bundles</h2>', (string) $php);
        $this->assertStringContainsString('select_activation_bundle', (string) $php);
        $this->assertStringContainsString('dismiss_activation_bundle', (string) $php);
        $this->assertStringContainsString('complete_activation_bundle', (string) $php);
        $this->assertStringContainsString('WorkspaceMarketplaceActivationBundleService', (string) $php);
        $this->assertStringContainsString('WorkspaceMarketplaceActivationBundleInsightService', (string) $php);
        $this->assertStringNotContainsString('marketplace-activation-bundle-insight-chip', (string) $php);
        $this->assertStringNotContainsString('marketplace-activation-bundle-adaptive-note', (string) $php);
        $this->assertStringContainsString('data-marketplace-activation-bundle-key', (string) $php);
        $this->assertStringContainsString('activation_bundle_key', (string) $php);
        $this->assertStringContainsString('marketplace_activation_bundle_event.php', (string) $php);
        $this->assertStringContainsString('trackActivationBundleClick', (string) $php);
        $this->assertStringContainsString('WorkspaceMarketplaceActivationBundleEventService', (string) $php);
        $this->assertStringContainsString('pin_recommendation', (string) $php);
        $this->assertStringContainsString('mute_recommendation', (string) $php);
        $this->assertStringContainsString('disable_recommendation_surface', (string) $php);
        $this->assertStringContainsString('WorkspaceMarketplaceRecommendationInsightService', (string) $php);
        $this->assertStringContainsString('complete_setup_step', (string) $php);
        $this->assertStringContainsString('skip_setup_step', (string) $php);
        $this->assertStringContainsString('reset_setup_step', (string) $php);
        $this->assertStringContainsString('marketplace_setup_journey_event.php', (string) $php);
        $this->assertStringContainsString('trackSetupJourneyOpen', (string) $php);
        $this->assertStringContainsString('marketplace_recommendation_event.php', (string) $php);
        $this->assertStringContainsString('trackRecommendationClick', (string) $php);
        $this->assertStringContainsString("event_type: 'cta_clicked'", (string) $php);

        $bundleInsightService = file_get_contents(__DIR__ . '/../../../services/WorkspaceMarketplaceActivationBundleInsightService.php');
        $this->assertNotFalse($bundleInsightService);
        $this->assertStringContainsString('Interest without completion', (string) $bundleInsightService);
        $this->assertStringContainsString('Setup interest', (string) $bundleInsightService);
    }

    public function testDiagnosticsPageRendersMarketplaceInsightPanel(): void
    {
        $php = file_get_contents(__DIR__ . '/../../../public/ai_automation_diagnostics.php');

        $this->assertNotFalse($php);
        $this->assertStringContainsString('getMarketplaceRecommendationInsights', (string) $php);
        $this->assertStringContainsString('Marketplace Recommendation Insights', (string) $php);
        $this->assertStringContainsString('Suggest-only interpretation', (string) $php);
        $this->assertStringContainsString('Marketplace Setup Journeys', (string) $php);
        $this->assertStringContainsString('Adaptive Recommendation Signals', (string) $php);
        $this->assertStringContainsString('Marketplace Controls', (string) $php);
        $this->assertStringContainsString('Marketplace Activation Bundles', (string) $php);
        $this->assertStringContainsString('getMarketplaceActivationBundleSummary', (string) $php);
        $this->assertStringContainsString('getMarketplaceActivationBundleEventSummary', (string) $php);
        $this->assertStringContainsString('Marketplace Activation Bundle Insights', (string) $php);
        $this->assertStringContainsString('getMarketplaceActivationBundleInsights', (string) $php);
        $this->assertStringContainsString('Adaptive Activation Bundle Signals', (string) $php);
        $this->assertStringContainsString('getMarketplaceActivationBundleAdaptiveSignalSummary', (string) $php);
        $this->assertStringContainsString('marketplaceActivationBundleAdaptiveSignalSummary', (string) $php);
        $this->assertStringContainsString('No adaptive activation bundle signals matched the current filters', (string) $php);
        $this->assertStringContainsString('marketplaceActivationBundleInsights', (string) $php);
        $this->assertStringContainsString('No activation bundle insights matched the current filters', (string) $php);
        $this->assertStringContainsString('marketplaceActivationBundleCounts', (string) $php);
        $this->assertStringContainsString('marketplaceActivationBundleEventCounts', (string) $php);
        $this->assertStringContainsString('getMarketplaceRecommendationControlSummary', (string) $php);
        $this->assertStringContainsString('getMarketplaceAdaptiveSignalSummary', (string) $php);
        $this->assertStringContainsString('getMarketplaceSetupJourneySummary', (string) $php);
        $this->assertStringContainsString('marketplace_setup_journey', (string) $php);
        $this->assertStringContainsString('metric_snapshot', (string) $php);
    }
}
