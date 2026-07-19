<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class BeginnerGuidancePhase2SourceTest extends TestCase
{
    public function testBeginnerGuidanceEndpointIsAuthenticatedGetAndDoesNotInvokeLiveAi(): void
    {
        $endpoint = file_get_contents(__DIR__ . '/../../../api/dashboard/beginner_guidance.php');

        $this->assertNotFalse($endpoint);
        $this->assertStringContainsString('Auth::check()', (string) $endpoint);
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'GET'", (string) $endpoint);
        $this->assertStringContainsString('BeginnerGuidanceService', (string) $endpoint);
        $this->assertStringContainsString('fallbackPayload($userId, $mode, $workspaceId)', (string) $endpoint);
        $this->assertStringNotContainsString('AICoach', (string) $endpoint);
        $this->assertStringNotContainsString('OpenAI', (string) $endpoint);
    }

    public function testPlainReadinessEndpointIsAuthenticatedGetAndDoesNotInvokeLiveAi(): void
    {
        $endpoint = file_get_contents(__DIR__ . '/../../../api/dashboard/plain_readiness.php');

        $this->assertNotFalse($endpoint);
        $this->assertStringContainsString('Auth::check()', (string) $endpoint);
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'GET'", (string) $endpoint);
        $this->assertStringContainsString('PlainLanguageReadinessService', (string) $endpoint);
        $this->assertStringContainsString('fallbackPayload($userId, $mode)', (string) $endpoint);
        $this->assertStringNotContainsString('AICoach', (string) $endpoint);
        $this->assertStringNotContainsString('OpenAI', (string) $endpoint);
    }

    public function testBeginnerGuidanceServiceDoesNotReturnDashboardFallbackCta(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../services/BeginnerGuidanceService.php');

        $this->assertNotFalse($service);
        $this->assertStringContainsString("public const CACHE_VERSION = 'v2';", (string) $service);
        $this->assertStringNotContainsString("'dashboard.php'", (string) $service);
        $this->assertStringNotContainsString('Stay on dashboard', (string) $service);
    }

    public function testSettingsCanPersistExperienceModeForAdmins(): void
    {
        $settings = file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertNotFalse($settings);
        $this->assertStringContainsString('use CRM\Services\UIExperienceService;', (string) $settings);
        $this->assertStringContainsString('$canManageUiExperienceMode', (string) $settings);
        $this->assertStringContainsString('name="ui_experience_mode"', (string) $settings);
        $this->assertStringContainsString('UIExperienceService::PREFERENCE_KEY', (string) $settings);
        $this->assertStringContainsString('Beginner - simple daily guidance', (string) $settings);
        $this->assertStringContainsString('Advanced - show full workspaces', (string) $settings);
    }

    public function testOutcomeEventsAllowGuidanceViewAndClickMeasurement(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../services/OutcomeEventService.php');

        $this->assertNotFalse($service);
        $this->assertStringContainsString("'dashboard.guidance.viewed'", (string) $service);
        $this->assertStringContainsString("'dashboard.guidance.clicked'", (string) $service);
        $this->assertStringContainsString("'dashboard.readiness.viewed'", (string) $service);
        $this->assertStringContainsString("'dashboard.readiness.clicked'", (string) $service);
        $this->assertStringNotContainsString("'dashboard.guidance.ai_generated'", (string) $service);
    }

    public function testPlainReadinessServiceUsesVersionedCacheAndPlainLabels(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../services/PlainLanguageReadinessService.php');

        $this->assertNotFalse($service);
        $this->assertStringContainsString("public const CACHE_VERSION = 'v3';", (string) $service);
        $this->assertStringContainsString("'plain_readiness:' . self::CACHE_VERSION", (string) $service);
        $this->assertStringContainsString('Profile and products saved', (string) $service);
        $this->assertStringContainsString('Blocked until a channel is connected', (string) $service);
        $this->assertStringContainsString('Ready to create invoices', (string) $service);
        $this->assertStringContainsString('Revenue Momentum', (string) $service);
        $this->assertStringContainsString('dashboard_momentum', (string) $service);
        $this->assertStringNotContainsString('campaign workspace', strtolower((string) $service));
    }

    public function testDashboardAddsDestinationContextWithoutChangingCommandStrip(): void
    {
        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');

        $this->assertNotFalse($dashboard);
        $this->assertStringContainsString('appendDashboardDestinationContext', (string) $dashboard);
        $this->assertStringContainsString(
            '(string) ($initialPlainReadiness[\'source\'] ?? \'dashboard_readiness\')',
            (string) $dashboard
        );
        $this->assertStringContainsString("source: 'dashboard_guidance'", (string) $dashboard);
        $this->assertStringContainsString("source: readiness.source || 'dashboard_readiness'", (string) $dashboard);
        $this->assertStringNotContainsString('data-plain-readiness-coach', (string) $dashboard);
        $this->assertStringContainsString('Revenue Focus', (string) $dashboard);
        $this->assertStringContainsString('data-plain-readiness-card', (string) $dashboard);
        $this->assertStringContainsString('TTFV (14d)', (string) $dashboard);
    }

    public function testGuidedDestinationServiceIsDeterministicAndCached(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../services/GuidedSetupDestinationService.php');

        $this->assertNotFalse($service);
        $this->assertStringContainsString("public const CACHE_VERSION = 'v2';", (string) $service);
        $this->assertStringContainsString('guided_setup_destination', (string) $service);
        $this->assertStringContainsString('WorkspaceMarketplaceRecommendationService', (string) $service);
        $this->assertStringContainsString('WorkspaceMarketplaceSetupJourneyService', (string) $service);
        $this->assertStringContainsString('Connect email or WhatsApp', (string) $service);
        $this->assertStringContainsString('Set up invoices', (string) $service);
        $this->assertStringNotContainsString('OpenAI', (string) $service);
    }

    public function testWorkspaceSkillsRendersBeginnerGuidedDestinationOnlyOnMarketplacePages(): void
    {
        $page = file_get_contents(__DIR__ . '/../../../public/workspace_skills.php');

        $this->assertNotFalse($page);
        $this->assertStringContainsString('GuidedSetupDestinationService', (string) $page);
        $this->assertStringContainsString('data-guided-setup-destination', (string) $page);
        $this->assertStringContainsString('dashboard_readiness', (string) $page);
        $this->assertStringContainsString('dashboard_guidance', (string) $page);
        $this->assertStringContainsString('$marketplaceModeIsBeginner && !empty($guidedDestination', (string) $page);
    }
}
